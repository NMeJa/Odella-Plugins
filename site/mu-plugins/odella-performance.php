<?php
/**
 * Plugin Name: Odella Performance & Asset Optimizer (MU)
 * Description: Prevents unnecessary admin plugin loading on frontend, eliminates redundant cache purges, and optimizes Mobile & Desktop Core Web Vitals (FCP, LCP, CLS, TBT).
 * Version: 1.5.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 1. Determine if current request is a public frontend visitor request.
 */
function odella_is_public_frontend_request(): bool {
    if ( PHP_SAPI === 'cli' || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
        return false;
    }
    if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return false;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (
        strpos( $uri, '/wp-admin' ) !== false ||
        strpos( $uri, 'wp-login.php' ) !== false ||
        strpos( $uri, 'wp-cron.php' ) !== false ||
        strpos( $uri, 'xmlrpc.php' ) !== false ||
        strpos( $uri, 'wp-json' ) !== false ||
        strpos( $uri, 'elementor' ) !== false ||
        strpos( $script, '/wp-admin' ) !== false ||
        isset( $_GET['elementor-preview'] ) ||
        isset( $_GET['action'] )
    ) {
        return false;
    }
    return true;
}

/**
 * Helper: Check if the current request is the homepage (Georgian or English).
 */
function odella_is_home_landing(): bool {
    if ( is_front_page() || is_home() ) {
        return true;
    }
    if ( function_exists( 'is_page' ) && is_page( 13863 ) ) {
        return true;
    }
    return false;
}

/**
 * 2. Skip loading heavy backend-only bulk spreadsheet / admin utility plugins on public frontend visits.
 *    All plugins remain 100% active inside /wp-admin/, WP-CLI, and cron.
 */
if ( odella_is_public_frontend_request() ) {
    add_filter( 'option_active_plugins', function( $plugins ) {
        if ( ! is_array( $plugins ) ) {
            return $plugins;
        }
        $admin_only_plugins = [
            'bulk-edit-custom-tables-premium/custom-tables.php',
            'bulk-edit-posts-on-frontend-premium/frontend-editor.php',
            'woo-orders-spreadsheet-table-premium/woocommerce-orders.php',
            'woo-products-bulk-editor-premium/products.php',
            'wp-sheet-editor-automations-premium/automations.php',
            'wp-sheet-editor-media-library-premium/media-library.php',
            'wp-sheet-editor-premium/wp-sheet-editor.php',
            'acf-quickedit-fields/index.php',
            'enable-media-replace/enable-media-replace.php',
            'hostinger/hostinger.php',
        ];
        return array_values( array_diff( $plugins, $admin_only_plugins ) );
    }, 1 );
}

/**
 * 3. Safeguard: Prevent elementor_pro_initialize_local_environment from writing options on every request
 *    (which previously triggered Atomic_Widget_Styles -> clear_cache -> LiteSpeed purge_all on every hit).
 */
add_action( 'plugins_loaded', function() {
    if ( function_exists( 'elementor_pro_initialize_local_environment' ) ) {
        $existing_v2 = get_option( '_elementor_pro_license_v2_data' );
        if ( is_array( $existing_v2 ) && ! empty( $existing_v2['timeout'] ) && $existing_v2['timeout'] > ( time() + 86400 ) ) {
            remove_action( 'init', 'elementor_pro_initialize_local_environment' );
        }
    }
}, 20 );

/**
 * 5. Preload LCP Hero Banner & Pre-Apply Full-Page-Scroll Section Geometry on Homepage.
 *    Pre-applying #video/#categories/#products/#instagram geometry in <head> prevents
 *    full-page-scroll.js from shifting .nmj-rv-inner down by 60-122px on DOMContentLoaded (eliminates 0.066 CLS).
 */
add_action( 'wp_head', function() {
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '<style id="odella-page-transition-fix">e-page-transition{display:none!important}</style>' . "\n";

    if ( odella_is_home_landing() ) {
        echo '<link rel="preload" as="image" href="https://odella.ge/wp-content/uploads/Main-PC.jpg" media="(min-width: 768px)" fetchpriority="high">' . "\n";
        echo '<link rel="preload" as="image" href="https://odella.ge/wp-content/uploads/Main-Mobile.jpg" media="(max-width: 767px)" fetchpriority="high">' . "\n";
        echo '<style id="odella-fps-critical-css">'
           . 'html{scroll-snap-type:y mandatory}'
           // scroll-margin-top:100vh, not the 60px header offset: the snap point clamps to scrollY 0
           // whatever the header height. With 60px, the 62px mobile header made the browser snap
           // 2px at load, which ends Chrome's LCP measurement (PageSpeed NO_LCP).
           . '#video{min-height:calc(100vh - 60px)!important;scroll-margin-top:100vh!important;padding-top:0px!important;box-sizing:border-box!important;scroll-snap-align:start!important;scroll-snap-stop:always!important}'
           . '#video .nmj-rv-inner{max-height:calc(100vh - 60px)}'
           . '#categories,#products,#instagram{min-height:100vh!important;scroll-margin-top:0px!important;padding-top:60px!important;box-sizing:border-box!important;scroll-snap-align:start!important;scroll-snap-stop:always!important}'
           . '#footer-id{scroll-snap-align:end!important;scroll-snap-stop:normal!important}'
           . '</style>' . "\n";
    }
}, 1 );

/**
 * 6. Eliminate Render-Blocking CSS on Mobile & Desktop:
 *    - Load Google Fonts asynchronously (media="print" onload="this.media='all'") to eliminate 750ms cross-origin render blocking.
 *    - Inline local stylesheets <= 28 KB (rewriting relative url() paths to absolute URLs so icons/backgrounds work seamlessly).
 *    - On the homepage, load large below-the-fold plugin stylesheets (woocommerce-general, moderncart, woo-variation-swatches)
 *      non-render-blocking so they never delay First Contentful Paint or Hero LCP.
 */
add_filter( 'style_loader_tag', function( $tag, $handle, $href, $media ) {
    if ( is_admin() || ! odella_is_public_frontend_request() ) {
        return $tag;
    }

    // 6a. Non-blocking Google Fonts CSS (already uses display=swap)
    if ( strpos( $href, 'fonts.googleapis.com/css' ) !== false ) {
        $safe_href = esc_url( $href );
        return '<link rel="preload" as="style" href="' . $safe_href . '" />' . "\n"
             . '<link rel="stylesheet" id="' . esc_attr( $handle ) . '-css" href="' . $safe_href . '" media="print" onload="this.media=\'all\'" />' . "\n"
             . '<noscript><link rel="stylesheet" href="' . $safe_href . '" /></noscript>' . "\n";
    }

    // 6b. Non-blocking below-the-fold / drawer plugin CSS on Homepage
    if ( odella_is_home_landing() && in_array( $handle, [ 'woocommerce-general', 'moderncart-cart-css', 'woo-variation-swatches', 'fluentform-elementor-widget' ], true ) ) {
        $safe_href = esc_url( $href );
        $prefix_style = '';
        if ( $handle === 'moderncart-cart-css' ) {
            // Ensure slide-out cart drawer stays hidden until its stylesheet finishes loading
            $prefix_style = '<style id="odella-moderncart-prehide">.moderncart-slide-out-modal,#moderncart-slide-out{display:none}</style>';
        }
        return $prefix_style
             . '<link rel="stylesheet" id="' . esc_attr( $handle ) . '-css" href="' . $safe_href . '" media="print" onload="this.media=\'all\'" />' . "\n"
             . '<noscript><link rel="stylesheet" href="' . $safe_href . '" /></noscript>' . "\n";
    }

    // 6c. Inline local stylesheets ≤ 2 KB (with automatic relative url() resolution)
    $path = parse_url( $href, PHP_URL_PATH );
    if ( empty( $path ) || strpos( $path, '/wp-content/' ) !== 0 ) {
        return $tag;
    }
    $file = ABSPATH . ltrim( $path, '/' );
    if ( ! is_file( $file ) ) {
        return $tag;
    }
    $size = filesize( $file );
    if ( $size > 0 && $size <= 2048 ) {
        $css = file_get_contents( $file );
        if ( $css !== false && stripos( $css, '@import' ) === false && strpos( $css, '</style' ) === false ) {
            if ( stripos( $css, 'url(' ) !== false ) {
                $base_dir_url = trailingslashit( dirname( strtok( $href, '?' ) ) );
                $css = preg_replace_callback(
                    '/url\(\s*([\'"]?)([^\'")\s]+)\1\s*\)/i',
                    function( $m ) use ( $base_dir_url ) {
                        $quote = $m[1];
                        $url   = $m[2];
                        if (
                            strpos( $url, 'data:' ) === 0 ||
                            strpos( $url, 'http:' ) === 0 ||
                            strpos( $url, 'https:' ) === 0 ||
                            strpos( $url, '//' ) === 0 ||
                            strpos( $url, '/' ) === 0 ||
                            strpos( $url, '#' ) === 0
                        ) {
                            return $m[0];
                        }
                        return 'url(' . $quote . $base_dir_url . $url . $quote . ')';
                    },
                    $css
                );
            }
            $media_attr = ( ! empty( $media ) && $media !== 'all' ) ? ' media="' . esc_attr( $media ) . '"' : '';
            return '<style id="' . esc_attr( $handle ) . '-inline-file-css"' . $media_attr . '>' . trim( $css ) . "</style>\n";
        }
    }
    return $tag;
}, 10, 4 );

/**
 * 7. Defer render-blocking <head> scripts that do not need to execute synchronously during <head> parse.
 */
add_filter( 'script_loader_tag', function( $tag, $handle, $src ) {
    if ( is_admin() || ! odella_is_public_frontend_request() ) {
        return $tag;
    }
    $defer_handles = [
        'jquery-migrate',
        'wc-jquery-blockui',
        'page-transitions',
    ];
    if ( in_array( $handle, $defer_handles, true ) && strpos( $tag, ' defer' ) === false ) {
        $tag = str_replace( '<script ', '<script defer ', $tag );
    }
    return $tag;
}, 10, 3 );

/**
 * 8. Image Delivery & Priority Optimization:
 *    - On Homepage, our custom <picture class="nmj-rv-poster-fallback"> is already the LCP image with fetchpriority="high".
 *      Set wp_omit_loading_attr_threshold to 0 on the homepage so WordPress does not strip loading="lazy" or add
 *      fetchpriority="high" to below-the-fold carousel images (Kimono, Coat, Silk-scarf).
 *    - Fix responsive `sizes` on `600x900` product/category card images from `(max-width: 600px) 100vw, 600px`
 *      to `(max-width: 767px) 340px, 500px` so Mobile Chrome (412px * 1.75 DPR = 595px <= 600w) selects the
 *      600x900 thumbnail instead of downloading all 25 full-size 800x1200 originals (saves >1.1 MB on Mobile).
 */
add_filter( 'wp_omit_loading_attr_threshold', function( $threshold ) {
    if ( odella_is_public_frontend_request() && odella_is_home_landing() ) {
        return 0;
    }
    return $threshold;
}, 999 );

// Prevent WordPress 6.7+ from prepending `auto, ` to `sizes`, which causes Mobile Chrome (1.75x DPR)
// to ignore our `(max-width: 767px) 340px` hint and double-download 1000w (800x1200) carousel images.
add_filter( 'wp_img_tag_add_auto_sizes', '__return_false', 999 );

add_filter( 'wp_get_attachment_image_attributes', function( $attr, $attachment, $size ) {
    if ( is_admin() || ! odella_is_public_frontend_request() ) {
        return $attr;
    }

    $src = $attr['src'] ?? '';

    // On Homepage, keep everything except Header Logo and Hero Poster lazy-loaded without competing fetchpriority=high
    if ( odella_is_home_landing() ) {
        $is_logo = ( strpos( $src, 'Odella-Full-Logo' ) !== false );
        $is_hero = ( strpos( $src, 'Main-PC' ) !== false || strpos( $src, 'Main-Mobile' ) !== false );
        if ( ! $is_logo && ! $is_hero ) {
            $attr['loading'] = 'lazy';
            unset( $attr['fetchpriority'] );
        }
    }

    // Optimize `sizes` for 600x900 card images so 1.75x/2x mobile screens use 600w instead of 1000w full originals
    if ( isset( $attr['sizes'] ) && strpos( $attr['sizes'], '600px' ) !== false ) {
        $attr['sizes'] = '(max-width: 767px) 340px, 500px';
    }

    return $attr;
}, 999, 3 );

/**
 * 9. Add explicit width/height and loading="lazy" to Elementor custom-thumb Instagram images & footer image.
 */
add_filter( 'elementor/widget/render_content', function( $content, $widget ) {
    if ( is_admin() || ! odella_is_public_frontend_request() || empty( $content ) ) {
        return $content;
    }
    if ( strpos( $content, '<img ' ) === false ) {
        return $content;
    }

    // Add explicit 1000x1000 dimensions to Elementor Instagram feed custom thumbs missing width/height
    if ( strpos( $content, '/elementor/thumbs/' ) !== false && strpos( $content, '_insta-' ) !== false ) {
        $content = preg_replace_callback(
            '/<img\b[^>]*src=["\'][^"\']*\/elementor\/thumbs\/[^"\']*_insta-[^"\']*["\'][^>]*>/i',
            function( $m ) {
                $img = $m[0];
                if ( stripos( $img, 'width=' ) === false && stripos( $img, 'height=' ) === false ) {
                    $img = str_replace( '<img ', '<img width="1000" height="1000" ', $img );
                }
                return $img;
            },
            $content
        );
    }

    // Ensure FooterImage has loading="lazy"
    if ( strpos( $content, 'FooterImage-' ) !== false && stripos( $content, 'loading=' ) === false ) {
        $content = str_replace( '<img ', '<img loading="lazy" ', $content );
    }

    return $content;
}, 999, 2 );

/**
 * 10. Dequeue unused frontend assets.
 */
function odella_dequeue_unused_frontend_assets() {
    if ( is_admin() || isset( $_GET['elementor-preview'] ) ) {
        return;
    }

    // Dequeue Elementor Pro full-screen page-transition preloader (prevents 0.47-0.93 CLS & 1.5s LCP curtain delay)
    wp_dequeue_script( 'page-transitions' );

    // Dequeue FileBird Gutenberg & Elementor gallery CSS on frontend
    wp_dequeue_style( 'filebird-block-filebird-gallery-style' );
    wp_dequeue_style( 'fbv-elementor' );

    global $wp_styles;
    if ( isset( $wp_styles->registered ) && is_array( $wp_styles->registered ) ) {
        foreach ( $wp_styles->registered as $handle => $obj ) {
            if ( ! empty( $obj->src ) && strpos( $obj->src, 'filebird-pro' ) !== false ) {
                wp_dequeue_style( $handle );
            }
        }
    }

}
add_action( 'wp_enqueue_scripts', 'odella_dequeue_unused_frontend_assets', 9999 );
add_action( 'wp_print_styles', 'odella_dequeue_unused_frontend_assets', 9999 );
add_action( 'wp_footer', 'odella_dequeue_unused_frontend_assets', 19 );
add_action( 'wp_print_footer_scripts', 'odella_dequeue_unused_frontend_assets', 1 );

/**
 * 11. Optimize Google Fonts (trim 18 Noto Sans weights down to 5 used weights).
 */
add_filter( 'style_loader_src', function( $src, $handle ) {
    if ( strpos( $src, 'fonts.googleapis.com/css' ) !== false && strpos( $src, 'Noto+Sans' ) !== false ) {
        $src = preg_replace(
            '/Noto\+Sans:[^&#"\']+/',
            'Noto+Sans:300,400,500,600,700',
            $src
        );
    }
    return $src;
}, 999, 2 );

/**
 * 12. Cap newly uploaded originals at 2560 px.
 *     Existing originals are left as they are; the lightbox still uses them.
 */
add_filter( 'big_image_size_threshold', function () {
    return 2560;
}, 999 );

/**
 * 13. Prefetch hovered links (WordPress speculation rules).
 *     Logged-in visitors keep core's own setting. Cart, checkout and account
 *     stay out of the prefetch list. Query-string URLs stay excluded by core.
 */
add_filter( 'wp_speculation_rules_configuration', function ( $config ) {
    if ( is_user_logged_in() ) {
        return $config;
    }
    return array( 'mode' => 'prefetch', 'eagerness' => 'moderate' );
} );
add_filter( 'wp_speculation_rules_href_exclude_paths', function ( $paths ) {
    return array_merge( $paths, array( '/ka/elementor-15559/*', '/en/elementor-15559/*', '/ka/my-account-2/*', '/en/my-account/*', '/ka/cart/*', '/en/cart/*' ) );
} );
