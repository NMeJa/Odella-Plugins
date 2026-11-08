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

add_action('template_redirect', 'nmj_legacy_redirects', 1);
add_filter('woocommerce_breadcrumb_main_term', 'nmj_breadcrumb_main_term', 10, 2);

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
