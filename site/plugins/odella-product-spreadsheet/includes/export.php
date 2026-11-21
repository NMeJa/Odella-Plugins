<?php
/**
 * Build the download: an xlsx table, or a CSV, optionally with a zip of pictures.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param array<int, string> $keys
 * @return array{path: string, filename: string}
 */
function ops_build_export(array $keys, string $format, string $preview, bool $pack_images): array
{
    $include_preview = $format === 'xlsx' && ($preview === 'excel' || $preview === 'sheets');
    $gallery_count = 0;
    if ($include_preview && in_array('gallery', $keys, true)) {
        foreach (ops_export_ids() as $id) {
            $product = wc_get_product($id);
            if ($product instanceof WC_Product) {
                $gallery_count = max($gallery_count, count($product->get_gallery_image_ids()));
            }
        }
        $gallery_count = min(8, $gallery_count);
    }
    $layout = ops_export_layout($keys, $include_preview, $gallery_count);
    $header = [];
    $widths = [];
    foreach ($layout as $item) {
        $header[] = $item['header'];
        $widths[] = $item['width'];
    }
    $category_terms = ops_term_index(ops_load_terms('product_cat'));
    $rows = [$header];
    $image_files = [];
    $excel_row = 1;
    foreach (ops_export_ids() as $id) {
        $product = wc_get_product($id);
        if (!$product instanceof WC_Product || !in_array($product->get_type(), ['simple', 'variable'], true)) {
            continue;
        }
        $excel_row++;
        $cells = [];
        $column_number = 0;
        $gallery_plain = '';
        foreach ($layout as $item) {
            $column_number++;
            if ($item['type'] === 'preview') {
                continue;
            }
            if ($item['type'] === 'gallery_url') {
                $urls = ops_http_urls($gallery_plain);
                $cells[$column_number] = $urls[(int) $item['index']] ?? '';
                continue;
            }
            $cell = ops_export_cell($product, $item['key'], $category_terms['by_id'], $pack_images, $image_files);
            $plain = is_array($cell) ? ops_cell_plain($cell) : (string) $cell;
            if ($item['key'] === 'gallery') {
                $gallery_plain = $plain;
            }
            $cells[$column_number] = $cell;
        }
        $line = [];
        $column_number = 0;
        foreach ($layout as $item) {
            $column_number++;
            if ($item['type'] !== 'preview') {
                $line[] = $cells[$column_number] ?? '';
                continue;
            }
            $source = $cells[(int) $item['source']] ?? '';
            $plain = trim(is_array($source) ? ops_cell_plain($source) : (string) $source);
            if (preg_match('#^https://\S+$#i', $plain)) {
                $line[] = ['formula' => 'IMAGE(' . ops_xlsx_col((int) $item['source']) . $excel_row . ')'];
            } else {
                $line[] = '';
            }
        }
        $rows[] = $line;
    }
    $dir = ops_work_dir();
    $stamp = gmdate('Ymd-His');
    if ($format === 'csv') {
        $plain = [];
        foreach ($rows as $row) {
            $plain[] = array_map(static function ($cell): string {
                return is_array($cell) ? ops_cell_plain($cell) : (string) $cell;
            }, $row);
        }
        $path = $dir . '/odella-products-' . $stamp . '.csv';
        ops_csv_write($path, $plain);
        return ['path' => $path, 'filename' => 'odella-products-' . $stamp . '.csv'];
    }
    $category_labels = ops_category_reference($category_terms);
    $tag_labels = ops_tag_reference();
    $validations = ops_export_validations($layout, count($category_labels), count($tag_labels));
    $hidden_columns = [];
    foreach ($layout as $index => $item) {
        if ($item['type'] === 'gallery_url') {
            $hidden_columns[] = $index + 1;
        }
    }
    $sheets = [
        [
            'name' => 'Products',
            'rows' => $rows,
            'validations' => $validations,
            'table' => true,
            'freeze_columns' => 3,
            'widths' => $widths,
            'hidden_columns' => $hidden_columns,
            'data_row_height' => $include_preview ? 72 : 0,
        ],
        [
            'name' => 'Categories',
            'rows' => ops_reference_rows('Categories', $category_labels),
            'table' => true,
        ],
        [
            'name' => 'Tags',
            'rows' => ops_reference_rows('Tags', $tag_labels),
            'table' => true,
        ],
        [
            'name' => 'Attributes',
            'rows' => $attribute_rows = (function_exists('ops_attribute_sheet_rows') ? ops_attribute_sheet_rows() : ops_reference_rows('Attributes', [])),
            'table' => true,
        ],
        [
            'name' => 'Variations',
            'rows' => $variation_rows = (function_exists('ops_variation_sheet_rows') ? ops_variation_sheet_rows() : [['Parent ID']]),
            'validations' => function_exists('ops_variation_validations') ? ops_variation_validations($variation_rows, $attribute_rows) : [],
            'table' => true,
        ],
        [
            'name' => 'Lists',
            'rows' => [
                ['publish', 'simple'],
                ['draft', 'variable'],
                ['', 'grouped'],
                ['', 'external'],
            ],
        ],
        [
            'name' => 'FileBird',
            'rows' => ops_reference_rows('FileBird', function_exists('ops_filebird_folders') ? ops_filebird_folders() : []),
            'table' => true,
        ],
    ];
    $xlsx = $dir . '/odella-products-' . $stamp . '.xlsx';
    ops_xlsx_write($xlsx, $sheets);
    if (!$pack_images) {
        return ['path' => $xlsx, 'filename' => 'odella-products-' . $stamp . '.xlsx'];
    }
    $zip_path = $dir . '/odella-products-' . $stamp . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('The picture zip could not be created.');
    }
    $zip->addFile($xlsx, 'odella-products-' . $stamp . '.xlsx');
    foreach ($image_files as $name => $file) {
        $zip->addFile($file, 'images/' . $name);
    }
    $zip->close();
    return ['path' => $zip_path, 'filename' => 'odella-products-' . $stamp . '.zip'];
}

/**
 * @return array<int, int>
 */
function ops_export_ids(): array
{
    $query = new WP_Query([
        'post_type' => 'product',
        'post_status' => ['publish', 'draft'],
        'posts_per_page' => -1,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'ASC',
        'lang' => 'ka',
        'no_found_rows' => true,
    ]);
    $ids = [];
    foreach ($query->posts as $id) {
        $id = (int) $id;
        $language = ops_product_language($id);
        if ($language !== '' && $language !== 'ka') {
            continue;
        }
        $ids[] = $id;
    }
    return $ids;
}

/**
 * @param array<int, array{id: int, parent: int, name: string}> $by_id
 * @param array<string, string> $image_files
 * @return string|array{runs: array<int, array{text: string, bold: bool, italic: bool, underline: bool}>}
 */
function ops_export_cell(WC_Product $product, string $key, array $by_id, bool $pack_images, array &$image_files)
{
    switch ($key) {
        case 'id':
            return (string) $product->get_id();
        case 'sku':
            return (string) $product->get_sku();
        case 'name':
            return $product->get_name();
        case 'name_en':
            $en = ops_export_en_product($product->get_id());
            return $en ? $en->get_name() : '';
        case 'status':
            return ops_status_label($product->get_status());
        case 'regular_price':
            return (string) $product->get_regular_price();
        case 'sale_price':
            return (string) $product->get_sale_price();
        case 'categories':
            return ops_category_export_label($product->get_category_ids(), $by_id);
        case 'categories_en':
            return ops_export_en_categories_label($product->get_category_ids(), $by_id);
        case 'tags':
            $names = [];
            foreach ($product->get_tag_ids() as $tag_id) {
                $term = get_term((int) $tag_id, 'product_tag');
                if ($term instanceof WP_Term) {
                    $names[] = $term->name;
                }
            }
            return implode('; ', $names);
        case 'featured':
            return ops_export_attachment_url((int) $product->get_image_id(), $pack_images, $image_files);
        case 'hover':
            return ops_export_attachment_url((int) get_post_meta($product->get_id(), 'hover_image', true), $pack_images, $image_files);
        case 'product_type':
            return $product->get_type();
        case 'attributes':
            return function_exists('ops_attribute_export') ? ops_attribute_export($product->get_id()) : '';
        case 'gallery':
            $urls = [];
            foreach ($product->get_gallery_image_ids() as $image_id) {
                $url = ops_export_attachment_url((int) $image_id, $pack_images, $image_files);
                if ($url !== '') {
                    $urls[] = $url;
                }
            }
            return implode('; ', $urls);
        case 'short_description':
            return ops_export_html((string) $product->get_short_description());
        case 'short_description_en':
            $en = ops_export_en_product($product->get_id());
            return $en ? ops_export_html((string) $en->get_short_description()) : '';
        case 'description':
            return ops_export_html((string) $product->get_description());
        case 'description_en':
            $en = ops_export_en_product($product->get_id());
            return $en ? ops_export_html((string) $en->get_description()) : '';
        case 'eur_regular':
        case 'eur_sale':
        case 'usd_regular':
        case 'usd_sale':
            return ops_export_zone_price($product->get_id(), $key);
    }
    $field = function_exists('ops_meta_field') ? ops_meta_field($key) : null;
    if (!$field) {
        return '';
    }
    if ($field['kind'] === 'image' || $field['kind'] === 'gallery') {
        return ops_export_meta_pictures($product->get_id(), $field, $pack_images, $image_files);
    }
    return ops_meta_plain(get_post_meta($product->get_id(), $field['meta_key'], true));
}

/**
 * @param array{meta_key: string, kind: string} $field
 * @param array<string, string> $image_files
 */
function ops_export_meta_pictures(int $id, array $field, bool $pack_images, array &$image_files): string
{
    $raw = get_post_meta($id, $field['meta_key'], true);
    $ids = [];
    if (is_array($raw)) {
        foreach ($raw as $attachment_id) {
            $ids[] = (int) $attachment_id;
        }
    } elseif ($raw !== '' && $raw !== null) {
        $ids[] = (int) $raw;
    }
    $urls = [];
    foreach ($ids as $attachment_id) {
        $url = ops_export_attachment_url($attachment_id, $pack_images, $image_files);
        if ($url !== '') {
            $urls[] = $url;
        }
    }
    return implode('; ', $urls);
}

/**
 * @param array<int, string> $keys
 * @return array<int, array{type: string, key: string, header: string, width: float, source: int}>
 */
function ops_export_layout(array $keys, bool $include_preview, int $gallery_count = 0): array
{
    $layout = [];
    $column = 1;
    $gallery_preview_at = null;
    foreach ($keys as $key) {
        $header = ops_column_header($key);
        $layout[] = [
            'type' => 'value',
            'key' => $key,
            'header' => $header,
            'width' => ops_export_width($key),
            'source' => 0,
            'index' => 0,
        ];
        $source = $column;
        $column++;
        if ($include_preview && ops_column_is_image($key)) {
            $layout[] = [
                'type' => 'preview',
                'key' => $key,
                'header' => $header . ' preview',
                'width' => 16,
                'source' => $source,
                'index' => 0,
            ];
            if ($key === 'gallery') {
                $gallery_preview_at = count($layout) - 1;
            }
            $column++;
        }
    }
    if ($gallery_count > 0 && $gallery_preview_at !== null) {
        $url_columns = [];
        for ($i = 0; $i < $gallery_count; $i++) {
            $url_columns[] = $column;
            $layout[] = [
                'type' => 'gallery_url',
                'key' => 'gallery',
                'header' => 'Gallery url ' . ($i + 1),
                'width' => 18,
                'source' => 0,
                'index' => $i,
            ];
            $column++;
        }
        $layout[$gallery_preview_at]['source'] = $url_columns[0];
        for ($i = 1; $i < $gallery_count; $i++) {
            $layout[] = [
                'type' => 'preview',
                'key' => 'gallery',
                'header' => 'Gallery preview ' . ($i + 1),
                'width' => 16,
                'source' => $url_columns[$i],
                'index' => $i,
            ];
        }
    }
    return $layout;
}

function ops_export_width(string $key): float
{
    if ($key === 'id') {
        return 12;
    }
    if ($key === 'sku') {
        return 18;
    }
    if ($key === 'name') {
        return 36;
    }
    if (ops_column_is_image($key) || $key === 'description' || $key === 'short_description') {
        return 42;
    }
    return 22;
}

/**
 * @param array<int, array{type: string, key: string}> $layout
 * @return array<int, array{sqref: string, formula: string, strict: bool}>
 */
function ops_export_validations(array $layout, int $category_count, int $tag_count): array
{
    $rules = [];
    foreach ($layout as $index => $item) {
        if ($item['type'] !== 'value') {
            continue;
        }
        $letter = ops_xlsx_col($index + 1);
        $sqref = $letter . '2:' . $letter . '5000';
        if ($item['key'] === 'status') {
            $rules[] = ['sqref' => $sqref, 'formula' => 'Lists!$A$1:$A$2', 'strict' => true];
        } elseif ($item['key'] === 'product_type') {
            $rules[] = ['sqref' => $sqref, 'formula' => 'Lists!$B$1:$B$4', 'strict' => true];
        }
    }
    return $rules;
}

function ops_http_urls(string $text): array
{
    preg_match_all('#https://[^\s;]+#i', $text, $matches);
    return $matches[0] ?? [];
}

/**
 * @param array<string, string> $image_files
 */
function ops_export_attachment_url(int $attachment_id, bool $pack_images, array &$image_files): string
{
    if ($attachment_id <= 0) {
        return '';
    }
    $url = wp_get_attachment_url($attachment_id);
    if (!is_string($url)) {
        return '';
    }
    if ($pack_images) {
        $file = get_attached_file($attachment_id);
        if (is_string($file) && is_file($file)) {
            $name = $attachment_id . '-' . basename($file);
            $image_files[$name] = $file;
        }
    }
    return $url;
}

/**
 * @return string|array{runs: array<int, array{text: string, bold: bool, italic: bool, underline: bool}>}
 */
function ops_export_html(string $html)
{
    if (trim($html) === '') {
        return '';
    }
    if (ops_is_complex_html($html)) {
        return $html;
    }
    $runs = ops_html_to_runs($html);
    $plain = '';
    foreach ($runs as $run) {
        $plain .= $run['text'];
    }
    if ($plain === '') {
        return '';
    }
    return ['runs' => $runs, 'text' => $plain];
}

function ops_export_zone_price(int $id, string $key): string
{
    $map = [
        'eur_regular' => ['_eurozone_regular_price', '_eurozone_price_method'],
        'eur_sale' => ['_eurozone_sale_price', '_eurozone_price_method'],
        'usd_regular' => ['_global_regular_price', '_global_price_method'],
        'usd_sale' => ['_global_sale_price', '_global_price_method'],
    ];
    [$meta, $method_key] = $map[$key];
    if ((string) get_post_meta($id, $method_key, true) === 'exchange_rate' && !str_contains($key, 'sale')) {
        return '';
    }
    if ((string) get_post_meta($id, $method_key, true) !== 'manual') {
        return '';
    }
    return (string) get_post_meta($id, $meta, true);
}

/**
 * @param array{by_id: array<int, array{id: int, parent: int, name: string}>} $index
 * @return array<int, string>
 */
function ops_category_reference(array $index): array
{
    $labels = [];
    foreach ($index['by_id'] as $term) {
        $labels[] = ops_category_export_label([$term['id']], $index['by_id']);
    }
    $labels = array_values(array_unique($labels));
    sort($labels, SORT_STRING);
    return $labels;
}

/**
 * @return array<int, string>
 */
function ops_tag_reference(): array
{
    $names = [];
    foreach (ops_load_terms('product_tag') as $tag) {
        $names[] = $tag['name'];
    }
    sort($names, SORT_STRING);
    return $names;
}

/**
 * @param array<int, string> $values
 * @return array<int, array<int, string>>
 */
function ops_reference_rows(string $title, array $values): array
{
    $rows = [[$title]];
    foreach ($values as $value) {
        $rows[] = [$value];
    }
    return $rows;
}

function ops_work_dir(): string
{
    $uploads = wp_upload_dir();
    $dir = $uploads['basedir'] . '/odella-product-sheet';
    if (!wp_mkdir_p($dir)) {
        throw new RuntimeException('The upload folder could not be created.');
    }
    $guard = $dir . '/index.php';
    if (!is_file($guard)) {
        file_put_contents($guard, "<?php\n// Silence is golden.\n");
    }
    return $dir;
}

/**
 * @param array<int, string> $headers
 * @param array<int, array<string, string>> $raw_rows
 * @param array<int, int> $created sheet row => new ID
 * @return array{path: string, filename: string}
 */
function ops_corrected_file(array $headers, array $labels, array $raw_rows, array $created, string $format): array
{
    $header = [];
    foreach ($headers as $position => $key) {
        if ($key === '') {
            $header[] = $labels[$position] ?? '';
            continue;
        }
        $header[] = ops_column_header($key);
    }
    $rows = [$header];
    foreach ($raw_rows as $raw) {
        $line = [];
        foreach ($headers as $key) {
            if ($key === '') {
                $line[] = '';
                continue;
            }
            $line[] = (string) ($raw['raw'][$key] ?? '');
        }
        $sheet_row = (int) ($raw['sheet_row'] ?? 0);
        if (isset($created[$sheet_row])) {
            $id_at = array_search('id', $headers, true);
            if ($id_at !== false) {
                $line[$id_at] = (string) $created[$sheet_row];
            }
        }
        $rows[] = $line;
    }
    $dir = ops_work_dir();
    $stamp = gmdate('Ymd-His');
    if ($format === 'csv') {
        $path = $dir . '/odella-corrected-' . $stamp . '.csv';
        ops_csv_write($path, $rows);
        return ['path' => $path, 'filename' => 'odella-corrected-' . $stamp . '.csv'];
    }
    $path = $dir . '/odella-corrected-' . $stamp . '.xlsx';
    ops_xlsx_write($path, [['name' => 'Products', 'rows' => $rows, 'table' => true]]);
    return ['path' => $path, 'filename' => 'odella-corrected-' . $stamp . '.xlsx'];
}

function ops_export_en_product(int $ka_id): ?WC_Product
{
    if ($ka_id <= 0 || !function_exists('pll_get_post')) {
        return null;
    }
    $en_id = (int) pll_get_post($ka_id, 'en');
    if ($en_id <= 0) {
        return null;
    }
    $p = wc_get_product($en_id);
    return $p instanceof WC_Product ? $p : null;
}

/**
 * @param array<int, int> $ka_cat_ids
 * @param array<int, array{id: int, parent: int, name: string}> $by_id
 */
function ops_export_en_categories_label(array $ka_cat_ids, array $by_id): string
{
    if (!$ka_cat_ids || !function_exists('pll_get_term')) {
        return '';
    }
    $en_ids = [];
    foreach ($ka_cat_ids as $tid) {
        $en_tid = (int) pll_get_term((int) $tid, 'en');
        if ($en_tid > 0) {
            $en_ids[] = $en_tid;
        }
    }
    if (!$en_ids) {
        return '';
    }
    return ops_category_export_label(array_values(array_unique($en_ids)), $by_id);
}
