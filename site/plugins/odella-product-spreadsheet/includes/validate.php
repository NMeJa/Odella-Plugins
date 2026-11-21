<?php
/**
 * Read a grid, then list every problem before any product is saved.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param array<int, array<int, array{text: string, formula: string, runs: array<int, array{text: string, bold: bool, italic: bool, underline: bool}>}>> $grid
 * @param array<string, string> $zip_files lower-case file name => path
 * @return array{errors: array<int, array{row: int, column: string, message: string}>, warnings: array<int, string>, plans: array<int, array<string, mixed>>, headers: array<int, string>, labels: array<int, string>}
 */
function ops_preflight(array $grid, array $zip_files): array
{
    $errors = [];
    $warnings = [];
    $plans = [];
    $header_index = null;
    foreach ($grid as $index => $row) {
        foreach ($row as $cell) {
            if (trim($cell['text'] ?? '') !== '') {
                $header_index = $index;
                break 2;
            }
        }
    }
    if ($header_index === null) {
        return ops_preflight_result([['row' => 1, 'column' => '', 'message' => 'The file has no header row.']], [], [], [], []);
    }
    $headers = [];
    $labels = [];
    foreach ($grid[$header_index] as $cell) {
        $label = trim($cell['text'] ?? '');
        $key = ops_header_key($label);
        $headers[] = $key;
        $labels[] = $label;
    }
    foreach (['id', 'sku'] as $required) {
        if (!in_array($required, $headers, true)) {
            $column = ops_columns()[$required];
            $errors[] = ['row' => $header_index + 1, 'column' => $column['label'], 'message' => 'The ' . $column['label'] . ' column is missing.'];
        }
    }
    if ($errors) {
        return ops_preflight_result($errors, $warnings, [], $headers, $labels);
    }

    $categories = function_exists('get_terms') ? ops_load_terms('product_cat') : [];
    $tags = function_exists('get_terms') ? ops_load_terms('product_tag') : [];
    $category_index = ops_term_index($categories);
    $sku_owner = function_exists('ops_sku_owners') ? ops_sku_owners() : [];
    $seen_ids = [];
    $seen_skus = [];
    $used_files = [];

    for ($r = $header_index + 1, $count = count($grid); $r < $count; $r++) {
        $row_number = $r + 1;
        $raw = [];
        $runs = [];
        $row = $grid[$r] ?? [];
        $any = false;
        foreach ($headers as $position => $key) {
            if ($key === '') {
                continue;
            }
            $cell = $row[$position] ?? ['text' => '', 'formula' => '', 'runs' => [ops_plain_run('')]];
            $text = (string) ($cell['text'] ?? '');
            if (trim($text) !== '' || (($cell['formula'] ?? '') !== '' && !ops_is_preview_formula($cell['formula']))) {
                $any = true;
            }
            if (($cell['formula'] ?? '') !== '' && trim($text) === '' && !ops_is_preview_formula($cell['formula'])) {
                $errors[] = ['row' => $row_number, 'column' => ops_column_label($key), 'message' => 'This cell is a formula. Type the value instead.'];
            }
            $raw[$key] = $text;
            $runs[$key] = $cell['runs'] ?? [ops_plain_run($text)];
        }
        if (!$any) {
            continue;
        }

        $plan = [
            'sheet_row' => $row_number,
            'id' => 0,
            'raw' => $raw,
            'values' => [],
            'category_ids' => null,
            'tag_ids' => null,
            'featured' => null,
            'hover' => null,
            'gallery' => null,
            'meta' => [],
            'attributes' => null,
        ];
        $id_raw = trim($raw['id'] ?? '');
        if (preg_match('/^(\d+)\.0+$/', $id_raw, $id_match)) {
            $id_raw = $id_match[1];
        }
        if ($id_raw !== '' && !preg_match('/^\d+$/', $id_raw)) {
            $errors[] = ops_issue($row_number, 'id', 'The ID must be empty for a new product, or the number from a fresh export.');
        }
        $id = $id_raw === '' ? 0 : (int) $id_raw;
        $plan['id'] = $id;
        if ($id > 0) {
            if (isset($seen_ids[$id])) {
                $errors[] = ops_issue($row_number, 'id', 'This ID is also on row ' . $seen_ids[$id] . '.');
                $errors[] = ops_issue($seen_ids[$id], 'id', 'This ID is also on row ' . $row_number . '.');
            }
            $seen_ids[$id] = $row_number;
        }

        $product = ($id > 0 && function_exists('wc_get_product')) ? wc_get_product($id) : null;
        if ($id > 0 && !$product) {
            $errors[] = ops_issue($row_number, 'id', 'Product ' . $id . ' does not exist. Stop using this file and export a new one.');
        } elseif ($product && !in_array($product->get_type(), ['simple', 'variable', 'grouped', 'external'], true)) {
            $errors[] = ops_issue($row_number, 'id', 'Product ' . $id . ' uses a product type this file cannot edit.');
        } elseif ($product) {
            $language = ops_product_language($id);
            if ($language !== '' && $language !== 'ka') {
                $errors[] = ops_issue($row_number, 'id', 'Product ' . $id . ' is not a Georgian product.');
            }
        }

        $sku = trim($raw['sku'] ?? '');
        if ($sku !== '') {
            $plan['values']['sku'] = $sku;
        }
        if ($id === 0 && $sku === '') {
            $errors[] = ops_issue($row_number, 'sku', 'A new product needs a SKU.');
        }
        if ($sku !== '') {
            $same_as_existing = $product instanceof WC_Product && (string) $product->get_sku() === $sku;
            $owner = $sku_owner[$sku] ?? 0;
            if ($owner && $owner !== $id && !$same_as_existing) {
                $errors[] = ops_issue($row_number, 'sku', 'SKU ' . $sku . ' already belongs to product ' . $owner . '. Put that ID in the ID column, or export a fresh file.');
            }
            $sku_key = mb_strtolower($sku, 'UTF-8');
            if (isset($seen_skus[$sku_key])) {
                if ($same_as_existing) {
                    $warnings[] = 'Row ' . $row_number . ': existing product ' . $id . ' already shares SKU ' . $sku . ' with row ' . $seen_skus[$sku_key] . ' on the shop (kept unchanged).';
                } else {
                    $errors[] = ops_issue($row_number, 'sku', 'SKU ' . $sku . ' is also on row ' . $seen_skus[$sku_key] . '.');
                }
            } else {
                $seen_skus[$sku_key] = $row_number;
            }
        }

        if (array_key_exists('name', $raw)) {
            $name = trim($raw['name']);
            if (mb_strtolower($name, 'UTF-8') === '(clear)' || ($id === 0 && $name === '')) {
                $errors[] = ops_issue($row_number, 'name', 'A product needs a name.');
            } elseif ($name !== '') {
                $plan['values']['name'] = $name;
            }
        } elseif ($id === 0) {
            $errors[] = ops_issue($row_number, 'name', 'A new product needs a name column.');
        }

        $status = $product ? $product->get_status() : 'draft';
        if (array_key_exists('status', $raw)) {
            $parsed = ops_parse_status($raw['status']);
            if ($parsed === null) {
                $errors[] = ops_issue($row_number, 'status', 'Status must be publish or draft.');
            } elseif ($parsed !== '') {
                $status = $parsed;
                $plan['values']['status'] = $parsed;
            }
        }
        $plan['status'] = $status;

        if (array_key_exists('product_type', $raw)) {
            $type = mb_strtolower(trim($raw['product_type']), 'UTF-8');
            if ($type === '') {
                // Leave the current type.
            } elseif (!in_array($type, ['simple', 'variable', 'grouped', 'external'], true)) {
                $errors[] = ops_issue($row_number, 'product_type', 'Product type must be simple, variable, grouped, or external.');
            } else {
                $plan['values']['product_type'] = $type;
            }
        }
        if (array_key_exists('attributes', $raw) && function_exists('ops_parse_attribute_list')) {
            $parsed = ops_parse_attribute_list($raw['attributes'], ops_attribute_catalog());
            foreach ($parsed['errors'] as $message) {
                $errors[] = ops_issue($row_number, 'attributes', $message);
            }
            if (!$parsed['errors'] && !$parsed['empty']) {
                $plan['attributes'] = $parsed;
            }
        }

        $regular = $product ? (string) $product->get_regular_price() : '';
        if (array_key_exists('regular_price', $raw)) {
            $parsed = ops_parse_price($raw['regular_price']);
            if ($parsed['kind'] === 'invalid') {
                $errors[] = ops_issue($row_number, 'regular_price', $parsed['message']);
            } elseif ($parsed['kind'] === 'clear') {
                $errors[] = ops_issue($row_number, 'regular_price', 'The GEL regular price cannot be cleared. Set Status to draft to take the product off the shop.');
            } elseif ($parsed['kind'] === 'value') {
                $regular = $parsed['value'];
                $plan['values']['regular_price'] = $parsed['value'];
            }
        }
        $sale = $product ? (string) $product->get_sale_price() : '';
        if (array_key_exists('sale_price', $raw)) {
            $parsed = ops_parse_price($raw['sale_price']);
            if ($parsed['kind'] === 'invalid') {
                $errors[] = ops_issue($row_number, 'sale_price', $parsed['message']);
            } elseif ($parsed['kind'] === 'clear') {
                $sale = '';
                $plan['values']['sale_price'] = '';
            } elseif ($parsed['kind'] === 'value') {
                $sale = $parsed['value'];
                $plan['values']['sale_price'] = $parsed['value'];
            }
        }
        $effective_type = (string) ($plan['values']['product_type'] ?? ($product ? $product->get_type() : 'simple'));
        if ($status === 'publish' && $regular === '' && !in_array($effective_type, ['variable', 'grouped'], true)) {
            $errors[] = ops_issue($row_number, 'regular_price', 'A published product needs a GEL regular price. The pricing guard would turn it back into a draft.');
        }
        if ($sale !== '' && ($regular === '' || (float) $sale >= (float) $regular)) {
            $errors[] = ops_issue($row_number, 'sale_price', 'The sale price has to be lower than the GEL regular price.');
        }

        foreach (['eur' => 'eurozone', 'usd' => 'global'] as $prefix => $zone) {
            $regular_key = $prefix . '_regular';
            $sale_key = $prefix . '_sale';
            $zone_regular = $product ? (string) get_post_meta($id, '_' . $zone . '_regular_price', true) : '';
            $zone_sale = $product ? (string) get_post_meta($id, '_' . $zone . '_sale_price', true) : '';
            if (array_key_exists($regular_key, $raw)) {
                $parsed = ops_parse_price($raw[$regular_key]);
                if ($parsed['kind'] === 'invalid') {
                    $errors[] = ops_issue($row_number, $regular_key, $parsed['message']);
                } elseif ($parsed['kind'] === 'clear') {
                    $zone_regular = '';
                    $plan['values'][$regular_key] = '';
                } elseif ($parsed['kind'] === 'value') {
                    $zone_regular = $parsed['value'];
                    $plan['values'][$regular_key] = $parsed['value'];
                }
            }
            if (array_key_exists($sale_key, $raw)) {
                $parsed = ops_parse_price($raw[$sale_key]);
                if ($parsed['kind'] === 'invalid') {
                    $errors[] = ops_issue($row_number, $sale_key, $parsed['message']);
                } elseif ($parsed['kind'] === 'clear') {
                    $zone_sale = '';
                    $plan['values'][$sale_key] = '';
                } elseif ($parsed['kind'] === 'value') {
                    $zone_sale = $parsed['value'];
                    $plan['values'][$sale_key] = $parsed['value'];
                }
            }
            if ($zone_sale !== '' && $zone_regular === '') {
                $errors[] = ops_issue($row_number, $sale_key, 'Set a manual ' . strtoupper($prefix) . ' regular price before a sale price. Leave both blank to keep the bank rate.');
            }
            if ($zone_sale !== '' && $zone_regular !== '' && (float) $zone_sale >= (float) $zone_regular) {
                $errors[] = ops_issue($row_number, $sale_key, 'The ' . strtoupper($prefix) . ' sale price has to be lower than the ' . strtoupper($prefix) . ' regular price.');
            }
        }

        if (array_key_exists('categories', $raw)) {
            $plan['category_ids'] = ops_term_plan($raw['categories'], $category_index, 'categories', $row_number, $errors);
        }
        if (array_key_exists('tags', $raw)) {
            $matched = ops_tag_plan($raw['tags'], $tags, $row_number, $errors);
            $plan['tag_ids'] = $matched;
        }
        if (array_key_exists('name_en', $raw)) {
            $name_en = trim($raw['name_en']);
            if ($name_en !== '') {
                $plan['values']['name_en'] = mb_strtolower($name_en, 'UTF-8') === '(clear)' ? '' : $name_en;
            }
        }
        foreach (['short_description', 'description', 'short_description_en', 'description_en'] as $text_key) {
            if (!array_key_exists($text_key, $raw)) {
                continue;
            }
            $text = trim($raw[$text_key]);
            if ($text === '') {
                continue;
            }
            if (mb_strtolower($text, 'UTF-8') === '(clear)') {
                $plan['values'][$text_key] = '';
                continue;
            }
            $html = ops_cell_to_html($runs[$text_key] ?? [ops_plain_run($raw[$text_key])]);
            foreach ($html['errors'] as $message) {
                $errors[] = ops_issue($row_number, $text_key, $message);
            }
            if (!$html['errors']) {
                $plan['values'][$text_key] = $html['html'];
            }
        }
        if (array_key_exists('featured', $raw)) {
            $plan['featured'] = ops_picture_plan($raw['featured'], false, $zip_files, $row_number, 'featured', $errors, $used_files);
        }
        if (array_key_exists('hover', $raw)) {
            $plan['hover'] = ops_picture_plan($raw['hover'], false, $zip_files, $row_number, 'hover', $errors, $used_files);
        }
        if (array_key_exists('gallery', $raw)) {
            $plan['gallery'] = ops_picture_plan($raw['gallery'], true, $zip_files, $row_number, 'gallery', $errors, $used_files);
        }
        foreach ($raw as $key => $text) {
            if (!is_string($key) || !str_starts_with($key, 'meta:') || !function_exists('ops_meta_field')) {
                continue;
            }
            $field = ops_meta_field($key);
            if (!$field) {
                $errors[] = ops_issue($row_number, $key, 'This column is not a product field on the shop.');
                continue;
            }
            if ($field['kind'] === 'image' || $field['kind'] === 'gallery') {
                $picture = ops_picture_plan((string) $text, $field['kind'] === 'gallery', $zip_files, $row_number, $key, $errors, $used_files);
                if ($picture !== null) {
                    $plan['meta'][] = ['field' => $field, 'image' => $picture];
                }
                continue;
            }
            $trim = trim((string) $text);
            if ($trim === '') {
                continue;
            }
            if (mb_strtolower($trim, 'UTF-8') === '(clear)') {
                $plan['meta'][] = ['field' => $field, 'clear' => true];
                continue;
            }
            if ($product && is_array(get_post_meta($id, $field['meta_key'], true))) {
                $decoded = json_decode($trim, true);
                if (!is_array($decoded)) {
                    $errors[] = ops_issue($row_number, $key, 'This field is stored as structured data. Keep the JSON, or use (clear).');
                    continue;
                }
            }
            $plan['meta'][] = ['field' => $field, 'value' => $trim];
        }
        $plans[] = $plan;
    }

    foreach ($zip_files as $name => $path) {
        if (!isset($used_files[$name])) {
            $warnings[] = basename($path) . ' is in the zip and no cell uses it.';
        }
    }
    if (!$plans && !$errors) {
        $errors[] = ['row' => $header_index + 1, 'column' => '', 'message' => 'The file has no product rows.'];
    }
    return ops_preflight_result($errors, $warnings, $plans, $headers, $labels);
}

/**
 * @param array<int, array{row: int, column: string, message: string}> $errors
 * @param array<int, string> $warnings
 * @param array<int, array<string, mixed>> $plans
 * @param array<int, string> $headers
 * @param array<int, string> $labels
 * @return array{errors: array<int, array{row: int, column: string, message: string}>, warnings: array<int, string>, plans: array<int, array<string, mixed>>, headers: array<int, string>, labels: array<int, string>}
 */
function ops_preflight_result(array $errors, array $warnings, array $plans, array $headers, array $labels): array
{
    return [
        'errors' => $errors,
        'warnings' => $warnings,
        'plans' => $errors ? [] : $plans,
        'headers' => $headers,
        'labels' => $labels,
    ];
}

/**
 * @return array{row: int, column: string, message: string}
 */
function ops_issue(int $row, string $key, string $message): array
{
    return ['row' => $row, 'column' => ops_column_label($key), 'message' => $message];
}

function ops_column_label(string $key): string
{
    return ops_column_header($key);
}

function ops_is_preview_formula(string $formula): bool
{
    return (bool) preg_match('/^=?\s*IMAGE\s*\(/i', $formula);
}

/**
 * @return array<int, array{id: int, parent: int, name: string}>
 */
function ops_load_terms(string $taxonomy): array
{
    $terms = get_terms([
        'taxonomy' => $taxonomy,
        'hide_empty' => false,
    ]);
    if (is_wp_error($terms) || !is_array($terms)) {
        return [];
    }
    $out = [];
    foreach ($terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }
        $out[] = ['id' => (int) $term->term_id, 'parent' => (int) $term->parent, 'name' => $term->name];
    }
    return $out;
}

/**
 * @return array<string, int>
 */
function ops_sku_owners(): array
{
    global $wpdb;
    $rows = $wpdb->get_results("SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_sku' AND pm.meta_value <> '' AND p.post_type = 'product' AND p.post_status IN ('publish','draft','private','pending')");
    $map = [];
    if (!is_array($rows)) {
        return $map;
    }
    foreach ($rows as $row) {
        $pid = (int) $row->post_id;
        $lang = ops_product_language($pid);
        if ($lang !== '' && $lang !== 'ka') {
            continue;
        }
        $map[(string) $row->meta_value] = $pid;
    }
    return $map;
}

function ops_product_language(int $id): string
{
    if (!function_exists('pll_get_post_language')) {
        return 'ka';
    }
    $language = pll_get_post_language($id);
    return is_string($language) ? $language : '';
}

/**
 * @param array{by_id: array<int, array{id: int, parent: int, name: string}>, by_parent: array<int, array<string, array<int, array{id: int, parent: int, name: string}>>>} $index
 * @param array<int, array{row: int, column: string, message: string}> $errors
 * @return array<int, int>|null
 */
function ops_term_plan(string $cell, array $index, string $key, int $row, array &$errors): ?array
{
    $trim = trim($cell);
    if ($trim === '') {
        return null;
    }
    if (mb_strtolower($trim, 'UTF-8') === '(clear)') {
        return [];
    }
    $matched = ops_match_category_paths(ops_category_paths($trim), $index);
    foreach ($matched['errors'] as $message) {
        $errors[] = ops_issue($row, $key, $message);
    }
    return $matched['errors'] ? null : $matched['ids'];
}

/**
 * @param array<int, array{id: int, parent: int, name: string}> $tags
 * @param array<int, array{row: int, column: string, message: string}> $errors
 * @return array<int, int>|null
 */
function ops_tag_plan(string $cell, array $tags, int $row, array &$errors): ?array
{
    $trim = trim($cell);
    if ($trim === '') {
        return null;
    }
    if (mb_strtolower($trim, 'UTF-8') === '(clear)') {
        return [];
    }
    $matched = ops_match_tags($trim, $tags);
    foreach ($matched['errors'] as $message) {
        $errors[] = ops_issue($row, 'tags', $message);
    }
    return $matched['errors'] ? null : $matched['ids'];
}

/**
 * @param array<string, string> $zip_files
 * @param array<int, array{row: int, column: string, message: string}> $errors
 * @param array<string, bool> $used_files
 * @return array<string, mixed>|null
 */
function ops_picture_plan(string $cell, bool $many, array $zip_files, int $row, string $key, array &$errors, array &$used_files): ?array
{
    $trim = trim($cell);
    if ($trim === '') {
        return null;
    }
    $tokens = ops_split_pictures($trim);
    if (count($tokens) === 1 && mb_strtolower($tokens[0], 'UTF-8') === '(clear)') {
        return ['clear' => true, 'items' => []];
    }
    if (!$many && count($tokens) > 1) {
        $errors[] = ops_issue($row, $key, 'This column holds one picture.');
        return null;
    }
    $items = [];
    foreach ($tokens as $token) {
        $parsed = ops_picture_token($token);
        if ($parsed['kind'] === 'invalid' || $parsed['kind'] === 'clear') {
            $errors[] = ops_issue($row, $key, $parsed['kind'] === 'clear' ? '(clear) replaces the whole list. It cannot sit beside a picture.' : $parsed['message']);
            continue;
        }
        if ($parsed['kind'] === 'file') {
            $file_key = mb_strtolower($parsed['value'], 'UTF-8');
            if (!isset($zip_files[$file_key])) {
                $errors[] = ops_issue($row, $key, $parsed['value'] . ' is not in the zip uploaded with this file.');
                continue;
            }
            $used_files[$file_key] = true;
        }
        $items[] = $parsed;
    }
    return ['clear' => false, 'items' => $items];
}
