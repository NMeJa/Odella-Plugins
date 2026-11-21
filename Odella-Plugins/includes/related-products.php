<?php
/**
 * Related products: deepest product category, then its parent.
 *
 * Tags are ignored. The list is published, in-stock, catalog-visible,
 * priced products. A short list is topped up from the parent term only.
 */

defined('ABSPATH') || exit;

add_filter('woocommerce_product_related_posts_relate_by_tag', '__return_false');
add_filter('woocommerce_related_products', 'nmj_related_products', 20, 3);
add_action('save_post_product', 'nmj_related_products_clear');

/**
 * @param array<int, int|string> $ids
 * @param array<string, mixed>   $args
 * @return array<int, int>
 */
function nmj_related_products($ids, $product_id, $args): array
{
    $product_id = (int) $product_id;
    if ($product_id <= 0) {
        return [];
    }

    $limit = 4;
    if (is_array($args) && isset($args['limit']) && is_numeric($args['limit'])) {
        $parsed = (int) $args['limit'];
        if ($parsed > 0) {
            $limit = $parsed;
        }
    }

    $lang = function_exists('nmj_current_language') ? nmj_current_language() : 'ka';
    $key = 'nmj_related_' . $product_id . '_' . $lang;
    $cached = get_transient($key);
    if (is_array($cached)) {
        return array_slice(array_map('intval', $cached), 0, $limit);
    }

    $found = nmj_related_product_ids($product_id, $limit);
    set_transient($key, $found, 6 * HOUR_IN_SECONDS);

    return $found;
}

function nmj_related_products_clear($post_id): void
{
    $post_id = (int) $post_id;
    if ($post_id <= 0 || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    $langs = ['ka', 'en'];
    if (function_exists('pll_languages_list')) {
        $listed = pll_languages_list();
        if (is_array($listed)) {
            $langs = array_values(array_unique(array_merge($langs, $listed)));
        }
    }

    foreach ($langs as $lang) {
        if (is_string($lang) && $lang !== '') {
            delete_transient('nmj_related_' . $post_id . '_' . $lang);
        }
    }
}

/**
 * @return array<int, int>
 */
function nmj_related_product_ids(int $product_id, int $limit): array
{
    $term = nmj_related_deepest_term($product_id);
    if (!$term instanceof WP_Term) {
        return [];
    }

    $picked = nmj_related_pick($term->term_id, [$product_id], false, $limit);
    $short = $limit - count($picked);
    if ($short > 0 && (int) $term->parent > 0) {
        $exclude = array_merge([$product_id], $picked);
        $picked = array_merge(
            $picked,
            nmj_related_pick((int) $term->parent, $exclude, true, $short)
        );
    }

    return array_values(array_map('intval', $picked));
}

function nmj_related_deepest_term(int $product_id): ?WP_Term
{
    $terms = get_the_terms($product_id, 'product_cat');
    if (!is_array($terms) || $terms === []) {
        return null;
    }

    $best = null;
    $best_depth = -1;
    $best_order = PHP_INT_MAX;

    foreach ($terms as $term) {
        if (!$term instanceof WP_Term) {
            continue;
        }
        $depth = count(get_ancestors($term->term_id, 'product_cat'));
        $order = isset($term->term_order) ? (int) $term->term_order : 0;
        if ($depth > $best_depth || ($depth === $best_depth && $order < $best_order)) {
            $best = $term;
            $best_depth = $depth;
            $best_order = $order;
        }
    }

    return $best;
}

/**
 * @param array<int, int> $exclude
 * @return array<int, int>
 */
function nmj_related_pick(int $term_id, array $exclude, bool $include_children, int $limit): array
{
    if ($term_id <= 0 || $limit <= 0) {
        return [];
    }

    $tax = [[
        'taxonomy'         => 'product_cat',
        'field'            => 'term_id',
        'terms'            => [$term_id],
        'include_children' => $include_children,
    ]];

    if (taxonomy_exists('product_visibility')) {
        $tax[] = [
            'taxonomy' => 'product_visibility',
            'field'    => 'name',
            'terms'    => ['exclude-from-catalog'],
            'operator' => 'NOT IN',
        ];
    }

    $query = [
        'post_type'              => 'product',
        'post_status'            => 'publish',
        'posts_per_page'         => -1,
        'fields'                 => 'ids',
        'post__not_in'           => array_map('intval', $exclude),
        'tax_query'              => $tax,
        'no_found_rows'          => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ];

    if (function_exists('pll_current_language')) {
        $lang = function_exists('nmj_current_language') ? nmj_current_language() : 'ka';
        $query['lang'] = $lang;
    }

    $found = new WP_Query($query);
    $ids = [];
    foreach ($found->posts as $post_id) {
        $post_id = (int) $post_id;
        if (nmj_related_product_ok($post_id)) {
            $ids[] = $post_id;
        }
    }

    shuffle($ids);

    return array_slice($ids, 0, $limit);
}

function nmj_related_product_ok(int $product_id): bool
{
    if (!function_exists('wc_get_product')) {
        return false;
    }

    $product = wc_get_product($product_id);
    if (!$product instanceof WC_Product) {
        return false;
    }
    if ($product->get_stock_status() !== 'instock') {
        return false;
    }

    $visibility = $product->get_catalog_visibility();
    if ($visibility !== 'visible' && $visibility !== 'catalog') {
        return false;
    }

    if (function_exists('odella_product_has_featured_image')) {
        if (!odella_product_has_featured_image($product)) {
            return false;
        }
    } elseif ((int) $product->get_image_id() <= 0) {
        return false;
    }

    if (function_exists('odella_product_has_price')) {
        return odella_product_has_price($product);
    }

    if ($product->is_type('variable')) {
        foreach ($product->get_children() as $child_id) {
            $child = wc_get_product($child_id);
            if ($child instanceof WC_Product && (string) $child->get_regular_price() !== '') {
                return true;
            }
        }
        return false;
    }

    return (string) $product->get_regular_price() !== '';
}
