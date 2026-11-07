/**
 * Full Page Scroll Widget – Front-End Controller
 *
 * Features:
 * • Full-viewport section layout with sticky header offset compensation.
 * • Native CSS scroll-snap (`y mandatory`) combined with debounced mouse-wheel,
 *   trackpad, and keyboard section-to-section smooth scrolling.
 * • Side dot navigation with smooth multi-section transitions and real-time active tracking.
 */
(function () {
    'use strict';

    var instances = [];
    var htmlEl = document.documentElement;
    var originalSnap = null;
    var globalListenersAttached = false;

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
     */
    function refreshSnap() {
        instances = instances.filter(function (inst) {
            return inst.sections.length && document.contains(inst.sections[0]);
        });

        if (!instances.length) {
            releaseSnap();
            return;
        }

        // Suspend CSS scroll-snap while a programmatic smooth scroll is in flight
        // so `scroll-snap-stop: always` on intermediate sections never blocks multi-section jumps.
        var anyAnimating = instances.some(function (inst) { return inst.isAnimating; });
        if (anyAnimating) {
            htmlEl.style.scrollSnapType = 'none';
            return;
        }

        var mandatory = instances.some(function (inst) { return !inst.released; });
        setSnap(mandatory ? 'y mandatory' : 'y proximity');
    }

    function setActive(inst, index) {
        if (index < 0 || index >= inst.sections.length) return;
        inst.activeIndex = index;
        inst.dots.forEach(function (dot, i) {
            dot.classList.toggle('active', i === index);
        });
    }

    function getSectionTargetY(inst, index) {
        if (index <= 0) return 0;
        var el = inst.sections[index];
        if (!el) return 0;
        var scrollY = window.pageYOffset || htmlEl.scrollTop || 0;
        return Math.max(0, Math.round(el.getBoundingClientRect().top + scrollY));
    }

    function scrollToSection(inst, index) {
        if (index < 0 || index >= inst.sections.length) return;

        inst.released = false;
        inst.isAnimating = true;
        setActive(inst, index);
        refreshSnap();

        var targetY = getSectionTargetY(inst, index);
        window.scrollTo({
            top: targetY,
            behavior: 'smooth'
        });

        if (inst.animTimer) clearTimeout(inst.animTimer);
        inst.animTimer = setTimeout(function () {
            inst.isAnimating = false;
            refreshSnap();
            updateActiveFromScroll(inst);
        }, 680);
    }

    function updateActiveFromScroll(inst) {
        if (inst.isAnimating || !inst.sections.length) return;

        var scrollY = window.pageYOffset || htmlEl.scrollTop || 0;
        var vh = window.innerHeight || htmlEl.clientHeight;
        var probeY = scrollY + (vh * 0.42);

        var bestIndex = 0;
        for (var i = 0; i < inst.sections.length; i++) {
            var secTop = inst.sections[i].getBoundingClientRect().top + scrollY;
            if (secTop <= probeY) {
                bestIndex = i;
            }
        }

        // If scrolled to the very bottom of the document, activate last section
        var docHeight = Math.max(
            document.body.scrollHeight, htmlEl.scrollHeight,
            document.body.offsetHeight, htmlEl.offsetHeight
        );
        if (scrollY + vh >= docHeight - 20) {
            bestIndex = inst.sections.length - 1;
        }

        if (bestIndex !== inst.activeIndex) {
            setActive(inst, bestIndex);
        }

        if (inst.allowPast && inst.activeIndex < inst.sections.length - 1 && inst.released) {
            inst.released = false;
            refreshSnap();
        }
    }

    function attachGlobalListeners() {
        if (globalListenersAttached) return;
        globalListenersAttached = true;

        var lastWheelTrigger = 0;
        var lastWheelEventTime = 0;

        window.addEventListener('wheel', function (e) {
            if (!instances.length) return;
            if (e.ctrlKey || e.metaKey || e.altKey) return;

            // Ignore horizontal-dominant gestures (e.g. Swiper carousel trackpad swiping)
            if (Math.abs(e.deltaX) >= Math.abs(e.deltaY)) return;
            if (Math.abs(e.deltaY) < 6) return;

            // Ignore wheel events inside open modals, slide-out cart drawers, or dropdowns
            if (e.target && e.target.closest && e.target.closest('.moderncart-slide-out-modal, #moderncart-slide-out, .elementor-popup-modal, .dialog-widget-content, .elementor-nav-menu--dropdown')) {
                return;
            }

            var inst = instances[instances.length - 1];
            if (!inst || !inst.sections.length) return;

            var now = Date.now();
            var timeSincePrevEvent = now - lastWheelEventTime;
            lastWheelEventTime = now;

            // If currently animating a section transition, swallow wheel momentum
            if (inst.isAnimating) {
                e.preventDefault();
                return;
            }

            // Cooldown guard against continuous trackpad inertia after a transition
            if (now - lastWheelTrigger < 750 && timeSincePrevEvent < 120) {
                e.preventDefault();
                return;
            }

            updateActiveFromScroll(inst);

            var dir = e.deltaY > 0 ? 1 : -1;
            var currentSec = inst.sections[inst.activeIndex];

            if (currentSec) {
                var rect = currentSec.getBoundingClientRect();
                var vh = window.innerHeight || htmlEl.clientHeight;

                // If the current section is taller than the viewport, allow natural scrolling inside it
                // until the visitor reaches its top or bottom boundary.
                if (rect.height > vh + 20) {
                    if (dir > 0 && rect.bottom > vh + 12) {
                        return;
                    }
                    if (dir < 0 && rect.top < -12) {
                        return;
                    }
                }
            }

            var nextIndex = inst.activeIndex + dir;

            if (nextIndex < 0) {
                if ((window.pageYOffset || htmlEl.scrollTop || 0) > 5) {
                    e.preventDefault();
                    lastWheelTrigger = now;
                    scrollToSection(inst, 0);
                }
                return;
            }

            if (nextIndex >= inst.sections.length) {
                if (inst.allowPast && !inst.released) {
                    inst.released = true;
                    refreshSnap();
                }
                return;
            }

            e.preventDefault();
            lastWheelTrigger = now;
            scrollToSection(inst, nextIndex);
        }, { passive: false });

        // Keyboard navigation (ArrowDown, ArrowUp, PageDown, PageUp, Home, End)
        window.addEventListener('keydown', function (e) {
            if (!instances.length) return;
            if (e.ctrlKey || e.metaKey || e.altKey) return;

            var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
            if (tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable)) {
                return;
            }

            var inst = instances[instances.length - 1];
            if (!inst || !inst.sections.length || inst.isAnimating) return;

            if (e.key === 'ArrowDown' || e.key === 'PageDown') {
                if (inst.activeIndex < inst.sections.length - 1) {
                    e.preventDefault();
                    scrollToSection(inst, inst.activeIndex + 1);
                }
            } else if (e.key === 'ArrowUp' || e.key === 'PageUp') {
                if (inst.activeIndex > 0) {
                    e.preventDefault();
                    scrollToSection(inst, inst.activeIndex - 1);
                }
            } else if (e.key === 'Home') {
                e.preventDefault();
                scrollToSection(inst, 0);
            } else if (e.key === 'End') {
                e.preventDefault();
                scrollToSection(inst, inst.sections.length - 1);
            }
        });

        // Real-time scroll tracking for touch swipes and scrollbar dragging
        var ticking = false;
        window.addEventListener('scroll', function () {
            if (!ticking) {
                window.requestAnimationFrame(function () {
                    for (var i = 0; i < instances.length; i++) {
                        updateActiveFromScroll(instances[i]);
                    }
                    ticking = false;
                });
                ticking = true;
            }
        }, { passive: true });
    }

    function initInstance(dataEl) {
        if (dataEl.dataset.nmjFpsReady === '1') return;
        dataEl.dataset.nmjFpsReady = '1';

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

        var root = dataEl.parentNode || document;
        var navDots = Array.prototype.slice.call(root.querySelectorAll('.nmj-fps-dot'));

        var inst = {
            sections: [],
            dots: navDots,
            activeIndex: 0,
            offset: offset,
            allowPast: allowPast,
            released: false,
            isAnimating: false,
            animTimer: null
        };

        sectionsData.forEach(function (sec, idx) {
            if (!sec || !sec.id) return;
            var el = document.getElementById(sec.id);
            if (!el) return;

            if (idx === 0) {
                // First section sits directly below the sticky header in normal flow:
                // scroll-margin-top = offset keeps its snap point at scrollY = 0 with zero layout shift.
                el.style.setProperty('min-height', 'calc(100vh - ' + offset + 'px)', 'important');
                el.style.setProperty('scroll-margin-top', offset + 'px', 'important');
                el.style.setProperty('padding-top', '0px', 'important');
                var rvInner = el.querySelector('.nmj-rv-inner');
                if (rvInner) {
                    rvInner.style.maxHeight = 'calc(100vh - ' + offset + 'px)';
                }
            } else {
                el.style.setProperty('min-height', '100vh', 'important');
                el.style.setProperty('scroll-margin-top', '0px', 'important');
                el.style.setProperty('padding-top', offset + 'px', 'important');
            }
            el.style.setProperty('box-sizing', 'border-box', 'important');
            el.style.setProperty('scroll-snap-align', 'start', 'important');
            el.style.setProperty('scroll-snap-stop', 'always', 'important');

            inst.sections.push(el);
        });

        if (!inst.sections.length) return;

        inst.dots.forEach(function (dot, index) {
            dot.addEventListener('click', function (e) {
                e.preventDefault();
                scrollToSection(inst, index);
            });
        });

        if (allowPast) {
            var touchStartY = 0;
            window.addEventListener('touchstart', function (e) {
                if (e.touches && e.touches.length) {
                    touchStartY = e.touches[0].clientY;
                }
            }, { passive: true });

            window.addEventListener('touchmove', function (e) {
                if (!e.touches || !e.touches.length) return;
                if (touchStartY - e.touches[0].clientY > 30 && inst.activeIndex === inst.sections.length - 1 && !inst.released) {
                    inst.released = true;
                    refreshSnap();
                }
            }, { passive: true });
        }

        instances.push(inst);
        attachGlobalListeners();
        refreshSnap();
        updateActiveFromScroll(inst);
    }

    function init(scope) {
        var root = scope || document;
        var nodes = root.querySelectorAll('.nmj-fps-data');
        for (var i = 0; i < nodes.length; i++) {
            initInstance(nodes[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }

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
