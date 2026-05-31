<?php
// widgets/responsive-video-widget.php

namespace NMJ\Widgets;

defined( 'ABSPATH' ) || exit;

/**
 * Responsive Video Widget
 *
 * Serves a different self-hosted video file (and poster image) per breakpoint.
 * The <video src> is injected by JavaScript AFTER the viewport width is known,
 * so the browser never requests the "wrong" file — zero wasted bandwidth.
 *
 * Fallback chains (most-specific → least-specific):
 *   mobile  → tablet  → desktop
 *   tablet  → desktop → mobile
 *   desktop → tablet  → mobile
 */
class Responsive_Video_Widget extends \Elementor\Widget_Base {

	// ── Identity ─────────────────────────────────────────────────────────────

	public function get_name(): string {
		return 'nmj-responsive-video';
	}

	public function get_title(): string {
		return esc_html__( 'Responsive Video', 'nmj' );
	}

	public function get_icon(): string {
		return 'eicon-video-camera';
	}

	public function get_categories(): array {
		return [ 'nmj-widgets' ];
	}

	public function get_keywords(): array {
		return [ 'video', 'responsive', 'mobile', 'desktop', 'tablet', 'mp4', 'self-hosted', 'poster' ];
	}

	public function get_script_depends(): array {
		return [ 'nmj-responsive-video' ];
	}

	public function get_style_depends(): array {
		return [ 'nmj-responsive-video' ];
	}

	// ── Controls ─────────────────────────────────────────────────────────────

	protected function register_controls(): void {
		$this->controls_videos();
		$this->controls_playback();
		$this->controls_breakpoints();
		$this->controls_style_layout();
		$this->controls_style_video();
	}

	// Content — Videos & Posters
	private function controls_videos(): void {
		$this->start_controls_section( 'section_videos', [
			'label' => esc_html__( 'Videos & Posters', 'nmj' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		] );

		foreach ( [
			'desktop' => esc_html__( 'Desktop', 'nmj' ),
			'tablet'  => esc_html__( 'Tablet', 'nmj' ),
			'mobile'  => esc_html__( 'Mobile', 'nmj' ),
		] as $device => $label ) {

			// Build heading args — only tablet/mobile get a visual separator
			$heading_args = [
				'label' => $label,
				'type'  => \Elementor\Controls_Manager::HEADING,
			];
			if ( $device !== 'desktop' ) {
				$heading_args['separator'] = 'before';
			}

			$this->add_control( "heading_{$device}", $heading_args );

			// Tablet and mobile are optional; show a hint
			if ( $device !== 'desktop' ) {
				$this->add_control( "{$device}_notice", [
					'type'            => \Elementor\Controls_Manager::RAW_HTML,
					'raw'             => esc_html__( 'Optional — falls back to Desktop if left empty.', 'nmj' ),
					'content_classes' => 'elementor-descriptor',
				] );
			}

			$this->add_control( "{$device}_video", [
				'label'       => esc_html__( 'Video File', 'nmj' ),
				'type'        => \Elementor\Controls_Manager::MEDIA,
				'media_types' => [ 'video' ],
				'dynamic'     => [ 'active' => true ],
			] );

			$this->add_control( "{$device}_fallback_video", [
				'label'       => esc_html__( 'Fallback Video (MP4)', 'nmj' ),
				'description' => esc_html__( 'Optional MP4 fallback if the primary video is a format like WebM.', 'nmj' ),
				'type'        => \Elementor\Controls_Manager::MEDIA,
				'media_types' => [ 'video' ],
				'dynamic'     => [ 'active' => true ],
			] );

			$this->add_control( "{$device}_poster", [
				'label'   => esc_html__( 'Poster Image', 'nmj' ),
				'type'    => \Elementor\Controls_Manager::MEDIA,
				'dynamic' => [ 'active' => true ],
			] );
		}

		$this->end_controls_section();
	}

	// Content — Playback
	private function controls_playback(): void {
		$this->start_controls_section( 'section_playback', [
			'label' => esc_html__( 'Playback', 'nmj' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		] );

		$this->add_control( 'autoplay', [
			'label'   => esc_html__( 'Autoplay', 'nmj' ),
			'type'    => \Elementor\Controls_Manager::SWITCHER,
			'default' => '',
		] );

		$this->add_control( 'autoplay_notice', [
			'type'            => \Elementor\Controls_Manager::RAW_HTML,
			'raw'             => esc_html__( 'Browsers require Muted to be enabled for autoplay to work.', 'nmj' ),
			'content_classes' => 'elementor-descriptor',
			'condition'       => [ 'autoplay' => 'yes' ],
		] );

		$this->add_control( 'muted', [
			'label'   => esc_html__( 'Muted', 'nmj' ),
			'type'    => \Elementor\Controls_Manager::SWITCHER,
			'default' => '',
		] );

		$this->add_control( 'loop', [
			'label'   => esc_html__( 'Loop', 'nmj' ),
			'type'    => \Elementor\Controls_Manager::SWITCHER,
			'default' => '',
		] );

		$this->add_control( 'controls', [
			'label'   => esc_html__( 'Show Controls', 'nmj' ),
			'type'    => \Elementor\Controls_Manager::SWITCHER,
			'default' => 'yes',
		] );

		$this->add_control( 'playsinline', [
			'label'       => esc_html__( 'Plays Inline on iOS', 'nmj' ),
			'description' => esc_html__( 'Prevents iOS Safari from going fullscreen automatically.', 'nmj' ),
			'type'        => \Elementor\Controls_Manager::SWITCHER,
			'default'     => 'yes',
		] );

		$this->add_control( 'preload', [
			'label'       => esc_html__( 'Preload', 'nmj' ),
			'description' => esc_html__( 'Use "None" for below-fold placement. "Metadata" or "Auto" can speed up above-fold start time at the cost of more bandwidth.', 'nmj' ),
			'type'        => \Elementor\Controls_Manager::SELECT,
			'default'     => 'none',
			'options'     => [
				'none'     => esc_html__( 'None', 'nmj' ),
				'metadata' => esc_html__( 'Metadata', 'nmj' ),
				'auto'     => esc_html__( 'Auto', 'nmj' ),
			],
		] );

		$this->end_controls_section();
	}

	// Content — Breakpoints
	private function controls_breakpoints(): void {
		$this->start_controls_section( 'section_breakpoints', [
			'label' => esc_html__( 'Breakpoints', 'nmj' ),
			'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
		] );

		$this->add_control( 'breakpoints_info', [
			'type'            => \Elementor\Controls_Manager::RAW_HTML,
			'raw'             => wp_kses_post(
				__( '<strong>Mobile</strong>: ≤ Mobile Max width<br><strong>Tablet</strong>: Mobile Max &lt; width ≤ Tablet Max<br><strong>Desktop</strong>: above Tablet Max', 'nmj' )
			),
			'content_classes' => 'elementor-descriptor',
		] );

		$this->add_control( 'mobile_breakpoint', [
			'label'   => esc_html__( 'Mobile Max Width (px)', 'nmj' ),
			'type'    => \Elementor\Controls_Manager::NUMBER,
			'default' => 767,
			'min'     => 200,
			'max'     => 1280,
		] );

		$this->add_control( 'tablet_breakpoint', [
			'label'   => esc_html__( 'Tablet Max Width (px)', 'nmj' ),
			'type'    => \Elementor\Controls_Manager::NUMBER,
			'default' => 1024,
			'min'     => 320,
			'max'     => 2560,
		] );

		$this->end_controls_section();
	}

	// Style — Layout
	private function controls_style_layout(): void {
		$this->start_controls_section( 'section_style_layout', [
			'label' => esc_html__( 'Layout', 'nmj' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		] );

		// Responsive SELECT control → Elementor auto-generates per-breakpoint CSS rules,
		// so the aspect ratio can be 16/9 on desktop and 9/16 on mobile, for example.
		$this->add_responsive_control( 'aspect_ratio', [
			'label'     => esc_html__( 'Aspect Ratio', 'nmj' ),
			'type'      => \Elementor\Controls_Manager::SELECT,
			'default'   => '16/9',
			'options'   => [
				'16/9' => '16:9 — Widescreen',
				'9/16' => '9:16 — Portrait',
				'4/3'  => '4:3 — Standard',
				'3/2'  => '3:2',
				'1/1'  => '1:1 — Square',
				'21/9' => '21:9 — Ultrawide',
			],
			'selectors' => [
				'{{WRAPPER}} .nmj-rv-inner' => 'aspect-ratio: {{VALUE}};',
			],
		] );

		$this->add_responsive_control( 'max_width', [
			'label'      => esc_html__( 'Max Width', 'nmj' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px', '%', 'vw' ],
			'range'      => [
				'px'  => [ 'min' => 100, 'max' => 2400, 'step' => 10 ],
				'%'   => [ 'min' => 10, 'max' => 100 ],
				'vw'  => [ 'min' => 10, 'max' => 100 ],
			],
			'selectors'  => [
				'{{WRAPPER}} .nmj-rv-wrap' => 'max-width: {{SIZE}}{{UNIT}};',
			],
		] );

		// Standard Elementor inline-block alignment pattern (same as Image widget).
		// .nmj-rv-wrap is display:inline-block so text-align on the wrapper centers it.
		$this->add_responsive_control( 'alignment', [
			'label'     => esc_html__( 'Alignment', 'nmj' ),
			'type'      => \Elementor\Controls_Manager::CHOOSE,
			'options'   => [
				'left'   => [ 'title' => esc_html__( 'Left', 'nmj' ),   'icon' => 'eicon-text-align-left' ],
				'center' => [ 'title' => esc_html__( 'Center', 'nmj' ), 'icon' => 'eicon-text-align-center' ],
				'right'  => [ 'title' => esc_html__( 'Right', 'nmj' ),  'icon' => 'eicon-text-align-right' ],
			],
			'selectors' => [
				'{{WRAPPER}}' => 'text-align: {{VALUE}};',
			],
		] );

		$this->end_controls_section();
	}

	// Style — Video
	private function controls_style_video(): void {
		$this->start_controls_section( 'section_style_video', [
			'label' => esc_html__( 'Video', 'nmj' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		] );

		$this->add_control( 'object_fit', [
			'label'     => esc_html__( 'Object Fit', 'nmj' ),
			'type'      => \Elementor\Controls_Manager::SELECT,
			'default'   => 'cover',
			'options'   => [
				'cover'   => esc_html__( 'Cover', 'nmj' ),
				'contain' => esc_html__( 'Contain', 'nmj' ),
				'fill'    => esc_html__( 'Fill', 'nmj' ),
			],
			'selectors' => [
				'{{WRAPPER}} .nmj-rv-video' => 'object-fit: {{VALUE}};',
			],
		] );

		$this->add_responsive_control( 'border_radius', [
			'label'      => esc_html__( 'Border Radius', 'nmj' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', '%', 'em', 'rem' ],
			'selectors'  => [
				// overflow:hidden written here so radius clips the video correctly
				'{{WRAPPER}} .nmj-rv-inner' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
			],
		] );

		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), [
			'name'     => 'box_shadow',
			'selector' => '{{WRAPPER}} .nmj-rv-inner',
		] );

		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), [
			'name'     => 'border',
			'selector' => '{{WRAPPER}} .nmj-rv-inner',
		] );

		$this->end_controls_section();
	}

	// ── Render (server-side / frontend) ──────────────────────────────────────

	protected function render(): void {
		$s = $this->get_settings_for_display();

		$desktop_src    = ! empty( $s['desktop_video']['url'] )  ? $s['desktop_video']['url']  : '';
		$tablet_src     = ! empty( $s['tablet_video']['url'] )   ? $s['tablet_video']['url']   : '';
		$mobile_src     = ! empty( $s['mobile_video']['url'] )   ? $s['mobile_video']['url']   : '';
		$desktop_fallback = ! empty( $s['desktop_fallback_video']['url'] ) ? $s['desktop_fallback_video']['url'] : '';
		$tablet_fallback  = ! empty( $s['tablet_fallback_video']['url'] )  ? $s['tablet_fallback_video']['url']  : '';
		$mobile_fallback  = ! empty( $s['mobile_fallback_video']['url'] )  ? $s['mobile_fallback_video']['url']  : '';
		$desktop_poster = ! empty( $s['desktop_poster']['url'] ) ? $s['desktop_poster']['url'] : '';
		$tablet_poster  = ! empty( $s['tablet_poster']['url'] )  ? $s['tablet_poster']['url']  : '';
		$mobile_poster  = ! empty( $s['mobile_poster']['url'] )  ? $s['mobile_poster']['url']  : '';

		// Bail early when no video is configured
		if ( ! $desktop_src && ! $tablet_src && ! $mobile_src ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<div class="nmj-rv-placeholder"><span>'
					. esc_html__( 'Set a Desktop or Mobile video in the widget settings.', 'nmj' )
					. '</span></div>';
			}
			return;
		}

		$mobile_bp = absint( $s['mobile_breakpoint'] ?? 767 );
		$tablet_bp = absint( $s['tablet_breakpoint'] ?? 1024 );

		// Build boolean attribute string from switcher controls.
		// All values come from a fixed set of known-safe HTML attribute names.
		$bool_attrs = '';
		foreach ( [
			'autoplay'    => $s['autoplay']    ?? '',
			'muted'       => $s['muted']       ?? '',
			'loop'        => $s['loop']        ?? '',
			'controls'    => $s['controls']    ?? 'yes',
			'playsinline' => $s['playsinline'] ?? 'yes',
		] as $attr => $val ) {
			if ( 'yes' === $val ) {
				$bool_attrs .= ' ' . $attr;
			}
		}

		// Resolve server-side poster so the browser's preload scanner can
		// discover it during HTML parsing — avoids waiting for JS execution.
		// Desktop is the safe default; JS swaps for the matched breakpoint.
		$server_poster = $desktop_poster ?: $tablet_poster ?: $mobile_poster;

		// Preload attribute — validated against a known-safe allowlist.
		$preload = $s['preload'] ?? 'none';
		if ( ! in_array( $preload, [ 'none', 'metadata', 'auto' ], true ) ) {
			$preload = 'none';
		}

		?>
		<div class="nmj-rv-wrap"
		     data-desktop-src="<?php echo esc_url( $desktop_src ); ?>"
		     data-tablet-src="<?php echo esc_url( $tablet_src ); ?>"
		     data-mobile-src="<?php echo esc_url( $mobile_src ); ?>"
		     data-desktop-fallback="<?php echo esc_url( $desktop_fallback ); ?>"
		     data-tablet-fallback="<?php echo esc_url( $tablet_fallback ); ?>"
		     data-mobile-fallback="<?php echo esc_url( $mobile_fallback ); ?>"
		     data-desktop-poster="<?php echo esc_url( $desktop_poster ); ?>"
		     data-tablet-poster="<?php echo esc_url( $tablet_poster ); ?>"
		     data-mobile-poster="<?php echo esc_url( $mobile_poster ); ?>"
		     data-mobile-bp="<?php echo esc_attr( $mobile_bp ); ?>"
		     data-tablet-bp="<?php echo esc_attr( $tablet_bp ); ?>">
			<div class="nmj-rv-inner">
				<video class="nmj-rv-video"
				       preload="<?php echo esc_attr( $preload ); ?>"
				       <?php if ( $server_poster ) : ?>poster="<?php echo esc_url( $server_poster ); ?>"<?php endif; ?>
				       <?php
				       // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				       // $bool_attrs is built from a closed set of known-safe HTML attribute names above.
				       echo $bool_attrs;
				       ?>></video>
			</div>
		</div>
		<?php
	}

	// ── content_template (Elementor editor live preview) ─────────────────────

	protected function content_template(): void {
		?>
		<#
		var desktopSrc    = settings.desktop_video  && settings.desktop_video.url  ? settings.desktop_video.url  : '';
		var tabletSrc     = settings.tablet_video   && settings.tablet_video.url   ? settings.tablet_video.url   : '';
		var mobileSrc     = settings.mobile_video   && settings.mobile_video.url   ? settings.mobile_video.url   : '';
		var desktopFallback = settings.desktop_fallback_video && settings.desktop_fallback_video.url ? settings.desktop_fallback_video.url : '';
		var tabletFallback  = settings.tablet_fallback_video  && settings.tablet_fallback_video.url  ? settings.tablet_fallback_video.url  : '';
		var mobileFallback  = settings.mobile_fallback_video  && settings.mobile_fallback_video.url  ? settings.mobile_fallback_video.url  : '';
		var desktopPoster = settings.desktop_poster && settings.desktop_poster.url ? settings.desktop_poster.url : '';
		var tabletPoster  = settings.tablet_poster  && settings.tablet_poster.url  ? settings.tablet_poster.url  : '';
		var mobilePoster  = settings.mobile_poster  && settings.mobile_poster.url  ? settings.mobile_poster.url  : '';

		if ( ! desktopSrc && ! tabletSrc && ! mobileSrc ) { #>
			<div class="nmj-rv-placeholder">
				<span><?php echo esc_js( __( 'Set a Desktop or Mobile video in the widget settings.', 'nmj' ) ); ?></span>
			</div>
		<# } else {
			var boolAttrs = '';
			if ( settings.autoplay    === 'yes' ) boolAttrs += ' autoplay';
			if ( settings.muted       === 'yes' ) boolAttrs += ' muted';
			if ( settings.loop        === 'yes' ) boolAttrs += ' loop';
			if ( settings.controls    === 'yes' ) boolAttrs += ' controls';
			if ( settings.playsinline === 'yes' ) boolAttrs += ' playsinline';
		#>
		<div class="nmj-rv-wrap"
		     data-desktop-src="{{ desktopSrc }}"
		     data-tablet-src="{{ tabletSrc }}"
		     data-mobile-src="{{ mobileSrc }}"
		     data-desktop-fallback="{{ desktopFallback }}"
		     data-tablet-fallback="{{ tabletFallback }}"
		     data-mobile-fallback="{{ mobileFallback }}"
		     data-desktop-poster="{{ desktopPoster }}"
		     data-tablet-poster="{{ tabletPoster }}"
		     data-mobile-poster="{{ mobilePoster }}"
		     data-mobile-bp="{{ settings.mobile_breakpoint || 767 }}"
		     data-tablet-bp="{{ settings.tablet_breakpoint || 1024 }}">
			<div class="nmj-rv-inner">
				{{!-- src/poster set directly for editor canvas immediate preview --}}
				<#
				var editorFallback = desktopFallback || tabletFallback || mobileFallback;
				var editorPoster  = desktopPoster || tabletPoster || mobilePoster;
				var editorSrc     = desktopSrc || tabletSrc || mobileSrc;
				var editorPreload = settings.preload || 'none';
				#>
				<video class="nmj-rv-video"
				       preload="{{ editorPreload }}"
				       {{{ boolAttrs }}}
				       <# if ( editorPoster ) { #>poster="{{ editorPoster }}"<# } #>>
					<# if ( editorSrc ) { #><source src="{{ editorSrc }}"><# } #>
					<# if ( editorFallback ) { #><source src="{{ editorFallback }}"><# } #>
				</video>
			</div>
		</div>
		<# } #>
		<?php
	}
}