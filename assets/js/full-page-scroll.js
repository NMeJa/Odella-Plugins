// full-page-scroll.js
document.addEventListener('DOMContentLoaded', () => {

    const widgetData = document.querySelector('.nmj-fps-data');
    if (!widgetData) return;

    const sectionsData = JSON.parse(widgetData.getAttribute('data-sections'));
    const offset = parseInt(widgetData.getAttribute('data-offset')) || 0;

    const navDots = document.querySelectorAll('.nmj-fps-dot');
    let sectionElements = [];

    // this changed: Replaced wheel hijacking and arbitrary setTimeouts with CSS native scroll-snapping and IntersectionObserver for rock-solid performance and UX.
    document.documentElement.style.scrollSnapType = 'y mandatory';

    // 1. Setup IntersectionObserver to update dots dynamically based on scroll position
    const observerOptions = {
        root: null,
        rootMargin: `-${offset}px 0px 0px 0px`,
        threshold: 0.5 // Triggers when section is 50% visible
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const activeIndex = sectionElements.indexOf(entry.target);
                navDots.forEach((dot, i) => {
                    dot.classList.toggle('active', i === activeIndex);
                });
            }
        });
    }, observerOptions);

    // 2. Find all sections and apply Native CSS Scroll Snapping
    sectionsData.forEach((sec) => {
        const el = document.getElementById(sec.id);
        if (el) {
            el.style.minHeight = '100vh';
            el.style.scrollMarginTop = '0px';
            el.style.paddingTop = `${offset}px`;
            el.style.boxSizing = 'border-box';

            // Apply native scroll snap rules instead of JS locking
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
            sectionElements[index].scrollIntoView({ behavior: 'smooth' });
        });
    });

});