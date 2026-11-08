<?php
/**
 * Theme functions and definitions.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * https://developers.elementor.com/docs/hello-elementor-theme/
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'HELLO_ELEMENTOR_CHILD_VERSION', '2.0.0' );

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_elementor_child_scripts_styles() {

	wp_enqueue_style(
		'hello-elementor-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		[
			'hello-elementor-theme-style',
		],
		filemtime( get_stylesheet_directory() . '/style.css' )
	);

}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 999 );

/**
 * Pages show their own Georgian headings; Hello's automatic (often English) title is not used.
 */
add_filter( 'hello_elementor_page_title', '__return_false' );

/**
 * Shortcode to display the current year [odella_current_year]
 */
add_shortcode('odella_current_year', function() {
    return date('Y');
});

/**
 * Enable SVG Uploads in WordPress Media Library
 */
function allow_svg_uploads( $mimes ) {
    $mimes['svg']  = 'image/svg+xml';
    $mimes['svgz'] = 'image/svg+xml';
    return $mimes;
}
add_filter( 'upload_mimes', 'allow_svg_uploads' );

function hello_elementor_add_sidebar_widget_area() {
    register_sidebar( array(
        'name'          => 'Main Sidebar',
        'id'            => 'sidebar-1',
        'before_widget' => '<div class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ) );
}
add_action( 'widgets_init', 'hello_elementor_add_sidebar_widget_area' );
