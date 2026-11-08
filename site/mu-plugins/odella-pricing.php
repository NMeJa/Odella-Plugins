<?php
/**
 * Plugin Name: Odella Pricing Guards (MU)
 * Description: Hides published products that have no GEL price from the catalog and search, reveals them when a price is saved, and keeps newly unpriced products as drafts.
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
    if ( ! in_array( $meta_key, array( '_regular_price', '_price', '_sale_price' ), true ) ) {
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
