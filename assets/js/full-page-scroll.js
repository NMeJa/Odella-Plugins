// full-page-scroll.js
document.addEventListener('DOMContentLoaded', () => {

    const widgetData = document.querySelector('.nmj-fps-data');
    if (!widgetData) return;

    const sectionsData = JSON.parse(widgetData.getAttribute('data-sections'));
    const offset = parseInt(widgetData.getAttribute('data-offset')) || 0;
    const allowPast = widgetData.getAttribute('data-allow-past') === '1';

    const navDots = document.querySelectorAll('.nmj-fps-dot');
    let sectionElements = [];
    let activeIndex = 0;

    // Start with mandatory snap
    document.documentElement.style.scrollSnapType = 'y mandatory';

    // 1. Setup IntersectionObserver to update dots and track active section
    const observerOptions = {
        root: null,
        rootMargin: `-${offset}px 0px 0px 0px`,
        threshold: 0.5
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                activeIndex = sectionElements.indexOf(entry.target);
                navDots.forEach((dot, i) => {
                    dot.classList.toggle('active', i === activeIndex);
                });

                // When allow-past is enabled:
                // - If a non-last section becomes visible, ensure mandatory snap
                // - If the last section becomes visible, we keep mandatory for now;
                //   the wheel/touch handler below switches to proximity on downward scroll
                if (allowPast && activeIndex < sectionElements.length - 1) {
                    document.documentElement.style.scrollSnapType = 'y mandatory';
                }
            }
        });
    }, observerOptions);

    // 2. Find all sections and apply native CSS scroll snapping
    sectionsData.forEach((sec) => {
        const el = document.getElementById(sec.id);
        if (el) {
            el.style.minHeight = '100vh';
            el.style.scrollMarginTop = '0px';
            el.style.paddingTop = `${offset}px`;
            el.style.boxSizing = 'border-box';
            el.style.scrollSnapAlign = 'start';
            el.style.scrollSnapStop = 'always';

            sectionElements.push(el);
            observer.observe(el);
        }
    });

    if (sectionElements.length === 0) {
        document.documentElement.style.scrollSnapType = 'none';
        return;
    }

    // 3. Listen for Dot Clicks
    navDots.forEach((dot, index) => {
        dot.addEventListener('click', () => {
            // Re-engage mandatory snap before navigating
            document.documentElement.style.scrollSnapType = 'y mandatory';
            sectionElements[index].scrollIntoView({ behavior: 'smooth' });
        });
    });

    // 4. Allow-past: switch to proximity when scrolling down past last section
    if (allowPast && sectionElements.length > 0) {
        const lastIndex = sectionElements.length - 1;

        // Detect downward scroll intent while on last section
        window.addEventListener('wheel', (e) => {
            if (e.deltaY > 0 && activeIndex === lastIndex) {
                document.documentElement.style.scrollSnapType = 'y proximity';
            }
        }, { passive: true });

        // Touch support: detect swipe-up (scroll down) on last section
        let touchStartY = 0;
        window.addEventListener('touchstart', (e) => {
            touchStartY = e.touches[0].clientY;
        }, { passive: true });

        window.addEventListener('touchmove', (e) => {
            const deltaY = touchStartY - e.touches[0].clientY;
            if (deltaY > 30 && activeIndex === lastIndex) {
                document.documentElement.style.scrollSnapType = 'y proximity';
            }
        }, { passive: true });
    }

});