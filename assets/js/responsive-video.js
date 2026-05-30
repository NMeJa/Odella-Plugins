/**
 * Responsive Video Widget – front-end controller
 *
 * Reads data-* attrs from .nmj-rv-wrap, picks the right video src + poster
 * for the current viewport, and injects them into the <video> element.
 *
 * Fallback chains (smaller → larger):
 *   mobile  → tablet  → desktop
 *   tablet  → desktop → mobile
 *   desktop → tablet  → mobile
 *
 * Only the matched source is ever set on the <video>, so no wasted bandwidth.
 */
( function () {
	'use strict';

	/* ── helpers ────────────────────────────────────────────────────────── */

	/**
	 * Return 'mobile' | 'tablet' | 'desktop' based on viewport width
	 * and the per-widget breakpoint data-attributes.
	 */
	function getBreakpoint( wrap ) {
		var mobileBp = parseInt( wrap.dataset.mobileBp, 10 ) || 767;
		var tabletBp = parseInt( wrap.dataset.tabletBp, 10 ) || 1024;
		var w        = window.innerWidth;

		if ( w <= mobileBp ) return 'mobile';
		if ( w <= tabletBp ) return 'tablet';
		return 'desktop';
	}

	/**
	 * Resolve a URL (video or poster) for the current breakpoint,
	 * falling back through the chain when a slot is empty.
	 *
	 * @param {HTMLElement} wrap   – .nmj-rv-wrap element
	 * @param {string}      suffix – 'Src' or 'Poster' (camelCase to match dataset keys)
	 * @return {string} resolved URL or ''
	 */
	function resolve( wrap, suffix ) {
		var desktop = wrap.dataset[ 'desktop' + suffix ] || '';
		var tablet  = wrap.dataset[ 'tablet'  + suffix ] || '';
		var mobile  = wrap.dataset[ 'mobile'  + suffix ] || '';
		var bp      = getBreakpoint( wrap );

		if ( bp === 'mobile'  ) return mobile  || tablet || desktop;
		if ( bp === 'tablet'  ) return tablet  || desktop || mobile;
		/* desktop */             return desktop || tablet || mobile;
	}

	/* ── per-widget apply ──────────────────────────────────────────────── */

	function applySource( wrap ) {
		var video = wrap.querySelector( '.nmj-rv-video' );
		if ( ! video ) return;

		var newSrc    = resolve( wrap, 'Src' );
		var newPoster = resolve( wrap, 'Poster' );

		/* Poster — always safe to swap instantly */
		var curPoster = video.getAttribute( 'poster' ) || '';
		if ( newPoster !== curPoster ) {
			if ( newPoster ) {
				video.setAttribute( 'poster', newPoster );
			} else {
				video.removeAttribute( 'poster' );
			}
		}

		/* Src — only reload when it actually changed */
		var curSrc = video.getAttribute( 'src' ) || '';
		if ( newSrc === curSrc ) return;

		var wasPlaying = ! video.paused && ! video.ended;
		var time       = video.currentTime || 0;

		if ( newSrc ) {
			video.setAttribute( 'src', newSrc );
			video.load();

			if ( wasPlaying ) {
				video.addEventListener( 'loadeddata', function onLoaded() {
					video.removeEventListener( 'loadeddata', onLoaded );
					video.currentTime = Math.min( time, video.duration || 0 );
					video.play().catch( function () {} );
				} );
			}
		} else {
			video.removeAttribute( 'src' );
			video.load();
		}
	}

	/* ── resize (debounced) ────────────────────────────────────────────── */

	var resizeTimer;
	function onResize() {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( function () {
			var wraps = document.querySelectorAll( '.nmj-rv-wrap' );
			for ( var i = 0; i < wraps.length; i++ ) {
				applySource( wraps[ i ] );
			}
		}, 150 );
	}

	/* ── init ──────────────────────────────────────────────────────────── */

	function init() {
		var wraps = document.querySelectorAll( '.nmj-rv-wrap' );
		for ( var i = 0; i < wraps.length; i++ ) {
			applySource( wraps[ i ] );
		}
		window.addEventListener( 'resize', onResize );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	/* ── Elementor editor live preview ─────────────────────────────────── */

	if ( typeof jQuery !== 'undefined' ) {
		jQuery( window ).on( 'elementor/frontend/init', function () {
			if ( window.elementorFrontend && elementorFrontend.hooks ) {
				elementorFrontend.hooks.addAction(
					'frontend/element_ready/nmj-responsive-video.default',
					function ( $scope ) {
						$scope.find( '.nmj-rv-wrap' ).each( function () {
							applySource( this );
						} );
					}
				);
			}
		} );
	}
} )();
