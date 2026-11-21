<?php
/**
 * Product spreadsheet screen.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'ops_register_menu', 11);
add_action('admin_post_ops_export', 'ops_handle_export');
add_action('admin_post_ops_import', 'ops_handle_import');
add_action('admin_post_ops_update_shop', 'ops_handle_shop');
add_action('admin_post_ops_corrected', 'ops_handle_corrected');
add_action('wp_ajax_ops_import_start', 'ops_ajax_import_start');
add_action('wp_ajax_ops_import_step', 'ops_ajax_import_step');

function ops_register_menu(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $parent = function_exists('odella_register_admin_menu') ? 'odella-speed-check' : '';
    if ($parent === '') {
        add_menu_page(
            'Product spreadsheet',
            'Product spreadsheet',
            'manage_woocommerce',
            'odella-product-spreadsheet',
            'ops_admin_page',
            'dashicons-media-spreadsheet',
            58
        );
        return;
    }
    add_submenu_page(
        $parent,
        'Product spreadsheet',
        'Product spreadsheet',
        'manage_woocommerce',
        'odella-product-spreadsheet',
        'ops_admin_page'
    );
}

function ops_admin_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html('You do not have permission to use the product spreadsheet.'));
    }
    $result = get_transient('ops_result_' . get_current_user_id());
    if (is_array($result)) {
        delete_transient('ops_result_' . get_current_user_id());
    } else {
        $result = null;
    }
    $shop = get_transient('ops_shop_' . get_current_user_id());
    if (is_array($shop)) {
        delete_transient('ops_shop_' . get_current_user_id());
    } else {
        $shop = null;
    }
    ?>
    <div class="wrap">
        <?php ops_column_picker_assets(); ?>
        <h1>Product spreadsheet</h1>
        <p>Download Georgian products, edit the file in the Odella Desktop Sheet Editor (or Excel / Google Sheets), then upload it. Column titles and status values are in English (<code>publish</code>, <code>draft</code>). Blank cells stay as they are. <code>(clear)</code> wipes a cell. One problem anywhere stops the import, and every problem is listed together. The Variations sheet is included when the file is Excel.</p>
        <?php
        $uploads = wp_upload_dir();
        $tool_rel = '/odella-tools/Odella-Sheet-Editor-Windows.zip';
        $tool_path = rtrim((string) ($uploads['basedir'] ?? ''), '/\\') . $tool_rel;
        $tool_url = rtrim((string) ($uploads['baseurl'] ?? ''), '/\\') . $tool_rel;
        if (file_exists($tool_path)) :
            $size_mb = round(filesize($tool_path) / (1024 * 1024), 1);
            ?>
            <div class="notice notice-info" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;">
                <div>
                    <strong>Odella Desktop Sheet Editor (Windows)</strong> — standalone app (no installation needed). Supports image previews, drag-and-drop FileBird tagging, multi-select categories, variation generator, Georgian/English translation, and automatic zipping.
                </div>
                <a class="button button-primary" href="<?php echo esc_url($tool_url); ?>" download>Download App (<?php echo esc_html((string) $size_mb); ?> MB)</a>
            </div>
        <?php endif; ?>
        <?php if (!class_exists('ZipArchive')) : ?>
            <div class="notice notice-error"><p>PHP ZipArchive is missing, so .xlsx files cannot be built on this server.</p></div>
        <?php endif; ?>
        <?php ops_render_result(is_array($result) ? $result : null); ?>
        <?php if (is_array($shop)) : ?>
            <div class="notice notice-success"><p><?php echo esc_html(implode(' ', $shop)); ?></p></div>
        <?php endif; ?>

        <h2>Export</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('ops_export'); ?>
            <input type="hidden" name="action" value="ops_export" />
            <table class="form-table" role="presentation"><tr><th scope="row">Columns</th><td>
                <?php ops_render_column_picker(); ?>
            </td></tr>
            <tr><th scope="row">File</th><td>
                <label><input type="radio" name="format" value="xlsx" checked /> Excel (.xlsx)</label>
                <label style="margin-left:12px;"><input type="radio" name="format" value="csv" /> CSV</label>
            </td></tr>
            <tr><th scope="row">Picture preview</th><td>
                <label><input type="radio" name="preview" value="none" checked /> URL only</label>
                <label style="margin-left:12px;"><input type="radio" name="preview" value="excel" /> Excel preview</label>
                <label style="margin-left:12px;"><input type="radio" name="preview" value="sheets" /> Google Sheets preview</label>
                <p class="description">Adds a preview beside each picture. One gallery cell holds several URLs, so each picture also gets its own hidden Gallery url column and its own preview. Excel shows the pictures when the file opens. Google Sheets needs the script below: Odella → Show pictures and lists.</p>
            </td></tr>
            <tr><th scope="row">Pictures</th><td>
                <label><input type="checkbox" name="pack_images" value="1" /> Also download the image files in a zip</label>
            </td></tr>
            </table>
            <p><button class="button button-primary" type="submit">Download</button></p>
        </form>
        <?php ops_render_sheet_scripts(); ?>

        <h2>Import</h2>
        <form id="ops-import-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('ops_import'); ?>
            <input type="hidden" name="action" value="ops_import" />
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="ops-sheet">Spreadsheet</label></th><td><input id="ops-sheet" type="file" name="sheet" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required /></td></tr>
                <tr><th scope="row"><label for="ops-zip">Picture zip</label></th><td><input id="ops-zip" type="file" name="pictures" accept=".zip,application/zip" /><p class="description">Required only when a cell contains a file name. The website cannot see a path on your computer.</p></td></tr>
                <tr><th scope="row"><label for="ops-batch-size">Batch size</label></th><td><input id="ops-batch-size" type="number" name="batch_size" value="5" min="1" max="50" step="1" class="small-text" /><p class="description">Number of products (or variation parent groups) saved per request (1–50, default 5). Lower values prevent server 500/503 timeouts; higher values run faster when few images or fields change.</p></td></tr>
                <tr><th scope="row">Missing products</th><td><label><input type="checkbox" name="trash_missing" value="1" /> Move existing products that are not in this file to Trash</label><p class="description">Off by default. Only runs if every row in the file succeeds. Also moves linked English translations to Trash (recoverable from Products → Trash).</p></td></tr>
            </table>
            <p><button id="ops-import-btn" class="button button-primary" type="submit">Check and import</button></p>
        </form>
        <div id="ops-import-progress" style="display:none;max-width:760px;margin:16px 0;padding:14px 16px;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;box-shadow:0 1px 1px rgba(0,0,0,.04);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                <strong id="ops-progress-title">Checking spreadsheet…</strong>
                <span id="ops-progress-pct" style="font-weight:600;color:#2271b1;">0%</span>
            </div>
            <div style="width:100%;height:14px;background:#e5e7eb;border-radius:7px;overflow:hidden;">
                <div id="ops-progress-bar" style="width:0%;height:100%;background:#2271b1;transition:width 0.25s ease;"></div>
            </div>
            <div id="ops-progress-detail" style="margin-top:8px;color:#50575e;font-size:13px;">Uploading and validating rows…</div>
        </div>
        <div id="ops-import-live-result"></div>
        <?php ops_render_import_ajax_script(); ?>
    </div>
    <?php
}

/**
 * @param array<string, mixed>|null $result
 */
function ops_render_result(?array $result): void
{
    if (!$result) {
        return;
    }
    if (!empty($result['errors']) && is_array($result['errors'])) {
        echo '<div class="notice notice-error"><p>Nothing was saved. Fix every row below and upload the file again.</p></div>';
        echo '<table class="widefat striped"><thead><tr><th>Row</th><th>Column</th><th>Problem</th></tr></thead><tbody>';
        foreach ($result['errors'] as $error) {
            echo '<tr><td>' . esc_html((string) ($error['row'] ?? '')) . '</td><td>' . esc_html((string) ($error['column'] ?? '')) . '</td><td>' . esc_html((string) ($error['message'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    if (!empty($result['warnings']) && is_array($result['warnings'])) {
        echo '<div class="notice notice-warning"><p>' . esc_html(implode(' ', $result['warnings'])) . '</p></div>';
    }
    if (!empty($result['failed'])) {
        echo '<div class="notice notice-error"><p>' . esc_html((string) $result['message']) . '</p>';
        if (!empty($result['corrected'])) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('ops_corrected');
            echo '<input type="hidden" name="action" value="ops_corrected" />';
            echo '<button class="button" type="submit">Download the file with new IDs filled in</button></form>';
        }
        echo '</div>';
    }
    if (isset($result['report']) && is_array($result['report'])) {
        if (!$result['report']) {
            echo '<div class="notice notice-success"><p>Nothing changed.</p></div>';
        } else {
            echo '<h2>What changed</h2><table class="widefat striped"><thead><tr><th>ID</th><th>SKU</th><th>Name</th><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>';
            foreach ($result['report'] as $row) {
                echo '<tr><td>' . esc_html((string) ($row['id'] ?? '')) . '</td><td>' . esc_html((string) ($row['sku'] ?? '')) . '</td><td>' . esc_html((string) ($row['name'] ?? '')) . '</td><td>' . esc_html((string) ($row['field'] ?? '')) . '</td><td>' . esc_html(ops_shorten((string) ($row['before'] ?? ''))) . '</td><td>' . esc_html(ops_shorten((string) ($row['after'] ?? ''))) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;">';
        wp_nonce_field('ops_update_shop');
        echo '<input type="hidden" name="action" value="ops_update_shop" />';
        echo '<p>The products are saved. The shop filters are still on the previous catalog.</p>';
        echo '<button class="button button-primary" type="submit">Update the shop</button></form>';
    }
}

function ops_shorten(string $text): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if (mb_strlen($text, 'UTF-8') <= 80) {
        return $text;
    }
    return mb_substr($text, 0, 80, 'UTF-8') . '…';
}

function ops_handle_export(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html('You do not have permission to export products.'));
    }
    check_admin_referer('ops_export');
    $selected = isset($_POST['columns']) && is_array($_POST['columns']) ? wp_unslash($_POST['columns']) : [];
    $order = isset($_POST['order']) && is_array($_POST['order']) ? wp_unslash($_POST['order']) : [];
    $selected = array_values(array_filter(array_map(static function ($key): string {
        return ops_clean_column_key((string) $key);
    }, $selected)));
    $order = array_values(array_filter(array_map(static function ($key): string {
        return ops_clean_column_key((string) $key);
    }, $order)));
    $format = isset($_POST['format']) && $_POST['format'] === 'csv' ? 'csv' : 'xlsx';
    $preview = isset($_POST['preview']) ? sanitize_key(wp_unslash($_POST['preview'])) : 'none';
    if (!in_array($preview, ['none', 'excel', 'sheets'], true)) {
        $preview = 'none';
    }
    $pack = $format === 'xlsx' && !empty($_POST['pack_images']);
    try {
        $file = ops_build_export(ops_selected_columns($selected, $order), $format, $preview, $pack);
    } catch (Throwable $error) {
        wp_die(esc_html($error->getMessage()));
    }
    ops_send_file($file['path'], $file['filename']);
}

function ops_handle_import(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html('You do not have permission to import products.'));
    }
    check_admin_referer('ops_import');
    $result = ['errors' => [], 'warnings' => [], 'report' => null, 'failed' => false, 'message' => '', 'corrected' => false];
    $sheet = $_FILES['sheet'] ?? null;
    if (!is_array($sheet) || !isset($sheet['tmp_name'], $sheet['name'], $sheet['error'])) {
        $result['errors'][] = ['row' => '', 'column' => '', 'message' => 'Choose a spreadsheet.'];
        ops_redirect_result($result);
    }
    if ((int) $sheet['error'] !== UPLOAD_ERR_OK) {
        $result['errors'][] = ['row' => '', 'column' => '', 'message' => 'The spreadsheet upload failed.'];
        ops_redirect_result($result);
    }
    $name = (string) $sheet['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'], true)) {
        $result['errors'][] = ['row' => '', 'column' => '', 'message' => 'Upload an .xlsx or a .csv file.'];
        ops_redirect_result($result);
    }
    $max = wp_max_upload_size();
    if ($max > 0 && (int) ($sheet['size'] ?? 0) > $max) {
        $result['errors'][] = ['row' => '', 'column' => '', 'message' => 'The file is larger than this server allows (' . size_format($max) . ').'];
        ops_redirect_result($result);
    }
    $zip_files = [];
    $zip_dir = '';
    $pictures = $_FILES['pictures'] ?? null;
    if (is_array($pictures) && (int) ($pictures['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ((int) $pictures['error'] !== UPLOAD_ERR_OK) {
            $result['errors'][] = ['row' => '', 'column' => '', 'message' => 'The picture zip upload failed.'];
            ops_redirect_result($result);
        }
        $opened = ops_open_image_zip((string) $pictures['tmp_name']);
        $zip_files = $opened['files'];
        $zip_dir = $opened['dir'];
        foreach ($opened['errors'] as $message) {
            $result['errors'][] = ['row' => '', 'column' => '', 'message' => $message];
        }
        if ($result['errors']) {
            ops_cleanup_dir($zip_dir);
            ops_redirect_result($result);
        }
    }
    try {
        $variations = [];
        if ($ext === 'csv') {
            $grid = ops_csv_read((string) $sheet['tmp_name']);
        } else {
            $book = ops_xlsx_read((string) $sheet['tmp_name']);
            if (isset($book['Products'])) {
                $grid = $book['Products'];
            } else {
                $first = reset($book);
                $grid = is_array($first) ? $first : [];
            }
            $variations = $book['Variations'] ?? [];
        }
        $checked = ops_preflight($grid, $zip_files);
        $variation_checked = function_exists('ops_preflight_variations') ? ops_preflight_variations($variations, $checked['plans']) : ['errors' => [], 'plans' => []];
    } catch (Throwable $error) {
        ops_cleanup_dir($zip_dir);
        $result['errors'][] = ['row' => '', 'column' => '', 'message' => $error->getMessage()];
        ops_redirect_result($result);
    }
    $result['warnings'] = $checked['warnings'];
    $errors = array_merge($checked['errors'], $variation_checked['errors']);
    if ($errors) {
        $result['errors'] = $errors;
        ops_cleanup_dir($zip_dir);
        ops_redirect_result($result);
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    $applied = ops_apply_plans($checked['plans'], $zip_files);
    ops_cleanup_dir($zip_dir);
    $result['report'] = $applied['report'];
    if (!$applied['ok']) {
        $result['failed'] = true;
        $result['message'] = 'Row ' . $applied['failed_row'] . ' failed after the check: ' . $applied['message'] . ' Rows already saved stay saved.';
        $file = ops_corrected_file($checked['headers'], $checked['labels'], $checked['plans'], $applied['created'], $ext === 'csv' ? 'csv' : 'xlsx');
        set_transient('ops_corrected_' . get_current_user_id(), $file, HOUR_IN_SECONDS);
        $result['corrected'] = true;
        ops_redirect_result($result);
    }
    if (!empty($variation_checked['plans']) && function_exists('ops_apply_variations')) {
        $desired_statuses = [];
        foreach ($checked['plans'] as $pplan) {
            $st = (string) ($pplan['values']['status'] ?? '');
            if ($st === '') {
                continue;
            }
            $pid = (int) ($pplan['id'] ?? 0);
            if ($pid > 0) {
                $desired_statuses['id:' . $pid] = $st;
            }
            $psku = mb_strtolower(trim((string) ($pplan['values']['sku'] ?? $pplan['raw']['sku'] ?? '')), 'UTF-8');
            if ($psku !== '') {
                $desired_statuses['sku:' . $psku] = $st;
            }
        }
        try {
            $result['report'] = array_merge($result['report'], ops_apply_variations($variation_checked['plans'], $applied['created_by_sku'] ?? [], $desired_statuses));
        } catch (Throwable $error) {
            $result['failed'] = true;
            $result['message'] = $error->getMessage();
        }
    }
    if (empty($result['failed']) && !empty($_POST['trash_missing']) && function_exists('ops_trash_missing_products')) {
        $kept_ids = [];
        foreach ($checked['plans'] as $plan) {
            $pid = (int) ($plan['id'] ?? 0);
            if ($pid > 0) {
                $kept_ids[$pid] = true;
            }
        }
        foreach (($applied['created'] ?? []) as $new_id) {
            if ((int) $new_id > 0) {
                $kept_ids[(int) $new_id] = true;
            }
        }
        $result['report'] = array_merge($result['report'], ops_trash_missing_products(array_keys($kept_ids)));
    }
    ops_redirect_result($result);
}

function ops_handle_shop(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html('You do not have permission to update the shop.'));
    }
    check_admin_referer('ops_update_shop');
    $messages = [];
    if (function_exists('nmj_product_index_build_all')) {
        $built = nmj_product_index_build_all();
        $messages[] = 'Catalog index rebuilt (' . (int) ($built['count'] ?? 0) . ' products).';
    } else {
        $messages[] = 'The catalog index could not be rebuilt.';
    }
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients();
    }
    wp_cache_flush();
    do_action('litespeed_purge_all');
    $messages[] = 'Page cache cleared.';
    set_transient('ops_shop_' . get_current_user_id(), $messages, MINUTE_IN_SECONDS);
    wp_safe_redirect(admin_url('admin.php?page=odella-product-spreadsheet'));
    exit;
}

function ops_handle_corrected(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html('You do not have permission to download that file.'));
    }
    check_admin_referer('ops_corrected');
    $file = get_transient('ops_corrected_' . get_current_user_id());
    if (!is_array($file) || empty($file['path']) || !is_file((string) $file['path'])) {
        wp_die(esc_html('That file is no longer available. Export a fresh spreadsheet.'));
    }
    ops_send_file((string) $file['path'], (string) ($file['filename'] ?? 'odella-corrected.xlsx'));
}

/**
 * @return array<int, array<int, array{text: string, formula: string, runs: array<int, array{text: string, bold: bool, italic: bool, underline: bool}>}>>
 */
function ops_xlsx_products(string $path): array
{
    $sheets = ops_xlsx_read($path);
    if (isset($sheets['Products'])) {
        return $sheets['Products'];
    }
    $first = reset($sheets);
    return is_array($first) ? $first : [];
}

/**
 * @param array<string, mixed> $result
 */
function ops_redirect_result(array $result): void
{
    set_transient('ops_result_' . get_current_user_id(), $result, 15 * MINUTE_IN_SECONDS);
    wp_safe_redirect(admin_url('admin.php?page=odella-product-spreadsheet'));
    exit;
}

function ops_send_file(string $path, string $filename): void
{
    if (!is_file($path)) {
        wp_die(esc_html('The file is missing.'));
    }
    $type = str_ends_with(strtolower($filename), '.csv') ? 'text/csv; charset=utf-8' : (str_ends_with(strtolower($filename), '.zip') ? 'application/zip' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}

function ops_cleanup_dir(string $dir): void
{
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    @rmdir($dir);
}

function ops_render_column_picker(): void
{
    echo '<input type="hidden" name="columns[]" value="id" />';
    echo '<input type="hidden" name="columns[]" value="sku" />';
    echo '<input type="hidden" name="columns[]" value="name" />';
    echo '<p>ID, SKU, and Name are always the first three columns. Name stays on screen when you scroll sideways.</p>';
    echo '<p>';
    echo '<button type="button" class="button" data-ops-select="all">Select all</button> ';
    echo '<button type="button" class="button" data-ops-select="none">Unselect all</button> ';
    echo '<button type="button" class="button" data-ops-select="default">Select default</button>';
    echo '</p>';
    echo '<ul class="ops-col-list" id="ops-main-columns">';
    foreach (ops_columns() as $key => $column) {
        if ($column['locked']) {
            continue;
        }
        ops_render_column_item($key, $column['label'], (bool) $column['default']);
    }
    echo '</ul>';
    echo '<p class="description">EUR and USD columns are manual prices. A blank cell keeps the bank rate. Drag a row, or use Up and Down, to choose the column order. Select default ticks categories, product type, the three pictures, status, both GEL prices, both descriptions, EUR, USD, and attributes.</p>';
    echo '<h3 style="margin:16px 0 6px;">Additional fields</h3>';
    echo '<p class="description">These are the other product fields. They stay out of the file until you tick them. Drag one into the list above to choose its place. Fields left here are added at the end.</p>';
    echo '<ul class="ops-col-list" id="ops-extra-columns">';
    if (function_exists('ops_meta_fields')) {
        foreach (ops_meta_fields() as $field) {
            ops_render_column_item($field['column'], $field['label'], false);
        }
    }
    echo '</ul>';
}

function ops_render_column_item(string $key, string $label, bool $checked): void
{
    echo '<li draggable="true">';
    echo '<span class="ops-handle" aria-hidden="true">↕</span>';
    echo '<input type="hidden" name="order[]" value="' . esc_attr($key) . '" />';
    echo '<label><input type="checkbox" name="columns[]" value="' . esc_attr($key) . '" data-default="' . ($checked ? '1' : '0') . '" ' . checked($checked, true, false) . ' /> ' . esc_html($label) . '</label>';
    echo '<button type="button" class="button button-small ops-up">Up</button>';
    echo '<button type="button" class="button button-small ops-down">Down</button>';
    echo '</li>';
}

function ops_render_sheet_scripts(): void
{
    ?>
    <h2>Dropdowns and pictures</h2>
    <p>Categories, tags, and attributes are lists you tick in the script. A product can hold several. Click a ticked item to remove it. Status and product type stay a single choice. Drop a picture onto Featured image, Hover image, or Gallery images, then click a cell: the script stores it and shows the preview. Gallery drops are added at the end. Drag a gallery preview sideways, then click a cell, to change the order. A drop on a preview column is refused, with a short message that names the column the shop reads.</p>
    <details>
        <summary>Google Sheets script</summary>
        <p>Extensions → Apps Script, replace everything, Save. In the function list choose <code>odellaSetup</code>, click Run, and allow the permissions. Reload the spreadsheet. The Odella panel opens beside the sheet: click Categories, Tags, or Attributes and the ticks follow that cell. Picture previews are drawn again. The text already in the cells stays. Use Odella → Read dropped pictures after a drop, not while the panel is closed.</p>
        <p><button type="button" class="button" data-ops-copy="ops-google-script">Copy Google script</button></p>
        <textarea id="ops-google-script" class="large-text code" rows="14" readonly><?php echo esc_textarea(ops_google_script()); ?></textarea>
    </details>
    <details style="margin-top:12px;">
        <summary>Excel script</summary>
        <p>Save the file as .xlsm. Alt+F11. Insert → Module, and paste the MODULE section. Then double-click the Products sheet and paste the SHEET section. Save and allow macros. Dropped pictures are copied into an odella-images folder next to the workbook; the cell gets the file name. Zip that folder and upload it with the spreadsheet.</p>
        <p><button type="button" class="button" data-ops-copy="ops-excel-script">Copy Excel script</button></p>
        <textarea id="ops-excel-script" class="large-text code" rows="14" readonly><?php echo esc_textarea(ops_excel_script()); ?></textarea>
    </details>
    <details style="margin-top:12px;">
        <summary>LibreOffice and OpenOffice script</summary>
        <p>Tools → Macros → Organize Macros → LibreOffice Basic, or OpenOffice Basic. Select this file, New, name the module Odella, and paste. Run OdellaSetup. Then Tools → Customize → Events, choose this document, and assign Double-click to OdellaDoubleClick. OdellaAddPicture stores a picture file. OdellaGalleryEarlier and OdellaGalleryLater change the gallery order.</p>
        <p><button type="button" class="button" data-ops-copy="ops-libreoffice-script">Copy LibreOffice / OpenOffice script</button></p>
        <textarea id="ops-libreoffice-script" class="large-text code" rows="14" readonly><?php echo esc_textarea(ops_libreoffice_script()); ?></textarea>
    </details>
    <details style="margin-top:12px;">
        <summary>OnlyOffice script</summary>
        <p>View → Macros, add a macro named Odella, and paste. The last line runs the picture previews. For the tick list, change that line to <code>odellaLists();</code>, type yes beside the wanted rows on the OdellaPick sheet, then change the line to <code>odellaApplyList();</code>. Gallery order uses <code>odellaMoveGallery(-1);</code> or <code>odellaMoveGallery(1);</code> while a gallery preview cell is selected. OnlyOffice cannot read a picture dropped from the desktop; paste an https link into the picture cell.</p>
        <p><button type="button" class="button" data-ops-copy="ops-onlyoffice-script">Copy OnlyOffice script</button></p>
        <textarea id="ops-onlyoffice-script" class="large-text code" rows="14" readonly><?php echo esc_textarea(ops_onlyoffice_script()); ?></textarea>
    </details>
    <?php
}

function ops_column_picker_assets(): void
{
    ?>
    <style>
        .ops-col-list { list-style: none; margin: 8px 0; padding: 0; max-width: 560px; min-height: 36px; }
        .ops-col-list li { display: flex; gap: 8px; align-items: center; margin: 0 0 4px; padding: 4px 8px; background: #fff; border: 1px solid #dcdcde; }
        .ops-col-list li label { flex: 1; }
        .ops-handle { cursor: grab; color: #787c82; }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var dragged = null;
        document.querySelectorAll('.ops-col-list').forEach(function (list) {
            list.addEventListener('dragstart', function (event) {
                var item = event.target.closest('li[draggable="true"]');
                if (!item) return;
                dragged = item;
                event.dataTransfer.effectAllowed = 'move';
            });
            list.addEventListener('dragover', function (event) {
                if (!dragged) return;
                event.preventDefault();
                var item = event.target.closest('li[draggable="true"]');
                if (!item || item.parentNode !== list) {
                    list.appendChild(dragged);
                    return;
                }
                if (item === dragged) return;
                var rect = item.getBoundingClientRect();
                list.insertBefore(dragged, event.clientY > rect.top + rect.height / 2 ? item.nextSibling : item);
            });
            list.addEventListener('drop', function (event) { event.preventDefault(); });
        });
        document.querySelectorAll('.ops-up, .ops-down').forEach(function (button) {
            button.addEventListener('click', function () {
                var item = button.closest('li');
                var list = item.parentNode;
                if (button.classList.contains('ops-up') && item.previousElementSibling) {
                    list.insertBefore(item, item.previousElementSibling);
                }
                if (button.classList.contains('ops-down') && item.nextElementSibling) {
                    list.insertBefore(item.nextElementSibling, item);
                }
            });
        });
        document.querySelectorAll('[data-ops-select]').forEach(function (button) {
            button.addEventListener('click', function () {
                var mode = button.getAttribute('data-ops-select');
                document.querySelectorAll('.ops-col-list input[type="checkbox"]').forEach(function (box) {
                    if (mode === 'all') box.checked = true;
                    else if (mode === 'none') box.checked = false;
                    else box.checked = box.getAttribute('data-default') === '1';
                });
            });
        });
        document.querySelectorAll('[data-ops-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var area = document.getElementById(button.getAttribute('data-ops-copy'));
                if (!area) return;
                var done = function () {
                    var previous = button.textContent;
                    button.textContent = 'Copied';
                    setTimeout(function () { button.textContent = previous; }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(area.value).then(done);
                    return;
                }
                area.focus();
                area.select();
                document.execCommand('copy');
                done();
            });
        });
    });
    </script>
    <?php
}

function ops_import_job_path(string $job_id): string
{
    if (!preg_match('/^job-[a-f0-9]{16}$/', $job_id)) {
        return '';
    }
    return ops_work_dir() . '/' . $job_id . '.json';
}

function ops_ajax_import_start(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'You do not have permission to import products.'], 403);
    }
    check_ajax_referer('ops_import_ajax', 'nonce');
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }
    $errors = [];
    $sheet = $_FILES['sheet'] ?? null;
    if (!is_array($sheet) || !isset($sheet['tmp_name'], $sheet['name'], $sheet['error'])) {
        wp_send_json_success(['ok' => false, 'errors' => [['row' => '', 'column' => '', 'message' => 'Choose a spreadsheet.']], 'warnings' => []]);
    }
    if ((int) $sheet['error'] !== UPLOAD_ERR_OK) {
        wp_send_json_success(['ok' => false, 'errors' => [['row' => '', 'column' => '', 'message' => 'The spreadsheet upload failed.']], 'warnings' => []]);
    }
    $name = (string) $sheet['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'], true)) {
        wp_send_json_success(['ok' => false, 'errors' => [['row' => '', 'column' => '', 'message' => 'Upload an .xlsx or a .csv file.']], 'warnings' => []]);
    }
    $max = wp_max_upload_size();
    if ($max > 0 && (int) ($sheet['size'] ?? 0) > $max) {
        wp_send_json_success(['ok' => false, 'errors' => [['row' => '', 'column' => '', 'message' => 'The file is larger than this server allows (' . size_format($max) . ').']], 'warnings' => []]);
    }
    $zip_files = [];
    $zip_dir = '';
    $pictures = $_FILES['pictures'] ?? null;
    if (is_array($pictures) && (int) ($pictures['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ((int) $pictures['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_success(['ok' => false, 'errors' => [['row' => '', 'column' => '', 'message' => 'The picture zip upload failed.']], 'warnings' => []]);
        }
        $opened = ops_open_image_zip((string) $pictures['tmp_name']);
        $zip_files = $opened['files'];
        $zip_dir = $opened['dir'];
        foreach ($opened['errors'] as $message) {
            $errors[] = ['row' => '', 'column' => '', 'message' => $message];
        }
        if ($errors) {
            ops_cleanup_dir($zip_dir);
            wp_send_json_success(['ok' => false, 'errors' => $errors, 'warnings' => []]);
        }
    }
    try {
        $variations = [];
        if ($ext === 'csv') {
            $grid = ops_csv_read((string) $sheet['tmp_name']);
        } else {
            $book = ops_xlsx_read((string) $sheet['tmp_name']);
            if (isset($book['Products'])) {
                $grid = $book['Products'];
            } else {
                $first = reset($book);
                $grid = is_array($first) ? $first : [];
            }
            $variations = $book['Variations'] ?? [];
        }
        $checked = ops_preflight($grid, $zip_files);
        $variation_checked = function_exists('ops_preflight_variations') ? ops_preflight_variations($variations, $checked['plans']) : ['errors' => [], 'plans' => []];
    } catch (Throwable $error) {
        ops_cleanup_dir($zip_dir);
        wp_send_json_success(['ok' => false, 'errors' => [['row' => '', 'column' => '', 'message' => $error->getMessage()]], 'warnings' => []]);
    }
    $all_errors = array_merge($checked['errors'], $variation_checked['errors']);
    if ($all_errors) {
        ops_cleanup_dir($zip_dir);
        wp_send_json_success(['ok' => false, 'errors' => $all_errors, 'warnings' => $checked['warnings']]);
    }

    // Group variation plans by parent so a parent's variations are never split across chunks
    $var_groups_map = [];
    foreach ($variation_checked['plans'] as $vplan) {
        $pid = (int) ($vplan['parent'] ?? 0);
        $psku = mb_strtolower(trim((string) ($vplan['parent_sku'] ?? '')), 'UTF-8');
        $gkey = $pid > 0 ? ('id:' . $pid) : ('sku:' . $psku);
        $var_groups_map[$gkey][] = $vplan;
    }
    $variation_groups = array_values($var_groups_map);
    $desired_statuses = [];
    foreach ($checked['plans'] as $pplan) {
        $st = (string) ($pplan['values']['status'] ?? '');
        if ($st === '') {
            continue;
        }
        $pid = (int) ($pplan['id'] ?? 0);
        if ($pid > 0) {
            $desired_statuses['id:' . $pid] = $st;
        }
        $psku = mb_strtolower(trim((string) ($pplan['values']['sku'] ?? $pplan['raw']['sku'] ?? '')), 'UTF-8');
        if ($psku !== '') {
            $desired_statuses['sku:' . $psku] = $st;
        }
    }
    $total_products = count($checked['plans']);
    $total_variations = count($variation_checked['plans']);
    $trash_missing = !empty($_POST['trash_missing']);
    $raw_batch = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 5;
    $batch_size = max(1, min(50, $raw_batch > 0 ? $raw_batch : 5));

    $job_id = 'job-' . bin2hex(random_bytes(8));
    $job_path = ops_import_job_path($job_id);
    $phase = $total_products > 0 ? 'products' : ($total_variations > 0 ? 'variations' : ($trash_missing ? 'trash' : 'done'));
    $job = [
        'user_id' => get_current_user_id(),
        'ext' => $ext,
        'headers' => $checked['headers'],
        'labels' => $checked['labels'],
        'plans' => $checked['plans'],
        'variation_groups' => $variation_groups,
        'desired_statuses' => $desired_statuses,
        'total_products' => $total_products,
        'total_variations' => $total_variations,
        'batch_size' => $batch_size,
        'zip_files' => $zip_files,
        'zip_dir' => $zip_dir,
        'trash_missing' => $trash_missing,
        'warnings' => $checked['warnings'],
        'product_offset' => 0,
        'var_group_offset' => 0,
        'variations_done' => 0,
        'created' => [],
        'created_by_sku' => [],
        'report' => [],
        'phase' => $phase,
    ];
    $encoded = wp_json_encode($job);
    if (!is_string($encoded) || file_put_contents($job_path, $encoded) === false) {
        ops_cleanup_dir($zip_dir);
        wp_send_json_error(['message' => 'Could not save import batch state on the server.']);
    }
    wp_send_json_success([
        'ok' => true,
        'job_id' => $job_id,
        'phase' => $phase,
        'total_products' => $total_products,
        'total_variations' => $total_variations,
        'batch_size' => $batch_size,
        'trash_missing' => $trash_missing,
        'warnings' => $checked['warnings'],
    ]);
}

function ops_ajax_import_step(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'You do not have permission to import products.'], 403);
    }
    check_ajax_referer('ops_import_ajax', 'nonce');
    if (function_exists('set_time_limit')) {
        @set_time_limit(60);
    }
    $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
    $job_path = ops_import_job_path($job_id);
    if ($job_path === '' || !is_file($job_path)) {
        wp_send_json_error(['message' => 'Import job expired or was not found. Please start the upload again.']);
    }
    $raw = file_get_contents($job_path);
    $job = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($job) || (int) ($job['user_id'] ?? 0) !== get_current_user_id()) {
        wp_send_json_error(['message' => 'Invalid import job state.']);
    }

    $batch_size = max(1, min(50, (int) ($job['batch_size'] ?? 5)));
    $product_batch = $batch_size;
    $var_group_batch = $batch_size;

    if ($job['phase'] === 'products') {
        $offset = (int) $job['product_offset'];
        $slice = array_slice($job['plans'], $offset, $product_batch);
        if ($slice) {
            $applied = ops_apply_plans($slice, $job['zip_files']);
            $job['report'] = array_merge($job['report'], $applied['report']);
            foreach (($applied['created'] ?? []) as $sheet_row => $new_id) {
                $job['created'][(int) $sheet_row] = (int) $new_id;
            }
            foreach (($applied['created_by_sku'] ?? []) as $sku_k => $new_id) {
                $job['created_by_sku'][$sku_k] = (int) $new_id;
            }
            if (!$applied['ok']) {
                $file = ops_corrected_file($job['headers'], $job['labels'], $job['plans'], $job['created'], $job['ext'] === 'csv' ? 'csv' : 'xlsx');
                set_transient('ops_corrected_' . get_current_user_id(), $file, HOUR_IN_SECONDS);
                ops_cleanup_dir((string) $job['zip_dir']);
                @unlink($job_path);
                wp_send_json_success([
                    'ok' => false,
                    'failed' => true,
                    'message' => 'Row ' . $applied['failed_row'] . ' failed after the check: ' . $applied['message'] . ' Rows already saved stay saved.',
                    'report' => $job['report'],
                    'warnings' => $job['warnings'],
                    'corrected' => true,
                ]);
            }
            $job['product_offset'] = $offset + count($slice);
        }
        if ($job['product_offset'] >= (int) $job['total_products']) {
            if (!empty($job['variation_groups'])) {
                $job['phase'] = 'variations';
            } elseif (!empty($job['trash_missing'])) {
                $job['phase'] = 'trash';
            } else {
                $job['phase'] = 'done';
            }
        }
    } elseif ($job['phase'] === 'variations') {
        $g_offset = (int) $job['var_group_offset'];
        $g_slice = array_slice($job['variation_groups'], $g_offset, $var_group_batch);
        $v_slice = [];
        foreach ($g_slice as $grp) {
            foreach ($grp as $vp) {
                $v_slice[] = $vp;
            }
        }
        if ($v_slice && function_exists('ops_apply_variations')) {
            try {
                $v_changes = ops_apply_variations($v_slice, $job['created_by_sku'], is_array($job['desired_statuses'] ?? null) ? $job['desired_statuses'] : []);
                $job['report'] = array_merge($job['report'], $v_changes);
            } catch (Throwable $error) {
                ops_cleanup_dir((string) $job['zip_dir']);
                @unlink($job_path);
                wp_send_json_success([
                    'ok' => false,
                    'failed' => true,
                    'message' => $error->getMessage(),
                    'report' => $job['report'],
                    'warnings' => $job['warnings'],
                    'corrected' => false,
                ]);
            }
            $job['var_group_offset'] = $g_offset + count($g_slice);
            $job['variations_done'] = (int) $job['variations_done'] + count($v_slice);
        }
        if ($job['var_group_offset'] >= count($job['variation_groups'])) {
            $job['phase'] = !empty($job['trash_missing']) ? 'trash' : 'done';
        }
    } elseif ($job['phase'] === 'trash') {
        if (function_exists('ops_trash_missing_products')) {
            $kept_ids = [];
            foreach ($job['plans'] as $plan) {
                $pid = (int) ($plan['id'] ?? 0);
                if ($pid > 0) {
                    $kept_ids[$pid] = true;
                }
            }
            foreach (($job['created'] ?? []) as $new_id) {
                if ((int) $new_id > 0) {
                    $kept_ids[(int) $new_id] = true;
                }
            }
            $job['report'] = array_merge($job['report'], ops_trash_missing_products(array_keys($kept_ids)));
        }
        $job['phase'] = 'done';
    }

    if ($job['phase'] === 'done') {
        ops_cleanup_dir((string) $job['zip_dir']);
        @unlink($job_path);
        wp_send_json_success([
            'ok' => true,
            'done' => true,
            'phase' => 'done',
            'products_done' => (int) $job['product_offset'],
            'total_products' => (int) $job['total_products'],
            'variations_done' => (int) $job['variations_done'],
            'total_variations' => (int) $job['total_variations'],
            'changes_count' => count($job['report']),
            'report' => $job['report'],
            'warnings' => $job['warnings'],
        ]);
    }

    file_put_contents($job_path, wp_json_encode($job));
    wp_send_json_success([
        'ok' => true,
        'done' => false,
        'phase' => $job['phase'],
        'products_done' => (int) $job['product_offset'],
        'total_products' => (int) $job['total_products'],
        'variations_done' => (int) $job['variations_done'],
        'total_variations' => (int) $job['total_variations'],
        'changes_count' => count($job['report']),
    ]);
}

function ops_render_import_ajax_script(): void
{
    $ajax_url = admin_url('admin-ajax.php');
    $ajax_nonce = wp_create_nonce('ops_import_ajax');
    $shop_url = admin_url('admin-post.php');
    $shop_nonce = wp_create_nonce('ops_update_shop');
    $corr_nonce = wp_create_nonce('ops_corrected');
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('ops-import-form');
        if (!form || !window.fetch || !window.FormData) return;
        var btn = document.getElementById('ops-import-btn');
        var box = document.getElementById('ops-import-progress');
        var title = document.getElementById('ops-progress-title');
        var pctEl = document.getElementById('ops-progress-pct');
        var bar = document.getElementById('ops-progress-bar');
        var detail = document.getElementById('ops-progress-detail');
        var out = document.getElementById('ops-import-live-result');
        var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
        var ajaxNonce = <?php echo wp_json_encode($ajax_nonce); ?>;
        var postUrl = <?php echo wp_json_encode($shop_url); ?>;
        var shopNonce = <?php echo wp_json_encode($shop_nonce); ?>;
        var corrNonce = <?php echo wp_json_encode($corr_nonce); ?>;

        function esc(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
        function shorten(s) {
            var t = String(s == null ? '' : s).replace(/\s+/g, ' ').trim();
            return t.length > 80 ? t.slice(0, 80) + '…' : t;
        }
        function setProgress(pct, head, sub, color) {
            var p = Math.max(0, Math.min(100, Math.round(pct)));
            box.style.display = 'block';
            bar.style.width = p + '%';
            pctEl.textContent = p + '%';
            if (head) title.textContent = head;
            if (sub != null) detail.textContent = sub;
            if (color) {
                bar.style.background = color;
                box.style.borderLeftColor = color;
                pctEl.style.color = color;
            }
        }
        function renderErrors(errors, warnings) {
            var html = '';
            if (errors && errors.length) {
                html += '<div class="notice notice-error"><p>Nothing was saved. Fix every row below and upload the file again.</p></div>';
                html += '<table class="widefat striped"><thead><tr><th>Row</th><th>Column</th><th>Problem</th></tr></thead><tbody>';
                errors.forEach(function (e) {
                    html += '<tr><td>' + esc(e.row) + '</td><td>' + esc(e.column) + '</td><td>' + esc(e.message) + '</td></tr>';
                });
                html += '</tbody></table>';
            }
            if (warnings && warnings.length) {
                html += '<div class="notice notice-warning"><p>' + esc(warnings.join(' ')) + '</p></div>';
            }
            out.innerHTML = html;
        }
        function renderFinal(data) {
            var html = '';
            if (data.warnings && data.warnings.length) {
                html += '<div class="notice notice-warning"><p>' + esc(data.warnings.join(' ')) + '</p></div>';
            }
            if (data.failed) {
                html += '<div class="notice notice-error"><p>' + esc(data.message || 'Import failed.') + '</p>';
                if (data.corrected) {
                    html += '<form method="post" action="' + esc(postUrl) + '">'
                        + '<input type="hidden" name="_wpnonce" value="' + esc(corrNonce) + '" />'
                        + '<input type="hidden" name="action" value="ops_corrected" />'
                        + '<button class="button" type="submit">Download the file with new IDs filled in</button></form>';
                }
                html += '</div>';
            }
            var report = Array.isArray(data.report) ? data.report : [];
            if (!report.length && !data.failed) {
                html += '<div class="notice notice-success"><p>Nothing changed.</p></div>';
            } else if (report.length) {
                html += '<h2>What changed (' + report.length + ')</h2><table class="widefat striped"><thead><tr><th>ID</th><th>SKU</th><th>Name</th><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>';
                report.forEach(function (r) {
                    html += '<tr><td>' + esc(r.id) + '</td><td>' + esc(r.sku) + '</td><td>' + esc(r.name) + '</td><td>' + esc(r.field) + '</td><td>' + esc(shorten(r.before)) + '</td><td>' + esc(shorten(r.after)) + '</td></tr>';
                });
                html += '</tbody></table>';
            }
            if (!data.failed) {
                html += '<form method="post" action="' + esc(postUrl) + '" style="margin-top:16px;">'
                    + '<input type="hidden" name="_wpnonce" value="' + esc(shopNonce) + '" />'
                    + '<input type="hidden" name="action" value="ops_update_shop" />'
                    + '<p>The products are saved. The shop filters are still on the previous catalog.</p>'
                    + '<button class="button button-primary" type="submit">Update the shop</button></form>';
            }
            out.innerHTML = html;
        }
        async function postStep(jobId, attempt) {
            var fd = new FormData();
            fd.append('action', 'ops_import_step');
            fd.append('nonce', ajaxNonce);
            fd.append('job_id', jobId);
            try {
                var resp = await fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                var json = await resp.json();
                if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.message) ? json.data.message : 'Batch failed.');
                }
                return json.data;
            } catch (err) {
                if (attempt < 2) {
                    await new Promise(function (r) { setTimeout(r, 1500); });
                    return postStep(jobId, attempt + 1);
                }
                throw err;
            }
        }

        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            var sheetInput = document.getElementById('ops-sheet');
            if (!sheetInput || !sheetInput.files || !sheetInput.files.length) return;
            btn.disabled = true;
            btn.textContent = 'Importing…';
            out.innerHTML = '';
            setProgress(2, 'Checking spreadsheet…', 'Uploading file and validating all rows…', '#2271b1');

            try {
                var fd = new FormData(form);
                fd.set('action', 'ops_import_start');
                fd.append('nonce', ajaxNonce);
                var startResp = await fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
                if (!startResp.ok) throw new Error('Upload check failed (HTTP ' + startResp.status + ').');
                var startJson = await startResp.json();
                if (!startJson || !startJson.success) {
                    throw new Error((startJson && startJson.data && startJson.data.message) ? startJson.data.message : 'Upload failed.');
                }
                var startData = startJson.data;
                if (!startData.ok) {
                    setProgress(100, 'Validation stopped the import', (startData.errors ? startData.errors.length : 0) + ' error(s) found.', '#d63638');
                    renderErrors(startData.errors || [], startData.warnings || []);
                    return;
                }

                var jobId = startData.job_id;
                var totalP = startData.total_products || 0;
                var totalV = startData.total_variations || 0;
                var batchSize = startData.batch_size || 5;
                var trashExtra = startData.trash_missing ? 1 : 0;
                var totalUnits = Math.max(1, totalP + totalV + trashExtra);

                setProgress(5, 'Applying changes in batches of ' + batchSize + '…', 'Products: 0 / ' + totalP + (totalV ? ' · Variations: 0 / ' + totalV : '') + ' · Changes saved: 0', '#2271b1');

                while (true) {
                    var step = await postStep(jobId, 0);
                    if (!step.ok && step.failed) {
                        setProgress(100, 'Import stopped on an error', step.message || '', '#d63638');
                        renderFinal(step);
                        return;
                    }
                    var doneUnits = (step.products_done || 0) + (step.variations_done || 0) + (step.done && trashExtra ? 1 : 0);
                    var pct = step.done ? 100 : Math.max(5, Math.min(99, (doneUnits / totalUnits) * 100));
                    var label = step.done
                        ? 'Import complete!'
                        : (step.phase === 'variations'
                            ? 'Applying variations in batches of ' + batchSize + '…'
                            : (step.phase === 'trash' ? 'Moving missing products to Trash…' : 'Applying products in batches of ' + batchSize + '…'));
                    var sub = 'Products: ' + (step.products_done || 0) + ' / ' + totalP
                        + (totalV ? (' · Variations: ' + (step.variations_done || 0) + ' / ' + totalV) : '')
                        + ' · Changes saved: ' + (step.changes_count || 0);
                    setProgress(pct, label, sub, step.done ? '#00a32a' : '#2271b1');
                    if (step.done) {
                        renderFinal(step);
                        break;
                    }
                }
            } catch (err) {
                setProgress(100, 'Import interrupted', err && err.message ? err.message : String(err), '#d63638');
                out.innerHTML = '<div class="notice notice-error"><p>' + esc(err && err.message ? err.message : String(err)) + '</p></div>';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Check and import';
            }
        });
    });
    </script>
    <?php
}
