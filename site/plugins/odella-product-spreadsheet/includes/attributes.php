<?php
/**
 * Global attributes (Size, Color) and the real variation rows that use them.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Direct DB lookup for variation IDs of a parent product.
 * Bypasses WP_Query / get_posts so Polylang's PLL_Frontend_Auto_Translate
 * (which hooks parse_query in admin-ajax.php) can never rewrite post_parent.
 *
 * @return array<int, int>
 */
function ops_get_variation_ids(int $parent_id): array
{
    global $wpdb;
    if ($parent_id <= 0 || !isset($wpdb)) {
        return [];
    }
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'product_variation'
           AND post_parent = %d
           AND post_status IN ('publish', 'private', 'draft')
         ORDER BY ID ASC",
        $parent_id
    ));
    return is_array($rows) ? array_map('intval', $rows) : [];
}

/**
 * Read raw attribute_* postmeta directly for a variation, independent of parent _product_attributes.
 *
 * @return array<string, string> taxonomy => slug
 */
function ops_get_raw_variation_attrs(int $variation_id): array
{
    global $wpdb;
    if ($variation_id <= 0 || !isset($wpdb)) {
        return [];
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
         WHERE post_id = %d AND meta_key LIKE 'attribute\\_%%'",
        $variation_id
    ), ARRAY_A);
    $attrs = [];
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $key = (string) ($row['meta_key'] ?? '');
            $val = trim((string) ($row['meta_value'] ?? ''));
            if (str_starts_with($key, 'attribute_') && $val !== '') {
                $attrs[substr($key, 10)] = $val;
            }
        }
    }
    return $attrs;
}

/**
 * Resolve (or auto-create and link) the English Polylang translation of a Georgian attribute term.
 */
function ops_ensure_en_attribute_term(int $ka_term_id, string $taxonomy): ?WP_Term
{
    if ($ka_term_id <= 0 || !taxonomy_exists($taxonomy)) {
        return null;
    }
    $ka_term = get_term($ka_term_id, $taxonomy);
    if (!$ka_term instanceof WP_Term) {
        return null;
    }
    if (!function_exists('pll_is_translated_taxonomy') || !pll_is_translated_taxonomy($taxonomy)) {
        return $ka_term;
    }
    if (function_exists('pll_get_term')) {
        $en_tid = (int) pll_get_term($ka_term_id, 'en');
        if ($en_tid > 0) {
            $en_term = get_term($en_tid, $taxonomy);
            if ($en_term instanceof WP_Term) {
                return $en_term;
            }
        }
    }
    if (!function_exists('pll_set_term_language') || !function_exists('pll_save_term_translations')) {
        return $ka_term;
    }
    $slug_en = $ka_term->slug . '-en';
    $ins = wp_insert_term($ka_term->name, $taxonomy, ['slug' => $slug_en]);
    $en_tid = 0;
    if (is_wp_error($ins)) {
        $existing = get_terms([
            'taxonomy' => $taxonomy,
            'slug' => $slug_en,
            'hide_empty' => false,
            'lang' => '',
            'number' => 1,
        ]);
        if (is_array($existing) && !empty($existing[0]) && $existing[0] instanceof WP_Term) {
            $en_tid = (int) $existing[0]->term_id;
        }
    } else {
        $en_tid = (int) ($ins['term_id'] ?? 0);
    }
    if ($en_tid <= 0) {
        return $ka_term;
    }
    pll_set_term_language($en_tid, 'en');
    pll_save_term_translations(['ka' => $ka_term_id, 'en' => $en_tid]);
    $en_term = get_term($en_tid, $taxonomy);
    return $en_term instanceof WP_Term ? $en_term : $ka_term;
}

/**
 * Copy _product_attributes and translated pa_* terms from a Georgian parent product to its English parent.
 */
function ops_sync_parent_attributes_to_en(int $ka_parent_id, int $en_parent_id): void
{
    if ($ka_parent_id <= 0 || $en_parent_id <= 0) {
        return;
    }
    $ka_attrs = get_post_meta($ka_parent_id, '_product_attributes', true);
    if (!is_array($ka_attrs)) {
        return;
    }
    update_post_meta($en_parent_id, '_product_attributes', $ka_attrs);
    foreach ($ka_attrs as $tax => $attr_meta) {
        if (!is_string($tax) || !str_starts_with($tax, 'pa_') || !taxonomy_exists($tax)) {
            continue;
        }
        $ka_term_ids = wp_get_object_terms($ka_parent_id, $tax, ['fields' => 'ids']);
        if (!is_array($ka_term_ids)) {
            continue;
        }
        $en_term_ids = [];
        foreach ($ka_term_ids as $ka_tid) {
            $en_term = ops_ensure_en_attribute_term((int) $ka_tid, $tax);
            if ($en_term instanceof WP_Term) {
                $en_term_ids[] = (int) $en_term->term_id;
            }
        }
        $en_term_ids = array_values(array_unique($en_term_ids));
        wp_set_object_terms($en_parent_id, $en_term_ids, $tax);
    }
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($ka_parent_id);
        wc_delete_product_transients($en_parent_id);
    }
}

/**
 * Ensure parent product (KA and linked EN) has _product_attributes and pa_* terms for all variation attributes.
 *
 * @param array<string, array<int, array{id: int, name: string, slug: string}>> $used_terms_by_tax
 */
function ops_ensure_parent_attributes(int $ka_parent_id, array $used_terms_by_tax): void
{
    if ($ka_parent_id <= 0 || !$used_terms_by_tax) {
        return;
    }
    $existing_meta = get_post_meta($ka_parent_id, '_product_attributes', true);
    if (!is_array($existing_meta)) {
        $existing_meta = [];
    }
    $meta = [];
    $position = 0;

    // Set every variation taxonomy used by this parent's variations
    foreach ($used_terms_by_tax as $tax => $terms_map) {
        if (!is_string($tax) || !taxonomy_exists($tax) || !$terms_map) {
            continue;
        }
        $ka_ids = array_values(array_unique(array_map('intval', array_keys($terms_map))));
        wp_set_object_terms($ka_parent_id, $ka_ids, $tax);
        $meta[$tax] = [
            'name' => $tax,
            'value' => '',
            'position' => $position,
            'is_visible' => 1,
            'is_variation' => 1,
            'is_taxonomy' => 1,
        ];
        $position++;
    }

    // Preserve any non-variation attributes that were already set on the product (e.g. informational Color)
    foreach ($existing_meta as $tax => $entry) {
        if (isset($meta[$tax]) || !is_array($entry)) {
            continue;
        }
        if (empty($entry['is_variation'])) {
            $entry['position'] = $position++;
            $meta[$tax] = $entry;
        } elseif (is_string($tax) && str_starts_with($tax, 'pa_') && taxonomy_exists($tax)) {
            // Clear stale variation taxonomy no longer used by any variation
            wp_set_object_terms($ka_parent_id, [], $tax);
        }
    }

    update_post_meta($ka_parent_id, '_product_attributes', $meta);

    if (function_exists('pll_get_post')) {
        $en_parent_id = (int) pll_get_post($ka_parent_id, 'en');
        if ($en_parent_id > 0) {
            ops_sync_parent_attributes_to_en($ka_parent_id, $en_parent_id);
        }
    }
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($ka_parent_id);
    }
}

/**
 * @return array<int, array{label: string, taxonomy: string, terms: array<int, array{id: int, name: string, slug: string}>}>
 */
function ops_attribute_catalog(): array
{
    if (!function_exists('wc_get_attribute_taxonomies')) {
        return [];
    }
    $catalog = [];
    $taxes = wc_get_attribute_taxonomies();
    if (!is_array($taxes)) {
        return [];
    }
    foreach ($taxes as $tax) {
        $taxonomy = 'pa_' . $tax->attribute_name;
        $label = (string) ($tax->attribute_label ?: $tax->attribute_name);
        $args = ['taxonomy' => $taxonomy, 'hide_empty' => false];
        if (function_exists('pll_is_translated_taxonomy') && pll_is_translated_taxonomy($taxonomy)) {
            $args['lang'] = 'ka';
        }
        $terms = get_terms($args);
        if ((!is_array($terms) || !$terms) && isset($args['lang'])) {
            $args['lang'] = '';
            $terms = get_terms($args);
        }
        $items = [];
        $seen = [];
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if (!$term instanceof WP_Term) {
                    continue;
                }
                $key = mb_strtolower($term->name, 'UTF-8');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $items[] = ['id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug];
            }
        }
        $catalog[] = ['label' => $label, 'taxonomy' => $taxonomy, 'terms' => $items];
    }
    return $catalog;
}

/**
 * @param array<int, array{label: string, taxonomy: string, terms: array<int, array{id: int, name: string, slug: string}>}> $catalog
 * @return array{empty: bool, clear: bool, chosen: array<string, array<int, array{id: int, name: string, slug: string}>>, errors: array<int, string>}
 */
function ops_parse_attribute_list(string $cell, array $catalog): array
{
    $trim = trim($cell);
    if ($trim === '') {
        return ['empty' => true, 'clear' => false, 'chosen' => [], 'errors' => []];
    }
    if (mb_strtolower($trim, 'UTF-8') === '(clear)') {
        return ['empty' => false, 'clear' => true, 'chosen' => [], 'errors' => []];
    }
    $by_label = [];
    foreach ($catalog as $attr) {
        $by_label[mb_strtolower($attr['label'], 'UTF-8')] = $attr;
    }
    $chosen = [];
    $errors = [];
    foreach (preg_split('/\s*;\s*/u', $trim) ?: [] as $token) {
        $token = trim((string) $token);
        if ($token === '') {
            continue;
        }
        if (!preg_match('/^(.+?):\s*(.+)$/u', $token, $match)) {
            $errors[] = 'Write an attribute as Size: 46x46.';
            continue;
        }
        $label = mb_strtolower(trim($match[1]), 'UTF-8');
        $value = trim($match[2]);
        if (!isset($by_label[$label])) {
            $errors[] = $match[1] . ' is not an attribute on the shop.';
            continue;
        }
        $attr = $by_label[$label];
        $term = null;
        foreach ($attr['terms'] as $candidate) {
            if (mb_strtolower($candidate['name'], 'UTF-8') === mb_strtolower($value, 'UTF-8') || mb_strtolower($candidate['slug'], 'UTF-8') === mb_strtolower($value, 'UTF-8')) {
                $term = $candidate;
                break;
            }
        }
        if (!$term) {
            $errors[] = $match[1] . ': ' . $value . ' is not one of the existing choices.';
            continue;
        }
        $chosen[$attr['taxonomy']][$term['id']] = $term;
    }
    return ['empty' => false, 'clear' => false, 'chosen' => $chosen, 'errors' => $errors];
}

function ops_attribute_export(int $id): string
{
    $catalog = ops_attribute_catalog();
    $tokens = [];
    foreach ($catalog as $attr) {
        $names = wp_get_object_terms($id, $attr['taxonomy'], ['fields' => 'names']);
        if (!is_array($names)) {
            continue;
        }
        foreach ($names as $name) {
            $tokens[] = $attr['label'] . ': ' . $name;
        }
    }
    if (!$tokens) {
        // Fallback for variable products whose parent pa_* terms were missing: infer from child variations
        $product = function_exists('wc_get_product') ? wc_get_product($id) : null;
        if ($product instanceof WC_Product && $product->get_type() === 'variable') {
            $slug_to_name = [];
            foreach ($catalog as $attr) {
                foreach ($attr['terms'] as $term) {
                    $slug_to_name[$attr['taxonomy']][$term['slug']] = $term['name'];
                }
            }
            $seen = [];
            foreach (ops_get_variation_ids($id) as $vid) {
                $raw = ops_get_raw_variation_attrs($vid);
                foreach ($catalog as $attr) {
                    $tax = $attr['taxonomy'];
                    $slug = (string) ($raw[$tax] ?? '');
                    if ($slug === '') {
                        continue;
                    }
                    $name = $slug_to_name[$tax][$slug] ?? '';
                    if ($name === '') {
                        $found = get_terms(['taxonomy' => $tax, 'slug' => $slug, 'hide_empty' => false, 'lang' => '', 'number' => 1]);
                        if (is_array($found) && !empty($found[0]) && $found[0] instanceof WP_Term) {
                            $name = $found[0]->name;
                        } else {
                            $name = $slug;
                        }
                    }
                    $tok = $attr['label'] . ': ' . $name;
                    if (!isset($seen[$tok])) {
                        $seen[$tok] = true;
                        $tokens[] = $tok;
                    }
                }
            }
        }
    }
    return implode('; ', $tokens);
}

/**
 * @return array<int, string>
 */
function ops_attribute_tokens(): array
{
    $tokens = [];
    foreach (ops_attribute_catalog() as $attr) {
        foreach ($attr['terms'] as $term) {
            $tokens[] = $attr['label'] . ': ' . $term['name'];
        }
    }
    return $tokens;
}

/**
 * @param array<string, array<int, array{id: int, slug: string}>> $chosen
 */
function ops_apply_attribute_choice(int $id, array $chosen, bool $clear, bool $variable): void
{
    $catalog = ops_attribute_catalog();
    $meta = [];
    $position = 0;
    foreach ($catalog as $attr) {
        $terms = array_values($chosen[$attr['taxonomy']] ?? []);
        $ids = array_map(static function (array $term): int {
            return (int) $term['id'];
        }, $terms);
        wp_set_object_terms($id, $clear ? [] : $ids, $attr['taxonomy']);
        if ($clear || !$ids) {
            continue;
        }
        $meta[$attr['taxonomy']] = [
            'name' => $attr['taxonomy'],
            'value' => '',
            'position' => $position,
            'is_visible' => 1,
            'is_variation' => $variable ? 1 : 0,
            'is_taxonomy' => 1,
        ];
        $position++;
    }
    update_post_meta($id, '_product_attributes', $meta);
}

/**
 * @return array<int, array<int, string>>
 */
function ops_variation_sheet_rows(): array
{
    global $wpdb;
    $catalog = ops_attribute_catalog();
    $header = ['Parent ID', 'Parent SKU', 'Variation ID', 'SKU'];
    $slug_to_name = [];
    foreach ($catalog as $attr) {
        $header[] = $attr['label'];
        foreach ($attr['terms'] as $term) {
            $slug_to_name[$attr['taxonomy']][$term['slug']] = $term['name'];
        }
    }
    $header[] = 'Regular price (GEL)';
    $header[] = 'Sale price (GEL)';
    $header[] = 'Status';
    $rows = [$header];
    if (!function_exists('wc_get_products') || !isset($wpdb)) {
        return $rows;
    }
    $allowed_parents = function_exists('ops_export_ids') ? array_flip(ops_export_ids()) : null;
    $ids = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'product_variation'
           AND post_status IN ('publish', 'private')
         ORDER BY ID ASC"
    );
    if (!is_array($ids)) {
        return $rows;
    }
    $parent_info = [];
    foreach ($ids as $variation_id) {
        $variation_id = (int) $variation_id;
        $variation = wc_get_product($variation_id);
        if (!$variation instanceof WC_Product_Variation) {
            continue;
        }
        $parent_id = (int) $variation->get_parent_id();
        if ($parent_id <= 0) {
            continue;
        }
        // Only export variations belonging to Georgian (ka) parent products on the Products sheet.
        if (is_array($allowed_parents)) {
            if (!isset($allowed_parents[$parent_id])) {
                continue;
            }
        } elseif (function_exists('ops_product_language')) {
            $lang = ops_product_language($parent_id);
            if ($lang !== '' && $lang !== 'ka') {
                continue;
            }
        }
        if (!array_key_exists($parent_id, $parent_info)) {
            $parent_product = wc_get_product($parent_id);
            $parent_info[$parent_id] = [
                'is_variable' => $parent_product instanceof WC_Product && $parent_product->get_type() === 'variable',
                'sku' => $parent_product instanceof WC_Product ? (string) $parent_product->get_sku() : '',
            ];
        }
        // Skip ghost variations left on simple/non-variable products
        if (!$parent_info[$parent_id]['is_variable']) {
            continue;
        }
        $attrs = $variation->get_attributes();
        $raw_attrs = ops_get_raw_variation_attrs($variation_id);
        $has_attribute = false;
        $values = [];
        foreach ($catalog as $attr) {
            $tax = $attr['taxonomy'];
            $slug = (string) ($attrs[$tax] ?? '');
            if ($slug === '' && isset($raw_attrs[$tax])) {
                $slug = (string) $raw_attrs[$tax];
            }
            $name = '';
            if ($slug !== '') {
                $has_attribute = true;
                if (isset($slug_to_name[$tax][$slug])) {
                    $name = $slug_to_name[$tax][$slug];
                } else {
                    $found = get_terms(['taxonomy' => $tax, 'slug' => $slug, 'hide_empty' => false, 'lang' => '', 'number' => 1]);
                    $name = (is_array($found) && !empty($found[0]) && $found[0] instanceof WP_Term) ? $found[0]->name : $slug;
                }
            }
            $values[] = $name;
        }
        $sku = (string) $variation->get_sku();
        $regular = (string) $variation->get_regular_price();
        if (!$has_attribute && $sku === '' && $regular === '') {
            continue;
        }
        $rows[] = array_merge(
            [(string) $parent_id, $parent_info[$parent_id]['sku'], (string) $variation->get_id(), $sku],
            $values,
            [$regular, (string) $variation->get_sale_price(), (string) $variation->get_status()]
        );
    }
    return $rows;
}

/**
 * @param array<int, array<int, array{text: string}>> $grid
 * @param array<int, array<string, mixed>> $product_plans
 * @return array{errors: array<int, array{row: int, column: string, message: string}>, plans: array<int, array<string, mixed>>}
 */
function ops_preflight_variations(array $grid, array $product_plans = []): array
{
    $errors = [];
    $plans = [];
    if (count($grid) < 2) {
        return ['errors' => [], 'plans' => []];
    }
    $headers = [];
    foreach ($grid[0] as $cell) {
        $headers[] = mb_strtolower(trim((string) ($cell['text'] ?? '')), 'UTF-8');
    }
    $find = static function (string $name) use ($headers): int {
        $at = array_search($name, $headers, true);
        return $at === false ? -1 : (int) $at;
    };
    $parent_at = $find('parent id');
    $parent_sku_at = $find('parent sku');
    $id_at = $find('variation id');
    if ($parent_at < 0 && $parent_sku_at < 0) {
        return ['errors' => [['row' => 1, 'column' => 'Variations', 'message' => 'The Variations sheet needs a Parent ID or Parent SKU column.']], 'plans' => []];
    }
    $known_skus = [];
    foreach ($product_plans as $p) {
        $psku = trim((string) ($p['values']['sku'] ?? $p['raw']['sku'] ?? ''));
        if ($psku !== '') {
            $known_skus[mb_strtolower($psku, 'UTF-8')] = true;
        }
    }
    $catalog = ops_attribute_catalog();
    for ($r = 1, $count = count($grid); $r < $count; $r++) {
        $row = $grid[$r];
        $text = static function (int $at) use ($row): string {
            return $at < 0 ? '' : trim((string) ($row[$at]['text'] ?? ''));
        };
        $parent = $text($parent_at);
        if (preg_match('/^(\d+)\.0+$/', $parent, $pm)) {
            $parent = $pm[1];
        }
        $parent_sku = $text($parent_sku_at);
        $variation_id = $text($id_at);
        if (preg_match('/^(\d+)\.0+$/', $variation_id, $vm)) {
            $variation_id = $vm[1];
        }
        $sku = $text($find('sku'));
        $regular = $text($find('regular price (gel)'));
        $sale = $text($find('sale price (gel)'));
        $status = mb_strtolower($text($find('status')), 'UTF-8');
        $any = $parent !== '' || $parent_sku !== '' || $variation_id !== '' || $sku !== '' || $regular !== '' || $sale !== '';
        $terms = [];
        foreach ($catalog as $attr) {
            $at = $find(mb_strtolower($attr['label'], 'UTF-8'));
            $value = $text($at);
            if ($value !== '') {
                $any = true;
            }
            if ($value === '') {
                continue;
            }
            $parsed = ops_parse_attribute_list($attr['label'] . ': ' . $value, [$attr]);
            foreach ($parsed['errors'] as $message) {
                $errors[] = ['row' => $r + 1, 'column' => $attr['label'], 'message' => $message];
            }
            if (!$parsed['errors'] && isset($parsed['chosen'][$attr['taxonomy']])) {
                $terms[$attr['taxonomy']] = array_values($parsed['chosen'][$attr['taxonomy']])[0];
            }
        }
        if (!$any) {
            continue;
        }
        if ($parent !== '' && !preg_match('/^\d+$/', $parent)) {
            $errors[] = ['row' => $r + 1, 'column' => 'Parent ID', 'message' => 'Parent ID must be a number, or leave it blank and provide Parent SKU.'];
        } elseif ($parent === '' && $parent_sku === '') {
            $errors[] = ['row' => $r + 1, 'column' => 'Parent ID', 'message' => 'A variation needs either Parent ID or Parent SKU.'];
        } elseif ($parent === '' && $parent_sku !== '') {
            $sku_key = mb_strtolower($parent_sku, 'UTF-8');
            $existing_id = function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($parent_sku) : 0;
            if (!isset($known_skus[$sku_key]) && $existing_id <= 0) {
                $errors[] = ['row' => $r + 1, 'column' => 'Parent SKU', 'message' => 'Parent SKU ' . $parent_sku . ' was not found on the shop or in the Products sheet.'];
            }
        }
        if ($variation_id !== '' && !preg_match('/^\d+$/', $variation_id)) {
            $errors[] = ['row' => $r + 1, 'column' => 'Variation ID', 'message' => 'Variation ID must be empty for a new variation, or the number from the export.'];
        }
        if ($status !== '' && !in_array($status, ['publish', 'private', 'draft'], true)) {
            $errors[] = ['row' => $r + 1, 'column' => 'Status', 'message' => 'Variation status must be publish or private.'];
        }
        $price = $regular === '' ? ['kind' => 'blank', 'value' => ''] : ops_parse_price($regular);
        $sale_price = $sale === '' ? ['kind' => 'blank', 'value' => ''] : ops_parse_price($sale);
        if ($price['kind'] === 'invalid') {
            $errors[] = ['row' => $r + 1, 'column' => 'Regular price (GEL)', 'message' => $price['message']];
        }
        if ($sale_price['kind'] === 'invalid') {
            $errors[] = ['row' => $r + 1, 'column' => 'Sale price (GEL)', 'message' => $sale_price['message']];
        }
        $plans[] = [
            'row' => $r + 1,
            'parent' => $parent === '' ? 0 : (int) $parent,
            'parent_sku' => $parent_sku,
            'id' => $variation_id === '' ? 0 : (int) $variation_id,
            'sku' => $sku,
            'regular' => $price['kind'] === 'value' ? $price['value'] : ($price['kind'] === 'clear' ? '' : null),
            'sale' => $sale_price['kind'] === 'value' ? $sale_price['value'] : ($sale_price['kind'] === 'clear' ? '' : null),
            'status' => $status,
            'terms' => $terms,
        ];
    }
    return ['errors' => $errors, 'plans' => $errors ? [] : $plans];
}

/**
 * @param array<int, array<string, mixed>> $plans
 * @param array<string, int> $created_by_sku
 * @param array<string, string> $desired_statuses Map of 'id:<int>' or 'sku:<lower>' => desired status
 * @return array<int, array<string, string>>
 */
function ops_apply_variations(array $plans, array $created_by_sku = [], array $desired_statuses = []): array
{
    $changes = [];
    $grouped_plans = [];

    // 1. Resolve KA parent ID for every variation row and group by parent
    foreach ($plans as $plan) {
        $parent_id = (int) $plan['parent'];
        if ($parent_id <= 0 && !empty($plan['parent_sku'])) {
            $sku_key = mb_strtolower((string) $plan['parent_sku'], 'UTF-8');
            if (isset($created_by_sku[$sku_key])) {
                $parent_id = (int) $created_by_sku[$sku_key];
            } elseif (function_exists('wc_get_product_id_by_sku')) {
                $parent_id = (int) wc_get_product_id_by_sku((string) $plan['parent_sku']);
                if ($parent_id > 0 && function_exists('pll_get_post')) {
                    $ka_parent = (int) pll_get_post($parent_id, 'ka');
                    if ($ka_parent > 0) {
                        $parent_id = $ka_parent;
                    }
                }
            }
        }
        $parent = wc_get_product($parent_id);
        if (!$parent instanceof WC_Product) {
            throw new RuntimeException('Variation row ' . $plan['row'] . ' points at product ' . ($parent_id ?: ($plan['parent_sku'] ?? '')) . ', which does not exist.');
        }
        // Georgian (ka) is the single source of truth; skip non-KA parent rows (EN is mirrored automatically).
        if (function_exists('ops_product_language')) {
            $parent_lang = ops_product_language($parent_id);
            if ($parent_lang !== '' && $parent_lang !== 'ka') {
                continue;
            }
        }
        $grouped_plans[$parent_id][] = $plan;
    }

    // 2. Apply each parent's variations as an atomic group
    foreach ($grouped_plans as $parent_id => $group_plans) {
        $parent_id = (int) $parent_id;
        $parent = wc_get_product($parent_id);
        if (!$parent instanceof WC_Product) {
            continue;
        }
        $en_parent_id = function_exists('pll_get_post') ? (int) pll_get_post($parent_id, 'en') : 0;

        // Ensure KA (and EN) parent product type is variable and clear parent-level prices
        if ($parent->get_type() !== 'variable') {
            wp_set_object_terms($parent_id, 'variable', 'product_type');
        }
        delete_post_meta($parent_id, '_regular_price');
        delete_post_meta($parent_id, '_sale_price');
        if ($en_parent_id > 0) {
            $en_parent = wc_get_product($en_parent_id);
            if ($en_parent instanceof WC_Product && $en_parent->get_type() !== 'variable') {
                wp_set_object_terms($en_parent_id, 'variable', 'product_type');
            }
            delete_post_meta($en_parent_id, '_regular_price');
            delete_post_meta($en_parent_id, '_sale_price');
        }

        // Collect all attribute terms used by this parent's variations and ensure parent _product_attributes + pa_* terms BEFORE saving variations
        $used_terms_by_tax = [];
        foreach ($group_plans as $gp) {
            foreach (($gp['terms'] ?? []) as $taxonomy => $term) {
                $tid = (int) ($term['id'] ?? 0);
                if ($tid > 0) {
                    $used_terms_by_tax[$taxonomy][$tid] = $term;
                }
            }
        }
        if ($used_terms_by_tax) {
            ops_ensure_parent_attributes($parent_id, $used_terms_by_tax);
        }

        $kept_ka_vars = [];
        $kept_en_vars = [];
        $has_priced_variation = false;
        $group_dirty = false;

        foreach ($group_plans as $plan) {
            $target_attrs = [];
            foreach (($plan['terms'] ?? []) as $taxonomy => $term) {
                $target_attrs[$taxonomy] = (string) $term['slug'];
            }

            $requested_id = (int) $plan['id'];
            $id = $requested_id;
            $variation = null;

            // Validate requested Variation ID: if it was deleted or belongs to a different parent, fall back gracefully
            if ($id > 0) {
                $cand = wc_get_product($id);
                if ($cand instanceof WC_Product_Variation && (int) $cand->get_parent_id() === $parent_id && !isset($kept_ka_vars[$id])) {
                    $variation = $cand;
                } else {
                    $id = 0;
                }
            }

            // Match existing KA variation by raw attribute_* postmeta (immune to Polylang query filters & missing parent meta)
            if (!$variation && $target_attrs) {
                $existing_children = ops_get_variation_ids($parent_id);
                foreach ($existing_children as $child_id) {
                    if (isset($kept_ka_vars[$child_id])) {
                        continue;
                    }
                    $c_attrs = ops_get_raw_variation_attrs($child_id);
                    if (count($c_attrs) !== count($target_attrs)) {
                        continue;
                    }
                    $match = true;
                    foreach ($target_attrs as $tax => $slug) {
                        if ((string) ($c_attrs[$tax] ?? '') !== $slug) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $cand = wc_get_product($child_id);
                        if ($cand instanceof WC_Product_Variation) {
                            $variation = $cand;
                            $id = $child_id;
                            break;
                        }
                    }
                }
            }

            if (!$variation) {
                $variation = new WC_Product_Variation();
                $variation->set_parent_id($parent_id);
                $id = 0;
            }

            $old_sku = $id > 0 ? (string) $variation->get_sku() : '';
            $old_reg = $id > 0 ? (string) $variation->get_regular_price() : '';
            $old_sale = $id > 0 ? (string) $variation->get_sale_price() : '';
            $old_status = $id > 0 ? (string) $variation->get_status() : '';
            $old_attrs = $id > 0 ? ops_get_raw_variation_attrs($id) : [];

            $sku_changed = ($plan['sku'] !== '' && $plan['sku'] !== $old_sku);
            $reg_changed = ($plan['regular'] !== null && !ops_prices_equal((string) $plan['regular'], $old_reg));
            $sale_changed = ($plan['sale'] !== null && !ops_prices_equal((string) $plan['sale'], $old_sale));
            $status_changed = ($plan['status'] !== '' && $plan['status'] !== $old_status);
            $attrs_changed = false;
            if ($target_attrs) {
                foreach ($target_attrs as $tax => $slug) {
                    if ((string) ($old_attrs[$tax] ?? '') !== $slug) {
                        $attrs_changed = true;
                        break;
                    }
                }
            }
            $var_dirty = ($id === 0) || $sku_changed || $reg_changed || $sale_changed || $status_changed || $attrs_changed;

            if ($var_dirty) {
                if ($sku_changed) {
                    if ($variation->get_id() > 0) {
                        update_post_meta($variation->get_id(), '_sku', (string) $plan['sku']);
                    }
                    try {
                        $variation->set_sku((string) $plan['sku']);
                    } catch (Throwable $e) {
                        // Allow variation SKU even if matching parent/legacy SKU
                    }
                }
                if ($plan['regular'] !== null) {
                    $variation->set_regular_price((string) $plan['regular']);
                }
                if ($plan['sale'] !== null) {
                    $variation->set_sale_price((string) $plan['sale']);
                }
                if ($plan['status'] !== '') {
                    $variation->set_status((string) $plan['status']);
                } elseif ($id === 0) {
                    $variation->set_status('publish');
                }
                if ($target_attrs) {
                    $variation->set_attributes($target_attrs);
                }
                $variation->save();
                $ka_var_id = (int) $variation->get_id();

                if ($plan['sku'] !== '' && $variation->get_sku() !== $plan['sku']) {
                    update_post_meta($ka_var_id, '_sku', (string) $plan['sku']);
                }
                foreach ($target_attrs as $tax => $slug) {
                    update_post_meta($ka_var_id, 'attribute_' . $tax, $slug);
                }
                if (function_exists('pll_set_post_language')) {
                    pll_set_post_language($ka_var_id, 'ka');
                }
                if (function_exists('odella_pricing_sync_zone_prices')) {
                    odella_pricing_sync_zone_prices($ka_var_id);
                }
                $group_dirty = true;
            } else {
                $ka_var_id = $id;
            }

            if ((float) $variation->get_regular_price() > 0 && $variation->get_status() === 'publish') {
                $has_priced_variation = true;
            }
            $kept_ka_vars[$ka_var_id] = true;

            // Mirror variation onto linked English Polylang product if present (skips save internally if unchanged)
            $en_var_changed = false;
            if ($en_parent_id > 0) {
                $en_var_id = ops_sync_en_variation($en_parent_id, $variation, $plan['terms'] ?? [], (string) $plan['sku'], $kept_en_vars, $en_var_changed);
                if ($en_var_id > 0) {
                    $kept_en_vars[$en_var_id] = true;
                }
                if ($en_var_changed) {
                    $group_dirty = true;
                }
            }

            if ($var_dirty) {
                $diff_parts = [];
                if ($id === 0) {
                    $diff_parts[] = 'created';
                } else {
                    if ($sku_changed) $diff_parts[] = 'SKU ' . $old_sku . ' -> ' . $plan['sku'];
                    if ($reg_changed) $diff_parts[] = 'Price ' . $old_reg . ' -> ' . $plan['regular'];
                    if ($sale_changed) $diff_parts[] = 'Sale ' . $old_sale . ' -> ' . $plan['sale'];
                    if ($status_changed) $diff_parts[] = 'Status ' . $old_status . ' -> ' . $plan['status'];
                    if ($attrs_changed) $diff_parts[] = 'Attributes';
                }
                $changes[] = [
                    'id' => (string) $parent_id,
                    'sku' => (string) $variation->get_sku(),
                    'name' => $parent->get_name(),
                    'field' => 'Variation ' . $ka_var_id,
                    'before' => $id > 0 ? (string) $id : ($requested_id > 0 ? (string) $requested_id . ' (recreated)' : '(new)'),
                    'after' => implode(', ', $diff_parts) ?: (string) $ka_var_id,
                ];
            }
        }

        // 3. Remove stale/duplicate variations on EN parent using direct $wpdb query (never get_posts)
        if ($en_parent_id > 0 && !empty($kept_en_vars)) {
            foreach (ops_get_variation_ids($en_parent_id) as $evid) {
                if (isset($kept_en_vars[$evid]) || isset($kept_ka_vars[$evid])) {
                    continue;
                }
                if ((int) wp_get_post_parent_id($evid) !== $en_parent_id) {
                    continue;
                }
                $linked_ka = function_exists('pll_get_post') ? (int) pll_get_post($evid, 'ka') : 0;
                if ($linked_ka > 0 && isset($kept_ka_vars[$linked_ka])) {
                    continue;
                }
                wp_delete_post($evid, true);
                $group_dirty = true;
            }
        }

        // 4. Remove stale/duplicate/ghost variations on KA parent not present in this parent's spreadsheet rows
        if (!empty($kept_ka_vars)) {
            foreach (ops_get_variation_ids($parent_id) as $kvid) {
                if (isset($kept_ka_vars[$kvid])) {
                    continue;
                }
                if ((int) wp_get_post_parent_id($kvid) !== $parent_id) {
                    continue;
                }
                wp_delete_post($kvid, true);
                $group_dirty = true;
                $changes[] = [
                    'id' => (string) $parent_id,
                    'sku' => (string) $parent->get_sku(),
                    'name' => $parent->get_name(),
                    'field' => 'Variation ' . $kvid,
                    'before' => (string) $kvid,
                    'after' => '(removed)',
                ];
            }
        }

        if (!$group_dirty) {
            continue;
        }

        // 5. Re-assert parent attributes (KA + EN), clear transients, and sync variable price ranges
        if ($used_terms_by_tax) {
            ops_ensure_parent_attributes($parent_id, $used_terms_by_tax);
        }
        $sync_pids = [$parent_id];
        if ($en_parent_id > 0) {
            $sync_pids[] = $en_parent_id;
        }
        foreach ($sync_pids as $pid) {
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($pid);
            }
            if (class_exists('WC_Product_Variable')) {
                WC_Product_Variable::sync($pid);
            }
            if (function_exists('odella_pricing_sync_zone_prices')) {
                odella_pricing_sync_zone_prices($pid);
            }
        }

        // 6. Restore 'publish' status if Phase 1's shutdown guard temporarily flipped an unpriced variable product to draft
        $parent_sku_key = mb_strtolower(trim((string) $parent->get_sku()), 'UTF-8');
        $desired = (string) ($desired_statuses['id:' . $parent_id] ?? ($parent_sku_key !== '' ? ($desired_statuses['sku:' . $parent_sku_key] ?? '') : ''));
        if ($desired === 'publish' && $has_priced_variation) {
            foreach ($sync_pids as $pid) {
                if (get_post_status($pid) !== 'publish') {
                    wp_update_post(['ID' => $pid, 'post_status' => 'publish']);
                }
                if (function_exists('odella_sync_unpriced_visibility')) {
                    odella_sync_unpriced_visibility($pid);
                }
            }
            // Belt-and-suspenders: ensure Polylang save_post hooks kept attributes intact after status update
            if ($used_terms_by_tax) {
                ops_ensure_parent_attributes($parent_id, $used_terms_by_tax);
            }
        }
    }

    return $changes;
}

/**
 * Mirror a Georgian variation onto its linked English variable parent product.
 * Uses Polylang links + direct $wpdb queries (immune to PLL_Frontend_Auto_Translate)
 * and translates KA attribute terms to their linked EN term slugs.
 *
 * @param array<string, array{id: int, name: string, slug: string}> $ka_terms
 * @param array<int, bool> $already_kept_en
 */
function ops_sync_en_variation(int $en_parent_id, WC_Product_Variation $ka_var, array $ka_terms, string $sku, array $already_kept_en = [], bool &$changed = false): int
{
    $en_parent = wc_get_product($en_parent_id);
    if (!$en_parent instanceof WC_Product) {
        return 0;
    }
    if ($en_parent->get_type() !== 'variable') {
        wp_set_object_terms($en_parent_id, 'variable', 'product_type');
        delete_post_meta($en_parent_id, '_regular_price');
        delete_post_meta($en_parent_id, '_sale_price');
    }

    // Build translated EN attribute slugs (falling back to KA slug if taxonomy is not translated)
    $en_target_attrs = [];
    $ka_target_attrs = [];
    foreach ($ka_terms as $tax => $term) {
        $ka_slug = (string) ($term['slug'] ?? '');
        $ka_tid = (int) ($term['id'] ?? 0);
        $ka_target_attrs[$tax] = $ka_slug;
        $en_term = $ka_tid > 0 ? ops_ensure_en_attribute_term($ka_tid, $tax) : null;
        $en_target_attrs[$tax] = $en_term instanceof WP_Term ? $en_term->slug : $ka_slug;
    }

    $ka_var_id = (int) $ka_var->get_id();
    $en_var = null;

    // 1. Prefer the EN variation already linked by Polylang-WC when $ka_var->save() ran
    if ($ka_var_id > 0 && function_exists('pll_get_post')) {
        $linked_en_id = (int) pll_get_post($ka_var_id, 'en');
        if ($linked_en_id > 0 && $linked_en_id !== $ka_var_id && !isset($already_kept_en[$linked_en_id]) && (int) wp_get_post_parent_id($linked_en_id) === $en_parent_id) {
            $cand = wc_get_product($linked_en_id);
            if ($cand instanceof WC_Product_Variation) {
                $en_var = $cand;
            }
        }
    }

    // 2. Otherwise search existing EN variations via direct $wpdb query (matching EN or legacy KA slugs)
    if (!$en_var && $en_target_attrs) {
        foreach (ops_get_variation_ids($en_parent_id) as $cid) {
            if (isset($already_kept_en[$cid])) {
                continue;
            }
            $ca = ops_get_raw_variation_attrs($cid);
            if (count($ca) !== count($en_target_attrs)) {
                continue;
            }
            $ok_en = true;
            $ok_ka = true;
            foreach ($en_target_attrs as $tax => $en_slug) {
                $actual = (string) ($ca[$tax] ?? '');
                if ($actual !== $en_slug) {
                    $ok_en = false;
                }
                if ($actual !== (string) ($ka_target_attrs[$tax] ?? '')) {
                    $ok_ka = false;
                }
            }
            if ($ok_en || $ok_ka) {
                $cand = wc_get_product($cid);
                if ($cand instanceof WC_Product_Variation) {
                    $en_var = $cand;
                    break;
                }
            }
        }
    }

    $is_new_en = false;
    if (!$en_var) {
        $en_var = new WC_Product_Variation();
        $en_var->set_parent_id($en_parent_id);
        $is_new_en = true;
    }

    $target_reg = (string) $ka_var->get_regular_price();
    $target_sale = (string) $ka_var->get_sale_price();
    $target_status = (string) $ka_var->get_status();
    if (!$is_new_en) {
        $en_existing_id = (int) $en_var->get_id();
        $en_attrs_now = ops_get_raw_variation_attrs($en_existing_id);
        $en_attrs_match = true;
        foreach ($en_target_attrs as $tax => $en_slug) {
            if ((string) ($en_attrs_now[$tax] ?? '') !== $en_slug) {
                $en_attrs_match = false;
                break;
            }
        }
        $en_sku_now = (string) $en_var->get_sku();
        $en_sku_match = ($sku === '' || $en_sku_now === $sku);
        if ($en_attrs_match
            && $en_sku_match
            && ops_prices_equal($target_reg, (string) $en_var->get_regular_price())
            && ops_prices_equal($target_sale, (string) $en_var->get_sale_price())
            && $target_status === (string) $en_var->get_status()
        ) {
            $changed = false;
            return $en_existing_id;
        }
    }

    $changed = true;
    $en_var->set_regular_price($target_reg);
    $en_var->set_sale_price($target_sale);
    $en_var->set_status($target_status);
    if ($en_target_attrs) {
        $en_var->set_attributes($en_target_attrs);
    }

    // Prevent PLLWC_Products::save_variation from reverse-creating a duplicate KA variation if $en_var is brand-new
    if ($is_new_en && $ka_var_id > 0 && function_exists('pll_set_post_language') && function_exists('pll_save_post_translations')) {
        // Insert minimal post first so we can link KA <-> EN BEFORE WooCommerce fires save_variation hooks
        $en_var_id = (int) wp_insert_post([
            'post_title' => $en_parent->get_name(),
            'post_name' => 'product-' . $en_parent_id . '-variation',
            'post_status' => (string) $ka_var->get_status() ?: 'publish',
            'post_parent' => $en_parent_id,
            'post_type' => 'product_variation',
        ]);
        if ($en_var_id > 0) {
            pll_set_post_language($en_var_id, 'en');
            pll_save_post_translations(['ka' => $ka_var_id, 'en' => $en_var_id]);
            $reloaded = wc_get_product($en_var_id);
            if ($reloaded instanceof WC_Product_Variation) {
                $en_var = $reloaded;
                $en_var->set_regular_price((string) $ka_var->get_regular_price());
                $en_var->set_sale_price((string) $ka_var->get_sale_price());
                $en_var->set_status((string) $ka_var->get_status());
                if ($en_target_attrs) {
                    $en_var->set_attributes($en_target_attrs);
                }
            }
        }
    }

    $en_var->save();
    $en_var_id = (int) $en_var->get_id();
    foreach ($en_target_attrs as $tax => $slug) {
        update_post_meta($en_var_id, 'attribute_' . $tax, $slug);
    }
    if ($sku !== '') {
        update_post_meta($en_var_id, '_sku', $sku);
    }
    if ($ka_var_id > 0 && $en_var_id > 0 && function_exists('pll_set_post_language') && function_exists('pll_save_post_translations')) {
        pll_set_post_language($en_var_id, 'en');
        pll_save_post_translations(['ka' => $ka_var_id, 'en' => $en_var_id]);
    }
    if (function_exists('odella_pricing_sync_zone_prices')) {
        odella_pricing_sync_zone_prices($en_var_id);
    }
    return $en_var_id;
}

/**
 * @return array<int, array<int, string>>
 */
function ops_attribute_sheet_rows(): array
{
    $catalog = ops_attribute_catalog();
    $columns = [ops_attribute_tokens()];
    $headers = ['Attributes'];
    foreach ($catalog as $attr) {
        $headers[] = $attr['label'];
        $names = [];
        foreach ($attr['terms'] as $term) {
            $names[] = $term['name'];
        }
        $columns[] = $names;
    }
    $height = 1;
    foreach ($columns as $column) {
        $height = max($height, count($column) + 1);
    }
    $rows = [];
    for ($row = 0; $row < $height; $row++) {
        $line = [];
        foreach ($columns as $index => $column) {
            $line[] = $row === 0 ? $headers[$index] : (string) ($column[$row - 1] ?? '');
        }
        $rows[] = $line;
    }
    return $rows;
}

/**
 * @param array<int, array<int, string>> $variations
 * @param array<int, array<int, string>> $attributes
 * @return array<int, array{sqref: string, formula: string, strict: bool}>
 */
function ops_variation_validations(array $variations, array $attributes): array
{
    $rules = [];
    $header = $variations[0] ?? [];
    $source_header = $attributes[0] ?? [];
    foreach ($header as $index => $name) {
        $source = array_search($name, $source_header, true);
        if ($source === false || $source === 0) {
            continue;
        }
        $count = 0;
        for ($row = 1, $total = count($attributes); $row < $total; $row++) {
            if (trim((string) ($attributes[$row][$source] ?? '')) !== '') {
                $count++;
            }
        }
        if ($count < 1) {
            continue;
        }
        $letter = ops_xlsx_col($index + 1);
        $source_letter = ops_xlsx_col((int) $source + 1);
        $rules[] = [
            'sqref' => $letter . '2:' . $letter . '5000',
            'formula' => 'Attributes!$' . $source_letter . '$2:$' . $source_letter . '$' . ($count + 1),
            'strict' => true,
        ];
    }
    return $rules;
}
