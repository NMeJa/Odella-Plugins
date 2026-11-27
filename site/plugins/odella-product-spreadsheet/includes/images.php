<?php
/**
 * Picture cells, zip matching, and uploads into the media library.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array<int, string>
 */
function ops_split_pictures(string $cell): array
{
    $cell = str_replace(["\r\n", "\r"], "\n", trim($cell));
    if ($cell === '') {
        return [];
    }
    $parts = preg_split('/\s*(?:;|\||\n)\s*|,\s*(?=https?:\/\/)/iu', $cell) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return $out;
}

/**
 * @return array{kind: string, value: string, folder: string, message: string}
 */
function ops_picture_token(string $token): array
{
    if (mb_strtolower($token, 'UTF-8') === '(clear)') {
        return ['kind' => 'clear', 'value' => '', 'folder' => '', 'message' => ''];
    }
    if (preg_match('#^https://#i', $token)) {
        return ['kind' => 'url', 'value' => $token, 'folder' => '', 'message' => ''];
    }
    if (preg_match('#^[a-zA-Z]:[\\\\/]#', $token) || str_starts_with($token, '\\\\') || str_starts_with($token, '/') || str_contains($token, '\\') || str_contains($token, '..')) {
        return ['kind' => 'invalid', 'value' => '', 'folder' => '', 'message' => 'A path on your computer cannot be read. Put the file in the zip and write its file name (or FileBirdFolder/file.jpg), or paste an https URL.'];
    }
    $folder = '';
    $file = $token;
    if (str_contains($token, '/')) {
        $pos = strrpos($token, '/');
        $folder = trim(substr($token, 0, $pos), '/ ');
        $file = trim(substr($token, $pos + 1));
    }
    if ($file !== '' && preg_match('/\.(jpe?g|png|gif|webp)$/i', $file)) {
        return ['kind' => 'file', 'value' => $file, 'folder' => $folder, 'message' => ''];
    }
    return ['kind' => 'invalid', 'value' => '', 'folder' => '', 'message' => 'Use an https URL or a picture file name such as silk.jpg (optionally prefixed with FileBirdFolder/).'];
}

/**
 * @return array{files: array<string, string>, warnings: array<int, string>, errors: array<int, string>, dir: string}
 */
function ops_open_image_zip(string $path): array
{
    $result = ['files' => [], 'warnings' => [], 'errors' => [], 'dir' => ''];
    if (!class_exists('ZipArchive')) {
        $result['errors'][] = 'This server cannot open zip files.';
        return $result;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        $result['errors'][] = 'The picture zip could not be opened.';
        return $result;
    }
    $dir = rtrim(get_temp_dir(), '\\/') . '/ops-' . wp_generate_password(12, false, false);
    if (!wp_mkdir_p($dir)) {
        $zip->close();
        $result['errors'][] = 'A temporary folder for the pictures could not be created.';
        return $result;
    }
    $result['dir'] = $dir;
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
        if ($name === '' || str_ends_with($name, '/')) {
            continue;
        }
        if (str_contains($name, '../') || str_starts_with($name, '/')) {
            $result['errors'][] = 'The zip contains an unsafe path.';
            continue;
        }
        $base = basename($name);
        if ($base === '' || str_starts_with($base, '.')) {
            continue;
        }
        $key = mb_strtolower($base, 'UTF-8');
        if (isset($names[$key])) {
            $result['errors'][] = 'Two files in the zip are named ' . $base . '.';
            continue;
        }
        $names[$key] = $name;
    }
    if ($result['errors']) {
        $zip->close();
        return $result;
    }
    foreach ($names as $key => $name) {
        $target = $dir . '/' . basename($name);
        $contents = $zip->getFromName($name);
        if (!is_string($contents)) {
            $result['errors'][] = basename($name) . ' could not be read from the zip.';
            continue;
        }
        file_put_contents($target, $contents);
        $result['files'][$key] = $target;
    }
    $zip->close();
    return $result;
}

function ops_attachment_for_url(string $url): int
{
    if (!function_exists('attachment_url_to_postid')) {
        return 0;
    }
    $id = attachment_url_to_postid($url);
    return $id > 0 ? $id : 0;
}

function ops_sideload_path(string $path, int $parent, string $folder = ''): int
{
    static $cache = [];
    $cache_key = $path . '|' . $parent;
    if (isset($cache[$cache_key]) && $cache[$cache_key] > 0) {
        if ($folder !== '') {
            ops_filebird_assign($cache[$cache_key], $folder);
        }
        return $cache[$cache_key];
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp = wp_tempnam(basename($path));
    if (!is_string($tmp) || !copy($path, $tmp)) {
        throw new RuntimeException(basename($path) . ' could not be prepared for upload.');
    }
    $file = [
        'name' => basename($path),
        'tmp_name' => $tmp,
        'error' => 0,
        'size' => (int) filesize($path),
    ];
    $id = media_handle_sideload($file, $parent);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $attachment_id = (int) $id;
    $cache[$cache_key] = $attachment_id;
    if ($folder !== '') {
        ops_filebird_assign($attachment_id, $folder);
    }
    return $attachment_id;
}

/**
 * List FileBird folder paths (e.g. "Products/Vest").
 *
 * @return array<int, string>
 */
function ops_filebird_folders(): array
{
    global $wpdb;
    if (!isset($wpdb) || !is_object($wpdb)) {
        return [];
    }
    $table = $wpdb->prefix . 'fbv';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
        return [];
    }
    $rows = $wpdb->get_results("SELECT id, name, parent FROM {$table} ORDER BY parent ASC, name ASC", ARRAY_A);
    if (!is_array($rows) || !$rows) {
        return [];
    }
    $by_id = [];
    foreach ($rows as $row) {
        $by_id[(int) $row['id']] = [
            'id' => (int) $row['id'],
            'name' => html_entity_decode((string) $row['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'parent' => (int) $row['parent'],
        ];
    }
    $paths = [];
    foreach ($by_id as $id => $item) {
        $parts = [];
        $cur = $item;
        $guard = 0;
        while ($cur && $guard < 20) {
            array_unshift($parts, $cur['name']);
            $parent_id = $cur['parent'];
            $cur = $parent_id > 0 && isset($by_id[$parent_id]) ? $by_id[$parent_id] : null;
            $guard++;
        }
        $paths[] = implode('/', $parts);
    }
    usort($paths, static function (string $a, string $b): int {
        $a_prod = str_starts_with($a, 'Products/') ? 0 : 1;
        $b_prod = str_starts_with($b, 'Products/') ? 0 : 1;
        if ($a_prod !== $b_prod) {
            return $a_prod <=> $b_prod;
        }
        return strcasecmp($a, $b);
    });
    return $paths;
}

function ops_filebird_resolve_folder(string $folder_path): int
{
    global $wpdb;
    $folder_path = trim($folder_path, "/ \t\n\r");
    if ($folder_path === '' || !isset($wpdb) || !is_object($wpdb)) {
        return 0;
    }
    $table = $wpdb->prefix . 'fbv';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
        return 0;
    }
    $rows = $wpdb->get_results("SELECT id, name, parent FROM {$table}", ARRAY_A);
    if (!is_array($rows)) {
        $rows = [];
    }
    $by_id = [];
    $by_parent_name = [];
    $products_id = 0;
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $parent = (int) $row['parent'];
        $name = html_entity_decode((string) $row['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $key = mb_strtolower(trim($name), 'UTF-8');
        $by_id[$id] = ['id' => $id, 'name' => $name, 'parent' => $parent];
        $by_parent_name[$parent][$key] = $id;
        if ($parent === 0 && $key === 'products') {
            $products_id = $id;
        }
    }
    $segments = array_values(array_filter(array_map('trim', explode('/', $folder_path)), static fn (string $s): bool => $s !== ''));
    if (!$segments) {
        return 0;
    }
    // If single segment and it exists under Products (e.g. "Vest"), resolve under Products.
    $parent = 0;
    if (count($segments) === 1 && $products_id > 0) {
        $single_key = mb_strtolower($segments[0], 'UTF-8');
        if (isset($by_parent_name[$products_id][$single_key])) {
            return $by_parent_name[$products_id][$single_key];
        }
        if (isset($by_parent_name[0][$single_key])) {
            return $by_parent_name[0][$single_key];
        }
        $parent = $products_id;
    }
    foreach ($segments as $segment) {
        $key = mb_strtolower($segment, 'UTF-8');
        if (isset($by_parent_name[$parent][$key])) {
            $parent = $by_parent_name[$parent][$key];
            continue;
        }
        $wpdb->insert($table, [
            'name' => $segment,
            'parent' => $parent,
            'type' => 0,
            'ord' => 0,
            'created_by' => get_current_user_id(),
        ], ['%s', '%d', '%d', '%d', '%d']);
        $new_id = (int) $wpdb->insert_id;
        if ($new_id <= 0) {
            return 0;
        }
        $by_parent_name[$parent][$key] = $new_id;
        $parent = $new_id;
    }
    return $parent;
}

function ops_filebird_assign(int $attachment_id, string $folder_path): void
{
    global $wpdb;
    if ($attachment_id <= 0 || trim($folder_path) === '' || !isset($wpdb) || !is_object($wpdb)) {
        return;
    }
    $rel_table = $wpdb->prefix . 'fbv_attachment_folder';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $rel_table));
    if ($exists !== $rel_table) {
        return;
    }
    $folder_id = ops_filebird_resolve_folder($folder_path);
    if ($folder_id <= 0) {
        return;
    }
    $wpdb->delete($rel_table, ['attachment_id' => $attachment_id], ['%d']);
    $wpdb->insert($rel_table, [
        'folder_id' => $folder_id,
        'attachment_id' => $attachment_id,
    ], ['%d', '%d']);
}

function ops_sideload_url(string $url, int $parent): int
{
    $existing = ops_attachment_for_url($url);
    if ($existing > 0) {
        return $existing;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp = download_url($url, 20);
    if (is_wp_error($tmp)) {
        throw new RuntimeException($url . ' could not be downloaded.');
    }
    $name = basename((string) parse_url($url, PHP_URL_PATH));
    if ($name === '' || $name === '/' || $name === '.') {
        $name = 'product-image.jpg';
    }
    $file = [
        'name' => $name,
        'tmp_name' => $tmp,
        'error' => 0,
        'size' => (int) filesize($tmp),
    ];
    $id = media_handle_sideload($file, $parent);
    if (is_wp_error($id)) {
        @unlink($tmp);
        throw new RuntimeException($id->get_error_message());
    }
    return (int) $id;
}
