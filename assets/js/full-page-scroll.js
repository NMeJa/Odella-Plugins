/**
 * Full Page Scroll Widget – front-end controller
 *
 * Each widget instance owns its own sections, dots and observer. Instances are
 * discovered per element rather than via document-wide queries, so two widgets
 * on one page no longer merge into each other.
 */
(function () {
    'use strict';

    // Every initialised instance, so the global snap state can be recomputed
    // from all of them rather than clobbered by whichever ran last.
    var instances = [];
    var htmlEl = document.documentElement;

    // The page's own scroll-snap value, captured before we touch it, so it can
    // be restored when the last instance goes away (e.g. a widget inside an
    // Elementor popup that has since closed).
    var originalSnap = null;

    function setSnap(value) {
        if (originalSnap === null) {
            originalSnap = htmlEl.style.scrollSnapType || '';
        }
        htmlEl.style.scrollSnapType = value;
    }

    function releaseSnap() {
        if (originalSnap === null) return;
        htmlEl.style.scrollSnapType = originalSnap;
        originalSnap = null;
    }

    /**
     * Recompute the document snap mode from all live instances.
     *
     * Instances whose sections have been removed from the DOM are dropped —
     * this is what stops a closed popup leaving the whole page snap-scrolling.
     */
    function refreshSnap() {
        instances = instances.filter(function (inst) {
            return inst.sections.length && document.contains(inst.sections[0]);
        });

        if (!instances.length) {
            releaseSnap();
            return;
        }

        // Proximity only when every live instance wants to release; any
        // instance still holding mandatory wins.
        var mandatory = instances.some(function (inst) { return !inst.released; });
        setSnap(mandatory ? 'y mandatory' : 'y proximity');
    }

    /* ── per-widget init ───────────────────────────────────────────────── */

    function initInstance(dataEl) {
        if (dataEl.dataset.nmjFpsReady === '1') return;
        dataEl.dataset.nmjFpsReady = '1';

        // A malformed attribute used to throw here and take the whole file
        // down with it, disabling every other instance on the page.
        var sectionsData;
        try {
            sectionsData = JSON.parse(dataEl.getAttribute('data-sections') || '[]');
        } catch (err) {
            if (window.console) {
                console.warn('[NMJ FPS] Could not parse data-sections; skipping this instance.', err);
            }
            return;
        }
        if (!Array.isArray(sectionsData) || !sectionsData.length) return;

        var offset = parseInt(dataEl.getAttribute('data-offset'), 10) || 0;
        var allowPast = dataEl.getAttribute('data-allow-past') === '1';

        // Scoped to this widget's own root, not the document. Both the data
        // node and the dots live inside the same widget wrapper.
        var root = dataEl.parentNode || document;
        var navDots = Array.prototype.slice.call(root.querySelectorAll('.nmj-fps-dot'));

        var inst = {
            sections: [],
            dots: navDots,
            activeIndex: 0,
            released: false
        };

        sectionsData.forEach(function (sec) {
            if (!sec || !sec.id) return;
            var el = document.getElementById(sec.id);
            if (!el) return;

            el.style.minHeight = '100vh';
            el.style.scrollMarginTop = '0px';
            el.style.paddingTop = offset + 'px';
            el.style.boxSizing = 'border-box';
            el.style.scrollSnapAlign = 'start';
            el.style.scrollSnapStop = 'always';

            inst.sections.push(el);
        });

        if (!inst.sections.length) return;

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;

                inst.activeIndex = inst.sections.indexOf(entry.target);
                inst.dots.forEach(function (dot, i) {
                    dot.classList.toggle('active', i === inst.activeIndex);
                });

                // Re-engage snapping as soon as a non-last section is in view.
                if (allowPast && inst.activeIndex < inst.sections.length - 1) {
                    inst.released = false;
                    refreshSnap();
                }
            });
        }, {
            root: null,
            rootMargin: '-' + offset + 'px 0px 0px 0px',
            threshold: 0.5
        });

        inst.sections.forEach(function (el) { observer.observe(el); });

        inst.dots.forEach(function (dot, index) {
            dot.addEventListener('click', function () {
                if (!inst.sections[index]) return;
                inst.released = false;
                refreshSnap();
                inst.sections[index].scrollIntoView({ behavior: 'smooth' });
            });
        });

        // Allow-past: release snapping when the visitor scrolls down from the
        // last section, so the footer stays reachable.
        if (allowPast) {
            var release = function () {
                if (inst.activeIndex !== inst.sections.length - 1) return;
                if (inst.released) return;
                inst.released = true;
                refreshSnap();
            };

            window.addEventListener('wheel', function (e) {
                if (e.deltaY > 0) release();
            }, { passive: true });

            var touchStartY = 0;
            window.addEventListener('touchstart', function (e) {
                touchStartY = e.touches[0].clientY;
            }, { passive: true });

            window.addEventListener('touchmove', function (e) {
                if (touchStartY - e.touches[0].clientY > 30) release();
            }, { passive: true });
        }

        instances.push(inst);
        refreshSnap();
    }

    function init(scope) {
        var root = scope || document;
        var nodes = root.querySelectorAll('.nmj-fps-data');
        for (var i = 0; i < nodes.length; i++) {
            initInstance(nodes[i]);
        }
    }

    /* ── boot ──────────────────────────────────────────────────────────── */

    // readyState guard: the script is loaded in the footer and may be deferred
    // by an optimisation plugin, in which case DOMContentLoaded has already
    // fired and a listener would never run.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }

    // Elementor editor preview + widgets arriving via AJAX (popups, filtered
    // loops), which DOMContentLoaded alone never covers.
    if (typeof jQuery !== 'undefined') {
        jQuery(window).on('elementor/frontend/init', function () {
            if (!window.elementorFrontend || !window.elementorFrontend.hooks) return;
            elementorFrontend.hooks.addAction(
                'frontend/element_ready/nmj_full_page_scroll.default',
                function ($scope) { init($scope[0]); }
            );
        });
    }
})();
