<?php
/**
 * Legacy shop redirects.
 *
 * Rank Math's redirections module is off, so the headscarf move is handled
 * here. Phase 3D adds the filter query to this file.
 */

defined('ABSPATH') || exit;

/**
 * Old path => new absolute URL. Paths are the pre-move permalinks.
 *
 * @var array<string, string>
 */
$GLOBALS['odella_redirects'] = [
    '/ka/product-category/%e1%83%90%e1%83%91%e1%83%a0%e1%83%94%e1%83%a8%e1%83%a3%e1%83%9b%e1%83%98%e1%83%a1-%e1%83%97%e1%83%90%e1%83%95%e1%83%a1%e1%83%90%e1%83%91%e1%83%a3%e1%83%a0%e1%83%90%e1%83%95%e1%83%98/'
        => 'https://odella.ge/ka/product-category/women/scarf-women/headscarf-women/',
    '/ka/product/women/scarf-women/square-scarf-women/%e1%83%90%e1%83%91%e1%83%a0%e1%83%94%e1%83%a8%e1%83%a3%e1%83%9b%e1%83%98%e1%83%a1-%e1%83%97%e1%83%90%e1%83%95%e1%83%a1%e1%83%90%e1%83%91%e1%83%a3%e1%83%a0%e1%83%90%e1%83%95%e1%83%98-oscen/'
        => 'https://odella.ge/ka/product/women/scarf-women/headscarf-women/%e1%83%90%e1%83%91%e1%83%a0%e1%83%94%e1%83%a8%e1%83%a3%e1%83%9b%e1%83%98%e1%83%a1-%e1%83%97%e1%83%90%e1%83%95%e1%83%a1%e1%83%90%e1%83%91%e1%83%a3%e1%83%a0%e1%83%90%e1%83%95%e1%83%98-oscen/',
    '/ka/product/women/scarf-women/square-scarf-women/%e1%83%90%e1%83%91%e1%83%a0%e1%83%94%e1%83%a8%e1%83%a3%e1%83%9b%e1%83%98%e1%83%a1-%e1%83%97%e1%83%90%e1%83%95%e1%83%a1%e1%83%90%e1%83%91%e1%83%a3%e1%83%a0%e1%83%90%e1%83%95%e1%83%98-juin/'
        => 'https://odella.ge/ka/product/women/scarf-women/headscarf-women/%e1%83%90%e1%83%91%e1%83%a0%e1%83%94%e1%83%a8%e1%83%a3%e1%83%9b%e1%83%98%e1%83%a1-%e1%83%97%e1%83%90%e1%83%95%e1%83%a1%e1%83%90%e1%83%91%e1%83%a3%e1%83%a0%e1%83%90%e1%83%95%e1%83%98-juin/',
    '/ka/product/women/scarf-women/square-scarf-women/%e1%83%90%e1%83%91%e1%83%a0%e1%83%94%e1%83%a8%e1%83%a3%e1%83%9b%e1%83%98%e1%83%a1-%e1%83%97%e1%83%90%e1%83%95%e1%83%a1%e1%83%90%e1%83%91%e1%83%a3%e1%83%a0%e1%83%90%e1%83%95%e1%83%98-cafard/'
        => 'https://odella.ge/ka/product/women/scarf-women/headscarf-women/%e1%83%90%e1%83%91%e1%83%a0%e1%83%94%e1%83%a8%e1%83%a3%e1%83%9b%e1%83%98%e1%83%a1-%e1%83%97%e1%83%90%e1%83%95%e1%83%a1%e1%83%90%e1%83%91%e1%83%a3%e1%83%a0%e1%83%90%e1%83%95%e1%83%98-cafard/',
    '/ka/product/women/scarf-women/square-scarf-women/%e1%83%90%e1%83%91%e1%83%a0%e1%83%94%e1%83%a8%e1%83%a3%e1%83%9b%e1%83%98%e1%83%a1-%e1%83%97%e1%83%90%e1%83%95%e1%83%a1%e1%83%90%e1%83%91%e1%83%a3%e1%83%a0%e1%83%90%e1%83%95%e1%83%98-vineyard/'
        => 'https://odella.ge/ka/product/women/scarf-women/headscarf-women/%e1%83%90%e1%83%91%e1%83%a0%e1%83%94%e1%83%a8%e1%83%a3%e1%83%9b%e1%83%98%e1%83%a1-%e1%83%97%e1%83%90%e1%83%95%e1%83%a1%e1%83%90%e1%83%91%e1%83%a3%e1%83%a0%e1%83%90%e1%83%95%e1%83%98-vineyard/',
    '/ka/product/women/scarf-women/square-scarf-women/%e1%83%90%e1%83%91%e1%83%a0%e1%83%94%e1%83%a8%e1%83%a3%e1%83%9b%e1%83%98%e1%83%a1-%e1%83%97%e1%83%90%e1%83%95%e1%83%a1%e1%83%90%e1%83%91%e1%83%a3%e1%83%a0%e1%83%90%e1%83%95%e1%83%98-morango/'
        => 'https://odella.ge/ka/product/women/scarf-women/headscarf-women/%e1%83%90%e1%83%91%e1%83%a0%e1%83%94%e1%83%a8%e1%83%a3%e1%83%9b%e1%83%98%e1%83%a1-%e1%83%97%e1%83%90%e1%83%95%e1%83%a1%e1%83%90%e1%83%91%e1%83%a3%e1%83%a0%e1%83%90%e1%83%95%e1%83%98-morango/',
    '/ka/product/women/scarf-women/square-scarf-women/%e1%83%90%e1%83%91%e1%83%a0%e1%83%94%e1%83%a8%e1%83%a3%e1%83%9b%e1%83%98%e1%83%a1-%e1%83%97%e1%83%90%e1%83%95%e1%83%a1%e1%83%90%e1%83%91%e1%83%a3%e1%83%a0%e1%83%90%e1%83%95%e1%83%98-filo/'
        => 'https://odella.ge/ka/product/women/scarf-women/headscarf-women/%e1%83%90%e1%83%91%e1%83%a0%e1%83%94%e1%83%a8%e1%83%a3%e1%83%9b%e1%83%98%e1%83%a1-%e1%83%97%e1%83%90%e1%83%95%e1%83%a1%e1%83%90%e1%83%91%e1%83%a3%e1%83%a0%e1%83%90%e1%83%95%e1%83%98-filo/',
];

add_action('template_redirect', 'nmj_swoof_redirect', 1);
add_action('template_redirect', 'nmj_legacy_redirects', 1);
add_action('pre_get_posts', 'nmj_catalog_search', 20);
add_filter('posts_search', 'nmj_catalog_sku_search', 10, 2);
add_filter('woocommerce_breadcrumb_main_term', 'nmj_breadcrumb_main_term', 10, 2);

function nmj_store_url(?string $lang = null): string
{
    $id = 16217;
    if (function_exists('pll_get_post')) {
        $translated = $lang ? pll_get_post($id, $lang) : pll_get_post($id);
        if ($translated) {
            $id = (int) $translated;
        }
    }
    $link = get_permalink($id);
    if (is_string($link) && $link !== '') {
        return $link;
    }
    if (function_exists('wc_get_page_permalink')) {
        $shop = wc_get_page_permalink('shop');
        if (is_string($shop) && $shop !== '') {
            return $shop;
        }
    }
    return home_url('/');
}

function nmj_catalog_search($query): void
{
    if (!($query instanceof WP_Query) || is_admin() || !$query->is_main_query()) {
        return;
    }
    $is_catalog = $query->is_tax('product_cat') || $query->is_post_type_archive('product');
    if (!$is_catalog && function_exists('is_shop')) {
        $is_catalog = is_shop() || (function_exists('is_product_category') && is_product_category());
    }
    if (!$is_catalog) {
        return;
    }
    $term = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
    if ($term === '') {
        return;
    }
    $query->set('s', $term);
    $query->set('nmj_sku_search', $term);
}

function nmj_catalog_sku_search(string $search, $query): string
{
    if (!($query instanceof WP_Query) || $search === '') {
        return $search;
    }
    $sku = $query->get('nmj_sku_search');
    if (!is_string($sku) || $sku === '') {
        return $search;
    }
    global $wpdb;
    $extra = $wpdb->prepare(
        " OR {$wpdb->posts}.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s)",
        $sku
    );
    return preg_replace('/\)\s*$/', $extra . ')', $search, 1) ?? $search;
}

function nmj_swoof_redirect(): void
{
    $path = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (!is_string($path) || !preg_match('#^/(ka|en)/store/swoof/(.*)$#', rawurldecode($path), $match)) {
        return;
    }
    $lang = $match[1];
    $target = nmj_store_url($lang);
    if (preg_match('/product_cat-([^\/,]+)/', $match[2], $cat)) {
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'slug' => $cat[1],
            'hide_empty' => false,
            'lang' => $lang,
        ]);
        if (is_array($terms) && isset($terms[0]) && $terms[0] instanceof WP_Term) {
            $link = get_term_link($terms[0]);
            if (!is_wp_error($link)) {
                $target = $link;
            }
        }
    }
    $keep = [];
    if (isset($_GET['q']) && (string) $_GET['q'] !== '') {
        $keep['q'] = sanitize_text_field(wp_unslash($_GET['q']));
    }
    if (isset($_GET['orderby']) && (string) $_GET['orderby'] !== '') {
        $keep['orderby'] = sanitize_text_field(wp_unslash($_GET['orderby']));
    }
    if ($keep !== []) {
        $target = add_query_arg($keep, $target);
    }
    wp_safe_redirect($target, 301);
    exit;
}

function nmj_legacy_redirects(): void
{
    $odella_redirects = $GLOBALS['odella_redirects'] ?? null;
    if (!is_array($odella_redirects) || $odella_redirects === []) {
        return;
    }

    $request = wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (!is_string($request) || $request === '') {
        return;
    }
    $request = trailingslashit(rawurldecode($request));

    foreach ($odella_redirects as $from => $to) {
        if (!is_string($from) || !is_string($to) || $to === '') {
            continue;
        }
        if (trailingslashit(rawurldecode($from)) !== $request) {
            continue;
        }
        wp_safe_redirect($to, 301);
        exit;
    }
}

/**
 * Headscarf products are also in Square scarf. Prefer Headscarf so the
 * trail is Women › Scarf › Headscarf.
 *
 * @param WP_Term        $term
 * @param array<WP_Term> $terms
 */
function nmj_breadcrumb_main_term($term, $terms)
{
    if (!is_array($terms)) {
        return $term;
    }
    foreach ($terms as $candidate) {
        if ($candidate instanceof WP_Term && (int) $candidate->term_id === 684) {
            return $candidate;
        }
    }
    return $term;
}
