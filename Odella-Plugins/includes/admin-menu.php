<?php
/**
 * Odella admin menu, Speed Check placeholder, and Pricing & Rates.
 */

defined('ABSPATH') || exit;

add_action('admin_menu', 'odella_register_admin_menu', 9);
add_action('admin_bar_menu', 'odella_admin_bar_is_slow', 81);

function odella_register_admin_menu(): void
{
    if (!function_exists('add_menu_page')) {
        $admin_includes = ABSPATH . 'wp-admin/includes/plugin.php';
        if (is_readable($admin_includes)) {
            require_once $admin_includes;
        }
    }
    if (!function_exists('add_menu_page') || !function_exists('add_submenu_page')) {
        return;
    }

    add_menu_page(
        'Odella',
        'Odella',
        'manage_woocommerce',
        'odella-speed-check',
        'odella_speed_check_page_dispatch',
        'dashicons-performance',
        58
    );
    add_submenu_page(
        'odella-speed-check',
        'Speed Check',
        'Speed Check',
        'manage_woocommerce',
        'odella-speed-check',
        'odella_speed_check_page_dispatch'
    );
    add_submenu_page(
        'odella-speed-check',
        'Catalog & Cache',
        'Catalog & Cache',
        'manage_woocommerce',
        'odella-catalog-index',
        'nmj_product_index_page'
    );
    add_submenu_page(
        'odella-speed-check',
        'Pricing & Rates',
        'Pricing & Rates',
        'manage_woocommerce',
        'odella-pricing',
        'odella_pricing_admin_page'
    );
    add_submenu_page(
        'odella-speed-check',
        'Settings',
        'Settings',
        'manage_options',
        'odella-settings',
        'odella_settings_page'
    );
}

function odella_speed_check_page_dispatch(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html('You do not have permission to view Speed Check.'));
    }
    if (function_exists('odella_speed_check_page')) {
        odella_speed_check_page();
        return;
    }
    echo '<div class="wrap">';
    echo '<h1>Speed Check</h1>';
    echo '<div class="card"><p>Speed Check arrives in a later update.</p></div>';
    echo '</div>';
}

/**
 * @param WP_Admin_Bar $bar
 */
function odella_admin_bar_is_slow($bar): void
{
    if (!is_object($bar) || !method_exists($bar, 'add_node') || !current_user_can('manage_woocommerce')) {
        return;
    }
    $bar->add_node([
        'id' => 'odella-is-slow',
        'parent' => 'top-secondary',
        'title' => 'Is Slow?',
        'href' => admin_url('admin.php?page=odella-speed-check&autorun=1'),
    ]);
}

function odella_pricing_zone_rate(array $regions, string $zone_id): string
{
    if (!isset($regions[$zone_id]) || !is_array($regions[$zone_id]) || !array_key_exists('exchange_rate', $regions[$zone_id])) {
        return '';
    }
    $rate = $regions[$zone_id]['exchange_rate'];
    if (is_string($rate) || is_int($rate) || is_float($rate)) {
        return (string) $rate;
    }
    return '';
}

function odella_pricing_admin_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html('You do not have permission to view Pricing & Rates.'));
    }

    $notice = '';
    $notice_class = 'notice-success';
    if (
        isset($_SERVER['REQUEST_METHOD'])
        && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['odella_sync_nbg_rates'])
    ) {
        check_admin_referer('odella_sync_nbg_rates');
        if (function_exists('odella_update_zone_rates')) {
            odella_update_zone_rates();
            $notice = 'NBG rates synced. The figures below are the rates stored now.';
        } else {
            $notice = 'odella_update_zone_rates() is not available.';
            $notice_class = 'notice-error';
        }
    }

    $last = get_option('odella_rates_last');
    if (!is_array($last)) {
        $last = [];
    }
    $synced_on = isset($last['date']) && (is_string($last['date']) || is_numeric($last['date'])) ? (string) $last['date'] : '';
    $eur = isset($last['EUR']) && (is_string($last['EUR']) || is_numeric($last['EUR'])) ? (string) $last['EUR'] : '';
    $usd = isset($last['USD']) && (is_string($last['USD']) || is_numeric($last['USD'])) ? (string) $last['USD'] : '';

    $regions = get_option('wc_price_based_country_regions');
    if (!is_array($regions)) {
        $regions = [];
    }
    $eurozone = odella_pricing_zone_rate($regions, 'eurozone');
    $global = odella_pricing_zone_rate($regions, 'global');

    $next_raw = wp_next_scheduled('odella_daily_rates');
    $next_label = is_numeric($next_raw) && (int) $next_raw > 0
        ? wp_date('Y-m-d H:i', (int) $next_raw)
        : 'Not scheduled';

    $rates_empty = $synced_on === '' && $eur === '' && $usd === '' && $eurozone === '' && $global === '';
    $hidden = new WP_Query([
        'post_type' => 'product',
        'post_status' => 'any',
        'meta_key' => '_odella_auto_hidden',
        'meta_value' => '1',
        'posts_per_page' => 200,
        'lang' => '',
        'orderby' => 'ID',
        'order' => 'ASC',
        'ignore_sticky_posts' => true,
        'no_found_rows' => false,
    ]);
    $hidden_total = (int) $hidden->found_posts;
    $hidden_shown = is_array($hidden->posts) ? count($hidden->posts) : 0;
    ?>
    <div class="wrap">
        <h1>Pricing &amp; Rates</h1>
        <?php if ($notice !== '') : ?>
            <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>
        <h2>NBG exchange rates</h2>
        <?php if ($rates_empty) : ?>
            <p>No NBG rates have been stored yet.</p>
        <?php endif; ?>
        <table class="widefat striped" style="max-width:720px;">
            <tbody>
                <tr>
                    <th scope="row">Last sync date</th>
                    <td><?php echo $synced_on !== '' ? esc_html($synced_on) : 'Not recorded'; ?></td>
                </tr>
                <tr>
                    <th scope="row">EUR</th>
                    <td><?php echo $eur !== '' ? esc_html($eur) : 'Not recorded'; ?></td>
                </tr>
                <tr>
                    <th scope="row">USD</th>
                    <td><?php echo $usd !== '' ? esc_html($usd) : 'Not recorded'; ?></td>
                </tr>
                <tr>
                    <th scope="row">Eurozone rate</th>
                    <td><?php echo $eurozone !== '' ? esc_html($eurozone) : 'Not recorded'; ?></td>
                </tr>
                <tr>
                    <th scope="row">Global rate</th>
                    <td><?php echo $global !== '' ? esc_html($global) : 'Not recorded'; ?></td>
                </tr>
                <tr>
                    <th scope="row">Next odella_daily_rates</th>
                    <td><?php echo esc_html($next_label); ?></td>
                </tr>
            </tbody>
        </table>
        <form method="post" style="margin-top:16px;">
            <?php wp_nonce_field('odella_sync_nbg_rates'); ?>
            <button type="submit" class="button button-primary" name="odella_sync_nbg_rates" value="1">Sync NBG Rates Now</button>
        </form>

        <h2>Auto-hidden unpriced products</h2>
        <p><?php echo esc_html((string) $hidden_total); ?> auto-hidden unpriced <?php echo $hidden_total === 1 ? 'product' : 'products'; ?>.</p>
        <?php if ($hidden_total > $hidden_shown) : ?>
            <p>Showing <?php echo esc_html((string) $hidden_shown); ?> of <?php echo esc_html((string) $hidden_total); ?>.</p>
        <?php endif; ?>
        <?php if ($hidden_shown === 0) : ?>
            <p>No products are auto-hidden for a missing GEL price.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th scope="col">ID</th>
                        <th scope="col">Title</th>
                        <th scope="col">SKU</th>
                        <th scope="col">Language</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hidden->posts as $hidden_post) : ?>
                        <?php
                        if (!$hidden_post instanceof WP_Post) {
                            continue;
                        }
                        $hidden_id = (int) $hidden_post->ID;
                        $edit_link = get_edit_post_link($hidden_id);
                        $title = get_the_title($hidden_post);
                        if ($title === '') {
                            $title = '(no title)';
                        }
                        $sku = '';
                        if (function_exists('wc_get_product')) {
                            $product = wc_get_product($hidden_id);
                            if ($product instanceof WC_Product) {
                                $sku = $product->get_sku();
                            }
                        }
                        $language = '';
                        if (function_exists('pll_get_post_language')) {
                            $language_raw = pll_get_post_language($hidden_id);
                            $language = is_string($language_raw) ? $language_raw : '';
                        }
                        $status = is_string($hidden_post->post_status) ? $hidden_post->post_status : '';
                        ?>
                        <tr>
                            <td><?php echo esc_html((string) $hidden_id); ?></td>
                            <td>
                                <?php if (is_string($edit_link) && $edit_link !== '') : ?>
                                    <a href="<?php echo esc_url($edit_link); ?>"><?php echo esc_html($title); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($title); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $sku !== '' ? esc_html($sku) : '—'; ?></td>
                            <td><?php echo $language !== '' ? esc_html($language) : '—'; ?></td>
                            <td><?php echo $status !== '' ? esc_html($status) : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
