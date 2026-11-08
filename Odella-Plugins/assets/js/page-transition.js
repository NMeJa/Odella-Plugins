(function () {
    var overlay = document.getElementById('odella-pt');
    if (!overlay) {
        return;
    }

    var failsafe = 0;

    function clearLeaving() {
        overlay.classList.remove('is-leaving');
        if (failsafe) {
            window.clearTimeout(failsafe);
            failsafe = 0;
        }
    }

    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            clearLeaving();
        }
    });

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0) {
            return;
        }
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }

        var link = event.target && event.target.closest ? event.target.closest('a') : null;
        if (!link || link.hasAttribute('data-no-transition') || link.hasAttribute('download')) {
            return;
        }
        var target = link.getAttribute('target');
        if (target && target !== '_self') {
            return;
        }
        if (link.closest('.moderncart-slide-out-modal, #moderncart-slide-out, .elementor-lightbox')) {
            return;
        }
        if (link.getAttribute('data-elementor-open-lightbox') === 'yes') {
            return;
        }

        var href = link.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || href.indexOf('mailto:') === 0 || href.indexOf('tel:') === 0 || href.indexOf('javascript:') === 0) {
            return;
        }

        var next;
        try {
            next = new URL(href, window.location.href);
        } catch (error) {
            return;
        }
        if (next.origin !== window.location.origin) {
            return;
        }
        if (next.pathname === window.location.pathname && next.search === window.location.search) {
            return;
        }

        overlay.classList.add('is-leaving');
        if (failsafe) {
            window.clearTimeout(failsafe);
        }
        failsafe = window.setTimeout(clearLeaving, 8000);
    });
}());
