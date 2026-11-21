<?php
/**
 * Product meta that is not already a column of its own.
 * Hover Image is an image. Everything else is text and stays off until chosen.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array<string, array{column: string, meta_key: string, label: string, kind: string, acf_key: string}>
 */
function ops_meta_fields(): array
{
    static $fields = null;
    if ($fields !== null) {
        return $fields;
    }
    $fields = [];
    if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
        return $fields;
    }
    global $wpdb;
    $keys = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s ORDER BY pm.meta_key ASC",
        'product'
    ));
    if (!is_array($keys)) {
        return $fields;
    }
    $acf = ops_meta_acf_index();
    $skip = array_fill_keys(ops_meta_skip_keys(), true);
    $labels = [];
    foreach ($keys as $meta_key) {
        $meta_key = (string) $meta_key;
        if ($meta_key === '' || isset($skip[$meta_key])) {
            continue;
        }
        if (str_starts_with($meta_key, '_') && isset($acf[substr($meta_key, 1)])) {
            continue;
        }
        $info = $acf[$meta_key] ?? ['label' => '', 'kind' => 'text', 'acf_key' => ''];
        if ($meta_key === 'hover_image') {
            $info['kind'] = 'image';
        }
        $label = ops_meta_label($meta_key, (string) $info['label']);
        $label_key = mb_strtolower($label, 'UTF-8');
        if (isset($labels[$label_key]) || ops_builtin_header_taken($label)) {
            $label .= ' (' . $meta_key . ')';
        }
        $labels[mb_strtolower($label, 'UTF-8')] = true;
        $fields[$meta_key] = [
            'column' => 'meta:' . $meta_key,
            'meta_key' => $meta_key,
            'label' => $label,
            'kind' => $info['kind'] === 'gallery' || $info['kind'] === 'image' ? $info['kind'] : 'text',
            'acf_key' => (string) $info['acf_key'],
        ];
    }
    uasort($fields, static function (array $left, array $right): int {
        if (($left['kind'] === 'image' || $left['kind'] === 'gallery') !== ($right['kind'] === 'image' || $right['kind'] === 'gallery')) {
            return ($left['kind'] === 'image' || $left['kind'] === 'gallery') ? -1 : 1;
        }
        return strnatcasecmp($left['label'], $right['label']);
    });
    return $fields;
}

/**
 * @return array{column: string, meta_key: string, label: string, kind: string, acf_key: string}|null
 */
function ops_meta_field(string $column): ?array
{
    $meta_key = str_starts_with($column, 'meta:') ? substr($column, 5) : $column;
    $fields = ops_meta_fields();
    return $fields[$meta_key] ?? null;
}

/**
 * @return array<int, string>
 */
function ops_meta_skip_keys(): array
{
    return [
        '_sku',
        '_regular_price',
        '_sale_price',
        '_price',
        '_thumbnail_id',
        '_product_image_gallery',
        '_eurozone_regular_price',
        '_eurozone_sale_price',
        '_eurozone_price_method',
        '_global_regular_price',
        '_global_sale_price',
        '_global_price_method',
        '_edit_lock',
        '_edit_last',
        '_elementor_global_class_usage_indexed',
        '_elementor_global_class_usage_indexed_preview',
        '_elementor_page_assets',
        '_product_version',
        '_children',
        '_product_attributes',
        'hover_image',
    ];
}

function ops_meta_label(string $meta_key, string $acf_label): string
{
    if ($acf_label !== '') {
        return $acf_label;
    }
    $names = [
        '_backorders' => 'Backorders',
        '_download_expiry' => 'Download expiry',
        '_download_limit' => 'Download limit',
        '_downloadable' => 'Downloadable',
        '_eurozone_price' => 'EUR calculated price',
        '_eurozone_sale_price_dates' => 'EUR sale dates',
        '_eurozone_sale_price_dates_from' => 'EUR sale starts',
        '_eurozone_sale_price_dates_to' => 'EUR sale ends',
        '_georgia_price' => 'GEL calculated price',
        '_georgia_price_method' => 'GEL price method',
        '_georgia_regular_price' => 'GEL zone regular price',
        '_georgia_sale_price' => 'GEL zone sale price',
        '_georgia_sale_price_dates' => 'GEL sale dates',
        '_georgia_sale_price_dates_from' => 'GEL sale starts',
        '_georgia_sale_price_dates_to' => 'GEL sale ends',
        '_global_price' => 'USD calculated price',
        '_global_sale_price_dates' => 'USD sale dates',
        '_global_sale_price_dates_from' => 'USD sale starts',
        '_global_sale_price_dates_to' => 'USD sale ends',
        '_manage_stock' => 'Manage stock',
        '_odella_auto_hidden' => 'Hidden because it has no price',
        '_odella_auto_hidden_terms' => 'Hidden from categories',
        '_product_attributes' => 'Attributes',
        '_sold_individually' => 'Sold individually',
        '_stock' => 'Stock',
        '_stock_status' => 'Stock status',
        '_tax_class' => 'Tax class',
        '_tax_status' => 'Tax status',
        '_virtual' => 'Virtual',
        '_wc_average_rating' => 'Average rating',
        '_wc_review_count' => 'Review count',
        'rank_math_contentai_score' => 'Content AI score',
        'rank_math_internal_links_processed' => 'Internal links processed',
        'rank_math_robots' => 'Robots',
        'rank_math_seo_score' => 'SEO score',
        'total_sales' => 'Total sales',
        'wpse_downloadable_file_names' => 'Download file names',
        'wpse_downloadable_file_urls' => 'Download file URLs',
    ];
    return $names[$meta_key] ?? $meta_key;
}

function ops_builtin_header_taken(string $label): bool
{
    $label = mb_strtolower(trim($label), 'UTF-8');
    foreach (ops_columns() as $column) {
        if ($label === mb_strtolower($column['label'], 'UTF-8')) {
            return true;
        }
    }
    return false;
}

/**
 * @return array<string, array{label: string, kind: string, acf_key: string}>
 */
function ops_meta_acf_index(): array
{
    $index = [];
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
        return $index;
    }
    $groups = acf_get_field_groups(['post_type' => 'product']);
    if (!is_array($groups)) {
        return $index;
    }
    foreach ($groups as $group) {
        $fields = acf_get_fields((string) ($group['key'] ?? ''));
        if (!is_array($fields)) {
            continue;
        }
        foreach ($fields as $field) {
            if (!is_array($field) || empty($field['name'])) {
                continue;
            }
            $type = (string) ($field['type'] ?? 'text');
            $kind = 'text';
            if ($type === 'gallery') {
                $kind = 'gallery';
            } elseif ($type === 'image') {
                $kind = 'image';
            }
            $index[(string) $field['name']] = [
                'label' => (string) ($field['label'] ?? $field['name']),
                'kind' => $kind,
                'acf_key' => (string) ($field['key'] ?? ''),
            ];
        }
    }
    return $index;
}

/**
 * @param mixed $value
 */
function ops_meta_plain($value): string
{
    if (is_array($value)) {
        return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }
    if (is_bool($value)) {
        return $value ? 'yes' : 'no';
    }
    if ($value === null) {
        return '';
    }
    return (string) $value;
}

function ops_keep_acf_reference(int $post_id, string $meta_key, string $acf_key): void
{
    if ($acf_key === '') {
        return;
    }
    $ref = '_' . $meta_key;
    if ((string) get_post_meta($post_id, $ref, true) !== $acf_key) {
        update_post_meta($post_id, $ref, $acf_key);
    }
}
