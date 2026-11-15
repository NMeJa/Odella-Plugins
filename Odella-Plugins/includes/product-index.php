<?php
/**
 * Lightweight catalog index.
 *
 * The shop filter reads this file instead of asking WooCommerce to render
 * another archive page. An admin rebuilds it from Odella → Catalog & Cache
 * after adding, removing, or changing products. The write is atomic: the
 * store keeps serving the previous file until the new one is renamed in.
 */

defined('ABSPATH') || exit;

add_action('admin_init', 'nmj_product_index_legacy_redirect');
add_action('admin_bar_menu', 'nmj_product_index_admin_bar', 80);
add_action('wp_ajax_nmj_product_index', 'nmj_product_index_ajax');
add_action('init', 'nmj_product_index_ensure_schedule');
add_filter('cron_schedules', 'nmj_product_index_cron_schedule');
add_action('nmj_product_index_cron', 'nmj_product_index_cron_run');
add_action('admin_footer', 'nmj_product_index_footer_script');
add_action('wp_footer', 'nmj_product_index_footer_script');

function nmj_product_index_hours(): int
{
    return max(0, (int) get_option('nmj_product_index_hours', 24));
}

function nmj_product_index_minutes(): int
{
    return min(59, max(0, (int) get_option('nmj_product_index_minutes', 0)));
}

function nmj_product_index_interval(): int
{
    return (nmj_product_index_hours() * 60 + nmj_product_index_minutes()) * 60;
}

/**
 * @param array<string, array{interval: int, display: string}> $schedules
 * @return array<string, array{interval: int, display: string}>
 */
function nmj_product_index_cron_schedule(array $schedules): array
{
    $interval = nmj_product_index_interval();
    if ($interval > 0) {
        $schedules['nmj_product_index_every'] = [
            'interval' => $interval,
            'display' => 'Odella catalog index',
        ];
    }
    return $schedules;
}

function nmj_product_index_ensure_schedule(): void
{
    $hook = 'nmj_product_index_cron';
    $interval = nmj_product_index_interval();
    $saved = get_option('nmj_product_index_every_stamp', null);
    $saved_interval = $saved === null ? null : (int) $saved;
    if ($interval === 0) {
        if (wp_next_scheduled($hook) || $saved_interval !== 0) {
            wp_clear_scheduled_hook($hook);
            update_option('nmj_product_index_every_stamp', 0, false);
        }
        return;
    }
    if ($saved_interval === $interval && wp_next_scheduled($hook)) {
        return;
    }
    wp_clear_scheduled_hook($hook);
    wp_schedule_event(time() + $interval, 'nmj_product_index_every', $hook);
    update_option('nmj_product_index_every_stamp', $interval, false);
}

function nmj_product_index_cron_run(): void
{
    try {
        nmj_product_index_build_all();
    } catch (Throwable $e) {
        error_log('NMJ catalog index: ' . $e->getMessage());
    }
}

/**
 * @param WP_Admin_Bar $bar
 */
function nmj_product_index_admin_bar($bar): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $bar->add_node([
        'id' => 'nmj-reindex',
        'parent' => 'top-secondary',
        'title' => 'Reindex',
        'href' => admin_url('admin.php?page=odella-catalog-index'),
        'meta' => ['title' => 'Rebuild the catalog index'],
    ]);
}

function nmj_product_index_dir(): string
{
    $uploads = wp_upload_dir(null, false);
    return trailingslashit($uploads['basedir']) . 'odella-catalog';
}

function nmj_product_index_base_url(): string
{
    $uploads = wp_upload_dir(null, false);
    return trailingslashit($uploads['baseurl']) . 'odella-catalog/';
}

/**
 * Published products that belong on the shop, newest first.
 *
 * @return array<int, int>
 */
function nmj_product_index_ids(): array
{
    $tax = [[
        'taxonomy' => 'product_visibility',
        'field' => 'name',
        'terms' => ['exclude-from-catalog'],
        'operator' => 'NOT IN',
    ]];
    if (get_option('woocommerce_hide_out_of_stock_items') === 'yes') {
        $tax[] = [
            'taxonomy' => 'product_visibility',
            'field' => 'name',
            'terms' => ['outofstock'],
            'operator' => 'NOT IN',
        ];
    }
    $query = new WP_Query([
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'orderby' => 'date',
        'order' => 'DESC',
        'no_found_rows' => true,
        'ignore_sticky_posts' => true,
        'lang' => '',
        'tax_query' => $tax,
    ]);
    return array_map('intval', $query->posts);
}

/**
 * @return array<string, mixed>|null
 */
function nmj_product_index_record(int $id): ?array
{
    $product = wc_get_product($id);
    if (!$product || $product->get_status() !== 'publish') {
        return null;
    }
    $image_id = (int) $product->get_image_id();
    $image = $image_id ? nmj_product_index_image($image_id) : ['url' => '', 'srcset' => '', 'width' => 0, 'height' => 0];
    $cats = [];
    $terms = get_the_terms($id, 'product_cat');
    if (is_array($terms)) {
        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }
            $cats[] = (int) $term->term_id;
            foreach (get_ancestors($term->term_id, 'product_cat') as $ancestor) {
                $cats[] = (int) $ancestor;
            }
        }
    }
    $lang = function_exists('pll_get_post_language') ? (string) pll_get_post_language($id) : '';
    $text = trim(wp_strip_all_tags($product->get_name() . ' ' . $product->get_sku() . ' ' . $product->get_short_description()));
    if (function_exists('mb_substr')) {
        $text = mb_substr($text, 0, 240);
    } else {
        $text = substr($text, 0, 240);
    }
    return [
        'id' => $id,
        'title' => $product->get_name(),
        'sku' => $product->get_sku(),
        'url' => get_permalink($id),
        'image' => $image['url'],
        'srcset' => $image['srcset'],
        'width' => $image['width'],
        'height' => $image['height'],
        'image_id' => $image_id,
        'price' => (float) $product->get_price(),
        'price_html' => wp_kses_post($product->get_price_html()),
        'date' => (int) get_post_time('U', true, $id),
        'cats' => array_values(array_unique($cats)),
        'in_stock' => $product->is_in_stock(),
        'type' => $product->get_type(),
        'lang' => $lang,
        'text' => $text,
    ];
}

/**
 * @return array{url: string, srcset: string, width: int, height: int}
 */
function nmj_product_index_image(int $image_id): array
{
    $size = nmj_product_index_image_size();
    $src = wp_get_attachment_image_src($image_id, $size);
    $url = is_array($src) ? (string) $src[0] : '';
    return [
        'url' => $url,
        'srcset' => $url !== '' ? (string) wp_get_attachment_image_srcset($image_id, $size) : '',
        'width' => is_array($src) ? (int) $src[1] : 0,
        'height' => is_array($src) ? (int) $src[2] : 0,
    ];
}

function nmj_product_index_image_size(): string
{
    static $size = null;
    if (is_string($size)) {
        return $size;
    }
    $size = 'woocommerce_thumbnail';
    foreach (wp_get_registered_image_subsizes() as $name => $data) {
        if ((int) ($data['width'] ?? 0) === 600 && (int) ($data['height'] ?? 0) === 900) {
            $size = (string) $name;
            break;
        }
    }
    return $size;
}

/**
 * @param array<int, array<string, mixed>> $products
 * @return array{count: int, url: string, bytes: int}
 */
function nmj_product_index_publish(array $products): array
{
    $dir = nmj_product_index_dir();
    if (!wp_mkdir_p($dir)) {
        throw new RuntimeException('Could not create the catalog directory.');
    }
    $cat_ids = [];
    foreach ($products as $product) {
        foreach ($product['cats'] as $cat_id) {
            $cat_ids[(int) $cat_id] = true;
        }
    }
    $categories = [];
    foreach (array_keys($cat_ids) as $cat_id) {
        $term = get_term((int) $cat_id, 'product_cat');
        if (!$term instanceof WP_Term) {
            continue;
        }
        $link = get_term_link($term);
        if (is_wp_error($link)) {
            continue;
        }
        $lang = function_exists('pll_get_term_language') ? (string) pll_get_term_language($term->term_id) : '';
        $categories[] = [
            'id' => (int) $term->term_id,
            'parent' => (int) $term->parent,
            'name' => $term->name,
            'slug' => $term->slug,
            'url' => $link,
            'lang' => $lang,
        ];
    }
    $stamp = time();
    $payload = [
        'built' => $stamp,
        'products' => array_values($products),
        'categories' => $categories,
    ];
    $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode the catalog.');
    }
    $final = $dir . '/catalog-' . $stamp . '.json';
    $tmp = $final . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Could not write the catalog.');
    }
    if (!rename($tmp, $final)) {
        throw new RuntimeException('Could not publish the catalog.');
    }
    $pointer = wp_json_encode([
        'v' => $stamp,
        'url' => nmj_product_index_base_url() . 'catalog-' . $stamp . '.json',
        'count' => count($products),
    ]);
    $pointer_tmp = $dir . '/current.json.tmp';
    file_put_contents($pointer_tmp, $pointer);
    rename($pointer_tmp, $dir . '/current.json');
    foreach (glob($dir . '/catalog-*.json') ?: [] as $old) {
        if ($old !== $final) {
            unlink($old);
        }
    }
    return [
        'count' => count($products),
        'url' => nmj_product_index_base_url() . 'catalog-' . $stamp . '.json',
        'bytes' => strlen($json),
    ];
}

/**
 * @return array{count: int, url: string, bytes: int}
 */
function nmj_product_index_build_all(): array
{
    $products = [];
    foreach (nmj_product_index_ids() as $id) {
        $row = nmj_product_index_record($id);
        if ($row !== null) {
            $products[] = $row;
        }
    }
    return nmj_product_index_publish($products);
}

function nmj_product_index_legacy_redirect(): void
{
    global $pagenow;
    if ($pagenow !== 'edit.php') {
        return;
    }
    $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    $post_type = isset($_GET['post_type']) && is_string($_GET['post_type']) ? sanitize_text_field(wp_unslash($_GET['post_type'])) : '';
    if ($page !== 'nmj-catalog-index' || $post_type !== 'product') {
        return;
    }
    wp_safe_redirect(admin_url('admin.php?page=odella-catalog-index'), 302);
    exit;
}

function nmj_product_index_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $current = nmj_product_index_dir() . '/current.json';
    $info = is_readable($current) ? json_decode((string) file_get_contents($current), true) : null;
    $built = is_array($info) && isset($info['v']) ? wp_date('Y-m-d H:i', (int) $info['v']) : 'never';
    $count = is_array($info) && isset($info['count']) ? (int) $info['count'] : 0;
    $saved = false;
    if (isset($_POST['nmj_index_hours']) && check_admin_referer('nmj_product_index_schedule')) {
        $hours = min(8760, max(0, absint($_POST['nmj_index_hours'])));
        $minutes = min(59, max(0, absint($_POST['nmj_index_minutes'] ?? 0)));
        update_option('nmj_product_index_hours', $hours);
        update_option('nmj_product_index_minutes', $minutes);
        nmj_product_index_ensure_schedule();
        $saved = true;
    }
    $hours = nmj_product_index_hours();
    $minutes = nmj_product_index_minutes();
    $next = wp_next_scheduled('nmj_product_index_cron');
    ?>
    <div class="wrap">
        <h1>Catalog index</h1>
        <p>The store filter reads this index instead of rebuilding the shop page. Rebuild it from here or from Reindex in the top toolbar. Shoppers keep using the previous file until the new one is ready.</p>
        <?php if ($saved) : ?>
            <div class="notice notice-success"><p>Schedule saved.</p></div>
        <?php endif; ?>
        <p><strong>Last build:</strong> <?php echo esc_html($built); ?> · <strong>Products:</strong> <?php echo esc_html((string) $count); ?></p>
        <p><button class="button button-primary" id="nmj-index-build" type="button">Rebuild index</button> <span id="nmj-index-status"></span></p>
        <form method="post">
            <?php wp_nonce_field('nmj_product_index_schedule'); ?>
            <h2>Automatic rebuild</h2>
            <p>
                <label>Hours <input type="number" name="nmj_index_hours" min="0" max="8760" step="1" value="<?php echo esc_attr((string) $hours); ?>" class="small-text"></label>
                <label>Minutes <input type="number" name="nmj_index_minutes" min="0" max="59" step="1" value="<?php echo esc_attr((string) $minutes); ?>" class="small-text"></label>
            </p>
            <p>0 h and 0 m turns automatic indexing off. The index is rebuilt only when you press the button.</p>
            <p>
                <?php if ($hours === 0 && $minutes === 0) : ?>
                    Automatic indexing is off.
                <?php elseif ($next) : ?>
                    Next automatic rebuild: <?php echo esc_html(wp_date('Y-m-d H:i', $next)); ?>.
                <?php endif; ?>
            </p>
            <?php submit_button('Save schedule'); ?>
        </form>
    </div>
    <?php
}

function nmj_product_index_footer_script(): void
{
    if (!is_admin_bar_showing() || !current_user_can('manage_woocommerce')) {
        return;
    }
    $config = [
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('nmj_product_index'),
    ];
    ?>
    <script>
    (function () {
        var cfg = <?php echo wp_json_encode($config); ?>;
        async function rebuild(onStatus) {
            var post = async function (fields) {
                var body = new URLSearchParams(fields);
                body.set('action', 'nmj_product_index');
                body.set('nonce', cfg.nonce);
                var response = await fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body });
                var data = await response.json();
                if (!data.success) {
                    throw new Error((data.data && data.data.message) || 'Rebuild failed');
                }
                return data.data;
            };
            onStatus('Starting…');
            var start = await post({ step: 'start' });
            var offset = 0;
            while (offset < start.total) {
                var batch = await post({ step: 'batch', token: start.token, offset: String(offset) });
                offset = batch.offset;
                onStatus(offset + ' / ' + start.total);
            }
            var done = await post({ step: 'finish', token: start.token });
            onStatus('Indexed ' + done.count);
        }
        var bar = document.querySelector('#wp-admin-bar-nmj-reindex > a');
        if (bar) {
            bar.addEventListener('click', function (event) {
                event.preventDefault();
                if (bar.dataset.busy === '1') {
                    return;
                }
                bar.dataset.busy = '1';
                rebuild(function (text) { bar.textContent = text; }).catch(function (error) {
                    bar.textContent = error.message;
                }).finally(function () {
                    bar.dataset.busy = '0';
                    window.setTimeout(function () { bar.textContent = 'Reindex'; }, 4000);
                });
            });
        }
        var button = document.getElementById('nmj-index-build');
        if (button) {
            button.addEventListener('click', function () {
                var status = document.getElementById('nmj-index-status');
                button.disabled = true;
                rebuild(function (text) {
                    if (status) {
                        status.textContent = text;
                    }
                }).catch(function (error) {
                    if (status) {
                        status.textContent = error.message;
                    }
                }).finally(function () {
                    button.disabled = false;
                });
            });
        }
    })();
    </script>
    <?php
}

function nmj_product_index_ajax(): void
{
    check_ajax_referer('nmj_product_index', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => 'Not allowed'], 403);
    }
    $step = isset($_POST['step']) ? sanitize_key(wp_unslash($_POST['step'])) : '';
    try {
        if ($step === 'start') {
            $token = bin2hex(random_bytes(16));
            $state = [
                'ids' => nmj_product_index_ids(),
                'offset' => 0,
            ];
            nmj_product_index_write_state($token, $state);
            wp_send_json_success(['token' => $token, 'total' => count($state['ids'])]);
        }
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            wp_send_json_error(['message' => 'Bad token'], 400);
        }
        $state = nmj_product_index_read_state($token);
        if ($step === 'batch') {
            $offset = max(0, absint($_POST['offset'] ?? 0));
            $slice = array_slice($state['ids'], $offset, 40);
            $rows = [];
            foreach ($slice as $id) {
                $row = nmj_product_index_record((int) $id);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
            $path = nmj_product_index_rows_path($token);
            $lines = '';
            foreach ($rows as $row) {
                $lines .= wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
            file_put_contents($path, $lines, FILE_APPEND);
            $offset += count($slice);
            $state['offset'] = $offset;
            nmj_product_index_write_state($token, $state);
            wp_send_json_success(['offset' => $offset]);
        }
        if ($step === 'finish') {
            $products = [];
            $path = nmj_product_index_rows_path($token);
            if (is_readable($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    $row = json_decode($line, true);
                    if (is_array($row)) {
                        $products[] = $row;
                    }
                }
            }
            $result = nmj_product_index_publish($products);
            @unlink($path);
            @unlink(nmj_product_index_state_path($token));
            wp_send_json_success($result);
        }
    } catch (Throwable $e) {
        wp_send_json_error(['message' => $e->getMessage()], 500);
    }
    wp_send_json_error(['message' => 'Unknown step'], 400);
}

function nmj_product_index_state_path(string $token): string
{
    return nmj_product_index_dir() . '/build-' . $token . '.json';
}

function nmj_product_index_rows_path(string $token): string
{
    return nmj_product_index_dir() . '/build-' . $token . '.jsonl';
}

/**
 * @param array<string, mixed> $state
 */
function nmj_product_index_write_state(string $token, array $state): void
{
    if (!wp_mkdir_p(nmj_product_index_dir())) {
        throw new RuntimeException('Could not create the catalog directory.');
    }
    file_put_contents(nmj_product_index_state_path($token), wp_json_encode($state));
}

/**
 * @return array{ids: array<int, int>, offset: int}
 */
function nmj_product_index_read_state(string $token): array
{
    $raw = file_get_contents(nmj_product_index_state_path($token));
    $state = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($state) || !isset($state['ids']) || !is_array($state['ids'])) {
        throw new RuntimeException('Build expired. Start again.');
    }
    return [
        'ids' => array_map('intval', $state['ids']),
        'offset' => (int) ($state['offset'] ?? 0),
    ];
}
