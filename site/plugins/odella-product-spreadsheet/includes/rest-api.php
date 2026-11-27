<?php
/**
 * WordPress REST API (odella-sheet/v1) for OdellaSheetEditor desktop sync:
 * Pull, Batched Push, Purge + Reindex + Hostinger Edge CDN + Cache Warm, Speed Check, and New Category Creation.
 * Authenticated via standard WordPress Application Passwords (manage_woocommerce capability).
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', 'ops_register_rest_routes');

function ops_register_rest_routes(): void
{
    $ns = 'odella-sheet/v1';
    $perm = static function (): bool {
        return current_user_can('manage_woocommerce');
    };

    register_rest_route($ns, '/ping', [
        'methods' => 'GET',
        'callback' => 'ops_rest_ping',
        'permission_callback' => $perm,
    ]);

    register_rest_route($ns, '/pull', [
        'methods' => 'POST',
        'callback' => 'ops_rest_pull',
        'permission_callback' => $perm,
    ]);

    register_rest_route($ns, '/push-start', [
        'methods' => 'POST',
        'callback' => 'ops_rest_push_start',
        'permission_callback' => $perm,
    ]);

    register_rest_route($ns, '/push-step', [
        'methods' => 'POST',
        'callback' => 'ops_rest_push_step',
        'permission_callback' => $perm,
    ]);

    register_rest_route($ns, '/purge-reindex', [
        'methods' => 'POST',
        'callback' => 'ops_rest_purge_reindex',
        'permission_callback' => $perm,
    ]);

    register_rest_route($ns, '/warm-step', [
        'methods' => 'POST',
        'callback' => 'ops_rest_warm_step',
        'permission_callback' => $perm,
    ]);

    register_rest_route($ns, '/speed-check', [
        'methods' => 'GET',
        'callback' => 'ops_rest_speed_check',
        'permission_callback' => $perm,
    ]);

    register_rest_route($ns, '/create-category', [
        'methods' => 'POST',
        'callback' => 'ops_rest_create_category',
        'permission_callback' => $perm,
    ]);

    register_rest_route($ns, '/rename-web-images', [
        'methods' => 'POST',
        'callback' => 'ops_rest_rename_web_images',
        'permission_callback' => $perm,
    ]);
}

/**
 * List Georgian product categories with their full path and linked English path for the desktop app.
 *
 * @return array<int, array{id: int, parent: int, name: string, ka_path: string, en_path: string}>
 */
function ops_rest_category_tree(): array
{
    $args = [
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
    ];
    if (function_exists('pll_languages_list')) {
        $args['lang'] = 'ka';
    }
    $terms = get_terms($args);
    if (is_wp_error($terms) || !is_array($terms)) {
        return [];
    }
    $by_id = [];
    foreach ($terms as $t) {
        if (!$t instanceof WP_Term) {
            continue;
        }
        $by_id[(int) $t->term_id] = [
            'id' => (int) $t->term_id,
            'parent' => (int) $t->parent,
            'name' => html_entity_decode((string) $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];
    }

    $en_by_id = [];
    if (function_exists('pll_get_term')) {
        $en_terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'lang' => 'en']);
        if (is_array($en_terms)) {
            foreach ($en_terms as $et) {
                if ($et instanceof WP_Term) {
                    $en_by_id[(int) $et->term_id] = [
                        'id' => (int) $et->term_id,
                        'parent' => (int) $et->parent,
                        'name' => html_entity_decode((string) $et->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    ];
                }
            }
        }
    }

    $build_path = static function (int $tid, array $map): string {
        $parts = [];
        $cur = $tid;
        $guard = 0;
        while ($cur > 0 && isset($map[$cur]) && $guard < 15) {
            array_unshift($parts, $map[$cur]['name']);
            $cur = (int) $map[$cur]['parent'];
            $guard++;
        }
        return implode('->', $parts);
    };

    $out = [];
    foreach ($by_id as $id => $item) {
        $ka_path = $build_path($id, $by_id);
        $en_path = '';
        if (function_exists('pll_get_term')) {
            $en_id = (int) pll_get_term($id, 'en');
            if ($en_id > 0 && isset($en_by_id[$en_id])) {
                $en_path = $build_path($en_id, $en_by_id);
            }
        }
        $out[] = [
            'id' => $id,
            'parent' => $item['parent'],
            'name' => $item['name'],
            'ka_path' => $ka_path,
            'en_path' => $en_path,
        ];
    }
    usort($out, static fn (array $a, array $b): int => strcasecmp($a['ka_path'], $b['ka_path']));
    return $out;
}

function ops_rest_ping(WP_REST_Request $req): WP_REST_Response
{
    $user = wp_get_current_user();
    $cols = [];
    foreach (ops_columns() as $key => $col) {
        $cols[] = [
            'key' => $key,
            'label' => (string) ($col['label'] ?? $key),
            'default' => !empty($col['default']),
            'required' => in_array($key, ['id', 'sku', 'name'], true),
        ];
    }
    return new WP_REST_Response([
        'ok' => true,
        'user' => $user ? $user->user_login : '',
        'site' => home_url('/'),
        'columns' => $cols,
        'categories' => ops_rest_category_tree(),
    ], 200);
}

function ops_rest_pull(WP_REST_Request $req): WP_REST_Response
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }
    $body = $req->get_json_params();
    $raw_cols = is_array($body['columns'] ?? null) ? $body['columns'] : [];
    $selected = array_values(array_filter(array_map(static function ($k): string {
        return ops_clean_column_key((string) $k);
    }, $raw_cols)));

    if (!$selected) {
        foreach (ops_columns() as $key => $col) {
            if (!empty($col['default'])) {
                $selected[] = $key;
            }
        }
    }

    try {
        $file = ops_build_export(ops_selected_columns($selected, $selected), 'xlsx', 'none', false);
        $path = (string) ($file['path'] ?? '');
        $filename = (string) ($file['filename'] ?? ('odella-products-' . gmdate('Ymd-His') . '.xlsx'));
        if ($path === '' || !is_file($path)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Export file could not be generated.'], 500);
        }
        $bytes = file_get_contents($path);
        @unlink($path);
        if (!is_string($bytes)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Export file could not be read.'], 500);
        }
        return new WP_REST_Response([
            'ok' => true,
            'filename' => $filename,
            'data_base64' => base64_encode($bytes),
        ], 200);
    } catch (Throwable $e) {
        return new WP_REST_Response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

function ops_rest_push_start(WP_REST_Request $req): WP_REST_Response
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }
    $body = $req->get_json_params();
    $xlsx_b64 = (string) ($body['xlsx_base64'] ?? '');
    if ($xlsx_b64 === '') {
        return new WP_REST_Response(['ok' => false, 'error' => 'Missing spreadsheet data.'], 400);
    }
    $xlsx_raw = base64_decode($xlsx_b64, true);
    if (!is_string($xlsx_raw) || $xlsx_raw === '') {
        return new WP_REST_Response(['ok' => false, 'error' => 'Invalid spreadsheet base64 payload.'], 400);
    }

    $tmp_xlsx = rtrim(get_temp_dir(), '\\/') . '/ops-rest-' . wp_generate_password(10, false, false) . '.xlsx';
    file_put_contents($tmp_xlsx, $xlsx_raw);

    $zip_files = [];
    $zip_dir = '';
    $zip_b64 = (string) ($body['zip_base64'] ?? '');
    if ($zip_b64 !== '') {
        $zip_raw = base64_decode($zip_b64, true);
        if (!is_string($zip_raw) || $zip_raw === '') {
            @unlink($tmp_xlsx);
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid image zip payload.'], 400);
        }
        $tmp_zip = rtrim(get_temp_dir(), '\\/') . '/ops-rest-zip-' . wp_generate_password(10, false, false) . '.zip';
        file_put_contents($tmp_zip, $zip_raw);
        $opened = ops_open_image_zip($tmp_zip);
        @unlink($tmp_zip);
        $zip_files = $opened['files'];
        $zip_dir = $opened['dir'];
        if (!empty($opened['errors'])) {
            @unlink($tmp_xlsx);
            ops_cleanup_dir($zip_dir);
            return new WP_REST_Response([
                'ok' => false,
                'errors' => array_map(static fn (string $m): array => ['row' => '', 'column' => 'Images Zip', 'message' => $m], $opened['errors']),
                'warnings' => [],
            ], 200);
        }
    }

    try {
        $book = ops_xlsx_read($tmp_xlsx);
        @unlink($tmp_xlsx);
        if (isset($book['Products'])) {
            $grid = $book['Products'];
        } else {
            $first = reset($book);
            $grid = is_array($first) ? $first : [];
        }
        $variations = $book['Variations'] ?? [];
        $checked = ops_preflight($grid, $zip_files);
        $variation_checked = function_exists('ops_preflight_variations')
            ? ops_preflight_variations($variations, $checked['plans'])
            : ['errors' => [], 'plans' => []];
    } catch (Throwable $error) {
        @unlink($tmp_xlsx);
        ops_cleanup_dir($zip_dir);
        return new WP_REST_Response([
            'ok' => false,
            'errors' => [['row' => '', 'column' => '', 'message' => $error->getMessage()]],
            'warnings' => [],
        ], 200);
    }

    $all_errors = array_merge($checked['errors'], $variation_checked['errors']);
    if ($all_errors) {
        ops_cleanup_dir($zip_dir);
        return new WP_REST_Response([
            'ok' => false,
            'errors' => $all_errors,
            'warnings' => $checked['warnings'],
        ], 200);
    }

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
    $trash_missing = !empty($body['trash_missing']);
    $raw_batch = isset($body['batch_size']) ? (int) $body['batch_size'] : 10;
    $batch_size = max(5, min(25, $raw_batch > 0 ? $raw_batch : 10));

    $job_id = 'job-' . bin2hex(random_bytes(8));
    $job_path = ops_import_job_path($job_id);
    $phase = $total_products > 0 ? 'products' : ($total_variations > 0 ? 'variations' : ($trash_missing ? 'trash' : 'done'));
    $job = [
        'user_id' => get_current_user_id(),
        'ext' => 'xlsx',
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
        return new WP_REST_Response(['ok' => false, 'error' => 'Could not save push batch state on the server.'], 500);
    }

    return new WP_REST_Response([
        'ok' => true,
        'job_id' => $job_id,
        'phase' => $phase,
        'total_products' => $total_products,
        'total_variations' => $total_variations,
        'batch_size' => $batch_size,
        'trash_missing' => $trash_missing,
        'warnings' => $checked['warnings'],
    ], 200);
}

function ops_rest_push_step(WP_REST_Request $req): WP_REST_Response
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(90);
    }
    $body = $req->get_json_params();
    $job_id = sanitize_text_field((string) ($body['job_id'] ?? ''));
    $job_path = ops_import_job_path($job_id);
    if ($job_path === '' || !is_file($job_path)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Push job expired or was not found.'], 404);
    }
    $raw = file_get_contents($job_path);
    $job = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($job) || (int) ($job['user_id'] ?? 0) !== get_current_user_id()) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Invalid push job state.'], 403);
    }

    $batch_size = max(1, min(50, (int) ($job['batch_size'] ?? 10)));

    if ($job['phase'] === 'products') {
        $offset = (int) $job['product_offset'];
        $slice = array_slice($job['plans'], $offset, $batch_size);
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
                ops_cleanup_dir((string) $job['zip_dir']);
                @unlink($job_path);
                return new WP_REST_Response([
                    'ok' => false,
                    'failed' => true,
                    'error' => 'Row ' . $applied['failed_row'] . ' failed: ' . $applied['message'],
                    'report' => $job['report'],
                    'created' => $job['created'],
                    'created_by_sku' => $job['created_by_sku'],
                ], 200);
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
        $g_slice = array_slice($job['variation_groups'], $g_offset, $batch_size);
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
                return new WP_REST_Response([
                    'ok' => false,
                    'failed' => true,
                    'error' => $error->getMessage(),
                    'report' => $job['report'],
                ], 200);
            }
            $job['var_group_offset'] = $g_offset + count($g_slice);
            $job['variations_done'] = (int) $job['variations_done'] + count($v_slice);
        }
        if ($job['var_group_offset'] >= count($job['variation_groups'])) {
            $job['phase'] = !empty($job['trash_missing']) ? 'trash' : 'done';
        }
    } elseif ($job['phase'] === 'trash') {
        if (!empty($job['trash_missing']) && function_exists('ops_trash_missing_products')) {
            $kept = [];
            foreach ($job['plans'] as $p) {
                $pid = (int) ($p['id'] ?? 0);
                if ($pid > 0) {
                    $kept[] = $pid;
                }
            }
            foreach (($job['created'] ?? []) as $new_id) {
                if ((int) $new_id > 0) {
                    $kept[] = (int) $new_id;
                }
            }
            $t_changes = ops_trash_missing_products($kept);
            $job['report'] = array_merge($job['report'], $t_changes);
        }
        $job['phase'] = 'done';
    }

    $done = $job['phase'] === 'done';
    if ($done) {
        ops_cleanup_dir((string) $job['zip_dir']);
        @unlink($job_path);
    } else {
        file_put_contents($job_path, (string) wp_json_encode($job));
    }

    return new WP_REST_Response([
        'ok' => true,
        'done' => $done,
        'phase' => $job['phase'],
        'products_done' => (int) $job['product_offset'],
        'total_products' => (int) $job['total_products'],
        'variations_done' => (int) $job['variations_done'],
        'total_variations' => (int) $job['total_variations'],
        'changes_count' => count($job['report']),
        'created' => $done ? $job['created'] : [],
        'created_by_sku' => $done ? $job['created_by_sku'] : [],
        'report' => $done ? array_slice($job['report'], 0, 200) : [],
        'warnings' => $job['warnings'],
    ], 200);
}

function ops_rest_purge_reindex(WP_REST_Request $req): WP_REST_Response
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }
    $body = $req->get_json_params();
    $messages = [];

    if (function_exists('nmj_product_index_build_all')) {
        $built = nmj_product_index_build_all();
        $messages[] = 'Catalog index rebuilt (' . (int) ($built['count'] ?? 0) . ' products).';
    }
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients();
    }
    if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
        $messages[] = 'Elementor cache cleared.';
    }
    wp_cache_flush();
    do_action('litespeed_purge_all');
    $messages[] = 'WordPress & LiteSpeed page cache purged.';

    // Optional Hostinger Edge CDN purge
    $h_token = trim((string) ($body['hostinger_api_token'] ?? ''));
    $h_user = trim((string) ($body['hostinger_username'] ?? 'u981153228')) ?: 'u981153228';
    $domain = (string) parse_url(home_url('/'), PHP_URL_HOST);
    if ($h_token !== '' && $domain !== '') {
        $cdn_url = 'https://developers.hostinger.com/api/hosting/v1/accounts/' . rawurlencode($h_user) . '/websites/' . rawurlencode($domain) . '/cdn/purge';
        $resp = wp_remote_post($cdn_url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $h_token,
                'Accept' => 'application/json',
            ],
        ]);
        if (is_wp_error($resp)) {
            $messages[] = 'Hostinger CDN purge warning: ' . $resp->get_error_message();
        } else {
            $code = (int) wp_remote_retrieve_response_code($resp);
            if ($code >= 200 && $code < 300) {
                $messages[] = 'Hostinger Edge CDN purged (HTTP ' . $code . ').';
            } elseif ($code === 422) {
                $messages[] = 'Hostinger Edge CDN is currently off (HTTP 422).';
            } else {
                $messages[] = 'Hostinger Edge CDN returned HTTP ' . $code . '.';
            }
        }
    }

    // Prepare URL list for batched cache warming
    $warm_urls = [];
    if (function_exists('nmj_cache_warmer_collect_urls')) {
        $warm_urls = array_values(array_unique(nmj_cache_warmer_collect_urls()));
    }
    if (!$warm_urls) {
        $warm_urls = [home_url('/ka/'), home_url('/ka/store/'), home_url('/en/'), home_url('/en/store/')];
    }
    $warm_token = 'warm-' . bin2hex(random_bytes(6));
    set_transient('ops_warm_' . $warm_token, $warm_urls, 15 * MINUTE_IN_SECONDS);

    return new WP_REST_Response([
        'ok' => true,
        'messages' => $messages,
        'warm_token' => $warm_token,
        'warm_total' => count($warm_urls),
    ], 200);
}

function ops_rest_warm_step(WP_REST_Request $req): WP_REST_Response
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(60);
    }
    $body = $req->get_json_params();
    $token = sanitize_text_field((string) ($body['warm_token'] ?? ''));
    $offset = max(0, (int) ($body['offset'] ?? 0));
    $limit = max(1, min(12, (int) ($body['limit'] ?? 6)));

    $urls = get_transient('ops_warm_' . $token);
    if (!is_array($urls)) {
        return new WP_REST_Response(['ok' => true, 'done' => true, 'warmed' => 0, 'total' => 0, 'hits' => 0, 'misses' => 0], 200);
    }
    $total = count($urls);
    $slice = array_slice($urls, $offset, $limit);
    $hits = 0;
    $misses = 0;

    if ($slice) {
        if (function_exists('nmj_cache_warmer_warm_slice')) {
            $res = nmj_cache_warmer_warm_slice($slice);
            $hits = (int) ($res['hits'] ?? 0);
            $misses = (int) ($res['misses'] ?? 0);
        } else {
            foreach ($slice as $u) {
                $r = wp_remote_get($u, ['timeout' => 12, 'redirection' => 2]);
                if (!is_wp_error($r)) {
                    $ls = strtolower((string) wp_remote_retrieve_header($r, 'x-litespeed-cache'));
                    if (str_contains($ls, 'hit')) {
                        $hits++;
                    } else {
                        $misses++;
                    }
                }
            }
        }
    }

    $next_offset = $offset + count($slice);
    $done = $next_offset >= $total;
    if ($done) {
        delete_transient('ops_warm_' . $token);
    }

    return new WP_REST_Response([
        'ok' => true,
        'done' => $done,
        'warmed' => min($next_offset, $total),
        'total' => $total,
        'hits' => $hits,
        'misses' => $misses,
    ], 200);
}

function ops_rest_speed_check(WP_REST_Request $req): WP_REST_Response
{
    $urls = [
        home_url('/ka/'),
        home_url('/ka/store/'),
        home_url('/en/store/'),
    ];
    $results = [];
    foreach ($urls as $u) {
        $t0 = microtime(true);
        $resp = wp_remote_get($u, ['timeout' => 15, 'redirection' => 2]);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if (is_wp_error($resp)) {
            $results[] = ['url' => $u, 'status' => 0, 'ms' => $ms, 'cache' => 'ERROR', 'error' => $resp->get_error_message()];
        } else {
            $code = (int) wp_remote_retrieve_response_code($resp);
            $ls = (string) wp_remote_retrieve_header($resp, 'x-litespeed-cache');
            $cf = (string) wp_remote_retrieve_header($resp, 'cf-cache-status');
            $cache = $ls !== '' ? ('LS: ' . $ls) : ($cf !== '' ? ('CDN: ' . $cf) : 'n/a');
            $results[] = ['url' => $u, 'status' => $code, 'ms' => $ms, 'cache' => $cache];
        }
    }
    return new WP_REST_Response(['ok' => true, 'results' => $results], 200);
}

function ops_rest_create_category(WP_REST_Request $req): WP_REST_Response
{
    $body = $req->get_json_params();
    $name_ka = trim((string) ($body['name_ka'] ?? ''));
    $name_en = trim((string) ($body['name_en'] ?? ''));
    $parent_ka = max(0, (int) ($body['parent_ka_id'] ?? 0));

    if ($name_ka === '' || $name_en === '') {
        return new WP_REST_Response(['ok' => false, 'error' => 'Both Georgian and English category names are required.'], 400);
    }

    $parent_en = 0;
    if ($parent_ka > 0) {
        $p_term = get_term($parent_ka, 'product_cat');
        if (!$p_term instanceof WP_Term) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Selected Georgian parent category was not found.'], 404);
        }
        if (function_exists('pll_get_term')) {
            $parent_en = (int) pll_get_term($parent_ka, 'en');
        }
    }

    $ka_res = wp_insert_term($name_ka, 'product_cat', ['parent' => $parent_ka]);
    if (is_wp_error($ka_res)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'Georgian category error: ' . $ka_res->get_error_message()], 400);
    }
    $ka_id = (int) $ka_res['term_id'];
    if (function_exists('pll_set_term_language')) {
        pll_set_term_language($ka_id, 'ka');
    }

    $en_res = wp_insert_term($name_en, 'product_cat', ['parent' => $parent_en]);
    if (is_wp_error($en_res)) {
        wp_delete_term($ka_id, 'product_cat');
        return new WP_REST_Response(['ok' => false, 'error' => 'English category error: ' . $en_res->get_error_message()], 400);
    }
    $en_id = (int) $en_res['term_id'];
    if (function_exists('pll_set_term_language')) {
        pll_set_term_language($en_id, 'en');
    }
    if (function_exists('pll_save_term_translations')) {
        pll_save_term_translations(['ka' => $ka_id, 'en' => $en_id]);
    }

    $tree = ops_rest_category_tree();
    $created_item = null;
    foreach ($tree as $item) {
        if ((int) $item['id'] === $ka_id) {
            $created_item = $item;
            break;
        }
    }

    return new WP_REST_Response([
        'ok' => true,
        'ka_id' => $ka_id,
        'en_id' => $en_id,
        'ka_path' => $created_item ? $created_item['ka_path'] : $name_ka,
        'en_path' => $created_item ? $created_item['en_path'] : $name_en,
        'categories' => $tree,
    ], 200);
}

/**
 * Resolve a WordPress attachment ID from a public upload URL (including -scaled or query strings).
 */
function ops_find_attachment_by_url(string $url): int
{
    global $wpdb;
    $clean = trim(preg_replace('/\?.*$/', '', $url));
    if ($clean === '') {
        return 0;
    }
    $id = ops_attachment_for_url($clean);
    if ($id > 0) {
        return $id;
    }
    $alt = str_starts_with($clean, 'https://')
        ? 'http://' . substr($clean, 8)
        : (str_starts_with($clean, 'http://') ? 'https://' . substr($clean, 7) : $clean);
    if ($alt !== $clean) {
        $id = ops_attachment_for_url($alt);
        if ($id > 0) {
            return $id;
        }
    }
    $uploads = wp_get_upload_dir();
    $baseurl = rtrim((string) ($uploads['baseurl'] ?? ''), '/');
    if ($baseurl !== '') {
        $rel = '';
        if (str_starts_with($clean, $baseurl . '/')) {
            $rel = ltrim(substr($clean, strlen($baseurl)), '/');
        } elseif (preg_match('#/wp-content/uploads/(.+)$#i', $clean, $m)) {
            $rel = ltrim($m[1], '/');
        }
        if ($rel !== '') {
            $rel_decoded = rawurldecode($rel);
            $found = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND (meta_value = %s OR meta_value = %s) LIMIT 1",
                    $rel,
                    $rel_decoded
                )
            );
            if ($found > 0) {
                return $found;
            }
            // Fallback: strip -scaled suffix
            $unscaled = preg_replace('/-scaled(\.[^.]+)$/i', '$1', $rel_decoded);
            if ($unscaled !== $rel_decoded) {
                $found = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
                        $unscaled
                    )
                );
                if ($found > 0) {
                    return $found;
                }
            }
        }
    }
    return 0;
}

/**
 * Rename already-uploaded WordPress media files in-place while keeping Attachment ID, FileBird folder & subsizes.
 */
function ops_rest_rename_web_images(WP_REST_Request $req): WP_REST_Response
{
    global $wpdb;
    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }
    $body = $req->get_json_params();
    $items = is_array($body['items'] ?? null) ? $body['items'] : [];
    if (empty($items)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'No images to rename.'], 400);
    }

    $uploads = wp_get_upload_dir();
    $basedir = wp_normalize_path(rtrim((string) ($uploads['basedir'] ?? ''), '/\\'));
    $baseurl = rtrim((string) ($uploads['baseurl'] ?? ''), '/');

    $results = [];
    $renamed = 0;
    $unchanged = 0;
    $errors = 0;

    foreach ($items as $item) {
        $old_url = trim((string) ($item['url'] ?? ''));
        $desired_raw = trim((string) ($item['new_filename'] ?? ''));
        if ($old_url === '' || $desired_raw === '') {
            $errors++;
            $results[] = ['old_url' => $old_url, 'new_url' => $old_url, 'status' => 'error', 'error' => 'Missing URL or target filename.'];
            continue;
        }

        $att_id = ops_find_attachment_by_url($old_url);
        if ($att_id <= 0) {
            $errors++;
            $results[] = ['old_url' => $old_url, 'new_url' => $old_url, 'status' => 'error', 'error' => 'Attachment not found in WordPress Media Library.'];
            continue;
        }

        $old_path = get_attached_file($att_id, true);
        if (!is_string($old_path) || $old_path === '' || !is_file($old_path)) {
            $errors++;
            $results[] = ['old_url' => $old_url, 'new_url' => $old_url, 'attachment_id' => $att_id, 'status' => 'error', 'error' => 'Media file not found on disk.'];
            continue;
        }

        $dir = dirname($old_path);
        $old_base = basename($old_path);
        $old_ext = strtolower((string) pathinfo($old_base, PATHINFO_EXTENSION));
        $old_stem = (string) pathinfo($old_base, PATHINFO_FILENAME);

        $desired_stem = (string) pathinfo($desired_raw, PATHINFO_FILENAME);
        $desired_clean = sanitize_file_name($desired_stem . ($old_ext !== '' ? '.' . $old_ext : ''));
        if ($desired_clean === '' || $desired_clean === '.' . $old_ext) {
            $errors++;
            $results[] = ['old_url' => $old_url, 'new_url' => $old_url, 'attachment_id' => $att_id, 'status' => 'error', 'error' => 'Target filename became empty after sanitization.'];
            continue;
        }

        if (strtolower($old_base) === strtolower($desired_clean)) {
            $unchanged++;
            $cur_url = wp_get_attachment_url($att_id) ?: $old_url;
            $results[] = ['old_url' => $old_url, 'new_url' => $cur_url, 'attachment_id' => $att_id, 'status' => 'unchanged'];
            continue;
        }

        $unique_base = wp_unique_filename($dir, $desired_clean);
        $new_path = $dir . '/' . $unique_base;
        $new_stem = (string) pathinfo($unique_base, PATHINFO_FILENAME);

        if (!@rename($old_path, $new_path)) {
            $errors++;
            $results[] = ['old_url' => $old_url, 'new_url' => $old_url, 'attachment_id' => $att_id, 'status' => 'error', 'error' => 'Failed to rename file on disk (permissions or locked).'];
            continue;
        }

        // Rename companion .webp for main file if present (LiteSpeed / WebP converters)
        if (is_file($old_path . '.webp')) {
            @rename($old_path . '.webp', $new_path . '.webp');
        }

        $meta = wp_get_attachment_metadata($att_id);
        if (!is_array($meta)) {
            $meta = [];
        }

        // Rename WordPress generated thumbnail subsizes
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size_key => $size_info) {
                if (!is_array($size_info) || empty($size_info['file'])) {
                    continue;
                }
                $sub_old_base = basename((string) $size_info['file']);
                $sub_old_path = $dir . '/' . $sub_old_base;
                if (!is_file($sub_old_path)) {
                    continue;
                }
                $sub_ext = (string) pathinfo($sub_old_base, PATHINFO_EXTENSION);
                $w = (int) ($size_info['width'] ?? 0);
                $h = (int) ($size_info['height'] ?? 0);
                $sub_new_base = ($w > 0 && $h > 0)
                    ? "{$new_stem}-{$w}x{$h}.{$sub_ext}"
                    : (str_starts_with($sub_old_base, $old_stem) ? ($new_stem . substr($sub_old_base, strlen($old_stem))) : "{$new_stem}-{$size_key}.{$sub_ext}");
                $sub_new_base = wp_unique_filename($dir, $sub_new_base);
                $sub_new_path = $dir . '/' . $sub_new_base;
                if (@rename($sub_old_path, $sub_new_path)) {
                    $meta['sizes'][$size_key]['file'] = $sub_new_base;
                    if (is_file($sub_old_path . '.webp')) {
                        @rename($sub_old_path . '.webp', $sub_new_path . '.webp');
                    }
                }
            }
        }

        // Rename original_image if WordPress created a -scaled version
        if (!empty($meta['original_image']) && is_string($meta['original_image'])) {
            $orig_old_base = basename($meta['original_image']);
            $orig_old_path = $dir . '/' . $orig_old_base;
            if (is_file($orig_old_path) && $orig_old_path !== $new_path) {
                $orig_ext = (string) pathinfo($orig_old_base, PATHINFO_EXTENSION);
                $orig_target = wp_unique_filename($dir, $new_stem . '-original.' . ($orig_ext ?: $old_ext));
                if (@rename($orig_old_path, $dir . '/' . $orig_target)) {
                    $meta['original_image'] = $orig_target;
                }
            }
        }

        // Compute relative upload path and new public URL
        $norm_new = wp_normalize_path($new_path);
        $rel_new = str_starts_with($norm_new, $basedir . '/')
            ? ltrim(substr($norm_new, strlen($basedir)), '/')
            : ltrim((string) _wp_relative_upload_path($new_path), '/');

        update_attached_file($att_id, $new_path);
        $meta['file'] = $rel_new;
        wp_update_attachment_metadata($att_id, $meta);

        $new_url = $baseurl !== '' ? ($baseurl . '/' . $rel_new) : (wp_get_attachment_url($att_id) ?: $old_url);

        // Update attachment post title, slug & guid
        $new_title = str_replace(['_', '.'], ' ', $new_stem);
        $new_slug = wp_unique_post_slug(sanitize_title($new_stem), $att_id, 'inherit', 'attachment', 0);
        $wpdb->update(
            $wpdb->posts,
            [
                'post_title' => $new_title,
                'post_name' => $new_slug,
                'guid' => $new_url,
            ],
            ['ID' => $att_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        clean_post_cache($att_id);

        // If any product meta stores literal URL (e.g. custom hover image URLs), update them too
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s",
                $old_url,
                $new_url,
                '%' . $wpdb->esc_like($old_url) . '%'
            )
        );

        $renamed++;
        $results[] = [
            'old_url' => $old_url,
            'new_url' => $new_url,
            'new_filename' => $unique_base,
            'attachment_id' => $att_id,
            'status' => 'renamed',
        ];
    }

    return new WP_REST_Response([
        'ok' => true,
        'renamed' => $renamed,
        'unchanged' => $unchanged,
        'errors' => $errors,
        'results' => $results,
    ], 200);
}
