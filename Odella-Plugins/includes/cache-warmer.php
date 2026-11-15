<?php
/**
 * LiteSpeed cache warmer for the Georgian storefront.
 *
 * Collects public catalog URLs and requests each one logged out, once with a
 * mobile user agent and once with a desktop user agent, so a later visitor
 * hits the cache. The URL list stays in a temp file. The option stores only
 * a small summary.
 */

defined('ABSPATH') || exit;

/**
 * @return array{mobile: string, desktop: string}
 */
function nmj_cache_warmer_user_agents(): array
{
    return [
        'mobile' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 OdellaCacheWarmer/1.0 (+https://odella.ge)',
        'desktop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) OdellaCacheWarmer/1.0 (+https://odella.ge)',
    ];
}

function nmj_cache_warmer_allow_url(string $url): bool
{
    if (!str_starts_with($url, 'https://odella.ge/')) {
        return false;
    }
    foreach (['/cart', '/checkout', '/my-account', '/en/'] as $fragment) {
        if (str_contains($url, $fragment)) {
            return false;
        }
    }
    return true;
}

/**
 * Georgian category archive URLs from the catalog file current.json points at.
 *
 * @return array<int, string>
 */
function nmj_cache_warmer_category_urls(): array
{
    $dir = nmj_product_index_dir();
    $pointer_path = $dir . '/current.json';
    if (!is_readable($pointer_path)) {
        return [];
    }
    $raw = file_get_contents($pointer_path);
    $pointer = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($pointer)) {
        return [];
    }
    $names = [];
    if (isset($pointer['v']) && is_numeric($pointer['v'])) {
        $names[] = 'catalog-' . (int) $pointer['v'] . '.json';
    }
    if (isset($pointer['url']) && is_string($pointer['url'])) {
        $path = parse_url($pointer['url'], PHP_URL_PATH);
        $base = is_string($path) ? wp_basename($path) : '';
        if (preg_match('/^catalog-\d+\.json$/', $base)) {
            $names[] = $base;
        }
    }
    $catalog_path = '';
    foreach ($names as $name) {
        $candidate = $dir . '/' . $name;
        if (is_readable($candidate)) {
            $catalog_path = $candidate;
            break;
        }
    }
    if ($catalog_path === '') {
        return [];
    }
    $catalog_raw = file_get_contents($catalog_path);
    $catalog = is_string($catalog_raw) ? json_decode($catalog_raw, true) : null;
    if (!is_array($catalog) || !isset($catalog['categories']) || !is_array($catalog['categories'])) {
        return [];
    }
    $urls = [];
    foreach ($catalog['categories'] as $category) {
        if (!is_array($category)) {
            continue;
        }
        $lang = strtolower((string) ($category['lang'] ?? ''));
        if ($lang !== 'ka') {
            continue;
        }
        $url = isset($category['url']) && is_string($category['url']) ? trim($category['url']) : '';
        if ($url !== '') {
            $urls[] = $url;
        }
    }
    return $urls;
}

/**
 * @return array<int, string>
 */
function nmj_cache_warmer_locs(string $xml): array
{
    if (!preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $xml, $matches)) {
        return [];
    }
    $locs = [];
    foreach ($matches[1] as $loc) {
        $url = trim(html_entity_decode((string) $loc, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        if ($url !== '') {
            $locs[] = $url;
        }
    }
    return $locs;
}

function nmj_cache_warmer_http_body(string $url): string
{
    $response = wp_remote_get($url, [
        'timeout' => 15,
        'redirection' => 3,
        'cookies' => [],
    ]);
    if (is_wp_error($response)) {
        return '';
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 400) {
        return '';
    }
    $body = wp_remote_retrieve_body($response);
    return is_string($body) ? $body : '';
}

/**
 * Home, shop, Georgian category archives, then page and product sitemap URLs.
 *
 * @return array<int, string>
 */
function nmj_cache_warmer_collect_urls(): array
{
    $urls = [
        home_url('/ka/'),
        home_url('/ka/shop/'),
    ];
    foreach (nmj_cache_warmer_category_urls() as $url) {
        $urls[] = $url;
    }
    $index = nmj_cache_warmer_http_body(home_url('/sitemap_index.xml'));
    foreach (nmj_cache_warmer_locs($index) as $loc) {
        if (!str_contains($loc, 'page-sitemap') && !str_contains($loc, 'product-sitemap')) {
            continue;
        }
        $child = nmj_cache_warmer_http_body($loc);
        foreach (nmj_cache_warmer_locs($child) as $page) {
            $urls[] = $page;
        }
    }
    $urls = array_values(array_unique($urls));
    $kept = [];
    foreach ($urls as $url) {
        if (is_string($url) && nmj_cache_warmer_allow_url($url)) {
            $kept[] = $url;
        }
    }
    return $kept;
}

function nmj_cache_warmer_header_token(string $header): string
{
    $header = strtolower(trim($header));
    if ($header === '') {
        return '';
    }
    return trim(explode(',', $header, 2)[0]);
}

/**
 * @param array<string, mixed>|WP_Error $response
 */
function nmj_cache_warmer_response_header($response): string
{
    if (is_wp_error($response)) {
        return '';
    }
    $value = wp_remote_retrieve_header($response, 'x-litespeed-cache');
    if (is_array($value)) {
        $value = (string) end($value);
    }
    return trim((string) $value);
}

/**
 * Two logged-out requests. Sleeps 100ms after a miss so LiteSpeed can finish
 * writing the entry. A hit does not sleep.
 *
 * @return array{hits: int, misses: int, mobile: string, desktop: string}
 */
function nmj_cache_warmer_warm_url(string $url): array
{
    $hits = 0;
    $misses = 0;
    $headers = [
        'mobile' => '',
        'desktop' => '',
    ];
    foreach (nmj_cache_warmer_user_agents() as $name => $agent) {
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'redirection' => 3,
            'cookies' => [],
            'user-agent' => $agent,
        ]);
        $header = nmj_cache_warmer_response_header($response);
        $headers[$name] = $header;
        $token = nmj_cache_warmer_header_token($header);
        if ($token === 'hit') {
            $hits++;
            continue;
        }
        $misses++;
        if ($token === 'miss') {
            usleep(100000);
        }
    }
    return [
        'hits' => $hits,
        'misses' => $misses,
        'mobile' => $headers['mobile'],
        'desktop' => $headers['desktop'],
    ];
}

/**
 * Warm until the budget elapses. One URL's two requests may finish after the
 * clock runs out. The next URL does not start.
 *
 * @return array{time: int, urls: int, hits: int, misses: int}
 */
function nmj_cache_warmer_run_budget(int $seconds): array
{
    $deadline = microtime(true) + max(0, $seconds);
    $hits = 0;
    $misses = 0;
    $warmed = 0;
    foreach (nmj_cache_warmer_collect_urls() as $url) {
        if (microtime(true) >= $deadline) {
            break;
        }
        $result = nmj_cache_warmer_warm_url($url);
        $warmed++;
        $hits += (int) $result['hits'];
        $misses += (int) $result['misses'];
    }
    return nmj_cache_warmer_store_summary($warmed, $hits, $misses);
}

/**
 * @return array{time: int, urls: int, hits: int, misses: int}
 */
function nmj_cache_warmer_store_summary(int $urls, int $hits, int $misses): array
{
    $summary = [
        'time' => time(),
        'urls' => $urls,
        'hits' => $hits,
        'misses' => $misses,
    ];
    update_option('odella_last_warm', $summary, false);
    return $summary;
}

function nmj_cache_warmer_state_path(string $token): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        throw new RuntimeException('Bad token');
    }
    return nmj_product_index_dir() . '/warm-' . $token . '.json';
}

/**
 * @param array<string, mixed> $state
 */
function nmj_cache_warmer_write_state(string $token, array $state): void
{
    if (!wp_mkdir_p(nmj_product_index_dir())) {
        throw new RuntimeException('Could not create the catalog directory.');
    }
    $json = wp_json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode the warm state.');
    }
    if (file_put_contents(nmj_cache_warmer_state_path($token), $json) === false) {
        throw new RuntimeException('Could not write the warm state.');
    }
}

/**
 * @return array{urls: array<int, string>, offset: int, hits: int, misses: int, started: int}
 */
function nmj_cache_warmer_read_state(string $token): array
{
    $path = nmj_cache_warmer_state_path($token);
    if (!is_readable($path)) {
        throw new RuntimeException('Warm expired. Start again.');
    }
    $raw = file_get_contents($path);
    $state = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($state) || !isset($state['urls']) || !is_array($state['urls'])) {
        throw new RuntimeException('Warm expired. Start again.');
    }
    $urls = [];
    foreach ($state['urls'] as $url) {
        if (is_string($url) && $url !== '') {
            $urls[] = $url;
        }
    }
    return [
        'urls' => $urls,
        'offset' => (int) ($state['offset'] ?? 0),
        'hits' => (int) ($state['hits'] ?? 0),
        'misses' => (int) ($state['misses'] ?? 0),
        'started' => (int) ($state['started'] ?? 0),
    ];
}

function nmj_cache_warmer_request_token(): string
{
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        wp_send_json_error(['message' => 'Bad token'], 400);
    }
    return $token;
}

/**
 * Warm two URLs at a time (four requests) when curl is available.
 *
 * @param array<int, string> $urls
 * @return array{hits: int, misses: int}
 */
function nmj_cache_warmer_warm_slice(array $urls): array
{
    $hits = 0;
    $misses = 0;
    foreach (array_chunk(array_values($urls), 2) as $pair) {
        $counted = nmj_cache_warmer_warm_pair($pair);
        $hits += $counted['hits'];
        $misses += $counted['misses'];
    }
    return [
        'hits' => $hits,
        'misses' => $misses,
    ];
}

/**
 * @param array<int, string> $urls
 * @return array{hits: int, misses: int}
 */
function nmj_cache_warmer_warm_pair(array $urls): array
{
    if (count($urls) < 2 || !function_exists('curl_multi_init')) {
        return nmj_cache_warmer_warm_sequential($urls);
    }
    try {
        $parallel = nmj_cache_warmer_warm_pair_curl($urls);
    } catch (Throwable $e) {
        $parallel = null;
    }
    if ($parallel === null) {
        return nmj_cache_warmer_warm_sequential($urls);
    }
    return $parallel;
}

/**
 * @param array<int, string> $urls
 * @return array{hits: int, misses: int}
 */
function nmj_cache_warmer_warm_sequential(array $urls): array
{
    $hits = 0;
    $misses = 0;
    foreach ($urls as $url) {
        if (!is_string($url) || !nmj_cache_warmer_allow_url($url)) {
            continue;
        }
        $result = nmj_cache_warmer_warm_url($url);
        $hits += (int) $result['hits'];
        $misses += (int) $result['misses'];
    }
    return [
        'hits' => $hits,
        'misses' => $misses,
    ];
}

/**
 * Four concurrent logged-out requests for two URLs. Returns null if curl
 * cannot run the pair, so the caller can fall back to wp_remote_get.
 *
 * @param array<int, string> $urls
 * @return array{hits: int, misses: int}|null
 */
function nmj_cache_warmer_warm_pair_curl(array $urls): ?array
{
    $multi = curl_multi_init();
    if ($multi === false) {
        return null;
    }
    $handles = [];
    $captured = [];
    foreach (array_values($urls) as $url) {
        if (!nmj_cache_warmer_allow_url($url)) {
            continue;
        }
        foreach (nmj_cache_warmer_user_agents() as $agent) {
            $handle = curl_init($url);
            if ($handle === false) {
                continue;
            }
            $id = spl_object_id($handle);
            $captured[$id] = '';
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => $agent,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk): int {
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$captured, $id): int {
                    if (preg_match('/^HTTP\//i', $line)) {
                        $captured[$id] = '';
                        return strlen($line);
                    }
                    if (stripos($line, 'x-litespeed-cache:') === 0) {
                        $captured[$id] = trim(substr($line, strlen('x-litespeed-cache:')));
                    }
                    return strlen($line);
                },
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
    }
    if ($handles === []) {
        curl_multi_close($multi);
        return [
            'hits' => 0,
            'misses' => 0,
        ];
    }
    $running = 0;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            if (curl_multi_select($multi, 1.0) === -1) {
                usleep(10000);
            }
        }
    } while ($running && $status === CURLM_OK);
    if ($status !== CURLM_OK) {
        foreach ($handles as $handle) {
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);
        return null;
    }
    $hits = 0;
    $misses = 0;
    $sleeps = 0;
    foreach ($handles as $handle) {
        if (curl_errno($handle) !== 0) {
            $misses++;
        } else {
            $token = nmj_cache_warmer_header_token($captured[spl_object_id($handle)] ?? '');
            if ($token === 'hit') {
                $hits++;
            } else {
                $misses++;
                if ($token === 'miss') {
                    $sleeps++;
                }
            }
        }
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    for ($i = 0; $i < $sleeps; $i++) {
        usleep(100000);
    }
    return [
        'hits' => $hits,
        'misses' => $misses,
    ];
}

function nmj_cache_warmer_handle(string $step): void
{
    if ($step === 'warm_start') {
        $token = bin2hex(random_bytes(16));
        $urls = nmj_cache_warmer_collect_urls();
        nmj_cache_warmer_write_state($token, [
            'urls' => $urls,
            'offset' => 0,
            'hits' => 0,
            'misses' => 0,
            'started' => time(),
        ]);
        wp_send_json_success([
            'token' => $token,
            'total' => count($urls),
        ]);
    }
    $token = nmj_cache_warmer_request_token();
    if ($step === 'warm_batch') {
        $state = nmj_cache_warmer_read_state($token);
        $total = count($state['urls']);
        $offset = max(0, $state['offset']);
        $slice = array_slice($state['urls'], $offset, 4);
        $counted = nmj_cache_warmer_warm_slice($slice);
        $offset += count($slice);
        $state['offset'] = $offset;
        $state['hits'] += $counted['hits'];
        $state['misses'] += $counted['misses'];
        nmj_cache_warmer_write_state($token, $state);
        wp_send_json_success([
            'offset' => $offset,
            'total' => $total,
            'hits' => $state['hits'],
            'misses' => $state['misses'],
        ]);
    }
    if ($step === 'warm_finish') {
        $state = nmj_cache_warmer_read_state($token);
        $total = count($state['urls']);
        $hits = $state['hits'];
        $misses = $state['misses'];
        @unlink(nmj_cache_warmer_state_path($token));
        wp_send_json_success(nmj_cache_warmer_store_summary($total, $hits, $misses));
    }
    wp_send_json_error(['message' => 'Unknown step'], 400);
}
