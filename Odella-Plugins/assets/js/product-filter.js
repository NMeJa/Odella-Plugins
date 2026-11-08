(function () {
    var cache = [];
    var bound = false;

    function onReady() {
        if (!window.elementorFrontend || !elementorFrontend.hooks) {
            return;
        }
        elementorFrontend.hooks.addAction('frontend/element_ready/nmj_product_filter.default', init);
    }

    if (window.elementorFrontend && elementorFrontend.hooks) {
        onReady();
    } else {
        window.addEventListener('elementor/frontend/init', onReady);
    }

    function init($scope) {
        var root = $scope && $scope[0] ? $scope[0] : $scope;
        if (!root || root.dataset.nmjPfReady === '1') {
            return;
        }
        var widget = root.querySelector('.nmj-pf') || root;
        if (!widget.classList || !widget.classList.contains('nmj-pf')) {
            return;
        }
        root.dataset.nmjPfReady = '1';
        var button = widget.querySelector('.nmj-pf-toggle');
        var panel = widget.querySelector('.nmj-pf-panel');
        var overlay = widget.querySelector('.nmj-pf-overlay');
        if (!button || !panel) {
            return;
        }

        button.addEventListener('click', function (event) {
            if (event.target.closest('.nmj-pf-clear')) {
                return;
            }
            setOpen(widget, button, panel, overlay, button.getAttribute('aria-expanded') !== 'true');
        });
        if (overlay) {
            overlay.addEventListener('click', function () {
                setOpen(widget, button, panel, overlay, false);
            });
        }
        panel.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setOpen(widget, button, panel, overlay, false);
                return;
            }
            if (event.key === 'Tab') {
                trap(panel, event);
            }
        });
        var handle = panel.querySelector('.nmj-pf-handle');
        if (handle) {
            drag(handle, function () {
                setOpen(widget, button, panel, overlay, false);
            });
        }
        panel.querySelectorAll('.nmj-pf-chevron').forEach(function (chevron) {
            chevron.addEventListener('click', function () {
                var sub = chevron.closest('.nmj-pf-item').querySelector('.nmj-pf-sub');
                var open = chevron.getAttribute('aria-expanded') !== 'true';
                chevron.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (sub) {
                    sub.classList.toggle('is-open', open);
                }
            });
        });

        widget.addEventListener('click', function (event) {
            var link = event.target.closest('a');
            if (!link || !widget.contains(link)) {
                return;
            }
            if (link.classList.contains('nmj-pf-link') || link.classList.contains('nmj-pf-chip') || link.classList.contains('nmj-pf-clear') || link.closest('.nmj-pf-empty')) {
                event.preventDefault();
                var href = link.getAttribute('href');
                if (link.classList.contains('nmj-pf-link') && !link.classList.contains('nmj-pf-all')) {
                    href = keepQuery(href);
                }
                go(widget, href, true);
            }
        });
        var search = widget.querySelector('.nmj-pf-search');
        if (search) {
            search.addEventListener('submit', function (event) {
                event.preventDefault();
                var url = new URL(search.action, window.location.href);
                var value = search.querySelector('[name="q"]').value.trim();
                if (value) {
                    url.searchParams.set('q', value);
                }
                var orderby = new URL(window.location.href).searchParams.get('orderby');
                if (orderby && orderby !== 'date') {
                    url.searchParams.set('orderby', orderby);
                }
                go(widget, url.toString(), true);
            });
        }
        var sort = widget.querySelector('.nmj-pf-sort select');
        if (sort) {
            sort.addEventListener('change', function () {
                var url = new URL(window.location.href);
                if (sort.value && sort.value !== 'date') {
                    url.searchParams.set('orderby', sort.value);
                } else {
                    url.searchParams.delete('orderby');
                }
                go(widget, url.toString(), true);
            });
        }
        widget.querySelectorAll('.nmj-pf-link').forEach(function (link) {
            var timer = 0;
            function arm() {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    prefetch(keepQuery(link.getAttribute('href')));
                }, 80);
            }
            link.addEventListener('pointerenter', arm);
            link.addEventListener('touchstart', arm, { passive: true });
        });

        watchProgress(widget);
        if (!bound) {
            bound = true;
            window.addEventListener('popstate', function () {
                var live = document.querySelector('.nmj-pf');
                if (live) {
                    go(live, window.location.href, false);
                }
            });
        }
    }

    function setOpen(widget, button, panel, overlay, open) {
        widget.classList.toggle('is-open', open);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        panel.hidden = !open;
        if (overlay) {
            overlay.hidden = !open;
        }
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) {
            var focusable = panel.querySelector('input, select, a, button');
            if (focusable) {
                focusable.focus();
            }
        } else {
            button.focus();
        }
    }

    function trap(panel, event) {
        var items = panel.querySelectorAll('a, button, input, select, textarea');
        if (!items.length) {
            return;
        }
        var first = items[0];
        var last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function drag(handle, close) {
        var startY = 0;
        handle.addEventListener('pointerdown', function (event) {
            if (!window.matchMedia('(max-width: 767px)').matches) {
                return;
            }
            startY = event.clientY;
            handle.setPointerCapture(event.pointerId);
        });
        handle.addEventListener('pointerup', function (event) {
            if (event.clientY - startY > 80) {
                close();
            }
        });
    }

    function keepQuery(href) {
        var url = new URL(href, window.location.href);
        var current = new URL(window.location.href);
        var q = current.searchParams.get('q');
        var orderby = current.searchParams.get('orderby');
        if (q && !url.searchParams.has('q')) {
            url.searchParams.set('q', q);
        }
        if (orderby && orderby !== 'date' && !url.searchParams.has('orderby')) {
            url.searchParams.set('orderby', orderby);
        }
        return url.toString();
    }

    function prefetch(url) {
        if (!url || recall(url)) {
            return;
        }
        fetch(url, { credentials: 'same-origin' }).then(function (response) {
            if (!response.ok) {
                return '';
            }
            return response.text();
        }).then(function (text) {
            if (text) {
                remember(url, text);
            }
        }).catch(function () {});
    }

    function remember(url, text) {
        cache = cache.filter(function (entry) {
            return entry.url !== url;
        });
        cache.unshift({ url: url, text: text, at: Date.now() });
        cache = cache.slice(0, 6);
    }

    function recall(url) {
        var now = Date.now();
        for (var i = 0; i < cache.length; i++) {
            if (cache[i].url === url && now - cache[i].at < 60000) {
                return cache[i].text;
            }
        }
        return '';
    }

    function go(widget, url, push) {
        if (!url) {
            return;
        }
        var grid = document.querySelector('.elementor-element-' + widget.dataset.grid);
        if (grid) {
            grid.classList.add('nmj-pf-loading');
        }
        if (window.matchMedia('(max-width: 767px)').matches) {
            var button = widget.querySelector('.nmj-pf-toggle');
            var panel = widget.querySelector('.nmj-pf-panel');
            var overlay = widget.querySelector('.nmj-pf-overlay');
            if (button && panel) {
                setOpen(widget, button, panel, overlay, false);
            }
        }
        var cached = recall(url);
        var request = cached ? Promise.resolve(cached) : fetch(url, { credentials: 'same-origin' }).then(function (response) {
            if (!response.ok) {
                throw new Error('status');
            }
            return response.text();
        });
        request.then(function (html) {
            remember(url, html);
            apply(widget, html, url);
            if (push) {
                history.pushState({ nmjPf: true }, '', url);
            }
        }).catch(function () {
            window.location.href = url;
        });
    }

    function apply(widget, html, url) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var gridId = widget.dataset.grid;
        var grid = replaceElement(doc, '.elementor-element-' + gridId);
        var element = widget.closest('.elementor-element');
        if (element && element.dataset.id) {
            replaceElement(doc, '.elementor-element-' + element.dataset.id);
        }
        // Template 15580 has loop-grid c490a4e and shortcode 816677c only.
        // There is no archive title or breadcrumb widget to swap.
        var title = doc.querySelector('title');
        if (title) {
            document.title = title.textContent;
        }
        copyHead(doc, 'link[rel="canonical"]');
        document.querySelectorAll('link[rel="alternate"][hreflang]').forEach(function (node) {
            node.remove();
        });
        doc.querySelectorAll('link[rel="alternate"][hreflang]').forEach(function (node) {
            document.head.appendChild(document.importNode(node, true));
        });
        if (grid && window.jQuery && window.elementorFrontend) {
            elementorFrontend.elementsHandler.runReadyTrigger(window.jQuery(grid));
            var filter = document.querySelector('.elementor-element-' + (element && element.dataset.id ? element.dataset.id : ''));
            if (filter) {
                elementorFrontend.elementsHandler.runReadyTrigger(window.jQuery(filter));
            }
            window.jQuery(document.body).trigger('post-load');
        }
        var live = document.querySelector('.nmj-pf') || widget;
        showEmpty(live, grid, url);
        watchProgress(live);
        if (grid) {
            grid.classList.remove('nmj-pf-loading');
            var top = grid.getBoundingClientRect().top;
            if (top < 0 || top > window.innerHeight) {
                grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    function replaceElement(doc, selector) {
        var next = doc.querySelector(selector);
        var current = document.querySelector(selector);
        if (!next || !current) {
            return null;
        }
        var imported = document.importNode(next, true);
        current.replaceWith(imported);
        return imported;
    }

    function copyHead(doc, selector) {
        var next = doc.querySelector(selector);
        var current = document.querySelector(selector);
        if (next && current) {
            current.replaceWith(document.importNode(next, true));
        }
    }

    function showEmpty(widget, grid, url) {
        if (!grid) {
            return;
        }
        var params = new URL(url, window.location.href).searchParams;
        var q = params.get('q');
        var existing = grid.querySelector('.nmj-pf-empty');
        if (existing) {
            existing.remove();
        }
        if (grid.querySelector('.e-loop-item') || !q) {
            return;
        }
        var strings = window.nmjPf || {};
        var box = document.createElement('div');
        box.className = 'nmj-pf-empty';
        var message = document.createElement('p');
        message.textContent = strings.nothingFound || '';
        var link = document.createElement('a');
        var store = new URL(widget.dataset.store || '/', window.location.href);
        store.searchParams.set('q', q);
        link.href = store.toString();
        link.textContent = strings.searchAll || '';
        box.appendChild(message);
        box.appendChild(link);
        grid.appendChild(box);
    }

    function watchProgress(widget) {
        var grid = document.querySelector('.elementor-element-' + widget.dataset.grid);
        if (!grid) {
            return;
        }
        updateProgress(widget, grid);
        if (grid.dataset.nmjPfWatch === '1') {
            return;
        }
        grid.dataset.nmjPfWatch = '1';
        var observer = new MutationObserver(function () {
            updateProgress(widget, grid);
        });
        observer.observe(grid, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });
    }

    function updateProgress(widget, grid) {
        var line = grid.querySelector('.nmj-pf-progress');
        if (widget.dataset.showProgress !== '1') {
            if (line) {
                line.hidden = true;
            }
            return;
        }
        var button = grid.querySelector('.e-loop__load-more');
        var ended = grid.classList.contains('e-load-more-pagination-end');
        var hidden = !button || ended || window.getComputedStyle(button).display === 'none';
        var count = grid.querySelectorAll('.e-loop-item').length;
        if (hidden || count === 0) {
            if (line) {
                line.hidden = true;
            }
            return;
        }
        if (!line) {
            line = document.createElement('p');
            line.className = 'nmj-pf-progress';
            button.insertAdjacentElement('afterend', line);
        }
        var template = (window.nmjPf && window.nmjPf.showing) || '%1$d / %2$d';
        var total = widget.dataset.total || '0';
        line.hidden = false;
        line.textContent = template.replace('%1$d', String(count)).replace('%2$d', String(total));
    }
})();
