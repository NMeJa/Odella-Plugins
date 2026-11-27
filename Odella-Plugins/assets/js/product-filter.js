(function () {
    var cache = [];
    var bound = false;
    var indexData = null;
    var indexPromise = null;
    var cardTemplate = null;

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
        if (!window.__nmjPfMore) {
            window.__nmjPfMore = true;
            document.addEventListener('click', function (event) {
                var button = event.target.closest('.e-loop__load-more');
                if (!button || !indexData) {
                    return;
                }
                var widget = document.querySelector('.nmj-pf');
                if (!widget || !widget._nmjMatch) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                showMore(widget);
            }, true);
        }

        watchProgress(widget);
        rememberCard();
        loadIndex();
        publishFilterMetrics(widget);
        if (!bound) {
            bound = true;
            window.addEventListener('popstate', function () {
                var live = document.querySelector('.nmj-pf');
                if (live) {
                    go(live, window.location.href, false);
                }
            });
            document.addEventListener('click', function (event) {
                var opener = event.target.closest ? event.target.closest('.nmj-pf-open') : null;
                if (!opener || opener.closest('.nmj-pf')) {
                    return;
                }
                var live = document.querySelector('.nmj-pf');
                var liveButton = live && live.querySelector('.nmj-pf-toggle');
                var livePanel = live && live.querySelector('.nmj-pf-panel');
                if (!live || !liveButton || !livePanel) {
                    return;
                }
                event.preventDefault();
                setOpen(live, liveButton, livePanel, live.querySelector('.nmj-pf-overlay'), liveButton.getAttribute('aria-expanded') !== 'true');
                window.setTimeout(function () {
                    blurOpener();
                }, 0);
            });
        }
    }

    function blurOpener() {
        var active = document.activeElement;
        if (!active || !active.closest) {
            return;
        }
        if (active.closest('.nmj-pf-open') || active.classList.contains('nmj-pf-open')) {
            active.blur();
        }
    }

    function publishFilterMetrics(widget) {
        var styles = window.getComputedStyle(widget);
        var width = styles.getPropertyValue('--nmj-pf-panel-width').trim();
        var height = styles.getPropertyValue('--nmj-pf-sheet-height').trim();
        if (width) {
            document.documentElement.style.setProperty('--nmj-pf-panel-width', width);
        }
        if (height) {
            document.documentElement.style.setProperty('--nmj-pf-sheet-height', height);
        }
    }

    var closeTimer = 0;

    function finishClose(widget, panel, overlay) {
        if (widget.classList.contains('is-open')) {
            return;
        }
        panel.hidden = true;
        if (overlay) {
            overlay.hidden = true;
        }
    }

    function setOpen(widget, button, panel, overlay, open) {
        publishFilterMetrics(widget);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.querySelectorAll('.nmj-pf-open').forEach(function (opener) {
            opener.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        window.clearTimeout(closeTimer);
        if (open) {
            panel.hidden = false;
            if (overlay) {
                overlay.hidden = false;
            }
            document.body.style.overflow = 'hidden';
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(function () {
                    if (button.getAttribute('aria-expanded') !== 'true') {
                        return;
                    }
                    widget.classList.add('is-open');
                    document.documentElement.classList.add('nmj-pf-is-open');
                });
            });
            var focusable = panel.querySelector('input, select, a, button');
            if (focusable) {
                focusable.focus();
            }
            return;
        }
        widget.classList.remove('is-open');
        document.documentElement.classList.remove('nmj-pf-is-open');
        document.body.style.overflow = '';
        if (document.activeElement && panel.contains(document.activeElement)) {
            document.activeElement.blur();
        }
        blurOpener();
        var onEnd = function (event) {
            if (event.target !== panel || event.propertyName !== 'transform') {
                return;
            }
            panel.removeEventListener('transitionend', onEnd);
            finishClose(widget, panel, overlay);
        };
        panel.addEventListener('transitionend', onEnd);
        closeTimer = window.setTimeout(function () {
            panel.removeEventListener('transitionend', onEnd);
            finishClose(widget, panel, overlay);
        }, 320);
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

    function prefetch() {
        loadIndex();
    }

    function loadIndex() {
        if (indexData) {
            return Promise.resolve(indexData);
        }
        if (indexPromise) {
            return indexPromise;
        }
        var base = (window.nmjPf && window.nmjPf.index) || '';
        if (!base) {
            return Promise.resolve(null);
        }
        indexPromise = fetch(base + 'current.json?t=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' }).then(function (response) {
            if (!response.ok) {
                return null;
            }
            return response.json();
        }).then(function (pointer) {
            if (!pointer || !pointer.url) {
                return null;
            }
            return fetch(pointer.url, { credentials: 'same-origin' }).then(function (response) {
                if (!response.ok) {
                    return null;
                }
                return response.json();
            });
        }).then(function (data) {
            indexData = data && data.products ? data : null;
            if (indexData) {
                fillCounts(indexData);
                var widget = document.querySelector('.nmj-pf');
                if (widget && !widget._nmjMatch) {
                    var picked = selectProducts(indexData, widget, window.location.href);
                    var grid = document.querySelector('.elementor-element-' + widget.dataset.grid);
                    if (picked && grid) {
                        pageSize(widget, grid);
                        widget._nmjMatch = picked.list;
                        widget._nmjShown = grid.querySelectorAll('.e-loop-item').length;
                    }
                }
            }
            return indexData;
        }).catch(function () {
            return null;
        });
        return indexPromise;
    }

    function pathOf(url) {
        try {
            var parsed = new URL(url, window.location.href);
            return decodeURI(parsed.pathname).replace(/\/+$/, '').toLowerCase();
        } catch (error) {
            return '';
        }
    }

    function rememberCard() {
        if (cardTemplate) {
            return;
        }
        var items = document.querySelectorAll('.e-loop-item');
        var live = null;
        for (var i = 0; i < items.length; i++) {
            if (items[i].querySelector('.nmj-price-in-stock')) {
                live = items[i];
                break;
            }
        }
        if (!live && items.length) {
            live = items[0];
        }
        if (live) {
            cardTemplate = live.cloneNode(true);
        }
    }

    function selectProducts(data, widget, url) {
        var parsed = new URL(url, window.location.href);
        var path = pathOf(parsed);
        var store = pathOf(widget.dataset.store || '/');
        var category = null;
        if (path && path !== store) {
            category = (data.categories || []).filter(function (item) {
                return pathOf(item.url) === path;
            })[0] || null;
            if (!category) {
                return null;
            }
        }
        var query = (parsed.searchParams.get('q') || '').trim().toLowerCase();
        var orderby = parsed.searchParams.get('orderby') || '';
        var lang = (window.nmjPf && window.nmjPf.lang) || '';
        var list = data.products.filter(function (product) {
            if (!product.image) {
                return false;
            }
            if (lang && product.lang && product.lang !== lang) {
                return false;
            }
            if (category && product.cats.indexOf(category.id) === -1) {
                return false;
            }
            if (!query) {
                return true;
            }
            var hay = (product.title + ' ' + (product.sku || '') + ' ' + (product.text || '')).toLowerCase();
            return query.split(/\s+/).every(function (word) {
                return hay.indexOf(word) !== -1;
            });
        });
        if (orderby === 'price' || orderby === 'price-desc') {
            list = list.slice().sort(function (a, b) {
                var diff = orderby === 'price' ? a.price - b.price : b.price - a.price;
                return diff || b.date - a.date;
            });
        }
        return { list: list, category: category, query: query, orderby: orderby };
    }

    function paintFromIndex(widget, data, url) {
        rememberCard();
        if (!cardTemplate) {
            return false;
        }
        var picked = selectProducts(data, widget, url);
        if (!picked) {
            return false;
        }
        var grid = document.querySelector('.elementor-element-' + widget.dataset.grid);
        var box = grid && (grid.querySelector('.elementor-loop-container') || grid.querySelector('.elementor-grid'));
        if (!box) {
            return false;
        }
        pageSize(widget, grid);
        widget._nmjMatch = picked.list;
        widget._nmjShown = 0;
        box.querySelectorAll('.e-loop-item').forEach(function (item) {
            item.remove();
        });
        appendProducts(widget, box, grid);
        updateChrome(widget, url, picked);
        showEmpty(widget, grid, url);
        watchProgress(widget);
        return true;
    }

    function appendProducts(widget, box, grid) {
        var size = pageSize(widget, grid);
        var list = widget._nmjMatch || [];
        var start = widget._nmjShown || 0;
        var end = Math.min(list.length, start + size);
        for (var i = start; i < end; i++) {
            box.appendChild(fillCard(widget, list[i], i === 0 && start === 0));
        }
        widget._nmjShown = end;
        if (window.nmjSyncCartButtons) {
            window.nmjSyncCartButtons();
        }
        var button = grid.querySelector('.e-loop__load-more');
        var ended = end >= list.length;
        if (!ended && !button) {
            button = ensureLoadMoreButton(grid);
        }
        grid.classList.toggle('e-load-more-pagination-end', ended);
        if (button) {
            button.style.display = ended ? 'none' : '';
        }
    }

    function ensureLoadMoreButton(grid) {
        var existing = grid.querySelector('.e-loop__load-more');
        if (existing) {
            return existing;
        }
        var label = (window.nmjPf && window.nmjPf.loadMore) || 'Load More';
        var wrap = document.createElement('div');
        wrap.className = 'e-loop__load-more elementor-button-wrapper';
        var anchor = document.createElement('a');
        anchor.className = 'elementor-button-link elementor-button';
        anchor.setAttribute('role', 'button');
        anchor.href = '#';
        var content = document.createElement('span');
        content.className = 'elementor-button-content-wrapper';
        var text = document.createElement('span');
        text.className = 'elementor-button-text';
        text.textContent = label;
        content.appendChild(text);
        anchor.appendChild(content);
        wrap.appendChild(anchor);
        var host = grid.querySelector('.elementor-widget-container') || grid;
        host.appendChild(wrap);
        return wrap;
    }

    function showMore(widget) {
        var grid = document.querySelector('.elementor-element-' + widget.dataset.grid);
        var box = grid && (grid.querySelector('.elementor-loop-container') || grid.querySelector('.elementor-grid'));
        if (!box) {
            return;
        }
        appendProducts(widget, box, grid);
        watchProgress(widget);
    }

    function pageSize(widget, grid) {
        if (!widget._nmjPageSize) {
            var existing = grid.querySelectorAll('.e-loop-item').length;
            var configured = parseInt(widget.dataset.perPage, 10) || 24;
            widget._nmjPageSize = Math.max(existing, configured);
        }
        return widget._nmjPageSize;
    }

    var wcpbcTimer = 0;

    function refreshPrices(root) {
        if (!root) {
            return;
        }
        var pending = root.querySelectorAll('.wcpbc-price.loading');
        if (!pending.length) {
            return;
        }
        if (window.jQuery) {
            window.jQuery(document.body).trigger('wc_price_based_country_ajax_geolocation');
        }
        window.clearTimeout(wcpbcTimer);
        wcpbcTimer = window.setTimeout(function () {
            root.querySelectorAll('.wcpbc-price.loading').forEach(function (el) {
                el.classList.remove('loading');
                el.style.visibility = '';
                el.style.display = '';
            });
            root.querySelectorAll('.wcpbc-placeholder').forEach(function (dot) {
                dot.remove();
            });
        }, 3500);
    }

    function fillCard(widget, product, first) {
        var node = cardTemplate.cloneNode(true);
        var id = String(product.id);
        node.className = node.className
            .replace(/\bpost-\d+\b/, 'post-' + id)
            .replace(/\be-loop-item-\d+\b/, 'e-loop-item-' + id)
            .replace(/\bproduct_cat-[\w-]+\b/g, '')
            .replace(/\b(instock|outofstock)\b/g, product.in_stock ? 'instock' : 'outofstock');
        (product.cats || []).forEach(function (catId) {
            var cat = (indexData.categories || []).filter(function (item) { return item.id === catId; })[0];
            if (cat && cat.slug) {
                node.classList.add('product_cat-' + cat.slug);
            }
        });
        node.querySelectorAll('a').forEach(function (link) {
            if (link.classList.contains('nmj-atc-btn')) {
                return;
            }
            link.href = product.url;
        });
        var title = node.querySelector('.elementor-heading-title a') || node.querySelector('.elementor-heading-title');
        if (title) {
            title.textContent = product.title;
        }
        node.querySelectorAll('img').forEach(function (img) {
            if (product.image) {
                img.src = product.image;
            }
            if (product.srcset) {
                img.setAttribute('srcset', product.srcset);
            } else {
                img.removeAttribute('srcset');
            }
            img.alt = product.title;
            if (product.width) {
                img.width = product.width;
            }
            if (product.height) {
                img.height = product.height;
            }
            img.removeAttribute('fetchpriority');
            if (!first) {
                img.loading = 'lazy';
            }
            if (product.image_id) {
                img.className = img.className.replace(/\bwp-image-\d+\b/, 'wp-image-' + product.image_id);
            }
        });
        var price = node.querySelector('.nmj-price-in-stock');
        var oos = node.querySelector('.nmj-price-oos');
        if (price) {
            price.innerHTML = product.price_html || '';
            price.classList.toggle('nmj-price--hidden', !product.in_stock);
            price.setAttribute('data-original-price', product.price_html || '');
        }
        if (oos) {
            oos.classList.toggle('nmj-price--hidden', !!product.in_stock);
        }
        var wrapper = node.querySelector('.nmj-product-price-wrapper');
        if (wrapper) {
            wrapper.setAttribute('data-product_id', id);
            wrapper.className = wrapper.className.replace(/\bnmj-price-product-\d+\b/, 'nmj-price-product-' + id);
            if (product.in_stock) {
                wrapper.removeAttribute('data-server-oos');
            } else {
                wrapper.setAttribute('data-server-oos', '1');
            }
        }
        node.querySelectorAll('.wcpbc-placeholder').forEach(function (dot) {
            dot.remove();
        });
        node.querySelectorAll('.wcpbc-price').forEach(function (amount) {
            amount.classList.remove('loading');
            amount.setAttribute('data-product-id', id);
        });
        var button = node.querySelector('.nmj-atc-btn');
        if (button) {
            var variable = product.type === 'variable' && product.in_stock;
            var state = !product.in_stock ? 'oos' : (variable ? 'awaiting' : 'in-stock');
            if (button.tagName.toLowerCase() !== 'a') {
                var anchor = document.createElement('a');
                Array.prototype.forEach.call(button.attributes, function (attr) {
                    anchor.setAttribute(attr.name, attr.value);
                });
                anchor.innerHTML = button.innerHTML;
                button.replaceWith(anchor);
                button = anchor;
            }
            ['in-stock', 'oos', 'added', 'awaiting'].forEach(function (name) {
                button.classList.remove('nmj--' + name);
            });
            button.classList.remove('nmj-qv-trigger', 'nmj--variable-inline', 'nmj--variable-link', 'nmj_custom_ajax_add_to_cart', 'add_to_cart_button', 'button', 'ajax_add_to_cart', 'added', 'loading');
            button.classList.add('nmj--' + state);
            button.setAttribute('data-state', state);
            button.setAttribute('data-product_id', id);
            button.setAttribute('data-product_sku', product.sku || '');
            button.setAttribute('data-product-url', product.url);
            button.removeAttribute('data-popup-id');
            button.style.pointerEvents = '';
            var add = new URL(widget.dataset.store || '/', window.location.href);
            add.searchParams.set('add-to-cart', id);
            button.setAttribute('data-add-url', add.pathname + add.search);
            var wrap = button.closest('.nmj-atc-wrap');
            var tpl = wrap && wrap.querySelector('.nmj-atc-icon-tpl[data-state="' + state + '"]');
            var icon = button.querySelector('.nmj-atc-icon');
            if (tpl && icon) {
                icon.innerHTML = tpl.innerHTML;
            }
            var label = button.querySelector('.nmj-atc-text');
            var texts = {};
            try { texts = JSON.parse(button.getAttribute('data-state-texts') || '{}'); } catch (error) { texts = {}; }
            if (label) {
                label.textContent = texts[state] || '';
            }
            if (variable) {
                button.classList.add('nmj--variable-link');
                button.setAttribute('data-is-variable', '1');
                button.setAttribute('data-variable-behavior', 'redirect');
                button.setAttribute('href', product.url);
                button.setAttribute('aria-label', 'Choose options for "' + product.title + '"');
                button.removeAttribute('aria-disabled');
                button.removeAttribute('tabindex');
            } else if (state === 'in-stock') {
                button.removeAttribute('data-is-variable');
                button.setAttribute('data-variable-behavior', '');
                button.setAttribute('href', button.getAttribute('data-add-url'));
                button.classList.add('add_to_cart_button', 'button', 'ajax_add_to_cart');
                button.setAttribute('aria-label', 'Add "' + product.title + '" to your cart');
                button.removeAttribute('aria-disabled');
                button.removeAttribute('tabindex');
            } else {
                button.removeAttribute('data-is-variable');
                button.setAttribute('href', product.url);
                button.setAttribute('aria-disabled', 'true');
                button.setAttribute('tabindex', '-1');
                button.setAttribute('aria-label', product.title);
            }
        }
        return node;
    }

    function updateChrome(widget, url, picked) {
        var total = picked.list.length;
        widget.dataset.total = String(total);
        var count = widget.querySelector('.nmj-pf-count');
        if (count) {
            var label = (window.nmjPf && window.nmjPf.countLabel) || '%d';
            count.textContent = label.replace('%d', String(total));
        }
        var button = widget.querySelector('.nmj-pf-toggle');
        if (button) {
            var current = button.querySelector('.nmj-pf-current');
            var clear = button.querySelector('.nmj-pf-clear');
            if (picked.category) {
                if (!current) {
                    current = document.createElement('span');
                    current.className = 'nmj-pf-current';
                    button.appendChild(current);
                }
                current.textContent = ' · ' + picked.category.name;
                if (!clear) {
                    clear = document.createElement('a');
                    clear.className = 'nmj-pf-clear';
                    clear.textContent = '✕';
                    button.appendChild(clear);
                }
                clear.href = widget.dataset.store || '/';
            } else {
                if (current) {
                    current.remove();
                }
                if (clear) {
                    clear.remove();
                }
            }
        }
        widget.querySelectorAll('.nmj-pf-link').forEach(function (link) {
            if (picked.category && pathOf(link.href) === pathOf(picked.category.url)) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
        var chips = widget.querySelector('.nmj-pf-chips');
        var orderby = picked.orderby;
        var showSort = orderby === 'price' || orderby === 'price-desc';
        if (!picked.query && !showSort) {
            if (chips) {
                chips.remove();
            }
            return;
        }
        if (!chips) {
            chips = document.createElement('div');
            chips.className = 'nmj-pf-chips';
            var bar = widget.querySelector('.nmj-pf-bar');
            if (bar) {
                bar.insertAdjacentElement('afterend', chips);
            }
        }
        chips.textContent = '';
        if (picked.query) {
            chips.appendChild(chipLink(url, 'q', picked.query + ' ✕'));
        }
        if (showSort) {
            var select = widget.querySelector('.nmj-pf-sort select');
            var option = select ? select.querySelector('option[value="' + orderby + '"]') : null;
            chips.appendChild(chipLink(url, 'orderby', ((option && option.textContent) || orderby) + ' ✕'));
        }
    }

    function chipLink(url, key, text) {
        var next = new URL(url, window.location.href);
        next.searchParams.delete(key);
        var link = document.createElement('a');
        link.className = 'nmj-pf-chip';
        link.href = next.toString();
        link.textContent = text;
        return link;
    }

    function fillCounts(data) {
        var widget = document.querySelector('.nmj-pf');
        if (!widget) {
            return;
        }
        var lang = (window.nmjPf && window.nmjPf.lang) || '';
        widget.querySelectorAll('.nmj-pf-term-count').forEach(function (span) {
            var link = span.parentElement && span.parentElement.querySelector('.nmj-pf-link');
            if (!link) {
                return;
            }
            var category = (data.categories || []).filter(function (item) {
                return pathOf(item.url) === pathOf(link.href);
            })[0];
            if (!category) {
                return;
            }
            var total = data.products.filter(function (product) {
                if (!product.image) {
                    return false;
                }
                if (lang && product.lang && product.lang !== lang) {
                    return false;
                }
                return product.cats.indexOf(category.id) !== -1;
            }).length;
            span.textContent = String(total);
        });
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
        loadIndex().then(function (data) {
            if (data && paintFromIndex(widget, data, url)) {
                if (push) {
                    history.pushState({ nmjPf: true }, '', url);
                }
                if (window.matchMedia('(max-width: 767px)').matches) {
                    var button = widget.querySelector('.nmj-pf-toggle');
                    var panel = widget.querySelector('.nmj-pf-panel');
                    var overlay = widget.querySelector('.nmj-pf-overlay');
                    if (button && panel) {
                        setOpen(widget, button, panel, overlay, false);
                    }
                }
                return;
            }
            fetchArchive(widget, url, push);
        });
    }

    function fetchArchive(widget, url, push) {
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
        refreshPrices(grid);
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
        var observer = new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                var node = records[i].target;
                if (node && node.nodeType === 3) {
                    node = node.parentNode;
                }
                if (!node || !node.closest || !node.closest('.nmj-pf-progress')) {
                    updateProgress(widget, grid);
                    refreshPrices(grid);
                    return;
                }
            }
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
        if (line.hidden) {
            line.hidden = false;
        }
        var nextText = template.replace('%1$d', String(count)).replace('%2$d', String(total));
        if (line.textContent !== nextText) {
            line.textContent = nextText;
        }
    }
})();
