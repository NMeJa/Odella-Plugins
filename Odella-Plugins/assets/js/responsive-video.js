/**
 * Responsive Video Widget – front-end controller
 *
 * Reads data-* attrs from .nmj-rv-wrap, picks the right video src + poster
 * for the current viewport, and injects them into the DOM.
 *
 * It dynamically swaps between HTML5 <video> elements and YouTube/Vimeo <iframe>
 * elements when hitting breakpoints. The un-needed DOM node is destroyed so
 * background audio doesn't continue playing.
 */
(function () {
	'use strict';

	/* ── helpers ────────────────────────────────────────────────────────── */

	function getBreakpoint(wrap) {
		var mobileBp = parseInt(wrap.dataset.mobileBp, 10) || 767;
		var tabletBp = parseInt(wrap.dataset.tabletBp, 10) || 1024;
		var w = window.innerWidth;

		if (w <= mobileBp) return 'mobile';
		if (w <= tabletBp) return 'tablet';
		return 'desktop';
	}

	function isPortrait() {
		if (window.matchMedia) return window.matchMedia('(orientation: portrait)').matches;
		return window.innerHeight >= window.innerWidth;
	}

	// 'poster' = show the poster image only; no video is loaded for this
	// breakpoint and it must not borrow another breakpoint's footage.
	function getMode(wrap, bp) {
		// Orientation wins over the width breakpoints. A phone in landscape can
		// be 992px wide — inside the tablet band — so a mobile-only poster rule
		// never reaches it, and widening the mobile band to catch it would take
		// real tablets with it. Shape is the axis that actually separates them.
		if (wrap.dataset.portraitPoster === '1' && isPortrait()) return 'poster';

		return wrap.dataset[bp + 'Mode'] === 'poster' ? 'poster' : 'video';
	}

	function hasData(wrap, bp) {
		// Poster mode always counts as configured, even with no poster set for
		// this specific breakpoint — the server-rendered <picture> already falls
		// back to another breakpoint's image on its own.
		//
		// Deliberately unconditional: "Show Video: off" has to be a guarantee,
		// not a preference. Returning false on a missing poster would send the
		// fallback chain looking for a video and land mobile on the desktop
		// cut — the exact outcome the switch exists to prevent.
		if (getMode(wrap, bp) === 'poster') return true;

		var type = wrap.dataset[bp + 'Type'];
		if (type === 'external') return !!wrap.dataset[bp + 'External'];
		return !!wrap.dataset[bp + 'Src'];
	}

	/**
	 * Find the active breakpoint, walking up/down the fallback chain
	 * if the exact matched breakpoint has no video configured.
	 */
	function getActiveBreakpoint(wrap) {
		var bp = getBreakpoint(wrap);

		if (bp === 'mobile') {
			if (hasData(wrap, 'mobile')) return 'mobile';
			if (hasData(wrap, 'tablet')) return 'tablet';
			return 'desktop';
		}
		if (bp === 'tablet') {
			if (hasData(wrap, 'tablet')) return 'tablet';
			if (hasData(wrap, 'desktop')) return 'desktop';
			return 'mobile';
		}
		// desktop
		if (hasData(wrap, 'desktop')) return 'desktop';
		if (hasData(wrap, 'tablet')) return 'tablet';
		return 'mobile';
	}

	function parseExternalUrl(url) {
		var ytMatch = url.match(/(?:youtu\.be\/|youtube\.com\/(?:embed\/|v\/|watch\?v=|watch\?.+&v=))([^&]{11})/);
		if (ytMatch) return { type: 'youtube', id: ytMatch[1] };

		var viMatch = url.match(/(?:vimeo\.com\/|player\.vimeo\.com\/video\/)([0-9]+)/);
		if (viMatch) return { type: 'vimeo', id: viMatch[1] };

		return null;
	}

	function guessVideoType(url) {
		var ext = url.split('?')[0].split('.').pop().toLowerCase();
		var map = { mp4: 'video/mp4', m4v: 'video/mp4', webm: 'video/webm', ogg: 'video/ogg', ogv: 'video/ogg', mov: 'video/quicktime' };
		return map[ext] || '';
	}

	function buildIframeUrl(parsed, attrsStr) {
		var autoplay = attrsStr.indexOf('autoplay') !== -1;
		var muted = attrsStr.indexOf('muted') !== -1;
		var loop = attrsStr.indexOf('loop') !== -1;
		var controls = attrsStr.indexOf('controls') !== -1;
		var playsinline = attrsStr.indexOf('playsinline') !== -1;

		if (parsed.type === 'youtube') {
			var url = 'https://www.youtube-nocookie.com/embed/' + parsed.id + '?rel=0';
			if (autoplay) url += '&autoplay=1';
			if (muted) url += '&mute=1';
			if (loop) url += '&loop=1&playlist=' + parsed.id;
			if (!controls) url += '&controls=0';
			if (playsinline) url += '&playsinline=1';
			return url;
		}

		if (parsed.type === 'vimeo') {
			var vUrl = 'https://player.vimeo.com/video/' + parsed.id + '?dnt=1';
			if (autoplay) vUrl += '&autoplay=1';
			if (muted) vUrl += '&muted=1';
			if (loop) vUrl += '&loop=1';
			if (!controls) vUrl += '&background=1'; // Vimeo hides controls entirely in background mode
			if (playsinline) vUrl += '&playsinline=1';
			return vUrl;
		}

		return '';
	}

	/* ── per-widget apply ──────────────────────────────────────────────── */

	function applySource(wrap) {
		var container = wrap.querySelector('.nmj-rv-media-container');
		if (!container) return;

		// Marks this wrap as activated, so the resize handler knows it is
		// allowed to re-evaluate it.
		wrap.setAttribute('data-nmj-active', '1');

		var bp = getActiveBreakpoint(wrap);
		var newMode = getMode(wrap, bp);

		// Poster-only: tear down any media left from a wider viewport and stop.
		// Nothing is downloaded, and the server-rendered <picture> — which has
		// already chosen the right poster for this viewport on its own — is the
		// finished output rather than a placeholder, so it is never removed.
		if (newMode === 'poster') {
			container.setAttribute('data-nmj-type', 'poster');
			container.removeAttribute('data-nmj-src');
			container.removeAttribute('data-nmj-fallback');
			container.removeAttribute('data-nmj-external');

			// Bring the poster back if a wider viewport had played a video and
			// hidden it.
			var posterEl = container.querySelector('.nmj-rv-poster-fallback');
			if (posterEl) posterEl.classList.remove('nmj-rv-poster--hidden');

			var stale = container.querySelectorAll('video, iframe');
			for (var m = 0; m < stale.length; m++) {
				var node = stale[m];
				// Pause before detaching: removing a playing <video> from the
				// DOM does not reliably stop its audio in every browser.
				if (node.tagName === 'VIDEO') { try { node.pause(); } catch (e) { } }
				node.parentNode.removeChild(node);
			}
			return;
		}

		var newType = wrap.dataset[bp + 'Type'] || 'hosted';
		var newSrc = wrap.dataset[bp + 'Src'] || '';
		var newFallback = wrap.dataset[bp + 'Fallback'] || '';
		var newExternal = wrap.dataset[bp + 'External'] || '';
		var newPoster = wrap.dataset[bp + 'Poster'] || '';

		var curType = container.getAttribute('data-nmj-type') || '';
		var curSrc = container.getAttribute('data-nmj-src') || '';
		var curFallback = container.getAttribute('data-nmj-fallback') || '';
		var curExternal = container.getAttribute('data-nmj-external') || '';

		// If nothing structural changed, bail out (or just update poster)
		if (newType === curType && newSrc === curSrc && newFallback === curFallback && newExternal === curExternal) {
			if (newType === 'hosted') {
				var existingVideo = container.querySelector('video');
				if (existingVideo) {
					var curPoster = existingVideo.getAttribute('poster') || '';
					if (newPoster !== curPoster) {
						if (newPoster) existingVideo.setAttribute('poster', newPoster);
						else existingVideo.removeAttribute('poster');
					}
				}
			}
			return;
		}

		// Save state
		container.setAttribute('data-nmj-type', newType);
		container.setAttribute('data-nmj-src', newSrc);
		container.setAttribute('data-nmj-fallback', newFallback);
		container.setAttribute('data-nmj-external', newExternal);

		// Remove existing video/iframe to stop playback, but keep poster fallback
		var oldMedia = container.querySelectorAll('video, iframe');
		for (var k = 0; k < oldMedia.length; k++) {
			oldMedia[k].parentNode.removeChild(oldMedia[k]);
		}

		// Hide, never remove. Resizing from a video breakpoint into a
		// poster-only one has to be able to bring the poster back, and it
		// cannot do that if playback destroyed the node.
		function removePoster() {
			var poster = container.querySelector('.nmj-rv-poster-fallback');
			if (poster) poster.classList.add('nmj-rv-poster--hidden');
		}

		if (newType === 'hosted') {
			if (!newSrc && !newFallback) return;

			var video = document.createElement('video');
			video.className = 'nmj-rv-video';

			var preload = container.getAttribute('data-preload') || 'none';
			video.setAttribute('preload', preload);

			if (newPoster) video.setAttribute('poster', newPoster);

			var attrsStr = container.getAttribute('data-attrs') || '';
			var attrsArr = attrsStr.split(' ');
			for (var j = 0; j < attrsArr.length; j++) {
				if (attrsArr[j]) video.setAttribute(attrsArr[j], '');
			}

			// Browsers check the .muted PROPERTY (not just attribute) for autoplay policy.
			// setAttribute('muted','') alone doesn't reliably sync the property on
			// dynamically created elements — always set both.
			if (video.hasAttribute('autoplay')) {
				video.muted = true;
				if (!video.hasAttribute('muted')) {
					video.setAttribute('muted', '');
				}
			} else if (video.hasAttribute('muted')) {
				video.muted = true;
			}

			if (newSrc) {
				var s1 = document.createElement('source');
				s1.src = newSrc;
				var t1 = guessVideoType(newSrc);
				if (t1) s1.type = t1;
				video.appendChild(s1);
			}
			if (newFallback) {
				var s2 = document.createElement('source');
				s2.src = newFallback;
				var t2 = guessVideoType(newFallback);
				if (t2) s2.type = t2;
				video.appendChild(s2);
			}

			container.appendChild(video);

			// Remove poster only once video actually starts playing
			video.addEventListener('playing', removePoster, { once: true });

			// Safety net: if the browser doesn't honour the autoplay attribute
			// on this dynamically-created element, retry once data is ready.
			if (video.hasAttribute('autoplay')) {
				video.addEventListener('canplay', function () {
					if (video.paused) {
						video.play().catch(function () { });
					}
				}, { once: true });
			}

		} else if (newType === 'external') {
			if (!newExternal) return;

			var parsed = parseExternalUrl(newExternal);
			if (!parsed) return;

			var iframeUrl = buildIframeUrl(parsed, container.getAttribute('data-attrs') || '');
			if (!iframeUrl) return;

			var iframe = document.createElement('iframe');
			iframe.className = 'nmj-rv-video';
			iframe.src = iframeUrl;
			iframe.setAttribute('frameborder', '0');
			iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
			iframe.setAttribute('allowfullscreen', '1');
			iframe.setAttribute('loading', 'lazy');

			container.appendChild(iframe);

			// Remove poster once iframe loads
			iframe.addEventListener('load', removePoster, { once: true });
		}
	}

	/* ── resize (debounced) ────────────────────────────────────────────── */

	var resizeTimer;
	function onResize() {
		clearTimeout(resizeTimer);
		resizeTimer = setTimeout(function () {
			// Only re-evaluate wraps the observer has already activated.
			// Walking every wrap here would bypass the IntersectionObserver
			// gate and eagerly load offscreen videos it deliberately deferred —
			// and on mobile the address bar collapsing counts as a resize, so
			// a single scroll could trigger a page-wide video load.
			var wraps = document.querySelectorAll('.nmj-rv-wrap[data-nmj-active="1"]');
			for (var i = 0; i < wraps.length; i++) {
				applySource(wraps[i]);
			}
		}, 150);
	}

	/* ── init ──────────────────────────────────────────────────────────── */

	function init() {
		var wraps = document.querySelectorAll('.nmj-rv-wrap');

		if ('IntersectionObserver' in window) {
			var observer = new IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting) {
						applySource(entry.target);
						observer.unobserve(entry.target); // load once, then stop watching
					}
				});
			}, { rootMargin: '200px' }); // start loading 200px before it enters the viewport

			for (var i = 0; i < wraps.length; i++) {
				observer.observe(wraps[i]);
			}
		} else {
			// Fallback for old browsers
			for (var i = 0; i < wraps.length; i++) {
				applySource(wraps[i]);
			}
		}

		window.addEventListener('resize', onResize);

		// Rotating a phone fires resize in current browsers, but not always
		// before the new dimensions settle. orientationchange is cheap
		// insurance for the Portrait: Poster Only rule, which is the one
		// setting that flips purely on rotation.
		window.addEventListener('orientationchange', onResize);
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

	/* ── Elementor editor live preview ─────────────────────────────────── */

	if (typeof jQuery !== 'undefined') {
		jQuery(window).on('elementor/frontend/init', function () {
			if (window.elementorFrontend && window.elementorFrontend.hooks) {
				elementorFrontend.hooks.addAction(
					'frontend/element_ready/nmj-responsive-video.default',
					function ($scope) {
						$scope.find('.nmj-rv-wrap').each(function () {
							applySource(this);
						});
					}
				);
			}
		});
	}
})();
