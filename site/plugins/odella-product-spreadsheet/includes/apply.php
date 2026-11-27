<?php
/**
 * Write a preflight-clean plan. A thrown error stops the rest of the file.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param array<int, array<string, mixed>> $plans
 * @param array<string, string> $zip_files
 * @return array{ok: bool, report: array<int, array<string, string>>, saved: array<int, int>, created: array<int, int>, failed_row: int, message: string}
 */
function ops_apply_plans(array $plans, array $zip_files): array
{
    $report = [];
    $saved = [];
    $created = [];
    $created_by_sku = [];
    $current = null;
    try {
        foreach ($plans as $plan) {
            $current = $plan;
            $result = ops_apply_plan($plan, $zip_files);
            $report = array_merge($report, $result['changes']);
            $saved[] = (int) $plan['sheet_row'];
            if ($result['created']) {
                $created[(int) $plan['sheet_row']] = $result['id'];
            }
            $sku = trim((string) ($plan['values']['sku'] ?? $plan['raw']['sku'] ?? ''));
            if ($sku !== '' && $result['id'] > 0) {
                $created_by_sku[mb_strtolower($sku, 'UTF-8')] = $result['id'];
            }
        }
    } catch (Throwable $error) {
        return [
            'ok' => false,
            'report' => $report,
            'saved' => $saved,
            'created' => $created,
            'created_by_sku' => $created_by_sku,
            'failed_row' => is_array($current) ? (int) $current['sheet_row'] : 0,
            'message' => $error->getMessage(),
        ];
    }
    return [
        'ok' => true,
        'report' => $report,
        'saved' => $saved,
        'created' => $created,
        'created_by_sku' => $created_by_sku,
        'failed_row' => 0,
        'message' => '',
    ];
}

/**
 * @param array<string, mixed> $plan
 * @param array<string, string> $zip_files
 * @return array{id: int, created: bool, changes: array<int, array<string, string>>}
 */
function ops_apply_plan(array $plan, array $zip_files): array
{
    $created = false;
    $id = (int) $plan['id'];
    if ($id === 0) {
        $product = new WC_Product_Simple();
        $product->set_name((string) ($plan['values']['name'] ?? ''));
        $product->set_status((string) ($plan['values']['status'] ?? 'draft'));
        $product->set_sku((string) ($plan['values']['sku'] ?? ''));
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
        $product->save();
        $id = $product->get_id();
        $created = true;
        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($id, 'ka');
        }
    } else {
        $loaded = wc_get_product($id);
        if (!$loaded instanceof WC_Product) {
            throw new RuntimeException('Product ' . $id . ' disappeared before it could be saved.');
        }
        $product = $loaded;
    }

    $changes = [];
    $sku = (string) ($plan['values']['sku'] ?? $product->get_sku());
    $name = (string) ($plan['values']['name'] ?? $product->get_name());
    $touch = static function (string $field, string $before, string $after) use (&$changes, $id, $sku, $name): void {
        if ($before === $after) {
            return;
        }
        $changes[] = [
            'id' => (string) $id,
            'sku' => $sku,
            'name' => $name,
            'field' => $field,
            'before' => $created_before = $before,
            'after' => $after,
        ];
        unset($created_before);
    };
    if ($created) {
        $changes[] = [
            'id' => (string) $id,
            'sku' => $sku,
            'name' => $name,
            'field' => 'Product',
            'before' => '(new)',
            'after' => $name,
        ];
    }

    if (isset($plan['values']['name']) && $plan['values']['name'] !== $product->get_name()) {
        $touch('Name', $product->get_name(), (string) $plan['values']['name']);
        $product->set_name((string) $plan['values']['name']);
    }
    if (isset($plan['values']['sku']) && $plan['values']['sku'] !== $product->get_sku()) {
        $touch('SKU', (string) $product->get_sku(), (string) $plan['values']['sku']);
        $product->set_sku((string) $plan['values']['sku']);
    }
    if (isset($plan['values']['status']) && $plan['values']['status'] !== $product->get_status()) {
        $touch('Status', $product->get_status(), (string) $plan['values']['status']);
        $product->set_status((string) $plan['values']['status']);
    }
    if (isset($plan['values']['regular_price']) && !ops_prices_equal((string) $plan['values']['regular_price'], (string) $product->get_regular_price())) {
        $touch('Regular price (GEL)', (string) $product->get_regular_price(), (string) $plan['values']['regular_price']);
        $product->set_regular_price((string) $plan['values']['regular_price']);
    }
    if (array_key_exists('sale_price', $plan['values']) && !ops_prices_equal((string) $plan['values']['sale_price'], (string) $product->get_sale_price())) {
        $touch('Sale price (GEL)', (string) $product->get_sale_price(), (string) $plan['values']['sale_price']);
        $product->set_sale_price((string) $plan['values']['sale_price']);
    }
    if (isset($plan['values']['short_description']) && !ops_descriptions_match((string) $product->get_short_description(), (string) $plan['values']['short_description'])) {
        $touch('Short description', wp_strip_all_tags((string) $product->get_short_description()), wp_strip_all_tags((string) $plan['values']['short_description']));
        $product->set_short_description(ops_kses((string) $plan['values']['short_description']));
    }
    if (isset($plan['values']['description']) && !ops_descriptions_match((string) $product->get_description(), (string) $plan['values']['description'])) {
        $touch('Description', wp_strip_all_tags((string) $product->get_description()), wp_strip_all_tags((string) $plan['values']['description']));
        $product->set_description(ops_kses((string) $plan['values']['description']));
    }
    if (is_array($plan['category_ids'])) {
        $before_ids = array_map('intval', $product->get_category_ids());
        $after_ids = array_map('intval', $plan['category_ids']);
        sort($before_ids);
        sort($after_ids);
        if ($before_ids !== $after_ids) {
            $touch('Categories', ops_term_list($before_ids, 'product_cat'), (string) ($plan['raw']['categories'] ?? ''));
            $product->set_category_ids($plan['category_ids']);
        }
    }
    if (is_array($plan['tag_ids'])) {
        $before_ids = array_map('intval', $product->get_tag_ids());
        $after_ids = array_map('intval', $plan['tag_ids']);
        sort($before_ids);
        sort($after_ids);
        if ($before_ids !== $after_ids) {
            $touch('Tags', ops_term_list($before_ids, 'product_tag'), (string) ($plan['raw']['tags'] ?? ''));
            $product->set_tag_ids($plan['tag_ids']);
        }
    }

    if (is_array($plan['featured'])) {
        $before = (int) $product->get_image_id();
        $after = ops_resolve_featured($plan['featured'], $zip_files, $id, $before);
        if ($before !== $after) {
            $touch('Featured image', $before ? (string) wp_get_attachment_url($before) : '', $after ? (string) wp_get_attachment_url($after) : '(clear)');
            $product->set_image_id($after);
            if ($after <= 0) {
                delete_post_thumbnail($id);
                delete_post_meta($id, '_thumbnail_id');
            }
        }
    }
    if (is_array($plan['hover'] ?? null)) {
        $before = (int) get_post_meta($id, 'hover_image', true);
        $after = ops_resolve_featured($plan['hover'], $zip_files, $id, $before);
        if ($before !== $after) {
            $touch('Hover image', $before ? (string) wp_get_attachment_url($before) : '', $after ? (string) wp_get_attachment_url($after) : '(clear)');
            if ($after > 0) {
                update_post_meta($id, 'hover_image', $after);
            } else {
                delete_post_meta($id, 'hover_image');
            }
            if (function_exists('ops_keep_acf_reference')) {
                $acf = function_exists('acf_get_field') ? acf_get_field('hover_image') : null;
                ops_keep_acf_reference($id, 'hover_image', is_array($acf) ? (string) ($acf['key'] ?? '') : '');
            }
        }
    }
    if (is_array($plan['gallery'])) {
        $before = array_map('intval', $product->get_gallery_image_ids());
        $after = ops_resolve_gallery($plan['gallery'], $zip_files, $id);
        if ($before !== $after) {
            $touch('Gallery images', (string) count($before), $after ? (string) count($after) : '(clear)');
            $product->set_gallery_image_ids($after);
            if (!$after) {
                delete_post_meta($id, '_product_image_gallery');
            }
        }
    }

    if ($created || !empty($changes)) {
        $product->save();
    }
    $changes = array_merge($changes, ops_apply_zone($id, $sku, $name, 'eur', 'eurozone', 'EUR regular price', 'EUR sale price', $plan));
    $changes = array_merge($changes, ops_apply_zone($id, $sku, $name, 'usd', 'global', 'USD regular price', 'USD sale price', $plan));
    $changes = array_merge($changes, ops_apply_meta_items($id, $sku, $name, is_array($plan['meta'] ?? null) ? $plan['meta'] : [], $zip_files));
    if (!empty($plan['values']['product_type']) && $plan['values']['product_type'] !== $product->get_type()) {
        wp_set_object_terms($id, (string) $plan['values']['product_type'], 'product_type');
        $changes[] = ops_change_row($id, $sku, $name, 'Product type', $product->get_type(), (string) $plan['values']['product_type']);
    }
    if (is_array($plan['attributes'] ?? null) && function_exists('ops_apply_attribute_choice')) {
        $choice = $plan['attributes'];
        $before = function_exists('ops_attribute_export') ? ops_attribute_export($id) : '';
        $variable = ($plan['values']['product_type'] ?? $product->get_type()) === 'variable';
        ops_apply_attribute_choice($id, $choice['chosen'] ?? [], !empty($choice['clear']), $variable);
        $after = !empty($choice['clear']) ? '(clear)' : (function_exists('ops_attribute_export') ? ops_attribute_export($id) : '');
        if ($before !== $after) {
            $changes[] = ops_change_row($id, $sku, $name, 'Attributes', $before, $after);
        }
    }
    $ka_changed = $created || !empty($changes);
    $changes = array_merge($changes, ops_sync_english_translation($id, $plan, $ka_changed));
    return ['id' => $id, 'created' => $created, 'changes' => $changes];
}

/**
 * Create or update the linked English Polylang product and auto-assign linked EN categories.
 *
 * @param array<string, mixed> $plan
 * @return array<int, array<string, string>>
 */
function ops_sync_english_translation(int $ka_id, array $plan, bool $ka_changed = true): array
{
    $changes = [];
    if ($ka_id <= 0 || !function_exists('pll_get_post') || !function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
        return $changes;
    }
    $vals = is_array($plan['values'] ?? null) ? $plan['values'] : [];
    $has_en_input = (isset($vals['name_en']) && trim((string) $vals['name_en']) !== '')
        || (isset($vals['short_description_en']) && trim((string) $vals['short_description_en']) !== '')
        || (isset($vals['description_en']) && trim((string) $vals['description_en']) !== '');

    $en_id = (int) pll_get_post($ka_id, 'en');
    if ($en_id <= 0 && !$has_en_input) {
        return $changes;
    }

    $ka_product = wc_get_product($ka_id);
    if (!$ka_product instanceof WC_Product) {
        return $changes;
    }
    $ka_type = $ka_product->get_type();
    $en_created = false;

    if ($en_id <= 0) {
        $en_product = $ka_type === 'variable' ? new WC_Product_Variable() : new WC_Product_Simple();
        $en_name = trim((string) ($vals['name_en'] ?? '')) !== '' ? (string) $vals['name_en'] : $ka_product->get_name();
        $en_product->set_name($en_name);
        $en_product->set_status($ka_product->get_status());
        $en_product->set_manage_stock(false);
        $en_product->set_stock_status('instock');
        $en_product->save();
        $en_id = (int) $en_product->get_id();
        pll_set_post_language($en_id, 'en');
        pll_save_post_translations(['ka' => $ka_id, 'en' => $en_id]);
        $en_created = true;
        $changes[] = ops_change_row($en_id, (string) $ka_product->get_sku(), $en_name, 'Product (EN)', '(new)', $en_name);
    } else {
        $loaded = wc_get_product($en_id);
        if (!$loaded instanceof WC_Product) {
            return $changes;
        }
        $en_product = $loaded;
    }

    // Update translatable fields
    $en_text_changed = false;
    if (isset($vals['name_en']) && trim((string) $vals['name_en']) !== '' && (string) $vals['name_en'] !== $en_product->get_name()) {
        $changes[] = ops_change_row($en_id, (string) $ka_product->get_sku(), (string) $vals['name_en'], 'Name (EN)', $en_product->get_name(), (string) $vals['name_en']);
        $en_product->set_name((string) $vals['name_en']);
        $en_text_changed = true;
    }
    if (array_key_exists('short_description_en', $vals) && !ops_descriptions_match((string) $en_product->get_short_description(), (string) $vals['short_description_en'])) {
        $changes[] = ops_change_row($en_id, (string) $ka_product->get_sku(), $en_product->get_name(), 'Short description (EN)', wp_strip_all_tags((string) $en_product->get_short_description()), wp_strip_all_tags((string) $vals['short_description_en']));
        $en_product->set_short_description(ops_kses((string) $vals['short_description_en']));
        $en_text_changed = true;
    }
    if (array_key_exists('description_en', $vals) && !ops_descriptions_match((string) $en_product->get_description(), (string) $vals['description_en'])) {
        $changes[] = ops_change_row($en_id, (string) $ka_product->get_sku(), $en_product->get_name(), 'Description (EN)', wp_strip_all_tags((string) $en_product->get_description()), wp_strip_all_tags((string) $vals['description_en']));
        $en_product->set_description(ops_kses((string) $vals['description_en']));
        $en_text_changed = true;
    }

    // Skip saving & meta sync completely when neither KA nor EN fields changed
    if (!$en_created && !$ka_changed && !$en_text_changed) {
        return $changes;
    }

    // Auto-assign linked English categories from Georgian categories via Polylang
    if (function_exists('pll_get_term')) {
        $en_cat_ids = [];
        foreach ($ka_product->get_category_ids() as $ka_cat_id) {
            $mapped = (int) pll_get_term((int) $ka_cat_id, 'en');
            if ($mapped > 0) {
                $en_cat_ids[] = $mapped;
            }
        }
        $en_cat_ids = array_values(array_unique($en_cat_ids));
        if ($en_cat_ids) {
            $en_product->set_category_ids($en_cat_ids);
        }
    }

    // Snapshot KA pa_* terms before $en_product->save() so Polylang's PLL_Sync_Tax cannot wipe KA terms
    $ka_attrs = get_post_meta($ka_id, '_product_attributes', true);
    $saved_ka_pa_terms = [];
    if (is_array($ka_attrs) && $ka_attrs) {
        foreach ($ka_attrs as $tax => $attr_meta) {
            if (is_string($tax) && str_starts_with($tax, 'pa_') && taxonomy_exists($tax)) {
                $tids = wp_get_object_terms($ka_id, $tax, ['fields' => 'ids']);
                if (is_array($tids) && $tids) {
                    $saved_ka_pa_terms[$tax] = array_map('intval', $tids);
                }
            }
        }
    }

    // Sync shared fields (status, prices, images, hover_image, SKU) — Georgian is source of truth
    $en_product->set_status($ka_product->get_status());
    if ($ka_type === 'variable') {
        $en_product->set_regular_price('');
        $en_product->set_sale_price('');
    } else {
        $en_product->set_regular_price((string) $ka_product->get_regular_price());
        $en_product->set_sale_price((string) $ka_product->get_sale_price());
    }
    $ka_img_id = (int) $ka_product->get_image_id();
    $ka_gal_ids = $ka_product->get_gallery_image_ids();
    $en_product->set_image_id($ka_img_id);
    $en_product->set_gallery_image_ids($ka_gal_ids);
    $ka_sku = (string) $ka_product->get_sku();
    if ($ka_sku !== '' && $en_product->get_sku() !== $ka_sku) {
        update_post_meta($en_id, '_sku', $ka_sku);
    }
    $en_product->save();
    if ($ka_img_id <= 0) {
        delete_post_thumbnail($en_id);
        delete_post_meta($en_id, '_thumbnail_id');
    }
    if (!$ka_gal_ids) {
        delete_post_meta($en_id, '_product_image_gallery');
    }

    if ($en_product->get_type() !== $ka_type) {
        wp_set_object_terms($en_id, $ka_type, 'product_type');
    }
    if ($ka_type === 'variable') {
        delete_post_meta($en_id, '_regular_price');
        delete_post_meta($en_id, '_sale_price');
    }

    // Restore KA pa_* terms if Polylang sync stripped them, then mirror translated EN terms onto $en_id
    foreach ($saved_ka_pa_terms as $tax => $ka_tids) {
        $current_ka = wp_get_object_terms($ka_id, $tax, ['fields' => 'ids']);
        if (!is_array($current_ka) || !$current_ka) {
            wp_set_object_terms($ka_id, $ka_tids, $tax);
        }
    }
    if (is_array($ka_attrs) && $ka_attrs && function_exists('ops_sync_parent_attributes_to_en')) {
        ops_sync_parent_attributes_to_en($ka_id, $en_id);
    }

    // Sync hover_image and EUR/USD pricing zone meta (including clearing when removed on KA)
    $hover_id = (int) get_post_meta($ka_id, 'hover_image', true);
    if ($hover_id > 0) {
        update_post_meta($en_id, 'hover_image', $hover_id);
    } else {
        delete_post_meta($en_id, 'hover_image');
    }
    foreach (['eurozone', 'global'] as $zone) {
        $method_key = '_' . $zone . '_price_method';
        $method_val = get_post_meta($ka_id, $method_key, true);
        if ($method_val !== '' && $method_val !== false) {
            update_post_meta($en_id, $method_key, $method_val);
        } else {
            delete_post_meta($en_id, $method_key);
        }
        foreach (['_regular_price', '_sale_price'] as $suffix) {
            $mkey = '_' . $zone . $suffix;
            $mval = get_post_meta($ka_id, $mkey, true);
            if ($mval !== '' && $mval !== false) {
                update_post_meta($en_id, $mkey, $mval);
            } else {
                delete_post_meta($en_id, $mkey);
            }
        }
        if (function_exists('odella_pricing_sync_one_zone')) {
            odella_pricing_sync_one_zone($en_id, $zone);
        }
    }
    unset($en_created);
    return $changes;
}

/**
 * @param array<string, mixed> $plan
 * @return array<int, array<string, string>>
 */
function ops_apply_zone(int $id, string $sku, string $name, string $prefix, string $zone, string $regular_label, string $sale_label, array $plan): array
{
    $regular_key = $prefix . '_regular';
    $sale_key = $prefix . '_sale';
    $changes = [];
    if (!array_key_exists($regular_key, $plan['values']) && !array_key_exists($sale_key, $plan['values'])) {
        return $changes;
    }
    $before_regular = (string) get_post_meta($id, '_' . $zone . '_regular_price', true);
    $before_sale = (string) get_post_meta($id, '_' . $zone . '_sale_price', true);
    if (array_key_exists($regular_key, $plan['values']) && !ops_prices_equal($before_regular, (string) $plan['values'][$regular_key])) {
        $value = (string) $plan['values'][$regular_key];
        if ($value === '') {
            delete_post_meta($id, '_' . $zone . '_regular_price');
        } else {
            update_post_meta($id, '_' . $zone . '_regular_price', $value);
        }
        $changes[] = ops_change_row($id, $sku, $name, $regular_label, $before_regular, $value === '' ? '(clear)' : $value);
    }
    if (array_key_exists($sale_key, $plan['values']) && !ops_prices_equal($before_sale, (string) $plan['values'][$sale_key])) {
        $value = (string) $plan['values'][$sale_key];
        if ($value === '') {
            delete_post_meta($id, '_' . $zone . '_sale_price');
        } else {
            update_post_meta($id, '_' . $zone . '_sale_price', $value);
        }
        $changes[] = ops_change_row($id, $sku, $name, $sale_label, $before_sale, $value === '' ? '(clear)' : $value);
    }
    if ($changes && function_exists('odella_pricing_sync_one_zone')) {
        odella_pricing_sync_one_zone($id, $zone);
    }
    return $changes;
}

/**
 * @return array{id: string, sku: string, name: string, field: string, before: string, after: string}
 */
function ops_change_row(int $id, string $sku, string $name, string $field, string $before, string $after): array
{
    return [
        'id' => (string) $id,
        'sku' => $sku,
        'name' => $name,
        'field' => $field,
        'before' => $before,
        'after' => $after,
    ];
}

/**
 * @param array<int, int> $ids
 */
function ops_term_list(array $ids, string $taxonomy): string
{
    $names = [];
    foreach ($ids as $term_id) {
        $term = get_term((int) $term_id, $taxonomy);
        if ($term instanceof WP_Term) {
            $names[] = $term->name;
        }
    }
    return implode(', ', $names);
}

/**
 * @param array{clear: bool, items: array<int, array{kind: string, value: string}>} $picture
 * @param array<string, string> $zip_files
 */
function ops_resolve_featured(array $picture, array $zip_files, int $parent, int $current): int
{
    if (!empty($picture['clear'])) {
        return 0;
    }
    $item = $picture['items'][0] ?? null;
    if (!$item) {
        return $current;
    }
    if ($item['kind'] === 'url') {
        $existing = ops_attachment_for_url($item['value']);
        if ($existing > 0) {
            return $existing;
        }
        if ($current && wp_get_attachment_url($current) === $item['value']) {
            return $current;
        }
        return ops_sideload_url($item['value'], $parent);
    }
    $path = $zip_files[mb_strtolower($item['value'], 'UTF-8')] ?? '';
    if ($path === '') {
        throw new RuntimeException($item['value'] . ' is missing from the zip.');
    }
    return ops_sideload_path($path, $parent, (string) ($item['folder'] ?? ''));
}

/**
 * @param array{clear: bool, items: array<int, array{kind: string, value: string, folder?: string}>} $picture
 * @param array<string, string> $zip_files
 * @return array<int, int>
 */
function ops_resolve_gallery(array $picture, array $zip_files, int $parent): array
{
    if (!empty($picture['clear'])) {
        return [];
    }
    $ids = [];
    foreach ($picture['items'] as $item) {
        if ($item['kind'] === 'url') {
            $existing = ops_attachment_for_url($item['value']);
            $ids[] = $existing > 0 ? $existing : ops_sideload_url($item['value'], $parent);
            continue;
        }
        $path = $zip_files[mb_strtolower($item['value'], 'UTF-8')] ?? '';
        if ($path === '') {
            throw new RuntimeException($item['value'] . ' is missing from the zip.');
        }
        $ids[] = ops_sideload_path($path, $parent, (string) ($item['folder'] ?? ''));
    }
    return $ids;
}

function ops_kses(string $html): string
{
    return wp_kses($html, ops_allowed_html());
}

/**
 * @param array<int, array<string, mixed>> $items
 * @param array<string, string> $zip_files
 * @return array<int, array<string, string>>
 */
function ops_apply_meta_items(int $id, string $sku, string $name, array $items, array $zip_files): array
{
    $changes = [];
    foreach ($items as $item) {
        $field = $item['field'] ?? null;
        if (!is_array($field) || empty($field['meta_key'])) {
            continue;
        }
        $meta_key = (string) $field['meta_key'];
        $label = (string) ($field['label'] ?? $meta_key);
        $acf_key = (string) ($field['acf_key'] ?? '');
        if (($field['kind'] ?? '') === 'gallery' && is_array($item['image'] ?? null)) {
            $before_ids = array_map('intval', (array) get_post_meta($id, $meta_key, true));
            $after_ids = ops_resolve_gallery($item['image'], $zip_files, $id);
            if ($before_ids === $after_ids) {
                continue;
            }
            $changes[] = ops_change_row($id, $sku, $name, $label, (string) count($before_ids), $after_ids ? (string) count($after_ids) : '(clear)');
            if ($after_ids) {
                update_post_meta($id, $meta_key, $after_ids);
            } else {
                delete_post_meta($id, $meta_key);
            }
            ops_keep_acf_reference($id, $meta_key, $acf_key);
            continue;
        }
        if (($field['kind'] ?? '') === 'image' && is_array($item['image'] ?? null)) {
            $before_id = (int) get_post_meta($id, $meta_key, true);
            $after_id = ops_resolve_featured($item['image'], $zip_files, $id, $before_id);
            if ($before_id === $after_id) {
                continue;
            }
            $before_url = $before_id ? (string) wp_get_attachment_url($before_id) : '';
            $after_url = $after_id ? (string) wp_get_attachment_url($after_id) : '';
            $changes[] = ops_change_row($id, $sku, $name, $label, $before_url, $after_id ? $after_url : '(clear)');
            if ($after_id > 0) {
                update_post_meta($id, $meta_key, $after_id);
            } else {
                delete_post_meta($id, $meta_key);
            }
            ops_keep_acf_reference($id, $meta_key, $acf_key);
            continue;
        }
        $current = get_post_meta($id, $meta_key, true);
        $before = ops_meta_plain($current);
        if (!empty($item['clear'])) {
            if ($before === '') {
                continue;
            }
            delete_post_meta($id, $meta_key);
            $changes[] = ops_change_row($id, $sku, $name, $label, $before, '(clear)');
            continue;
        }
        $value = (string) ($item['value'] ?? '');
        if ($value === $before) {
            continue;
        }
        if (is_array($current)) {
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                throw new RuntimeException($label . ' on product ' . $id . ' is structured data and the cell is not JSON.');
            }
            update_post_meta($id, $meta_key, $decoded);
        } else {
            update_post_meta($id, $meta_key, $value);
        }
        ops_keep_acf_reference($id, $meta_key, $acf_key);
        $changes[] = ops_change_row($id, $sku, $name, $label, $before, $value);
    }
    return $changes;
}

/**
 * Move Georgian products (and linked English translations) not in $kept_ka_ids to Trash.
 *
 * @param array<int, int> $kept_ka_ids
 * @return array<int, array<string, string>>
 */
function ops_trash_missing_products(array $kept_ka_ids): array
{
    $changes = [];
    if (!$kept_ka_ids) {
        return $changes;
    }
    $kept = array_fill_keys(array_map('intval', $kept_ka_ids), true);
    $all_ids = get_posts([
        'post_type' => 'product',
        'post_status' => ['publish', 'draft', 'private', 'pending'],
        'posts_per_page' => -1,
        'fields' => 'ids',
        'lang' => 'ka',
        'no_found_rows' => true,
    ]);
    if (!is_array($all_ids)) {
        return $changes;
    }
    foreach ($all_ids as $raw_id) {
        $ka_id = (int) $raw_id;
        if ($ka_id <= 0 || isset($kept[$ka_id])) {
            continue;
        }
        $lang = function_exists('ops_product_language') ? ops_product_language($ka_id) : 'ka';
        if ($lang !== '' && $lang !== 'ka') {
            continue;
        }
        $product = wc_get_product($ka_id);
        $sku = $product instanceof WC_Product ? (string) $product->get_sku() : '';
        $name = $product instanceof WC_Product ? $product->get_name() : (string) get_the_title($ka_id);
        $before_status = (string) get_post_status($ka_id);
        wp_trash_post($ka_id);
        $changes[] = ops_change_row($ka_id, $sku, $name, 'Product (missing from file)', $before_status, 'trash');

        if (function_exists('pll_get_post')) {
            $en_id = (int) pll_get_post($ka_id, 'en');
            if ($en_id > 0 && get_post_status($en_id) !== 'trash') {
                $en_before = (string) get_post_status($en_id);
                wp_trash_post($en_id);
                $changes[] = ops_change_row($en_id, $sku, (string) get_the_title($en_id), 'Product (EN, missing from file)', $en_before, 'trash');
            }
        }
    }
    return $changes;
}
