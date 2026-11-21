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
        'odella-settings1',
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

function odella_pricing_hidden_query(string $meta_key): WP_Query
{
    return new WP_Query([
        'post_type' => 'product',
        'post_status' => 'any',
        'meta_key' => $meta_key,
        'meta_value' => '1',
        'posts_per_page' => 200,
        'lang' => '',
        'orderby' => 'ID',
        'order' => 'ASC',
        'ignore_sticky_posts' => true,
        'no_found_rows' => false,
    ]);
}

function odella_pricing_render_hidden_table(WP_Query $hidden): void
{
    $total = (int) $hidden->found_posts;
    $shown = is_array($hidden->posts) ? count($hidden->posts) : 0;
    if ($total > $shown) {
        echo '<p>Showing ' . esc_html((string) $shown) . ' of ' . esc_html((string) $total) . '.</p>';
    }
    if ($shown === 0 || !is_array($hidden->posts)) {
        return;
    }
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th scope="col">ID</th><th scope="col">Title</th><th scope="col">SKU</th><th scope="col">Language</th><th scope="col">Status</th>';
    echo '</tr></thead><tbody>';
    foreach ($hidden->posts as $hidden_post) {
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
        echo '<tr><td>' . esc_html((string) $hidden_id) . '</td><td>';
        if (is_string($edit_link) && $edit_link !== '') {
            echo '<a href="' . esc_url($edit_link) . '">' . esc_html($title) . '</a>';
        } else {
            echo esc_html($title);
        }
        echo '</td><td>' . ($sku !== '' ? esc_html($sku) : '—') . '</td>';
        echo '<td>' . ($language !== '' ? esc_html($language) : '—') . '</td>';
        echo '<td>' . ($status !== '' ? esc_html($status) : '—') . '</td></tr>';
    }
    echo '</tbody></table>';
}

function odella_pricing_admin_page(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html('You do not have permission to view Pricing & Rates.'));
    }

    $notice = '';
    $notice_class = 'notice-success';
    $pricing_notices = [];
    if (
        isset($_SERVER['REQUEST_METHOD'])
        && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['odella_save_pricing_settings'])
        && function_exists('odella_pricing_save_settings')
    ) {
        check_admin_referer('odella_pricing_settings');
        $pricing_notices = odella_pricing_save_settings([
            'interval_hours' => isset($_POST['odella_pricing_interval']) ? wp_unslash($_POST['odella_pricing_interval']) : '24',
            'fee_percent' => isset($_POST['odella_pricing_fee']) ? wp_unslash($_POST['odella_pricing_fee']) : '0',
            'rounding' => isset($_POST['odella_pricing_rounding']) ? wp_unslash($_POST['odella_pricing_rounding']) : 'end_in_9',
            'reduce_by' => isset($_POST['odella_pricing_reduce']) ? wp_unslash($_POST['odella_pricing_reduce']) : 'off',
            'round_after_tax' => isset($_POST['odella_pricing_round_after_tax']) ? '1' : '0',
        ]);
    }
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
    $hidden = odella_pricing_hidden_query('_odella_auto_hidden');
    $hidden_total = (int) $hidden->found_posts;
    $hidden_image = odella_pricing_hidden_query('_odella_auto_hidden_image');
    $hidden_image_total = (int) $hidden_image->found_posts;
    ?>
    <div class="wrap">
        <h1>Pricing &amp; Rates</h1>
        <?php if ($notice !== '') : ?>
            <div class="notice <?php echo esc_attr($notice_class); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>
        <?php foreach ($pricing_notices as $pricing_notice) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($pricing_notice); ?></p></div>
        <?php endforeach; ?>
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
        <?php if (function_exists('odella_pricing_settings') && function_exists('odella_pricing_preview_amount')) : ?>
            <?php
            $pricing = odella_pricing_settings();
            $preview = odella_pricing_preview_amount(148.30);
            $fee_rate = '';
            if ($eurozone !== '') {
                $fee_rate = (string) round((float) $eurozone * (1 + ((float) $pricing['fee_percent'] / 100)), 6);
            }
            ?>
            <h2>Calculated foreign prices</h2>
            <p>These controls do the work of the grey Pro fields. The exchange rate stored in Price Based on Country stays the National Bank rate. A typed EUR or USD price on a product is left as entered. GEL prices are left as entered.</p>
            <form method="post" style="max-width:720px;">
                <?php wp_nonce_field('odella_pricing_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="odella-pricing-interval">Update every</label></th>
                        <td>
                            <input id="odella-pricing-interval" name="odella_pricing_interval" type="number" min="1" max="168" step="1" value="<?php echo esc_attr((string) $pricing['interval_hours']); ?>" class="small-text" />
                            hours
                            <p class="description">24 keeps the daily run at 03:30 Asia/Tbilisi. Any other number runs that many hours after you save.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="odella-pricing-fee">Exchange rate fee</label></th>
                        <td>
                            <input id="odella-pricing-fee" name="odella_pricing_fee" type="number" min="0" max="100" step="0.01" value="<?php echo esc_attr((string) $pricing['fee_percent']); ?>" class="small-text" />
                            %
                            <p class="description">Added on top of the bank rate for calculated EUR and USD prices. 0 leaves today’s prices unchanged. Type 1 for a 1% markup<?php echo $fee_rate !== '' ? '. The eurozone rate used for conversion would be ' . esc_html($fee_rate) : ''; ?>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="odella-pricing-rounding">Round calculated prices</label></th>
                        <td>
                            <select id="odella-pricing-rounding" name="odella_pricing_rounding">
                                <option value="end_in_9" <?php selected($pricing['rounding'], 'end_in_9'); ?>>Up to the next whole number ending in 9</option>
                                <option value="whole" <?php selected($pricing['rounding'], 'whole'); ?>>Up to the next whole number</option>
                                <option value="multiple_10" <?php selected($pricing['rounding'], 'multiple_10'); ?>>Up to the next multiple of 10</option>
                                <option value="none" <?php selected($pricing['rounding'], 'none'); ?>>No rounding</option>
                            </select>
                            <p class="description">A converted 148.30 displays as <?php echo esc_html((string) $preview); ?> with the settings on this screen.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="odella-pricing-reduce">Then reduce by</label></th>
                        <td>
                            <select id="odella-pricing-reduce" name="odella_pricing_reduce">
                                <option value="off" <?php selected($pricing['reduce_by'], 'off'); ?>>Off</option>
                                <option value="0.01" <?php selected($pricing['reduce_by'], '0.01'); ?>>0.01</option>
                                <option value="1" <?php selected($pricing['reduce_by'], '1'); ?>>1</option>
                            </select>
                            <p class="description">Ignored while rounding is “ending in 9”, so the two steps cannot stack.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Round after tax</th>
                        <td>
                            <label>
                                <input name="odella_pricing_round_after_tax" type="checkbox" value="1" <?php checked(!empty($pricing['round_after_tax'])); ?> />
                                Round the tax-inclusive price instead of the price before tax
                            </label>
                            <p class="description">Leave this off while WooCommerce is not adding tax.</p>
                        </td>
                    </tr>
                </table>
                <p><button type="submit" class="button button-primary" name="odella_save_pricing_settings" value="1">Save pricing settings</button></p>
            </form>
        <?php endif; ?>

        <h2>Auto-hidden unpriced products</h2>
        <p><?php echo esc_html((string) $hidden_total); ?> auto-hidden unpriced <?php echo $hidden_total === 1 ? 'product' : 'products'; ?>.</p>
        <?php if ($hidden_total === 0) : ?>
            <p>No products are auto-hidden for a missing GEL price.</p>
        <?php else : ?>
            <?php odella_pricing_render_hidden_table($hidden); ?>
        <?php endif; ?>

        <h2>Auto-hidden products with no featured image</h2>
        <p><?php echo esc_html((string) $hidden_image_total); ?> auto-hidden <?php echo $hidden_image_total === 1 ? 'product' : 'products'; ?> with no featured image. They stay published, and they are left out of the catalog index and the store.</p>
        <?php if ($hidden_image_total === 0) : ?>
            <p>No products are auto-hidden for a missing featured image.</p>
        <?php else : ?>
            <?php odella_pricing_render_hidden_table($hidden_image); ?>
        <?php endif; ?>
    </div>
    <?php
}
