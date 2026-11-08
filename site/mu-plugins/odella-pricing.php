<?php
/**
 * Plugin Name: Odella Pricing Guards (MU)
 * Description: Hides published products that have no GEL price, keeps newly unpriced products as drafts, and fills EUR/USD zone prices from the National Bank of Georgia unless a manual zone price is entered.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Base GEL price: simple/external use their own regular price, a variable
 * product needs one priced variation, and a grouped product has no price of its own.
 */
function odella_product_has_price( WC_Product $product ): bool {
    $type = $product->get_type();
    if ( 'grouped' === $type ) {
        return true;
    }
    if ( 'variable' === $type ) {
        foreach ( $product->get_children() as $child_id ) {
            $child = wc_get_product( $child_id );
            if ( $child instanceof WC_Product && '' !== (string) $child->get_regular_price() ) {
                return true;
            }
        }
        return false;
    }
    return '' !== (string) $product->get_regular_price();
}

/**
 * Hide a published product with no price, or undo a hide this plugin applied
 * once a price exists. Terms the owner set themselves are left in place.
 */
function odella_sync_unpriced_visibility( int $product_id ): void {
    if ( $product_id <= 0 || ! taxonomy_exists( 'product_visibility' ) ) {
        return;
    }
    $post = get_post( $product_id );
    if ( ! $post || 'product' !== $post->post_type ) {
        return;
    }
    $product = wc_get_product( $product_id );
    if ( ! $product instanceof WC_Product ) {
        return;
    }

    $auto      = get_post_meta( $product_id, '_odella_auto_hidden', true );
    $has_price = odella_product_has_price( $product );
    $managed   = array( 'exclude-from-catalog', 'exclude-from-search' );

    if ( 'publish' === $post->post_status && ! $has_price ) {
        if ( ! $auto ) {
            $current = wp_get_object_terms( $product_id, 'product_visibility', array( 'fields' => 'slugs' ) );
            if ( is_wp_error( $current ) || ! is_array( $current ) ) {
                $current = array();
            }
            $to_add = array_values( array_diff( $managed, $current ) );
            // Both terms already present means the owner hid it. Do not claim the hide.
            if ( $to_add ) {
                wp_set_object_terms( $product_id, $to_add, 'product_visibility', true );
                update_post_meta( $product_id, '_odella_auto_hidden', '1' );
                update_post_meta( $product_id, '_odella_auto_hidden_terms', wp_json_encode( $to_add ) );
            }
        }
    } elseif ( $has_price && $auto ) {
        $raw   = get_post_meta( $product_id, '_odella_auto_hidden_terms', true );
        $added = is_string( $raw ) ? json_decode( $raw, true ) : array();
        if ( ! is_array( $added ) ) {
            $added = array();
        }
        $added = array_values( array_intersect( $added, $managed ) );
        if ( $added ) {
            wp_remove_object_terms( $product_id, $added, 'product_visibility' );
        }
        delete_post_meta( $product_id, '_odella_auto_hidden' );
        delete_post_meta( $product_id, '_odella_auto_hidden_terms' );
    }

    wc_delete_product_transients( $product_id );
}

/**
 * Product IDs whose price or status changed during this request.
 *
 * @param int  $product_id Collect this ID, or 0 to leave the queue unchanged.
 * @param bool $take       Return the queued IDs and clear the queue.
 * @return int[]
 */
function odella_pricing_queue( int $product_id = 0, bool $take = false ): array {
    static $ids = array();
    if ( $take ) {
        $out = array_map( 'intval', array_keys( $ids ) );
        $ids = array();
        return $out;
    }
    if ( $product_id > 0 ) {
        $ids[ $product_id ] = true;
    }
    return array_map( 'intval', array_keys( $ids ) );
}

/**
 * True while this plugin is writing a product, so those writes are not processed again.
 */
function odella_pricing_recursion( $value = null ): bool {
    static $flag = false;
    if ( is_bool( $value ) ) {
        $flag = $value;
    }
    return $flag;
}

/**
 * Queue a product. Variation writes are tracked on the parent.
 */
function odella_pricing_collect( int $post_id ): void {
    if ( $post_id <= 0 || odella_pricing_recursion() ) {
        return;
    }
    $post = get_post( $post_id );
    if ( ! $post ) {
        return;
    }
    if ( 'product_variation' === $post->post_type ) {
        $post_id = (int) $post->post_parent;
    } elseif ( 'product' !== $post->post_type ) {
        return;
    }
    if ( $post_id > 0 ) {
        odella_pricing_queue( $post_id );
    }
}

/**
 * WooCommerce leaves `_price` empty when only `_regular_price` was written.
 * Saving the product rebuilds `_price` and the lookup table.
 */
function odella_pricing_rebuild_price_if_needed( int $product_id ): void {
    $regular = get_post_meta( $product_id, '_regular_price', true );
    $price   = get_post_meta( $product_id, '_price', true );
    if ( '' === (string) $regular || '' !== (string) $price ) {
        return;
    }
    $product = wc_get_product( $product_id );
    if ( $product instanceof WC_Product ) {
        odella_pricing_recursion( true );
        try {
            $product->save();
        } finally {
            odella_pricing_recursion( false );
        }
    }
}

/**
 * A product published with no price in this request goes back to Draft.
 * Products already marked `_odella_auto_hidden` are the legacy unpriced
 * catalog and stay published.
 */
function odella_pricing_keep_unpriced_as_draft( int $product_id ): void {
    $post = get_post( $product_id );
    if ( ! $post || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
        return;
    }
    if ( get_post_meta( $product_id, '_odella_auto_hidden', true ) ) {
        return;
    }
    $product = wc_get_product( $product_id );
    if ( ! $product instanceof WC_Product || odella_product_has_price( $product ) ) {
        return;
    }

    odella_pricing_recursion( true );
    try {
        $result = wp_update_post(
            array(
                'ID'          => $product_id,
                'post_status' => 'draft',
            ),
            true
        );
    } finally {
        odella_pricing_recursion( false );
    }
    if ( is_wp_error( $result ) || ! $result ) {
        return;
    }

    $user_id = (int) get_current_user_id();
    if ( $user_id <= 0 ) {
        return;
    }
    $key = 'odella_unpriced_drafted_' . $user_id;
    $ids = get_transient( $key );
    if ( ! is_array( $ids ) ) {
        $ids = array();
    }
    $ids[] = $product_id;
    set_transient( $key, array_values( array_unique( array_map( 'intval', $ids ) ) ), DAY_IN_SECONDS );
}

/**
 * Yellow notice on every wp-admin screen. One view, then the transient is gone.
 */
function odella_pricing_admin_notice(): void {
    $user_id = (int) get_current_user_id();
    if ( $user_id <= 0 ) {
        return;
    }
    $key = 'odella_unpriced_drafted_' . $user_id;
    $ids = get_transient( $key );
    if ( ! is_array( $ids ) || ! $ids ) {
        return;
    }
    delete_transient( $key );

    $label = 0 === strpos( (string) get_user_locale(), 'ka' )
        ? 'შენახულია მონახაზად, რადგან ფასი არ აქვს:'
        : 'Kept as Draft because they have no price:';

    $links = array();
    foreach ( $ids as $id ) {
        $id    = (int) $id;
        $title = get_the_title( $id );
        if ( ! is_string( $title ) || '' === $title ) {
            $title = '#' . $id;
        }
        $text = $title . ' (' . $id . ')';
        $url  = get_edit_post_link( $id, 'raw' );
        if ( $url ) {
            $links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
        } else {
            $links[] = esc_html( $text );
        }
    }
    if ( ! $links ) {
        return;
    }

    echo '<div class="notice notice-warning"><p>' . esc_html( $label ) . ' ' . implode( ', ', $links ) . '</p></div>';
}

/**
 * Apply queued visibility changes once, after every writer in the request
 * (product screen, CRUD, direct post meta, Sheet Editor) has finished.
 */
function odella_pricing_on_shutdown(): void {
    static $running = false;
    if ( $running || ! function_exists( 'wc_get_product' ) ) {
        return;
    }
    $running = true;
    $guard   = 0;
    while ( $guard < 5 ) {
        $ids = odella_pricing_queue( 0, true );
        if ( ! $ids ) {
            break;
        }
        foreach ( $ids as $product_id ) {
            odella_pricing_rebuild_price_if_needed( $product_id );
            odella_pricing_keep_unpriced_as_draft( $product_id );
            odella_sync_unpriced_visibility( $product_id );
            odella_pricing_sync_zone_prices( $product_id );
        }
        $guard++;
    }
    $running = false;
}

/**
 * One-time (and re-runnable) pass over every published product.
 */
function odella_pricing_backfill(): void {
    $ids = get_posts(
        array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'suppress_filters'       => true,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        )
    );

    $hidden   = 0;
    $restored = 0;
    foreach ( $ids as $product_id ) {
        $product_id = (int) $product_id;
        $before     = (bool) get_post_meta( $product_id, '_odella_auto_hidden', true );
        odella_sync_unpriced_visibility( $product_id );
        $after = (bool) get_post_meta( $product_id, '_odella_auto_hidden', true );
        if ( ! $before && $after ) {
            $hidden++;
        } elseif ( $before && ! $after ) {
            $restored++;
        }
    }

    echo 'hidden=' . $hidden . ' restored=' . $restored . "\n";
}

/**
 * A hidden product's own URL still opens. Ask Rank Math not to index it.
 *
 * @param array<string, string> $robots
 * @return array<string, string>
 */
function odella_pricing_robots( $robots ) {
    if ( ! is_array( $robots ) || ! is_singular( 'product' ) ) {
        return $robots;
    }
    $product_id = (int) get_queried_object_id();
    if ( $product_id > 0 && get_post_meta( $product_id, '_odella_auto_hidden', true ) ) {
        $robots['index'] = 'noindex';
    }
    return $robots;
}

add_action(
    'save_post_product',
    static function ( $post_id ) {
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        odella_pricing_collect( (int) $post_id );
    }
);

add_action(
    'woocommerce_update_product',
    static function ( $product_id ) {
        odella_pricing_collect( (int) $product_id );
    }
);

add_action(
    'woocommerce_new_product',
    static function ( $product_id ) {
        odella_pricing_collect( (int) $product_id );
    }
);

/**
 * Direct update_post_meta / delete_post_meta writes, including Sheet Editor.
 *
 * @param mixed  $meta_id Unused. deleted_post_meta passes an array of IDs.
 * @param int    $post_id
 * @param string $meta_key
 */
function odella_pricing_on_price_meta( $meta_id, $post_id, $meta_key ): void {
    unset( $meta_id );
    $watched = array(
        '_regular_price',
        '_price',
        '_sale_price',
        '_eurozone_regular_price',
        '_eurozone_sale_price',
        '_global_regular_price',
        '_global_sale_price',
    );
    if ( ! in_array( $meta_key, $watched, true ) ) {
        return;
    }
    odella_pricing_collect( (int) $post_id );
}

add_action( 'added_post_meta', 'odella_pricing_on_price_meta', 10, 3 );
add_action( 'updated_post_meta', 'odella_pricing_on_price_meta', 10, 3 );
add_action( 'deleted_post_meta', 'odella_pricing_on_price_meta', 10, 3 );

add_action(
    'vg_sheet_editor/save_rows/after_saving_post',
    static function ( $post_id ) {
        odella_pricing_collect( (int) $post_id );
    }
);

add_action(
    'transition_post_status',
    static function ( $new_status, $old_status, $post ) {
        unset( $new_status, $old_status );
        if ( $post instanceof WP_Post && 'product' === $post->post_type ) {
            odella_pricing_collect( (int) $post->ID );
        }
    },
    10,
    3
);

add_action( 'shutdown', 'odella_pricing_on_shutdown' );
add_action( 'admin_notices', 'odella_pricing_admin_notice' );
add_filter( 'rank_math/frontend/robots', 'odella_pricing_robots' );

/**
 * National Bank of Georgia JSON. The currencies=EUR,USD filter currently returns [],
 * so the same endpoint without that filter is the fallback. Shape is unchanged.
 *
 * @return array<int, mixed>|null
 */
function odella_fetch_nbg_rates() {
    $urls = array(
        'https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies/en/json/?currencies=EUR,USD',
        'https://nbg.gov.ge/gw/api/ct/monetarypolicy/currencies/en/json',
    );
    $last_code = 0;
    foreach ( $urls as $url ) {
        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );
        if ( is_wp_error( $response ) ) {
            error_log( 'Odella rates: NBG request failed: ' . $response->get_error_message() );
            continue;
        }
        $last_code = (int) wp_remote_retrieve_response_code( $response );
        $body      = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $last_code || ! is_array( $body ) || empty( $body[0]['currencies'] ) || ! is_array( $body[0]['currencies'] ) ) {
            continue;
        }
        $codes = array();
        foreach ( $body[0]['currencies'] as $row ) {
            if ( is_array( $row ) && isset( $row['code'] ) ) {
                $codes[] = (string) $row['code'];
            }
        }
        if ( in_array( 'EUR', $codes, true ) && in_array( 'USD', $codes, true ) ) {
            return $body;
        }
    }
    error_log( 'Odella rates: NBG response was not usable JSON (HTTP ' . $last_code . ').' );
    return null;
}

/**
 * National Bank of Georgia: GEL per 1 foreign unit. Zone rate is foreign units per 1 GEL.
 */
function odella_update_zone_rates(): void {
    $body = odella_fetch_nbg_rates();
    if ( ! is_array( $body ) ) {
        return;
    }

    $by_code = array();
    foreach ( $body[0]['currencies'] as $row ) {
        if ( is_array( $row ) && isset( $row['code'] ) ) {
            $by_code[ (string) $row['code'] ] = $row;
        }
    }

    $regions = get_option( 'wc_price_based_country_regions' );
    if ( ! is_array( $regions ) ) {
        error_log( 'Odella rates: wc_price_based_country_regions is missing.' );
        return;
    }

    $map     = array(
        'eurozone' => 'EUR',
        'global'   => 'USD',
    );
    $applied  = array();
    $changed  = false;
    $accepted = false;
    foreach ( $map as $zone_id => $currency ) {
        if ( empty( $regions[ $zone_id ] ) || ! is_array( $regions[ $zone_id ] ) ) {
            error_log( 'Odella rates: zone ' . $zone_id . ' is missing; left unchanged.' );
            continue;
        }
        $row = isset( $by_code[ $currency ] ) ? $by_code[ $currency ] : null;
        if ( ! is_array( $row ) || ! isset( $row['rate'], $row['quantity'] ) || ! is_numeric( $row['rate'] ) || ! is_numeric( $row['quantity'] ) ) {
            error_log( 'Odella rates: no usable ' . $currency . ' rate; left ' . $zone_id . ' unchanged.' );
            $applied[ $currency ] = isset( $regions[ $zone_id ]['exchange_rate'] ) ? $regions[ $zone_id ]['exchange_rate'] : '';
            continue;
        }
        $gel_per_unit = (float) $row['rate'];
        $quantity     = (float) $row['quantity'];
        if ( $gel_per_unit <= 0 || $quantity <= 0 ) {
            error_log( 'Odella rates: ' . $currency . ' rate or quantity was not positive; left ' . $zone_id . ' unchanged.' );
            $applied[ $currency ] = $regions[ $zone_id ]['exchange_rate'];
            continue;
        }
        $new = round( $quantity / $gel_per_unit, 6 );
        $old = isset( $regions[ $zone_id ]['exchange_rate'] ) ? (float) $regions[ $zone_id ]['exchange_rate'] : 0.0;
        $sane = $new >= 0.15 && $new <= 0.80;
        if ( $sane && abs( $old - 1.0 ) >= 0.0000001 ) {
            $sane = $new >= ( $old * 0.9 ) && $new <= ( $old * 1.1 );
        }
        if ( ! $sane ) {
            error_log( 'Odella rates: rejected ' . $currency . ' rate ' . $new . ' (stored ' . $old . '); left ' . $zone_id . ' unchanged.' );
            $applied[ $currency ] = $regions[ $zone_id ]['exchange_rate'];
            continue;
        }
        $stored = sprintf( '%.6F', $new );
        $accepted = true;
        if ( (string) $regions[ $zone_id ]['exchange_rate'] !== $stored ) {
            $regions[ $zone_id ]['exchange_rate'] = $stored;
            $changed = true;
        }
        $applied[ $currency ] = $stored;
    }

    if ( $changed ) {
        update_option( 'wc_price_based_country_regions', $regions );
        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients();
        }
        if ( class_exists( 'WCPBC_Product_Meta_Data' ) ) {
            WCPBC_Product_Meta_Data::delete_product_transients();
        }
        if ( class_exists( 'WCPBC_Cache_Plugins_Helper' ) ) {
            WCPBC_Cache_Plugins_Helper::flush_cache();
        }
        do_action( 'litespeed_purge_all' );
    }
    if ( ! $accepted || ! isset( $applied['EUR'], $applied['USD'] ) ) {
        return;
    }
    update_option(
        'odella_rates_last',
        array(
            'date' => isset( $body[0]['date'] ) ? (string) $body[0]['date'] : '',
            'EUR'  => $applied['EUR'],
            'USD'  => $applied['USD'],
        )
    );
}

/**
 * 03:30 Asia/Tbilisi. WP-Cron runs on traffic, which is enough for a daily job.
 */
function odella_pricing_schedule_rates(): void {
    if ( wp_next_scheduled( 'odella_daily_rates' ) ) {
        return;
    }
    try {
        $tz = new DateTimeZone( 'Asia/Tbilisi' );
    } catch ( Exception $e ) {
        error_log( 'Odella rates: Asia/Tbilisi timezone is unavailable.' );
        return;
    }
    $now  = new DateTime( 'now', $tz );
    $next = new DateTime( 'today 03:30', $tz );
    if ( $next <= $now ) {
        $next->modify( '+1 day' );
    }
    wp_schedule_event( $next->getTimestamp(), 'daily', 'odella_daily_rates' );
}

/**
 * A typed zone regular price is manual. An empty one converts from GEL.
 * Variable parents also sync their variations, because variation saves are queued on the parent.
 */
function odella_pricing_sync_zone_prices( int $product_id ): void {
    if ( $product_id <= 0 || ! class_exists( 'WCPBC_Pricing_Zones' ) ) {
        return;
    }
    $ids     = array( $product_id );
    $product = wc_get_product( $product_id );
    if ( $product instanceof WC_Product && $product->is_type( 'variable' ) ) {
        foreach ( $product->get_children() as $child_id ) {
            $ids[] = (int) $child_id;
        }
    }

    odella_pricing_recursion( true );
    try {
        foreach ( array_unique( $ids ) as $id ) {
            foreach ( array( 'eurozone', 'global' ) as $zone_id ) {
                odella_pricing_sync_one_zone( (int) $id, $zone_id );
            }
        }
    } finally {
        odella_pricing_recursion( false );
    }
}

/**
 * Set the zone price method, then the zone `_price` the cart reads.
 */
function odella_pricing_sync_one_zone( int $post_id, string $zone_id ): void {
    $regular = get_post_meta( $post_id, '_' . $zone_id . '_regular_price', true );
    if ( '' !== (string) $regular ) {
        update_post_meta( $post_id, '_' . $zone_id . '_price_method', 'manual' );
        $sale = get_post_meta( $post_id, '_' . $zone_id . '_sale_price', true );
        update_post_meta( $post_id, '_' . $zone_id . '_price', '' !== (string) $sale ? $sale : $regular );
        return;
    }

    update_post_meta( $post_id, '_' . $zone_id . '_price_method', 'exchange_rate' );
    $zone = WCPBC_Pricing_Zones::get_zone( $zone_id );
    if ( ! is_object( $zone ) || ! method_exists( $zone, 'get_post_price' ) ) {
        return;
    }
    if ( method_exists( $zone, 'maybe_cache_flush' ) ) {
        $zone->maybe_cache_flush( 0, $post_id, '_' . $zone_id . '_price_method' );
    }
    $zone->get_post_price( $post_id, '_price' );
}

/**
 * Next whole number ending in 9, never below the value. Empty and non-positive values pass through.
 *
 * @param mixed $v
 * @return mixed
 */
function odella_round_up_9( $v ) {
    if ( '' === $v || null === $v || ! is_numeric( $v ) ) {
        return $v;
    }
    $v = (float) $v;
    if ( $v <= 0 ) {
        return $v;
    }
    $whole = ceil( $v );
    return (string) ( ceil( ( $whole + 1 ) / 10 ) * 10 - 1 );
}

/**
 * @return object|false
 */
function odella_pricing_active_zone() {
    if ( ! function_exists( 'wcpbc_the_zone' ) ) {
        return false;
    }
    $zone = wcpbc_the_zone();
    return is_object( $zone ) ? $zone : false;
}

/**
 * Round a converted price. Manual zone prices and GEL prices are left as entered.
 *
 * @param mixed         $value
 * @param WC_Product|mixed $product
 * @return mixed
 */
function odella_pricing_round_foreign_price( $value, $product ) {
    $zone = odella_pricing_active_zone();
    if ( ! $zone || ! method_exists( $zone, 'get_currency' ) || 'GEL' === $zone->get_currency() ) {
        return $value;
    }
    if ( ! is_object( $product ) || ! method_exists( $zone, 'is_exchange_rate_price' ) || ! $zone->is_exchange_rate_price( $product ) ) {
        return $value;
    }
    return odella_round_up_9( $value );
}

/**
 * Variation price rows pass the variation as the second argument.
 *
 * @param mixed $price
 * @param mixed $variation
 * @param mixed $product
 * @return mixed
 */
function odella_pricing_round_variation_prices( $price, $variation, $product ) {
    unset( $product );
    return odella_pricing_round_foreign_price( $price, $variation );
}

/**
 * Rebuild cached variation prices when the rounding rule or the zone changes.
 *
 * @param array<int, mixed> $hash
 * @param WC_Product        $product
 * @param bool              $for_display
 * @return array<int, mixed>
 */
function odella_pricing_variation_prices_hash( $hash, $product, $for_display ) {
    unset( $product, $for_display );
    if ( ! is_array( $hash ) ) {
        $hash = array();
    }
    $zone = odella_pricing_active_zone();
    $hash[] = 'odella_r9';
    $hash[] = ( $zone && method_exists( $zone, 'get_id' ) ) ? (string) $zone->get_id() : '';
    return $hash;
}

add_action( 'init', 'odella_pricing_schedule_rates' );
add_action( 'odella_daily_rates', 'odella_update_zone_rates' );

foreach (
    array(
        'woocommerce_product_get_price',
        'woocommerce_product_get_regular_price',
        'woocommerce_product_get_sale_price',
        'woocommerce_product_variation_get_price',
        'woocommerce_product_variation_get_regular_price',
        'woocommerce_product_variation_get_sale_price',
    ) as $odella_price_hook
) {
    add_filter( $odella_price_hook, 'odella_pricing_round_foreign_price', PHP_INT_MAX, 2 );
}
foreach (
    array(
        'woocommerce_variation_prices_price',
        'woocommerce_variation_prices_regular_price',
        'woocommerce_variation_prices_sale_price',
    ) as $odella_price_hook
) {
    add_filter( $odella_price_hook, 'odella_pricing_round_variation_prices', PHP_INT_MAX, 3 );
}
add_filter( 'woocommerce_get_variation_prices_hash', 'odella_pricing_variation_prices_hash', 10, 3 );
