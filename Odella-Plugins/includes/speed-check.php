<?php
/**
 * Speed Check diagnostics for the Odella admin screen.
 *
 * Nine server checks, two PageSpeed waves, and a Gemini prompt.
 * API keys stay in the encrypted settings option and are never printed.
 */

defined('ABSPATH') || exit;

add_action('admin_enqueue_scripts', 'odella_speed_check_assets');
add_action('wp_ajax_odella_speed_check', 'odella_speed_check_ajax');

function odella_speed_settings_url(): string
{
    return admin_url('admin.php?page=odella-settings1');
}

function odella_speed_check_assets(string $hook_suffix): void
{
    unset($hook_suffix);
    if (!isset($_GET['page']) || !is_string($_GET['page']) || $_GET['page'] !== 'odella-speed-check') {
        return;
    }
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    if (!defined('NMJ_PATH') || !defined('NMJ_URL') || !defined('NMJ_VERSION')) {
        return;
    }

    $css = NMJ_PATH . 'assets/css/speed-check.css';
    $js = NMJ_PATH . 'assets/js/speed-check.js';
    wp_enqueue_style(
        'odella-speed-check',
        NMJ_URL . 'assets/css/speed-check.css',
        [],
        file_exists($css) ? (string) filemtime($css) : NMJ_VERSION
    );
    wp_register_script(
        'odella-speed-check',
        NMJ_URL . 'assets/js/speed-check.js',
        [],
        file_exists($js) ? (string) filemtime($js) : NMJ_VERSION,
        true
    );
    $autorun = isset($_GET['autorun']) && (string) wp_unslash($_GET['autorun']) === '1';
    wp_localize_script('odella-speed-check', 'odellaSpeedCheck', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('odella_speed_check'),
        'autorun' => $autorun,
        'indexNonce' => wp_create_nonce('nmj_product_index'),
        'settingsUrl' => odella_speed_settings_url(),
    ]);
    wp_enqueue_script('odella-speed-check');
}

function odella_speed_check_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html('You do not have permission to view Speed Check.'));
    }

    $last = get_option('odella_speed_last_report');
    $last_label = '';
    if (is_array($last) && isset($last['timestamp']) && is_numeric($last['timestamp'])) {
        $last_label = wp_date('Y-m-d H:i', (int) $last['timestamp']);
    }
    ?>
    <div class="wrap odella-speed-wrap">
        <h1>Speed Check</h1>
        <?php if ($last_label !== '') : ?>
            <p id="odella-speed-last-report">Last report: <?php echo esc_html($last_label); ?></p>
        <?php endif; ?>
        <p class="odella-speed-actions">
            <button type="button" class="button button-primary" id="odella-speed-run">Run check</button>
            <button type="button" class="button" id="odella-speed-explain">Explain with AI</button>
            <button type="button" class="button" id="odella-speed-purge">Force Purge &amp; Rebuild Cache</button>
        </p>
        <div id="odella-speed-banner" class="odella-speed-banner" hidden></div>
        <div id="odella-speed-notice" class="notice notice-warning" hidden><p></p></div>
        <div id="odella-speed-progress" class="odella-speed-progress" hidden>
            <div class="odella-speed-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                <span id="odella-speed-progress-fill" class="odella-speed-progress-fill"></span>
            </div>
            <p id="odella-speed-progress-text"></p>
        </div>
        <table class="widefat striped odella-speed-table">
            <thead>
                <tr>
                    <th scope="col">Check</th>
                    <th scope="col">Status</th>
                    <th scope="col">Detail</th>
                </tr>
            </thead>
            <tbody id="odella-speed-check-rows">
                <tr>
                    <td colspan="3">Run a check to see the nine diagnostics.</td>
                </tr>
            </tbody>
        </table>
        <div class="odella-speed-psi">
            <section id="odella-speed-psi-mobile" class="odella-speed-psi-wave"></section>
            <section id="odella-speed-psi-desktop" class="odella-speed-psi-wave"></section>
        </div>
        <div id="odella-speed-ai" class="odella-speed-ai" hidden>
            <div id="odella-speed-ai-tabs" class="odella-speed-tabs" role="tablist"></div>
            <div id="odella-speed-ai-panel" class="odella-speed-ai-panel"></div>
        </div>
        <p id="odella-speed-cdn" class="odella-speed-callout" hidden></p>
    </div>
    <?php
}

function odella_speed_check_ajax(): void
{
    check_ajax_referer('odella_speed_check', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'You do not have permission to run Speed Check.'], 403);
    }

    $substep = isset($_POST['substep']) && is_string($_POST['substep'])
        ? sanitize_key(wp_unslash($_POST['substep']))
        : '';

    if ($substep === 'deterministic') {
        wp_send_json_success(odella_speed_run_deterministic());
    }
    if ($substep === 'recheck_cache') {
        wp_send_json_success(odella_speed_recheck_cache());
    }
    if ($substep === 'purge_only') {
        odella_speed_purge_only();
    }
    if ($substep === 'psi_wave') {
        odella_speed_psi_wave();
    }
    if ($substep === 'ai_explain') {
        odella_speed_ai_explain();
    }

    wp_send_json_error(['message' => 'Unknown Speed Check substep.'], 400);
}

/**
 * @return array{checks: array<int, array{id: string, label: string, status: string, detail: string}>, recommended_action: string, needs_ai: bool}
 */
function odella_speed_run_deterministic(): array
{
    $probes = odella_speed_collect_probes();
    $action = 'none';
    $needs_ai = false;
    $checks = [];

    $steps = [
        odella_speed_judge_litespeed($probes),
        odella_speed_judge_cache_wipe($probes),
        odella_speed_judge_css($probes),
        odella_speed_judge_config(),
        odella_speed_judge_autoload(),
        odella_speed_judge_cron(),
        odella_speed_judge_catalog(),
        odella_speed_judge_ajax_time(),
        odella_speed_judge_sessions(),
    ];
    foreach ($steps as $step) {
        $checks[] = $step['row'];
        $action = odella_speed_raise_action($action, $step['action']);
        if ($step['needs_ai']) {
            $needs_ai = true;
        }
    }

    return [
        'checks' => $checks,
        'recommended_action' => $action,
        'needs_ai' => $needs_ai,
    ];
}

/**
 * @return array{check: array{id: string, label: string, status: string, detail: string}, status: string, probes: array<int, array<string, mixed>>}
 */
function odella_speed_recheck_cache(): array
{
    $probes = odella_speed_collect_probes();
    $judged = odella_speed_judge_litespeed($probes);
    $public = [];
    foreach ($probes['rows'] as $row) {
        $public[] = [
            'url' => $row['url'],
            'ua' => $row['ua'],
            'http_status' => $row['http_status'],
            'cache' => $row['cache'],
            'ttfb_ms' => $row['ttfb_ms'],
            'purged' => $row['purged'],
        ];
    }
    return [
        'check' => $judged['row'],
        'status' => $judged['row']['status'],
        'probes' => $public,
    ];
}

function odella_speed_home_url(): string
{
    return 'https://odella.ge/ka/';
}

function odella_speed_store_url(): string
{
    return 'https://odella.ge/ka/store/';
}

function odella_speed_product_fallback_url(): string
{
    return 'https://odella.ge/ka/product-category/women/coat-women/';
}

function odella_speed_category_url(): string
{
    if (!function_exists('get_terms')) {
        return '';
    }
    $queries = [
        [
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'number' => 30,
            'orderby' => 'term_id',
            'order' => 'ASC',
            'lang' => 'ka',
        ],
        [
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'number' => 30,
            'orderby' => 'term_id',
            'order' => 'ASC',
            'lang' => 'ka',
        ],
    ];
    foreach ($queries as $args) {
        $terms = get_terms($args);
        if (is_wp_error($terms) || !is_array($terms)) {
            continue;
        }
        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }
            if (function_exists('pll_get_term_language')) {
                $lang = pll_get_term_language($term->term_id);
                if ($lang !== 'ka') {
                    continue;
                }
            }
            $link = get_term_link($term);
            if (!is_wp_error($link) && is_string($link) && $link !== '') {
                return $link;
            }
        }
    }
    return '';
}

function odella_speed_product_url(): string
{
    static $resolved = null;
    if (is_string($resolved)) {
        return $resolved;
    }
    $resolved = odella_speed_find_product_url();
    return $resolved;
}

function odella_speed_find_product_url(): string
{
    $fallback = odella_speed_product_fallback_url();
    if (!function_exists('wc_get_product')) {
        return $fallback;
    }
    $attempts = [
        [
            'tax' => false,
            'priced' => true,
        ],
        [
            'tax' => true,
            'priced' => true,
        ],
        [
            'tax' => true,
            'priced' => false,
        ],
    ];
    foreach ($attempts as $attempt) {
        $link = odella_speed_query_product_url($attempt['tax'], $attempt['priced']);
        if ($link !== '') {
            return $link;
        }
    }
    return $fallback;
}

function odella_speed_query_product_url(bool $language_tax, bool $require_price_meta): string
{
    $args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => 20,
        'orderby' => 'date',
        'order' => 'DESC',
        'no_found_rows' => true,
        'ignore_sticky_posts' => true,
        'fields' => 'ids',
        'lang' => 'ka',
    ];
    if ($require_price_meta) {
        $args['meta_query'] = [[
            'key' => '_price',
            'value' => 0,
            'compare' => '>',
            'type' => 'NUMERIC',
        ]];
    }
    if ($language_tax) {
        $args['tax_query'] = [[
            'taxonomy' => 'language',
            'field' => 'slug',
            'terms' => 'ka',
        ]];
    }
    $query = new WP_Query($args);
    $ids = is_array($query->posts) ? $query->posts : [];
    wp_reset_postdata();
    foreach ($ids as $id) {
        $link = odella_speed_priced_product_link((int) $id);
        if ($link !== '') {
            return $link;
        }
    }
    return '';
}

function odella_speed_priced_product_link(int $id): string
{
    if ($id <= 0) {
        return '';
    }
    if (function_exists('pll_get_post_language')) {
        $lang = pll_get_post_language($id);
        if ($lang !== 'ka') {
            return '';
        }
    }
    $product = wc_get_product($id);
    if (!$product instanceof WC_Product || $product->get_status() !== 'publish') {
        return '';
    }
    $priced = function_exists('odella_product_has_price')
        ? odella_product_has_price($product)
        : (string) $product->get_price() !== '';
    if (!$priced) {
        return '';
    }
    $link = get_permalink($id);
    return is_string($link) ? $link : '';
}

/**
 * @return array{mobile: string, desktop: string}
 */
function odella_speed_user_agents(): array
{
    if (function_exists('nmj_cache_warmer_user_agents')) {
        $agents = nmj_cache_warmer_user_agents();
        if (
            isset($agents['mobile'], $agents['desktop'])
            && is_string($agents['mobile'])
            && $agents['mobile'] !== ''
            && is_string($agents['desktop'])
            && $agents['desktop'] !== ''
        ) {
            return [
                'mobile' => $agents['mobile'],
                'desktop' => $agents['desktop'],
            ];
        }
    }
    return [
        'mobile' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 OdellaCacheWarmer/1.0 (+https://odella.ge)',
        'desktop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) OdellaCacheWarmer/1.0 (+https://odella.ge)',
    ];
}

/**
 * @return array{rows: array<int, array<string, mixed>>, bodies: array<string, string>}
 */
function odella_speed_collect_probes(): array
{
    $targets = [
        'home' => odella_speed_home_url(),
        'store' => odella_speed_store_url(),
        'category' => odella_speed_category_url(),
        'product' => odella_speed_product_url(),
    ];
    $jobs = [];
    foreach ($targets as $target => $url) {
        foreach (odella_speed_user_agents() as $ua_name => $agent) {
            $jobs[] = [
                'target' => $target,
                'url' => $url,
                'ua' => $ua_name,
                'agent' => $agent,
            ];
        }
    }
    $rows = [];
    $bodies = [];
    foreach (array_chunk($jobs, 4) as $chunk) {
        $part = null;
        if (function_exists('curl_multi_init')) {
            $part = odella_speed_probe_curl($chunk);
        }
        if ($part === null) {
            $part = odella_speed_probe_http($chunk);
        }
        foreach ($part['rows'] as $row) {
            $rows[] = $row;
        }
        foreach ($part['bodies'] as $target => $html) {
            if (is_string($html) && $html !== '' && ($bodies[$target] ?? '') === '') {
                $bodies[$target] = $html;
            }
        }
    }
    return [
        'rows' => $rows,
        'bodies' => $bodies,
    ];
}

/**
 * @param array<int, array{target: string, url: string, ua: string, agent: string}> $jobs
 * @return array{rows: array<int, array<string, mixed>>, bodies: array<string, string>}|null
 */
function odella_speed_probe_curl(array $jobs): ?array
{
    $multi = curl_multi_init();
    if ($multi === false) {
        return null;
    }
    $handles = [];
    $meta = [];
    $headers = [];
    foreach ($jobs as $index => $job) {
        if ($job['url'] === '') {
            continue;
        }
        $handle = curl_init($job['url']);
        if ($handle === false) {
            continue;
        }
        $id = spl_object_id($handle);
        $headers[$id] = [
            'cache' => '',
            'purge' => '',
        ];
        $meta[$id] = $job;
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => $job['agent'],
            CURLOPT_ENCODING => '',
            CURLOPT_COOKIE => '',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers, $id): int {
                unset($handle);
                if (preg_match('/^HTTP\//i', $line)) {
                    $headers[$id] = [
                        'cache' => '',
                        'purge' => '',
                    ];
                    return strlen($line);
                }
                if (stripos($line, 'x-litespeed-cache:') === 0) {
                    $headers[$id]['cache'] = trim(substr($line, strlen('x-litespeed-cache:')));
                } elseif (stripos($line, 'x-litespeed-purge:') === 0) {
                    $headers[$id]['purge'] = trim(substr($line, strlen('x-litespeed-purge:')));
                }
                return strlen($line);
            },
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[$index] = $handle;
    }
    if ($handles === []) {
        curl_multi_close($multi);
        return odella_speed_empty_probe_rows($jobs);
    }

    $running = 0;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running && curl_multi_select($multi, 1.0) === -1) {
            usleep(10000);
        }
    } while ($running && $status === CURLM_OK);

    $measured = [];
    foreach ($handles as $handle) {
        $id = spl_object_id($handle);
        $job = $meta[$id];
        $errno = curl_errno($handle);
        $raw_body = curl_multi_getcontent($handle);
        $body = is_string($raw_body) ? $raw_body : '';
        $http_status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $seconds = curl_getinfo($handle, CURLINFO_STARTTRANSFER_TIME);
        $ttfb_ms = is_numeric($seconds) ? (int) round(((float) $seconds) * 1000) : null;
        $timed_out = defined('CURLE_OPERATION_TIMEDOUT') && $errno === CURLE_OPERATION_TIMEDOUT;
        $usable = $status === CURLM_OK && ($errno === 0 || ($timed_out && $http_status >= 200 && $http_status < 400 && $ttfb_ms !== null));
        $error = '';
        if (!$usable) {
            $error = curl_error($handle);
            if ($error === '') {
                $error = 'Request failed';
            }
            if ($http_status < 200 || $http_status >= 400) {
                $http_status = $http_status > 0 ? $http_status : 0;
            }
        }
        $cache = odella_speed_header_token($headers[$id]['cache'] ?? '');
        $purge = trim((string) ($headers[$id]['purge'] ?? ''));
        $measured[] = odella_speed_probe_row($job, $http_status, $cache, $ttfb_ms, $purge !== '', $error, $body);
        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);

    return odella_speed_assemble_probes($jobs, $measured);
}

/**
 * @param array<int, array{target: string, url: string, ua: string, agent: string}> $jobs
 * @return array{rows: array<int, array<string, mixed>>, bodies: array<string, string>}
 */
function odella_speed_probe_http(array $jobs): array
{
    $measured = [];
    foreach ($jobs as $job) {
        if ($job['url'] === '') {
            $measured[] = odella_speed_probe_row($job, 0, '', null, false, 'URL missing', '');
            continue;
        }
        $started = microtime(true);
        $response = wp_remote_get($job['url'], [
            'timeout' => 15,
            'redirection' => 5,
            'cookies' => [],
            'user-agent' => $job['agent'],
        ]);
        $ttfb_ms = (int) round((microtime(true) - $started) * 1000);
        if (is_wp_error($response)) {
            $measured[] = odella_speed_probe_row($job, 0, '', $ttfb_ms, false, $response->get_error_message(), '');
            continue;
        }
        $cache = '';
        $cache_header = wp_remote_retrieve_header($response, 'x-litespeed-cache');
        if (is_array($cache_header)) {
            $cache_header = (string) end($cache_header);
        }
        $cache = odella_speed_header_token((string) $cache_header);
        $purge_header = wp_remote_retrieve_header($response, 'x-litespeed-purge');
        if (is_array($purge_header)) {
            $purge_header = (string) end($purge_header);
        }
        $body = wp_remote_retrieve_body($response);
        $measured[] = odella_speed_probe_row(
            $job,
            (int) wp_remote_retrieve_response_code($response),
            $cache,
            $ttfb_ms,
            trim((string) $purge_header) !== '',
            '',
            is_string($body) ? $body : ''
        );
    }
    return odella_speed_assemble_probes($jobs, $measured);
}

/**
 * @param array<int, array{target: string, url: string, ua: string, agent: string}> $jobs
 * @return array{rows: array<int, array<string, mixed>>, bodies: array<string, string>}
 */
function odella_speed_empty_probe_rows(array $jobs): array
{
    $measured = [];
    foreach ($jobs as $job) {
        $measured[] = odella_speed_probe_row($job, 0, '', null, false, $job['url'] === '' ? 'URL missing' : 'Request failed', '');
    }
    return odella_speed_assemble_probes($jobs, $measured);
}

/**
 * @param array{target: string, url: string, ua: string, agent: string} $job
 * @return array<string, mixed>
 */
function odella_speed_probe_row(array $job, int $http_status, string $cache, ?int $ttfb_ms, bool $purged, string $error, string $body): array
{
    return [
        'target' => $job['target'],
        'url' => $job['url'],
        'ua' => $job['ua'],
        'http_status' => $http_status,
        'cache' => $cache,
        'ttfb_ms' => $ttfb_ms,
        'purged' => $purged,
        'error' => $error,
        'body' => $body,
    ];
}

/**
 * @param array<int, array{target: string, url: string, ua: string, agent: string}> $jobs
 * @param array<int, array<string, mixed>> $measured
 * @return array{rows: array<int, array<string, mixed>>, bodies: array<string, string>}
 */
function odella_speed_assemble_probes(array $jobs, array $measured): array
{
    $by_key = [];
    foreach ($measured as $row) {
        $by_key[$row['target'] . '|' . $row['ua']] = $row;
    }
    $rows = [];
    $bodies = [];
    foreach ($jobs as $job) {
        $key = $job['target'] . '|' . $job['ua'];
        $row = $by_key[$key] ?? odella_speed_probe_row($job, 0, '', null, false, $job['url'] === '' ? 'URL missing' : 'Request failed', '');
        $body = is_string($row['body'] ?? null) ? $row['body'] : '';
        unset($row['body']);
        $rows[] = $row;
        if (($job['target'] === 'home' || $job['target'] === 'product') && $body !== '') {
            $current = $bodies[$job['target']] ?? '';
            if ($current === '' || $job['ua'] === 'desktop') {
                $bodies[$job['target']] = $body;
            }
        }
    }
    return [
        'rows' => $rows,
        'bodies' => $bodies,
    ];
}

function odella_speed_header_token(string $header): string
{
    $header = strtolower(trim($header));
    if ($header === '') {
        return '';
    }
    $token = trim(explode(',', $header, 2)[0]);
    return trim(explode(';', $token, 2)[0]);
}

/**
 * @param array{rows: array<int, array<string, mixed>>, bodies: array<string, string>} $probes
 * @return array{row: array{id: string, label: string, status: string, detail: string}, action: string, needs_ai: bool}
 */
function odella_speed_judge_litespeed(array $probes): array
{
    $hits = 0;
    $misses = 0;
    $hard_fail = false;
    $slow_hit = false;
    $max_hit = null;
    $notes = [];
    foreach ($probes['rows'] as $row) {
        $status = (int) ($row['http_status'] ?? 0);
        $cache = is_string($row['cache'] ?? null) ? $row['cache'] : '';
        $ttfb = is_int($row['ttfb_ms'] ?? null) ? $row['ttfb_ms'] : null;
        $url = is_string($row['url'] ?? null) ? $row['url'] : '';
        $ua = is_string($row['ua'] ?? null) ? $row['ua'] : '';
        $label = trim($url . ' ' . $ua);
        if ($url === '' || $status === 0 || $status >= 400) {
            $hard_fail = true;
            $code = $status === 0 ? 'failed' : (string) $status;
            $error = is_string($row['error'] ?? null) ? trim($row['error']) : '';
            if ($error !== '') {
                $error = function_exists('mb_substr') ? mb_substr($error, 0, 80) : substr($error, 0, 80);
                $code .= ' ' . $error;
            }
            $notes[] = $label . ' ' . $code;
            continue;
        }
        if ($cache === 'hit') {
            $hits++;
            if ($ttfb !== null) {
                $max_hit = $max_hit === null ? $ttfb : max($max_hit, $ttfb);
                if ($ttfb > 800) {
                    $slow_hit = true;
                    $notes[] = $label . ' hit ' . $ttfb . ' ms';
                }
            }
            continue;
        }
        $misses++;
    }

    $total = count($probes['rows']);
    $max_text = $max_hit === null ? 'n/a' : (string) $max_hit . ' ms';
    $detail = $hits . '/' . $total . ' hit, max cached TTFB ' . $max_text;
    if ($misses > 0) {
        $detail .= ', ' . $misses . ' miss';
    }
    if ($notes !== []) {
        $detail .= '. ' . implode('; ', array_slice($notes, 0, 4));
    }

    $action = 'none';
    $needs_ai = false;
    if ($hard_fail || $slow_hit) {
        $status = 'fail';
        if ($slow_hit) {
            $needs_ai = true;
        }
    } elseif ($misses > 0 || $hits < 6 || $max_hit === null || $max_hit >= 400) {
        $status = 'warn';
    } else {
        $status = 'pass';
    }
    if ($misses > 0) {
        $action = 'warm_cache';
    }

    return [
        'row' => odella_speed_row('litespeed_ttfb', 'LiteSpeed page cache and TTFB', $status, $detail),
        'action' => $action,
        'needs_ai' => $needs_ai,
    ];
}

/**
 * @param array{rows: array<int, array<string, mixed>>, bodies: array<string, string>} $probes
 * @return array{row: array{id: string, label: string, status: string, detail: string}, action: string, needs_ai: bool}
 */
function odella_speed_judge_cache_wipe(array $probes): array
{
    $purged = 0;
    foreach ($probes['rows'] as $row) {
        if (!empty($row['purged'])) {
            $purged++;
        }
    }
    $timeout = odella_speed_elementor_license_timeout();
    $license_ok = $timeout > time() + 86400;
    if ($purged === 0 && $license_ok) {
        return [
            'row' => odella_speed_row(
                'cache_wipe',
                'Cache-wipe loop',
                'pass',
                'No x-litespeed-purge header on the probes. Elementor license cache stays valid for more than a day.'
            ),
            'action' => 'none',
            'needs_ai' => false,
        ];
    }
    $parts = [];
    if ($purged > 0) {
        $parts[] = $purged . ' probe response(s) sent x-litespeed-purge';
    }
    if (!$license_ok) {
        $parts[] = 'Elementor license cache is missing or expires within 24 hours';
    }
    return [
        'row' => odella_speed_row('cache_wipe', 'Cache-wipe loop', 'fail', implode('. ', $parts) . '.'),
        'action' => 'none',
        'needs_ai' => true,
    ];
}

function odella_speed_elementor_license_timeout(): int
{
    $data = get_option('_elementor_pro_license_v2_data');
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }
    if (!is_array($data) || !isset($data['timeout']) || !is_numeric($data['timeout'])) {
        return 0;
    }
    return (int) $data['timeout'];
}

/**
 * @param array{rows: array<int, array<string, mixed>>, bodies: array<string, string>} $probes
 * @return array{row: array{id: string, label: string, status: string, detail: string}, action: string, needs_ai: bool}
 */
function odella_speed_judge_css(array $probes): array
{
    $home = odella_speed_html_body($probes, 'home');
    $product = odella_speed_html_body($probes, 'product');
    if ($home === '' && $product === '') {
        return [
            'row' => odella_speed_row('elementor_css', 'Elementor CSS', 'fail', 'Home and product HTML were not available to inspect stylesheets.'),
            'action' => 'none',
            'needs_ai' => false,
        ];
    }
    $hrefs = array_merge(odella_speed_stylesheet_hrefs($home), odella_speed_stylesheet_hrefs($product));
    $local = [];
    foreach ($hrefs as $href) {
        $url = odella_speed_local_stylesheet($href);
        if ($url !== null) {
            $local[$url] = true;
        }
    }
    $urls = array_keys($local);
    if ($urls === []) {
        return [
            'row' => odella_speed_row('elementor_css', 'Elementor CSS', 'fail', 'No local stylesheets were found in the home or product HTML.'),
            'action' => 'none',
            'needs_ai' => false,
        ];
    }

    $bad = [];
    foreach ($urls as $url) {
        $response = wp_remote_head($url, [
            'timeout' => 10,
            'redirection' => 3,
            'cookies' => [],
        ]);
        if (is_wp_error($response)) {
            $bad[] = $url . ' request failed';
            continue;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 400 || $code === 0) {
            $bad[] = $url . ' HTTP ' . $code;
        }
    }
    if ($bad === []) {
        return [
            'row' => odella_speed_row('elementor_css', 'Elementor CSS', 'pass', count($urls) . ' local stylesheets returned HTTP 200.'),
            'action' => 'none',
            'needs_ai' => false,
        ];
    }
    $action = 'none';
    foreach ($bad as $item) {
        if (preg_match('/HTTP\s+(\d+)/', $item, $match) && (int) $match[1] >= 400) {
            $action = 'purge_and_rebuild';
            break;
        }
    }
    return [
        'row' => odella_speed_row(
            'elementor_css',
            'Elementor CSS',
            'fail',
            count($bad) . ' stylesheet problem(s). ' . implode('; ', array_slice($bad, 0, 3))
        ),
        'action' => $action,
        'needs_ai' => false,
    ];
}

function odella_speed_html_body(array $probes, string $target): string
{
    $body = $probes['bodies'][$target] ?? '';
    if (is_string($body) && $body !== '') {
        return $body;
    }
    $url = '';
    foreach ($probes['rows'] as $row) {
        if (($row['target'] ?? '') === $target && is_string($row['url'] ?? null) && $row['url'] !== '') {
            $url = $row['url'];
            break;
        }
    }
    if ($url === '') {
        return '';
    }
    $agents = odella_speed_user_agents();
    $response = wp_remote_get($url, [
        'timeout' => 15,
        'redirection' => 5,
        'cookies' => [],
        'user-agent' => $agents['desktop'],
    ]);
    if (is_wp_error($response)) {
        return '';
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 400) {
        return '';
    }
    $fetched = wp_remote_retrieve_body($response);
    return is_string($fetched) ? $fetched : '';
}

/**
 * @return array<int, string>
 */
function odella_speed_stylesheet_hrefs(string $html): array
{
    if ($html === '' || !preg_match_all('/<link\b[^>]*>/i', $html, $tags)) {
        return [];
    }
    $hrefs = [];
    foreach ($tags[0] as $tag) {
        if (!odella_speed_link_is_stylesheet($tag)) {
            continue;
        }
        $href = odella_speed_link_href($tag);
        if ($href !== '') {
            $hrefs[] = $href;
        }
    }
    return $hrefs;
}

function odella_speed_link_is_stylesheet(string $tag): bool
{
    $rel = '';
    if (preg_match('/\brel\s*=\s*([\'"])([^\'"]*)\1/i', $tag, $match)) {
        $rel = $match[2];
    } elseif (preg_match('/\brel\s*=\s*([^\s>]+)/i', $tag, $match)) {
        $rel = trim($match[1], '\'"');
    }
    if ($rel === '') {
        return false;
    }
    $tokens = preg_split('/\s+/', strtolower($rel));
    return is_array($tokens) && in_array('stylesheet', $tokens, true);
}

function odella_speed_link_href(string $tag): string
{
    if (preg_match('/\bhref\s*=\s*([\'"])([^\'"]*)\1/i', $tag, $match)) {
        return trim(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'));
    }
    return '';
}

function odella_speed_local_stylesheet(string $href): ?string
{
    $href = trim($href);
    if ($href === '' || str_starts_with($href, 'data:') || str_starts_with($href, 'blob:')) {
        return null;
    }
    if (str_starts_with($href, '/wp-content/')) {
        return 'https://odella.ge' . $href;
    }
    if (str_starts_with($href, '//')) {
        $href = 'https:' . $href;
    }
    $parts = wp_parse_url($href);
    if (!is_array($parts)) {
        return null;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host !== 'odella.ge') {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== '' && $scheme !== 'https') {
        return null;
    }
    return $href;
}

/**
 * @return array{row: array{id: string, label: string, status: string, detail: string}, action: string, needs_ai: bool}
 */
function odella_speed_judge_config(): array
{
    $object = get_option('litespeed.conf.object');
    $object_off = $object === 0 || $object === '0' || $object === false || $object === '' || $object === null;
    $object_on = $object === 1 || $object === '1' || $object === true || $object === 'on';
    $print = get_option('elementor_css_print_method');
    $print_ok = $print === 'external';
    $print_shown = is_string($print) && $print !== '' ? $print : 'not set';

    if ($object_off && $print_ok) {
        return [
            'row' => odella_speed_row('cache_config', 'LiteSpeed and object cache', 'pass', 'Object cache is off and Elementor CSS print method is external.'),
            'action' => 'none',
            'needs_ai' => false,
        ];
    }

    $parts = [];
    if ($object_on || !$object_off) {
        $parts[] = 'Object cache is on. Redis and Memcached are not available on this hosting plan.';
    }
    if (!$print_ok) {
        $parts[] = 'Elementor CSS print method is ' . $print_shown . ' instead of external.';
    }
    return [
        'row' => odella_speed_row('cache_config', 'LiteSpeed and object cache', 'fail', implode(' ', $parts)),
        'action' => 'none',
        'needs_ai' => false,
    ];
}

/**
 * @return array{row: array{id: string, label: string, status: string, detail: string}, action: string, needs_ai: bool}
 */
function odella_speed_judge_autoload(): array
{
    global $wpdb;
    $before = $wpdb->last_error;
    $sum = $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')");
    if ($wpdb->last_error !== '' && $wpdb->last_error !== $before) {
        return [
            'row' => odella_speed_row('autoload', 'Autoloaded options', 'fail', 'Could not measure autoloaded option size.'),
            'action' => 'none',
            'needs_ai' => true,
        ];
    }
    $bytes = is_numeric($sum) ? (int) $sum : 0;
    $top = $wpdb->get_results(
        "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY LENGTH(option_value) DESC LIMIT 5",
        ARRAY_A
    );
    $names = [];
    if (is_array($top)) {
        foreach ($top as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = isset($item['option_name']) && is_string($item['option_name']) ? $item['option_name'] : '';
            $size = isset($item['bytes']) && is_numeric($item['bytes']) ? (int) $item['bytes'] : 0;
            if ($name !== '') {
                $names[] = $name . ' ' . $size;
            }
        }
    }
    $detail = odella_speed_kb($bytes);
    if ($names !== []) {
        $detail .= '. Largest: ' . implode(', ', $names);
    }
    $pass_under = 800 * 1024;
    $fail_over = 1572864;
    if ($bytes > $fail_over) {
        return [
            'row' => odella_speed_row('autoload', 'Autoloaded options', 'fail', $detail),
            'action' => 'none',
            'needs_ai' => true,
        ];
    }
    $status = $bytes < $pass_under ? 'pass' : 'warn';
    return [
        'row' => odella_speed_row('autoload', 'Autoloaded options', $status, $detail),
        'action' => 'none',
        'needs_ai' => false,
    ];
}

function odella_speed_kb(int $bytes): string
{
    return number_format($bytes / 1024, 1) . ' KB';
}

/**
 * @return array{row: array{id: string, label: string, status: string, detail: string}, action: string, needs_ai: bool}
 */
function odella_speed_judge_cron(): array
{
    $warn = false;
    $parts = [];
    $disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
    $parts[] = 'DISABLE_WP_CRON ' . ($disabled ? 'true' : 'false');
    foreach (['nmj_product_index_cron', 'odella_daily_rates'] as $hook) {
        $next = wp_next_scheduled($hook);
        if (!is_numeric($next) || (int) $next <= 0) {
            $parts[] = $hook . ' not scheduled';
            continue;
        }
        $stamp = (int) $next;
        if ($stamp < time() - 3600) {
            $warn = true;
            $parts[] = $hook . ' overdue since ' . wp_date('Y-m-d H:i', $stamp);
            continue;
        }
        $parts[] = $hook . ' next ' . wp_date('Y-m-d H:i', $stamp);
    }

    $table = $GLOBALS['wpdb']->prefix . 'actionscheduler_actions';
    if (!odella_speed_table_exists($table)) {
        $warn = true;
        $parts[] = 'Action Scheduler table missing';
    } else {
        global $wpdb;
        $before = $wpdb->last_error;
        $pending = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status = 'pending'");
        $failed = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE status = 'failed'");
        if ($wpdb->last_error !== '' && $wpdb->last_error !== $before) {
            $warn = true;
            $parts[] = 'Action Scheduler counts unavailable';
        } else {
            $pending_count = is_numeric($pending) ? (int) $pending : 0;
            $failed_count = is_numeric($failed) ? (int) $failed : 0;
            if ($pending_count > 250 || $failed_count > 50) {
                $warn = true;
            }
            $parts[] = 'pending ' . $pending_count . ', failed ' . $failed_count;
        }
    }

    return [
        'row' => odella_speed_row('cron', 'Cron and Action Scheduler', $warn ? 'warn' : 'pass', implode('. ', $parts) . '.'),
        'action' => 'none',
        'needs_ai' => false,
    ];
}

function odella_speed_table_exists(string $table): bool
{
    global $wpdb;
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
    return is_string($found) && $found !== '';
}

/**
 * @return array{row: array{id: string, label: string, status: string, detail: string}, action: string, needs_ai: bool}
 */
function odella_speed_judge_catalog(): array
{
    $path = function_exists('nmj_product_index_dir') ? nmj_product_index_dir() . '/current.json' : '';
    $raw = $path !== '' && is_readable($path) ? file_get_contents($path) : false;
    $pointer = is_string($raw) ? json_decode($raw, true) : null;
    $version = is_array($pointer) && isset($pointer['v']) && is_numeric($pointer['v']) ? (int) $pointer['v'] : 0;
    if ($version <= 0) {
        return [
            'row' => odella_speed_row('catalog_freshness', 'Catalog index freshness', 'warn', 'Catalog index pointer is missing.'),
            'action' => 'reindex_and_warm',
            'needs_ai' => false,
        ];
    }

    global $wpdb;
    $before = $wpdb->last_error;
    $max = $wpdb->get_var("SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'");
    if ($wpdb->last_error !== '' && $wpdb->last_error !== $before) {
        return [
            'row' => odella_speed_row('catalog_freshness', 'Catalog index freshness', 'warn', 'Could not read the latest product edit time.'),
            'action' => 'reindex_and_warm',
            'needs_ai' => false,
        ];
    }
    $edited = 0;
    if (is_string($max) && $max !== '' && $max !== '0000-00-00 00:00:00') {
        $parsed = strtotime($max . ' UTC');
        $edited = is_int($parsed) ? $parsed : 0;
    }
    if ($edited <= 0 || ($version + 600) >= $edited) {
        $detail = 'Index is current';
        if ($edited > 0) {
            $detail .= ' (index ' . wp_date('Y-m-d H:i', $version) . ', latest product edit ' . wp_date('Y-m-d H:i', $edited) . ')';
        }
        return [
            'row' => odella_speed_row('catalog_freshness', 'Catalog index freshness', 'pass', $detail . '.'),
            'action' => 'none',
            'needs_ai' => false,
        ];
    }
    return [
        'row' => odella_speed_row(
            'catalog_freshness',
            'Catalog index freshness',
            'warn',
            'Index ' . wp_date('Y-m-d H:i', $version) . ' is older than the latest product edit ' . wp_date('Y-m-d H:i', $edited) . '.'
        ),
        'action' => 'reindex_and_warm',
        'needs_ai' => false,
    ];
}

/**
 * @return array{row: array{id: string, label: string, status: string, detail: string}, action: string, needs_ai: bool}
 */
function odella_speed_judge_ajax_time(): array
{
    $started = microtime(true);
    $response = wp_remote_post(admin_url('admin-ajax.php'), [
        'timeout' => 15,
        'redirection' => 2,
        'cookies' => [],
        'body' => [
            'action' => 'moderncart_refresh_floating_cart',
        ],
    ]);
    $ms = (int) round((microtime(true) - $started) * 1000);
    if (is_wp_error($response)) {
        return [
            'row' => odella_speed_row('ajax_time', 'Cart AJAX response time', 'fail', 'Cart AJAX request failed after ' . $ms . ' ms.'),
            'action' => 'none',
            'needs_ai' => $ms > 700,
        ];
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $detail = 'HTTP ' . $code . ' in ' . $ms . ' ms.';
    if ($ms > 700) {
        return [
            'row' => odella_speed_row('ajax_time', 'Cart AJAX response time', 'fail', $detail),
            'action' => 'none',
            'needs_ai' => true,
        ];
    }
    $status = $ms < 350 ? 'pass' : 'warn';
    return [
        'row' => odella_speed_row('ajax_time', 'Cart AJAX response time', $status, $detail),
        'action' => 'none',
        'needs_ai' => false,
    ];
}

/**
 * @return array{row: array{id: string, label: string, status: string, detail: string}, action: string, needs_ai: bool}
 */
function odella_speed_judge_sessions(): array
{
    global $wpdb;
    $warn = false;
    $parts = [];
    $table = $wpdb->prefix . 'woocommerce_sessions';
    if (!odella_speed_table_exists($table)) {
        $warn = true;
        $parts[] = 'WooCommerce sessions table is missing';
    } else {
        $before = $wpdb->last_error;
        $count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
        if ($wpdb->last_error !== '' && $wpdb->last_error !== $before) {
            $warn = true;
            $parts[] = 'WooCommerce sessions could not be counted';
        } else {
            $sessions = is_numeric($count) ? (int) $count : 0;
            if ($sessions > 5000) {
                $warn = true;
            }
            $parts[] = $sessions . ' WooCommerce sessions';
        }
    }

    $before = $wpdb->last_error;
    $expired = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) > 0 AND CAST(option_value AS UNSIGNED) < %d",
        $wpdb->esc_like('_transient_timeout_') . '%',
        time()
    ));
    if ($wpdb->last_error !== '' && $wpdb->last_error !== $before) {
        $warn = true;
        $parts[] = 'expired transients could not be counted';
    } else {
        $expired_count = is_numeric($expired) ? (int) $expired : 0;
        if ($expired_count > 1000) {
            $warn = true;
        }
        $parts[] = $expired_count . ' expired transients';
    }

    return [
        'row' => odella_speed_row('sessions', 'Sessions and expired transients', $warn ? 'warn' : 'pass', implode(', ', $parts) . '.'),
        'action' => 'none',
        'needs_ai' => false,
    ];
}

function odella_speed_raise_action(string $current, string $next): string
{
    $rank = [
        'none' => 0,
        'warm_cache' => 1,
        'reindex_and_warm' => 2,
        'purge_and_rebuild' => 3,
    ];
    $current_rank = $rank[$current] ?? 0;
    $next_rank = $rank[$next] ?? 0;
    return $next_rank > $current_rank ? $next : $current;
}

/**
 * @return array{id: string, label: string, status: string, detail: string}
 */
function odella_speed_row(string $id, string $label, string $status, string $detail): array
{
    if (!in_array($status, ['pass', 'warn', 'fail'], true)) {
        $status = 'fail';
    }
    return [
        'id' => $id,
        'label' => $label,
        'status' => $status,
        'detail' => $detail,
    ];
}

function odella_speed_purge_only(): void
{
    if (class_exists('\Elementor\Plugin')) {
        $elementor = \Elementor\Plugin::$instance;
        if (
            is_object($elementor)
            && isset($elementor->files_manager)
            && is_object($elementor->files_manager)
            && method_exists($elementor->files_manager, 'clear_cache')
        ) {
            $elementor->files_manager->clear_cache();
        }
    }
    wp_cache_flush();
    do_action('litespeed_purge_all');
    $cdn = function_exists('odella_purge_hostinger_cdn')
        ? odella_purge_hostinger_cdn()
        : ['attempted' => false, 'ok' => false, 'message' => ''];
    wp_send_json_success(['ok' => true, 'cdn' => $cdn]);
}

/**
 * @return array<int, array{label: string, url: string}>
 */
function odella_speed_psi_targets(): array
{
    return [
        [
            'label' => 'Home',
            'url' => odella_speed_home_url(),
        ],
        [
            'label' => 'Store',
            'url' => odella_speed_store_url(),
        ],
        [
            'label' => 'Product',
            'url' => odella_speed_product_url(),
        ],
    ];
}

function odella_speed_psi_wave(): void
{
    $wave = isset($_POST['wave']) && is_string($_POST['wave']) ? sanitize_key(wp_unslash($_POST['wave'])) : '';
    if ($wave !== 'mobile' && $wave !== 'desktop') {
        wp_send_json_error(['message' => 'Choose a mobile or desktop PageSpeed wave.']);
    }
    $settings = function_exists('odella_get_speed_settings') ? odella_get_speed_settings() : [];
    $stored = is_array($settings) && isset($settings['psi_key_enc']) && is_string($settings['psi_key_enc'])
        ? $settings['psi_key_enc']
        : '';
    $key = function_exists('odella_decrypt_secret') ? odella_decrypt_secret($stored) : '';
    if ($key === '') {
        wp_send_json_error([
            'message' => 'PageSpeed Insights key is not set.',
            'settings_url' => odella_speed_settings_url(),
        ]);
    }
    if (!function_exists('curl_multi_init')) {
        $key = '';
        wp_send_json_error(['message' => 'PageSpeed requests need curl, which is not available.']);
    }

    $score_limit = $wave === 'mobile' ? 75 : 85;
    $needs_ai = false;
    $results = [];
    $multi = curl_multi_init();
    $handles = [];
    if ($multi === false) {
        $key = '';
        wp_send_json_error(['message' => 'PageSpeed requests could not start.']);
    }
    foreach (odella_speed_psi_targets() as $target) {
        $endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query([
            'url' => $target['url'],
            'strategy' => $wave,
            'category' => 'performance',
            'key' => $key,
        ]);
        $handle = curl_init($endpoint);
        if ($handle === false) {
            $results[] = [
                'label' => $target['label'],
                'url' => $target['url'],
                'error' => 'PageSpeed request could not start.',
            ];
            continue;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 75,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[spl_object_id($handle)] = [
            'handle' => $handle,
            'label' => $target['label'],
            'url' => $target['url'],
        ];
    }

    $running = 0;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running && curl_multi_select($multi, 1.0) === -1) {
            usleep(10000);
        }
    } while ($running && $status === CURLM_OK);

    foreach ($handles as $item) {
        $handle = $item['handle'];
        $error = '';
        $payload = null;
        if ($status !== CURLM_OK || curl_errno($handle) !== 0) {
            $error = curl_error($handle);
            if ($error === '') {
                $error = 'PageSpeed request failed.';
            }
        } else {
            $raw = curl_multi_getcontent($handle);
            $payload = is_string($raw) ? json_decode($raw, true) : null;
            $http = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($http !== 200 || !is_array($payload)) {
                $message = 'PageSpeed request failed.';
                if (is_array($payload) && isset($payload['error']['message']) && is_string($payload['error']['message'])) {
                    $message = $payload['error']['message'];
                } elseif ($http > 0) {
                    $message = 'PageSpeed request failed (HTTP ' . $http . ').';
                }
                $error = $message;
                $payload = null;
            }
        }
        curl_multi_remove_handle($multi, $handle);
        if ($error !== '') {
            $results[] = [
                'label' => $item['label'],
                'url' => $item['url'],
                'error' => odella_speed_redact($error, $key),
            ];
            continue;
        }
        $parsed = odella_speed_parse_psi(is_array($payload) ? $payload : []);
        $parsed['label'] = $item['label'];
        $parsed['url'] = $item['url'];
        $score = $parsed['score'];
        $lcp = $parsed['lcp'];
        if ((is_int($score) || is_float($score)) && $score < $score_limit) {
            $needs_ai = true;
        }
        if ((is_int($lcp) || is_float($lcp)) && $lcp > 3000) {
            $needs_ai = true;
        }
        $results[] = $parsed;
    }
    curl_multi_close($multi);
    $key = '';

    wp_send_json_success([
        'wave' => $wave,
        'needs_ai' => $needs_ai,
        'results' => $results,
    ]);
}

/**
 * @param array<string, mixed> $payload
 * @return array{score: int|null, fcp: int|null, lcp: int|null, tbt: int|null, cls: float|null, ttfb: int|null, audits: array<int, array{title: string, displayValue: string}>}
 */
function odella_speed_parse_psi(array $payload): array
{
    $lighthouse = isset($payload['lighthouseResult']) && is_array($payload['lighthouseResult'])
        ? $payload['lighthouseResult']
        : [];
    $categories = isset($lighthouse['categories']) && is_array($lighthouse['categories']) ? $lighthouse['categories'] : [];
    $performance = isset($categories['performance']) && is_array($categories['performance']) ? $categories['performance'] : [];
    $score = null;
    if (isset($performance['score']) && is_numeric($performance['score'])) {
        $raw_score = (float) $performance['score'];
        $score = (int) round($raw_score <= 1 ? $raw_score * 100 : $raw_score);
    }
    $audits = isset($lighthouse['audits']) && is_array($lighthouse['audits']) ? $lighthouse['audits'] : [];
    $failed = [];
    foreach ($audits as $audit) {
        if (!is_array($audit) || !isset($audit['score']) || !is_numeric($audit['score'])) {
            continue;
        }
        if ((float) $audit['score'] >= 0.9) {
            continue;
        }
        $title = isset($audit['title']) && is_string($audit['title']) ? $audit['title'] : '';
        if ($title === '') {
            continue;
        }
        $display = isset($audit['displayValue']) && is_string($audit['displayValue']) ? $audit['displayValue'] : '';
        $failed[] = [
            'score' => (float) $audit['score'],
            'title' => $title,
            'displayValue' => $display,
        ];
    }
    usort($failed, static function (array $left, array $right): int {
        return $left['score'] <=> $right['score'];
    });
    $top = [];
    foreach (array_slice($failed, 0, 5) as $audit) {
        $top[] = [
            'title' => $audit['title'],
            'displayValue' => $audit['displayValue'],
        ];
    }
    return [
        'score' => $score,
        'fcp' => odella_speed_audit_ms($audits, 'first-contentful-paint'),
        'lcp' => odella_speed_audit_ms($audits, 'largest-contentful-paint'),
        'tbt' => odella_speed_audit_ms($audits, 'total-blocking-time'),
        'cls' => odella_speed_audit_number($audits, 'cumulative-layout-shift'),
        'ttfb' => odella_speed_audit_ms($audits, 'server-response-time'),
        'audits' => $top,
    ];
}

/**
 * @param array<string, mixed> $audits
 */
function odella_speed_audit_ms(array $audits, string $id): ?int
{
    $number = odella_speed_audit_number($audits, $id);
    if ($number === null) {
        return null;
    }
    return (int) round($number);
}

/**
 * @param array<string, mixed> $audits
 */
function odella_speed_audit_number(array $audits, string $id): ?float
{
    if (!isset($audits[$id]) || !is_array($audits[$id])) {
        return null;
    }
    $value = $audits[$id]['numericValue'] ?? null;
    return is_numeric($value) ? (float) $value : null;
}

function odella_speed_ai_explain(): void
{
    $settings = function_exists('odella_get_speed_settings') ? odella_get_speed_settings() : [];
    $stored = is_array($settings) && isset($settings['gemini_key_enc']) && is_string($settings['gemini_key_enc'])
        ? $settings['gemini_key_enc']
        : '';
    $key = function_exists('odella_decrypt_secret') ? odella_decrypt_secret($stored) : '';
    if ($key === '') {
        wp_send_json_error([
            'message' => 'Gemini key is not set.',
            'settings_url' => odella_speed_settings_url(),
        ]);
    }

    $telemetry = odella_speed_posted_telemetry();
    $prompt = odella_speed_ai_prompt($telemetry);
    $model = is_array($settings) && isset($settings['gemini_model']) && is_string($settings['gemini_model'])
        ? $settings['gemini_model']
        : 'gemini-flash-latest';
    $payload = wp_json_encode([
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt],
                ],
            ],
        ],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'temperature' => 0.2,
        ],
    ]);
    if (!is_string($payload)) {
        $key = '';
        wp_send_json_error(['message' => 'Could not build the Gemini request.']);
    }

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $response = wp_remote_post($endpoint, [
        'timeout' => 35,
        'headers' => [
            'x-goog-api-key' => $key,
            'Content-Type' => 'application/json',
        ],
        'body' => $payload,
    ]);
    if (is_wp_error($response)) {
        $message = odella_speed_redact($response->get_error_message(), $key);
        $key = '';
        wp_send_json_error(['message' => $message]);
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    $text = '';
    if (
        is_array($body)
        && isset($body['candidates'][0]['content']['parts'][0]['text'])
        && is_string($body['candidates'][0]['content']['parts'][0]['text'])
    ) {
        $text = trim($body['candidates'][0]['content']['parts'][0]['text']);
    }
    if ($code !== 200 || $text === '') {
        $fallback = $code > 0 ? 'Gemini request failed (HTTP ' . $code . ').' : 'Gemini request failed.';
        $message = function_exists('odella_api_error_message')
            ? odella_api_error_message($body, $fallback, $key)
            : odella_speed_redact($fallback, $key);
        $key = '';
        wp_send_json_error(['message' => $message]);
    }

    $parsed = odella_speed_parse_ai_json($text);
    $key = '';
    if ($parsed === null) {
        wp_send_json_error(['message' => 'Gemini did not return the report JSON.']);
    }

    $report = [
        'timestamp' => time(),
        'checks' => $telemetry['checks'],
        'psi' => $telemetry['psi'],
        'ai_reports' => $parsed,
    ];
    update_option('odella_speed_last_report', $report, false);
    wp_send_json_success([
        'reports' => $parsed,
        'timestamp' => $report['timestamp'],
    ]);
}

/**
 * @param array<string, mixed> $telemetry
 */
function odella_speed_ai_prompt(array $telemetry): string
{
    $settings = function_exists('odella_get_speed_settings') ? odella_get_speed_settings() : [];
    $languages = function_exists('odella_sanitize_report_languages')
        ? odella_sanitize_report_languages($settings['languages'] ?? [])
        : ['en'];
    $quoted = [];
    foreach ($languages as $code) {
        $quoted[] = '"' . $code . '"';
    }
    $csv = implode(', ', $quoted);
    $clean = odella_speed_strip_secrets($telemetry);
    $json = wp_json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{}';
    }
    $prompt = function_exists('odella_effective_ai_prompt') ? odella_effective_ai_prompt() : '';
    $contract = "REQUIRED OUTPUT FORMAT:\n"
        . 'Return ONLY valid JSON (no markdown fences) with translations for these exact language codes: ' . $csv . ".\n"
        . "Schema:\n"
        . "{\n"
        . "  \"reports\": {\n"
        . "    \"<lang_code>\": {\n"
        . "      \"headline\": \"One-sentence verdict\",\n"
        . "      \"summary\": \"2-3 sentence plain-language explanation of why the site is slow or healthy\",\n"
        . "      \"root_causes\": [\"Cause 1 with metric evidence\", \"Cause 2\"],\n"
        . "      \"actions\": [\"Actionable fix 1\", \"Actionable fix 2\"]\n"
        . "    }\n"
        . "  }\n"
        . '}';
    return $prompt . "\n\n" . $contract . "\n\n" . $json;
}

/**
 * @return array{checks: array<int, array{id: string, label: string, status: string, detail: string}>, psi: array<string, mixed>, auto_warm_result: string}
 */
function odella_speed_posted_telemetry(): array
{
    $raw = isset($_POST['telemetry']) && is_string($_POST['telemetry']) ? wp_unslash($_POST['telemetry']) : '';
    if (strlen($raw) > 200000) {
        $raw = '';
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    $clean = odella_speed_strip_secrets($decoded);
    $checks = [];
    if (isset($clean['checks']) && is_array($clean['checks'])) {
        foreach ($clean['checks'] as $check) {
            if (!is_array($check)) {
                continue;
            }
            $status = isset($check['status']) && is_string($check['status']) ? $check['status'] : 'fail';
            $checks[] = odella_speed_row(
                isset($check['id']) && is_string($check['id']) ? sanitize_key($check['id']) : 'check',
                isset($check['label']) && is_string($check['label']) ? wp_strip_all_tags($check['label']) : '',
                $status,
                isset($check['detail']) && is_string($check['detail']) ? wp_strip_all_tags($check['detail']) : ''
            );
        }
    }
    $psi = [];
    if (isset($clean['psi']) && is_array($clean['psi'])) {
        foreach (['mobile', 'desktop'] as $wave) {
            if (isset($clean['psi'][$wave]) && is_array($clean['psi'][$wave])) {
                $psi[$wave] = $clean['psi'][$wave];
            }
        }
    }
    $auto = '';
    if (isset($clean['auto_warm_result']) && is_string($clean['auto_warm_result'])) {
        $auto = sanitize_key($clean['auto_warm_result']);
    }
    return [
        'checks' => $checks,
        'psi' => $psi,
        'auto_warm_result' => $auto,
    ];
}

/**
 * @param array<string, mixed> $json
 * @return array<string, array{headline: string, summary: string, root_causes: array<int, string>, actions: array<int, string>}>|null
 */
function odella_speed_parse_ai_json(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text) ?? $text;
    $decoded = json_decode(trim($text), true);
    if (!is_array($decoded)) {
        return null;
    }
    $reports = isset($decoded['reports']) && is_array($decoded['reports']) ? $decoded['reports'] : $decoded;
    $clean = [];
    foreach ($reports as $code => $report) {
        if (!is_string($code) || preg_match('/^[a-z]{2}$/', $code) !== 1 || !is_array($report)) {
            continue;
        }
        $clean[$code] = [
            'headline' => odella_speed_clean_text(isset($report['headline']) && is_string($report['headline']) ? $report['headline'] : ''),
            'summary' => odella_speed_clean_text(isset($report['summary']) && is_string($report['summary']) ? $report['summary'] : ''),
            'root_causes' => odella_speed_clean_list($report['root_causes'] ?? []),
            'actions' => odella_speed_clean_list($report['actions'] ?? []),
        ];
    }
    return $clean === [] ? null : $clean;
}

/**
 * @param mixed $value
 * @return array<int, string>
 */
function odella_speed_clean_list($value): array
{
    if (!is_array($value)) {
        return [];
    }
    $items = [];
    foreach ($value as $item) {
        if (!is_string($item)) {
            continue;
        }
        $text = odella_speed_clean_text($item);
        if ($text !== '') {
            $items[] = $text;
        }
        if (count($items) >= 8) {
            break;
        }
    }
    return $items;
}

function odella_speed_clean_text(string $text): string
{
    $text = wp_strip_all_tags($text);
    $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted]', $text) ?? $text;
    if (function_exists('mb_substr')) {
        return trim(mb_substr($text, 0, 2000));
    }
    return trim(substr($text, 0, 2000));
}

/**
 * @param array<mixed> $value
 * @return array<mixed>
 */
function odella_speed_strip_secrets(array $value): array
{
    $clean = [];
    foreach ($value as $key => $item) {
        if (is_string($key) && preg_match('/key|secret|token|password|passwd|credential|authorization|nonce|email|customer|order_id/i', $key) === 1) {
            continue;
        }
        if (is_array($item)) {
            $clean[$key] = odella_speed_strip_secrets($item);
            continue;
        }
        if (is_string($item)) {
            $clean[$key] = odella_speed_redact_key_material(odella_speed_clean_text($item));
            continue;
        }
        if (is_int($item) || is_float($item) || is_bool($item) || $item === null) {
            $clean[$key] = $item;
        }
    }
    return $clean;
}

function odella_speed_redact_key_material(string $text): string
{
    $text = preg_replace('/AIza[0-9A-Za-z_\-]{20,}/', '[redacted]', $text) ?? $text;
    return preg_replace('/ya29\.[0-9A-Za-z_\-]+/', '[redacted]', $text) ?? $text;
}

function odella_speed_redact(string $message, string $secret): string
{
    if (function_exists('odella_redact_secret')) {
        return odella_redact_secret($message, $secret);
    }
    if ($secret === '') {
        return $message;
    }
    return str_replace([$secret, rawurlencode($secret)], '[redacted]', $message);
}
