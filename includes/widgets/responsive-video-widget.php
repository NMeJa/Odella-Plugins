<?php
// widgets/responsive-video-widget.php

namespace NMJ\Widgets;

defined('ABSPATH') || exit;

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
class Responsive_Video_Widget extends \Elementor\Widget_Base
{

	// ── Identity ─────────────────────────────────────────────────────────────

	public function get_name(): string
	{
		return 'nmj-responsive-video';
	}

	public function get_title(): string
	{
		return esc_html__('Responsive Video', 'nmj');
	}

	public function get_icon(): string
	{
		return 'eicon-video-camera';
	}

	public function get_categories(): array
	{
		return ['nmj-widgets'];
	}

	public function get_keywords(): array
	{
		return ['video', 'responsive', 'mobile', 'desktop', 'tablet', 'mp4', 'self-hosted', 'poster'];
	}

	public function get_script_depends(): array
	{
		return ['nmj-responsive-video'];
	}

	public function get_style_depends(): array
	{
		return ['nmj-responsive-video'];
	}

	// ── Controls ─────────────────────────────────────────────────────────────

	protected function register_controls(): void
	{
		$this->controls_videos();
		$this->controls_playback();
		$this->controls_breakpoints();
		$this->controls_style_layout();
		$this->controls_style_video();
	}

	// Content — Videos & Posters
	private function controls_videos(): void
	{
		$this->start_controls_section('section_videos', [
			'label' => esc_html__('Videos & Posters', 'nmj'),
			'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
		]);

		foreach ([
			'desktop' => esc_html__('Desktop', 'nmj'),
			'tablet' => esc_html__('Tablet', 'nmj'),
			'mobile' => esc_html__('Mobile', 'nmj'),
		] as $device => $label) {

			// Build heading args — only tablet/mobile get a visual separator
			$heading_args = [
				'label' => $label,
				'type' => \Elementor\Controls_Manager::HEADING,
			];
			if ($device !== 'desktop') {
				$heading_args['separator'] = 'before';
			}

			$this->add_control("heading_{$device}", $heading_args);

			// Tablet and mobile are optional; show a hint
			if ($device !== 'desktop') {
				$this->add_control("{$device}_notice", [
					'type' => \Elementor\Controls_Manager::RAW_HTML,
					'raw' => esc_html__('Optional — falls back to Desktop if left empty.', 'nmj'),
					'content_classes' => 'elementor-descriptor',
				]);
			}

			// Per-breakpoint kill switch. Off means this breakpoint renders the
			// poster image and nothing else — no <video>, no iframe, and no
			// falling through to another breakpoint's video. Use it where you
			// have no footage for that shape (e.g. no portrait cut for mobile),
			// or to spare mobile visitors the download.
			$this->add_control("{$device}_show_video", [
				'label' => esc_html__('Show Video', 'nmj'),
				'description' => esc_html__('Off = show the poster image only on this breakpoint. No video is loaded or downloaded.', 'nmj'),
				'type' => \Elementor\Controls_Manager::SWITCHER,
				'label_on' => esc_html__('Video', 'nmj'),
				'label_off' => esc_html__('Poster', 'nmj'),
				'return_value' => 'yes',
				'default' => 'yes',
			]);

			$this->add_control("{$device}_source_type", [
				'label' => esc_html__('Video Source', 'nmj'),
				'type' => \Elementor\Controls_Manager::SELECT,
				'default' => 'hosted',
				'options' => [
					'hosted' => esc_html__('Self Hosted', 'nmj'),
					'external' => esc_html__('YouTube / Vimeo', 'nmj'),
				],
				'condition' => ["{$device}_show_video" => 'yes'],
			]);

			$this->add_control("{$device}_video", [
				'label' => esc_html__('Video File', 'nmj'),
				'type' => \Elementor\Controls_Manager::MEDIA,
				'media_types' => ['video'],
				'dynamic' => ['active' => true],
				'condition' => [
					"{$device}_show_video" => 'yes',
					"{$device}_source_type" => 'hosted',
				],
			]);

			$this->add_control("{$device}_fallback_video", [
				'label' => esc_html__('Fallback Video (MP4)', 'nmj'),
				'description' => esc_html__('Optional MP4 fallback if the primary video is a format like WebM.', 'nmj'),
				'type' => \Elementor\Controls_Manager::MEDIA,
				'media_types' => ['video'],
				'dynamic' => ['active' => true],
				'condition' => [
					"{$device}_show_video" => 'yes',
					"{$device}_source_type" => 'hosted',
				],
			]);

			$this->add_control("{$device}_external_url", [
				'label' => esc_html__('External URL', 'nmj'),
				'description' => esc_html__('Enter a YouTube or Vimeo link.', 'nmj'),
				'type' => \Elementor\Controls_Manager::TEXT,
				'dynamic' => ['active' => true],
				'label_block' => true,
				'condition' => [
					"{$device}_show_video" => 'yes',
					"{$device}_source_type" => 'external',
				],
			]);

			// Unconditional. The poster is required in every mode: it is the
			// entire output when Show Video is off, and the placeholder that
			// prevents an empty box while the video loads when it is on. It was
			// previously hidden for the YouTube/Vimeo source type, which left
			// external embeds with no placeholder at all.
			$this->add_control("{$device}_poster", [
				'label' => esc_html__('Poster Image', 'nmj'),
				'description' => esc_html__('Required. Shown on its own when Show Video is off, and while the video loads when it is on.', 'nmj'),
				'type' => \Elementor\Controls_Manager::MEDIA,
				'dynamic' => ['active' => true],
			]);
		}

		$this->end_controls_section();
	}

	// Content — Playback
	private function controls_playback(): void
	{
		$this->start_controls_section('section_playback', [
			'label' => esc_html__('Playback', 'nmj'),
			'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
		]);

		$this->add_control('autoplay', [
			'label' => esc_html__('Autoplay', 'nmj'),
			'type' => \Elementor\Controls_Manager::SWITCHER,
			'default' => '',
		]);

		$this->add_control('autoplay_notice', [
			'type' => \Elementor\Controls_Manager::RAW_HTML,
			'raw' => esc_html__('Browsers require Muted to be enabled for autoplay to work.', 'nmj'),
			'content_classes' => 'elementor-descriptor',
			'condition' => ['autoplay' => 'yes'],
		]);

		$this->add_control('muted', [
			'label' => esc_html__('Muted', 'nmj'),
			'type' => \Elementor\Controls_Manager::SWITCHER,
			'default' => 'yes',
		]);

		$this->add_control('loop', [
			'label' => esc_html__('Loop', 'nmj'),
			'type' => \Elementor\Controls_Manager::SWITCHER,
			'default' => '',
		]);

		$this->add_control('controls', [
			'label' => esc_html__('Show Controls', 'nmj'),
			'type' => \Elementor\Controls_Manager::SWITCHER,
			'default' => 'yes',
		]);

		$this->add_control('playsinline', [
			'label' => esc_html__('Plays Inline on iOS', 'nmj'),
			'description' => esc_html__('Prevents iOS Safari from going fullscreen automatically.', 'nmj'),
			'type' => \Elementor\Controls_Manager::SWITCHER,
			'default' => 'yes',
		]);

		$this->add_control('preload', [
			'label' => esc_html__('Preload', 'nmj'),
			'description' => esc_html__('Use "None" for below-fold placement. "Metadata" or "Auto" can speed up above-fold start time at the cost of more bandwidth.', 'nmj'),
			'type' => \Elementor\Controls_Manager::SELECT,
			'default' => 'none',
			'options' => [
				'none' => esc_html__('None', 'nmj'),
				'metadata' => esc_html__('Metadata', 'nmj'),
				'auto' => esc_html__('Auto', 'nmj'),
			],
		]);

		$this->end_controls_section();
	}

	// Content — Breakpoints
	private function controls_breakpoints(): void
	{
		$this->start_controls_section('section_breakpoints', [
			'label' => esc_html__('Breakpoints', 'nmj'),
			'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
		]);

		$this->add_control('breakpoints_info', [
			'type' => \Elementor\Controls_Manager::RAW_HTML,
			'raw' => wp_kses_post(
				__('<strong>Mobile</strong>: ≤ Mobile Max width<br><strong>Tablet</strong>: Mobile Max &lt; width ≤ Tablet Max<br><strong>Desktop</strong>: above Tablet Max', 'nmj')
			),
			'content_classes' => 'elementor-descriptor',
		]);

		$this->add_control('mobile_breakpoint', [
			'label' => esc_html__('Mobile Max Width (px)', 'nmj'),
			'type' => \Elementor\Controls_Manager::NUMBER,
			'default' => 767,
			'min' => 200,
			'max' => 1280,
		]);

		$this->add_control('tablet_breakpoint', [
			'label' => esc_html__('Tablet Max Width (px)', 'nmj'),
			'type' => \Elementor\Controls_Manager::NUMBER,
			'default' => 1024,
			'min' => 320,
			'max' => 2560,
		]);

		$this->end_controls_section();
	}

	// Style — Layout
	private function controls_style_layout(): void
	{
		$this->start_controls_section('section_style_layout', [
			'label' => esc_html__('Layout', 'nmj'),
			'tab' => \Elementor\Controls_Manager::TAB_STYLE,
		]);

		// Responsive SELECT control → Elementor auto-generates per-breakpoint CSS rules,
		// so the aspect ratio can be 16/9 on desktop and 9/16 on mobile, for example.
		$this->add_responsive_control('aspect_ratio', [
			'label' => esc_html__('Aspect Ratio', 'nmj'),
			'type' => \Elementor\Controls_Manager::SELECT,
			'default' => '16/9',
			'options' => [
				'16/9' => '16:9 — Widescreen',
				'9/16' => '9:16 — Portrait',
				'4/3' => '4:3 — Standard',
				'3/2' => '3:2',
				'1/1' => '1:1 — Square',
				'21/9' => '21:9 — Ultrawide',
			],
			'selectors' => [
				'{{WRAPPER}} .nmj-rv-inner' => 'aspect-ratio: {{VALUE}};',
			],
		]);

		$this->add_control('max_width', [
			'label' => esc_html__('Max Width', 'nmj'),
			'type' => \Elementor\Controls_Manager::SLIDER,
			'size_units' => ['px', '%', 'vw'],
			'range' => [
				'px' => ['min' => 100, 'max' => 2400, 'step' => 10],
				'%' => ['min' => 10, 'max' => 100],
				'vw' => ['min' => 10, 'max' => 100],
			],
			'selectors' => [
				'{{WRAPPER}} .nmj-rv-wrap' => 'max-width: {{SIZE}}{{UNIT}};',
			],
		]);

		// Block-level alignment via margins (applies when max-width constrains the wrap).
		$this->add_responsive_control('alignment', [
			'label' => esc_html__('Alignment', 'nmj'),
			'type' => \Elementor\Controls_Manager::CHOOSE,
			'options' => [
				'left' => ['title' => esc_html__('Left', 'nmj'), 'icon' => 'eicon-text-align-left'],
				'center' => ['title' => esc_html__('Center', 'nmj'), 'icon' => 'eicon-text-align-center'],
				'right' => ['title' => esc_html__('Right', 'nmj'), 'icon' => 'eicon-text-align-right'],
			],
			'selectors' => [
				'{{WRAPPER}} .nmj-rv-wrap' => '{{VALUE}}',
			],
			'selectors_dictionary' => [
				'left' => 'margin-right: auto;',
				'center' => 'margin-left: auto; margin-right: auto;',
				'right' => 'margin-left: auto;',
			],
		]);

		$this->end_controls_section();
	}

	// Style — Video
	private function controls_style_video(): void
	{
		$this->start_controls_section('section_style_video', [
			'label' => esc_html__('Video', 'nmj'),
			'tab' => \Elementor\Controls_Manager::TAB_STYLE,
		]);

		$this->add_control('object_fit', [
			'label' => esc_html__('Object Fit', 'nmj'),
			'type' => \Elementor\Controls_Manager::SELECT,
			'default' => 'cover',
			'options' => [
				'cover' => esc_html__('Cover', 'nmj'),
				'contain' => esc_html__('Contain', 'nmj'),
				'fill' => esc_html__('Fill', 'nmj'),
			],
			'selectors' => [
				'{{WRAPPER}} .nmj-rv-video' => 'object-fit: {{VALUE}};',
			],
		]);

		$this->add_responsive_control('border_radius', [
			'label' => esc_html__('Border Radius', 'nmj'),
			'type' => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => ['px', '%', 'em', 'rem'],
			'selectors' => [
				// overflow:hidden written here so radius clips the video correctly
				'{{WRAPPER}} .nmj-rv-inner' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
			],
		]);

		$this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
			'name' => 'box_shadow',
			'selector' => '{{WRAPPER}} .nmj-rv-inner',
		]);

		$this->add_group_control(\Elementor\Group_Control_Border::get_type(), [
			'name' => 'border',
			'selector' => '{{WRAPPER}} .nmj-rv-inner',
		]);

		$this->end_controls_section();
	}

	// ── Render (server-side / frontend) ──────────────────────────────────────

	protected function render(): void
	{
		$s = $this->get_settings_for_display();

		$desktop_type = $s['desktop_source_type'] ?? 'hosted';
		$tablet_type = $s['tablet_source_type'] ?? 'hosted';
		$mobile_type = $s['mobile_source_type'] ?? 'hosted';
		$desktop_src = !empty($s['desktop_video']['url']) ? $s['desktop_video']['url'] : '';
		$tablet_src = !empty($s['tablet_video']['url']) ? $s['tablet_video']['url'] : '';
		$mobile_src = !empty($s['mobile_video']['url']) ? $s['mobile_video']['url'] : '';
		$desktop_fallback = !empty($s['desktop_fallback_video']['url']) ? $s['desktop_fallback_video']['url'] : '';
		$tablet_fallback = !empty($s['tablet_fallback_video']['url']) ? $s['tablet_fallback_video']['url'] : '';
		$mobile_fallback = !empty($s['mobile_fallback_video']['url']) ? $s['mobile_fallback_video']['url'] : '';
		$desktop_external = $s['desktop_external_url'] ?? '';
		$tablet_external = $s['tablet_external_url'] ?? '';
		$mobile_external = $s['mobile_external_url'] ?? '';
		$desktop_poster = !empty($s['desktop_poster']['url']) ? $s['desktop_poster']['url'] : '';
		$tablet_poster = !empty($s['tablet_poster']['url']) ? $s['tablet_poster']['url'] : '';
		$mobile_poster = !empty($s['mobile_poster']['url']) ? $s['mobile_poster']['url'] : '';

		// Per-breakpoint mode. 'poster' means this breakpoint renders the poster
		// and nothing else — it must NOT fall through to another breakpoint's
		// video, which is the whole point of the switch.
		$modes = [];
		foreach (['desktop', 'tablet', 'mobile'] as $device) {
			$modes[$device] = (($s["{$device}_show_video"] ?? 'yes') === 'yes') ? 'video' : 'poster';
		}

		$posters = ['desktop' => $desktop_poster, 'tablet' => $tablet_poster, 'mobile' => $mobile_poster];

		// Any poster at all is enough for a poster-mode breakpoint — the
		// <picture> below falls back across breakpoints on its own.
		$any_poster = $desktop_poster || $tablet_poster || $mobile_poster;

		// A breakpoint is configured if it has what its own mode requires.
		$has = [
			'desktop' => 'poster' === $modes['desktop']
				? (bool) $any_poster
				: (('hosted' === $desktop_type && $desktop_src) || ('external' === $desktop_type && $desktop_external)),
			'tablet' => 'poster' === $modes['tablet']
				? (bool) $any_poster
				: (('hosted' === $tablet_type && $tablet_src) || ('external' === $tablet_type && $tablet_external)),
			'mobile' => 'poster' === $modes['mobile']
				? (bool) $any_poster
				: (('hosted' === $mobile_type && $mobile_src) || ('external' === $mobile_type && $mobile_external)),
		];

		if (!$has['desktop'] && !$has['tablet'] && !$has['mobile']) {
			if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
				echo '<div class="nmj-rv-placeholder"><span>'
					. esc_html__('Set a video, or switch a breakpoint to Poster and set a poster image.', 'nmj')
					. '</span></div>';
			}
			return;
		}

		// The poster is required. Warn in the editor only — the front end still
		// renders whatever it can rather than showing visitors an error.
		if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
			$missing = [];
			foreach ($modes as $device => $mode) {
				// Only complain about a breakpoint that is actually in use.
				if ($has[$device] && empty($posters[$device])) {
					$missing[] = ucfirst($device);
				}
			}
			if ($missing) {
				printf(
					'<div class="nmj-rv-notice">%s</div>',
					esc_html(sprintf(
						/* translators: %s: comma-separated breakpoint names. */
						__('Poster image missing for: %s. It will fall back to another breakpoint’s poster.', 'nmj'),
						implode(', ', $missing)
					))
				);
			}
		}

		$mobile_bp = absint($s['mobile_breakpoint'] ?? 767);
		$tablet_bp = absint($s['tablet_breakpoint'] ?? 1024);

		// Build boolean attribute string from switcher controls.
		// All values come from a fixed set of known-safe HTML attribute names.
		$bool_attrs = '';
		foreach ([
			'autoplay' => $s['autoplay'] ?? '',
			'muted' => $s['muted'] ?? '',
			'loop' => $s['loop'] ?? '',
			'controls' => $s['controls'] ?? 'yes',
			'playsinline' => $s['playsinline'] ?? 'yes',
		] as $attr => $val) {
			if ('yes' === $val) {
				$bool_attrs .= ' ' . $attr;
			}
		}

		// Resolve one poster per breakpoint, each falling back to whatever is
		// set. These go into a <picture> below so the browser's preload scanner
		// picks the right one during HTML parsing — the correct poster is on
		// screen before any JS runs, which matters most in poster-only mode
		// where the poster is the entire content.
		$fallback_poster = $desktop_poster ?: $tablet_poster ?: $mobile_poster;
		$poster_for = [
			'desktop' => $desktop_poster ?: $fallback_poster,
			'tablet' => $tablet_poster ?: $fallback_poster,
			'mobile' => $mobile_poster ?: $fallback_poster,
		];
		$server_poster = $poster_for['desktop'];

		// Preload attribute — validated against a known-safe allowlist.
		$preload = $s['preload'] ?? 'none';
		if (!in_array($preload, ['none', 'metadata', 'auto'], true)) {
			$preload = 'none';
		}

		?>
		<div class="nmj-rv-wrap" data-desktop-type="<?php echo esc_attr($desktop_type); ?>"
			data-tablet-type="<?php echo esc_attr($tablet_type); ?>"
			data-mobile-type="<?php echo esc_attr($mobile_type); ?>" data-desktop-src="<?php echo esc_url($desktop_src); ?>"
			data-tablet-src="<?php echo esc_url($tablet_src); ?>" data-mobile-src="<?php echo esc_url($mobile_src); ?>"
			data-desktop-fallback="<?php echo esc_url($desktop_fallback); ?>"
			data-tablet-fallback="<?php echo esc_url($tablet_fallback); ?>"
			data-mobile-fallback="<?php echo esc_url($mobile_fallback); ?>"
			data-desktop-external="<?php echo esc_url($desktop_external); ?>"
			data-tablet-external="<?php echo esc_url($tablet_external); ?>"
			data-mobile-external="<?php echo esc_url($mobile_external); ?>"
			data-desktop-poster="<?php echo esc_url($desktop_poster); ?>"
			data-tablet-poster="<?php echo esc_url($tablet_poster); ?>"
			data-mobile-poster="<?php echo esc_url($mobile_poster); ?>" data-mobile-bp="<?php echo esc_attr($mobile_bp); ?>"
			data-tablet-bp="<?php echo esc_attr($tablet_bp); ?>"
			data-desktop-mode="<?php echo esc_attr($modes['desktop']); ?>"
			data-tablet-mode="<?php echo esc_attr($modes['tablet']); ?>"
			data-mobile-mode="<?php echo esc_attr($modes['mobile']); ?>">
			<div class="nmj-rv-inner">
				<?php
				// The media container starts empty. JavaScript injects a <video>
				// or an <iframe> for breakpoints in video mode, and injects
				// nothing at all for breakpoints in poster mode — where the
				// <picture> below is the finished output, not a placeholder.
				?>
				<div class="nmj-rv-media-container" data-preload="<?php echo esc_attr($preload); ?>"
					data-attrs="<?php echo esc_attr(trim($bool_attrs)); ?>">
					<?php if ($server_poster): ?>
						<?php
						// <picture> rather than a single <img>: the browser picks
						// the poster matching the viewport before JS runs, so
						// mobile never briefly shows the desktop still.
						// Source order matters — narrowest first, first match wins.
						?>
						<picture class="nmj-rv-poster-fallback">
							<?php if ($poster_for['mobile'] && $poster_for['mobile'] !== $server_poster): ?>
								<source media="(max-width: <?php echo esc_attr($mobile_bp); ?>px)"
									srcset="<?php echo esc_url($poster_for['mobile']); ?>" />
							<?php endif; ?>
							<?php if ($poster_for['tablet'] && $poster_for['tablet'] !== $server_poster): ?>
								<source media="(max-width: <?php echo esc_attr($tablet_bp); ?>px)"
									srcset="<?php echo esc_url($poster_for['tablet']); ?>" />
							<?php endif; ?>
							<img src="<?php echo esc_url($server_poster); ?>" alt="" />
						</picture>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	// ── content_template (Elementor editor live preview) ─────────────────────

	protected function content_template(): void
	{
		?>
		<# var desktopType=settings.desktop_source_type || 'hosted' ; var tabletType=settings.tablet_source_type || 'hosted' ;
			var mobileType=settings.mobile_source_type || 'hosted' ; var desktopSrc=settings.desktop_video &&
			settings.desktop_video.url ? settings.desktop_video.url : '' ; var tabletSrc=settings.tablet_video &&
			settings.tablet_video.url ? settings.tablet_video.url : '' ; var mobileSrc=settings.mobile_video &&
			settings.mobile_video.url ? settings.mobile_video.url : '' ; var desktopFallback=settings.desktop_fallback_video &&
			settings.desktop_fallback_video.url ? settings.desktop_fallback_video.url : '' ; var
			tabletFallback=settings.tablet_fallback_video && settings.tablet_fallback_video.url ?
			settings.tablet_fallback_video.url : '' ; var mobileFallback=settings.mobile_fallback_video &&
			settings.mobile_fallback_video.url ? settings.mobile_fallback_video.url : '' ; var
			desktopExternal=settings.desktop_external_url || '' ; var tabletExternal=settings.tablet_external_url || '' ; var
			mobileExternal=settings.mobile_external_url || '' ; var desktopPoster=settings.desktop_poster &&
			settings.desktop_poster.url ? settings.desktop_poster.url : '' ; var tabletPoster=settings.tablet_poster &&
			settings.tablet_poster.url ? settings.tablet_poster.url : '' ; var mobilePoster=settings.mobile_poster &&
			settings.mobile_poster.url ? settings.mobile_poster.url : '' ;
			var desktopMode=(settings.desktop_show_video==='yes' || settings.desktop_show_video===undefined) ? 'video' : 'poster' ;
			var tabletMode=(settings.tablet_show_video==='yes' || settings.tablet_show_video===undefined) ? 'video' : 'poster' ;
			var mobileMode=(settings.mobile_show_video==='yes' || settings.mobile_show_video===undefined) ? 'video' : 'poster' ;
			var hasDesktop=desktopMode==='poster' ? !!desktopPoster : ((desktopType==='hosted' && desktopSrc)
			|| (desktopType==='external' && desktopExternal)); var hasTablet=tabletMode==='poster' ? !!tabletPoster : ((tabletType==='hosted' && tabletSrc) ||
			(tabletType==='external' && tabletExternal)); var hasMobile=mobileMode==='poster' ? !!mobilePoster : ((mobileType==='hosted' && mobileSrc) ||
			(mobileType==='external' && mobileExternal)); if ( ! hasDesktop && ! hasTablet && ! hasMobile ) { #>
			<div class="nmj-rv-placeholder">
				<span><?php echo esc_js(__('Set a video, or switch a breakpoint to Poster and set a poster image.', 'nmj')); ?></span>
			</div>
			<# } else { var boolAttrs='' ; if ( settings.autoplay==='yes' ) boolAttrs +=' autoplay' ; if (
				settings.muted==='yes' ) boolAttrs +=' muted' ; if ( settings.loop==='yes' ) boolAttrs +=' loop' ; if (
				settings.controls==='yes' ) boolAttrs +=' controls' ; if ( settings.playsinline==='yes' ) boolAttrs
				+=' playsinline' ; boolAttrs=boolAttrs.trim(); #>
				<div class="nmj-rv-wrap" data-desktop-type="{{ desktopType }}" data-tablet-type="{{ tabletType }}"
					data-mobile-type="{{ mobileType }}" data-desktop-src="{{ desktopSrc }}" data-tablet-src="{{ tabletSrc }}"
					data-mobile-src="{{ mobileSrc }}" data-desktop-fallback="{{ desktopFallback }}"
					data-tablet-fallback="{{ tabletFallback }}" data-mobile-fallback="{{ mobileFallback }}"
					data-desktop-external="{{ desktopExternal }}" data-tablet-external="{{ tabletExternal }}"
					data-mobile-external="{{ mobileExternal }}" data-desktop-poster="{{ desktopPoster }}"
					data-tablet-poster="{{ tabletPoster }}" data-mobile-poster="{{ mobilePoster }}"
					data-mobile-bp="{{ settings.mobile_breakpoint || 767 }}"
					data-tablet-bp="{{ settings.tablet_breakpoint || 1024 }}" data-desktop-mode="{{ desktopMode }}"
					data-tablet-mode="{{ tabletMode }}" data-mobile-mode="{{ mobileMode }}">
					<div class="nmj-rv-inner">
						<div class="nmj-rv-media-container" data-preload="{{ settings.preload || 'none' }}"
							data-attrs="{{ boolAttrs }}">
							<# var fallbackPoster=desktopPoster || tabletPoster || mobilePoster;
							   var serverPoster=fallbackPoster;
							   var mobileP=mobilePoster || fallbackPoster;
							   var tabletP=tabletPoster || fallbackPoster;
							   if ( serverPoster ) { #>
								<picture class="nmj-rv-poster-fallback">
									<# if ( mobileP && mobileP !== serverPoster ) { #>
										<source media="(max-width: {{ settings.mobile_breakpoint || 767 }}px)" srcset="{{ mobileP }}" />
									<# } #>
									<# if ( tabletP && tabletP !== serverPoster ) { #>
										<source media="(max-width: {{ settings.tablet_breakpoint || 1024 }}px)" srcset="{{ tabletP }}" />
									<# } #>
									<img src="{{ serverPoster }}" alt="" />
								</picture>
								<# } #>
						</div>
					</div>
				</div>
				<# } #>
					<?php
	}
}