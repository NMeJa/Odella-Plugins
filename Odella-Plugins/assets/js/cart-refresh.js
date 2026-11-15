(function () {
    var object = window.moderncart_ajax_object;
    if (!object) {
        return;
    }

    var config = window.nmjCart;

    // Plus/minus stay instant even when the nonce-refresh config is missing.
    installQuantitySync();

    if (!config || !config.nonceUrl) {
        return;
    }

    var refreshing = false;

    function hasCartCookie() {
        return /(?:^|;\s*)woocommerce_items_in_cart=1(?:\s*;|$)/.test(document.cookie);
    }

    function badgeCount() {
        var el = document.querySelector('#moderncart-floating-cart .moderncart-floating-cart-count');
        if (!el) {
            return 0;
        }
        var count = parseInt(el.textContent, 10);
        return isNaN(count) ? 0 : count;
    }

    function drawerIsStale() {
        var cookie = hasCartCookie();
        var count = badgeCount();
        if (cookie) {
            return true;
        }
        return count > 0;
    }

    function fetchNonce() {
        return fetch(config.nonceUrl, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && typeof data.nonce === 'string' && data.nonce) {
                    object.ajax_nonce = data.nonce;
                }
            });
    }

    function post(action) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('moderncart_nonce', object.ajax_nonce);
        return fetch(object.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            if (!response.ok) {
                return null;
            }
            return response.json();
        }).catch(function () {
            return null;
        });
    }

    function apply(selector, html) {
        var node = document.querySelector(selector);
        if (node && typeof html === 'string') {
            node.innerHTML = html;
        }
    }

    function paint(slide, floating) {
        if (!slide || typeof slide.content !== 'string' || !floating || typeof floating.content !== 'string') {
            return false;
        }
        apply('#moderncart-slide-out-modal', slide.content);
        apply('#moderncart-floating-cart', floating.content);
        if (floating.hide_if_empty) {
            var launcher = document.getElementById('moderncart-floating-cart');
            if (launcher) {
                launcher.classList.toggle('moderncart-floating-cart-empty', !(floating.cart_count > 0));
            }
        }
        return true;
    }

    function requestBoth() {
        return Promise.all([
            post('moderncart_refresh_slide_out_cart'),
            post('moderncart_refresh_floating_cart')
        ]).then(function (pair) {
            return paint(pair[0], pair[1]);
        });
    }

    function refresh(useCachedNonce) {
        if (refreshing) {
            return Promise.resolve(false);
        }
        refreshing = true;
        var start = useCachedNonce ? Promise.resolve() : fetchNonce();
        return start.then(requestBoth).then(function (ok) {
            if (ok) {
                return true;
            }
            return fetchNonce().then(requestBoth);
        }).then(function (ok) {
            refreshing = false;
            return ok;
        }, function () {
            refreshing = false;
            return false;
        });
    }

    window.nmjRefreshCart = refresh;

    function onLoad() {
        if (drawerIsStale()) {
            refresh(false);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onLoad);
    } else {
        onLoad();
    }

    window.addEventListener('pageshow', function (event) {
        if (event.persisted && drawerIsStale()) {
            refresh(false);
        }
    });

    /**
     * Capture-phase plus/minus. Modern Cart listens on
     * `.moderncart-cart-item-quantity button` (data-action up/down, data-key)
     * and reads the quantity from `#q-{cart_key}`.
     */
    function installQuantitySync() {
        var syncState = {};

        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!target) {
                return;
            }
            if (target.nodeType !== 1) {
                target = target.parentElement;
            }
            if (!target || !target.closest) {
                return;
            }
            var button = target.closest('#moderncart-slide-out-modal .moderncart-cart-item-quantity button');
            if (!button) {
                return;
            }
            var direction = button.getAttribute('data-action');
            if (direction !== 'up' && direction !== 'down') {
                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            var key = button.getAttribute('data-key');
            if (!key) {
                return;
            }
            var input = document.getElementById('q-' + key);
            if (!input) {
                return;
            }
            var current = parseInt(input.value, 10);
            if (isNaN(current) || current < 0) {
                current = 0;
            }
            var next = direction === 'up' ? current + 1 : Math.max(0, current - 1);
            if (next === current) {
                return;
            }

            input.value = String(next);
            if (input.hasAttribute('aria-valuenow')) {
                input.setAttribute('aria-valuenow', String(next));
            }
            scalePrices(key, current, next);
            stepBadge(next - current);
            scheduleSync(key, next);
        }, true);

        function rowFor(key) {
            var modal = document.getElementById('moderncart-slide-out-modal');
            if (!modal) {
                return null;
            }
            var nodes = modal.querySelectorAll('[data-key]');
            for (var i = 0; i < nodes.length; i++) {
                var node = nodes[i];
                if (node.getAttribute('data-key') !== key) {
                    continue;
                }
                if (node.tagName === 'BUTTON' || node.tagName === 'INPUT') {
                    continue;
                }
                if (node.className && node.className.indexOf('moderncart-cart-item') !== -1) {
                    return node;
                }
            }
            return null;
        }

        function amountNode(root) {
            if (!root) {
                return null;
            }
            var ins = root.querySelector('ins .woocommerce-Price-amount');
            if (ins) {
                return ins;
            }
            var amounts = root.querySelectorAll('.woocommerce-Price-amount');
            for (var i = 0; i < amounts.length; i++) {
                if (!amounts[i].closest('del')) {
                    return amounts[i];
                }
            }
            return null;
        }

        function parseMoney(text) {
            var match = String(text || '').match(/(\d[\d\s.,]*\d|\d)/);
            if (!match) {
                return null;
            }
            var raw = match[1].replace(/\s/g, '');
            var comma = raw.lastIndexOf(',');
            var dot = raw.lastIndexOf('.');
            var sep = '';
            if (comma > dot && raw.length - comma - 1 > 0 && raw.length - comma - 1 <= 2) {
                sep = ',';
            } else if (dot > comma && raw.length - dot - 1 > 0 && raw.length - dot - 1 <= 2) {
                sep = '.';
            }
            var normalized = raw;
            if (sep === ',') {
                normalized = raw.replace(/\./g, '').replace(',', '.');
            } else if (sep === '.') {
                normalized = raw.replace(/,/g, '');
            } else {
                normalized = raw.replace(/[.,]/g, '');
            }
            var value = parseFloat(normalized);
            if (isNaN(value)) {
                return null;
            }
            return {
                value: value,
                raw: raw,
                sep: sep,
                decimals: sep ? raw.length - Math.max(raw.lastIndexOf(','), raw.lastIndexOf('.')) - 1 : 0
            };
        }

        function formatMoney(value, sample) {
            var sign = value < 0 ? '-' : '';
            var fixed = Math.abs(value).toFixed(sample.decimals);
            var bits = fixed.split('.');
            var whole = bits[0];
            if (sample.sep === ',') {
                whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                return sign + whole + (sample.decimals ? ',' + bits[1] : '');
            }
            if (sample.sep === '.') {
                whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                return sign + whole + '.' + bits[1];
            }
            return sign + whole;
        }

        function writeAmount(node, nextValue) {
            if (!node) {
                return null;
            }
            var sample = parseMoney(node.textContent);
            node.classList.add('odella-qty-syncing');
            if (!sample) {
                return null;
            }
            var formatted = formatMoney(nextValue, sample);
            var walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT);
            var textNode = walker.nextNode();
            while (textNode) {
                if (textNode.nodeValue && textNode.nodeValue.indexOf(sample.raw) !== -1) {
                    textNode.nodeValue = textNode.nodeValue.replace(sample.raw, formatted);
                    break;
                }
                textNode = walker.nextNode();
            }
            return sample.value;
        }

        function scalePrices(key, current, next) {
            if (!(current > 0)) {
                return;
            }
            var row = rowFor(key);
            var line = amountNode(row ? row.querySelector('.moderncart-price') : null);
            var lineSample = line ? parseMoney(line.textContent) : null;
            if (!line || !lineSample) {
                if (line) {
                    line.classList.add('odella-qty-syncing');
                }
                return;
            }
            var unit = lineSample.value / current;
            writeAmount(line, unit * next);
            var total = amountNode(document.querySelector('#moderncart-slide-out-modal .moderncart-cart-line-items__total-value'));
            var totalSample = total ? parseMoney(total.textContent) : null;
            if (total && totalSample) {
                writeAmount(total, totalSample.value + (next - current) * unit);
            } else if (total) {
                total.classList.add('odella-qty-syncing');
            }
        }

        function stepBadge(delta) {
            var badge = document.querySelector('#moderncart-floating-cart .moderncart-floating-cart-count');
            if (!badge) {
                return;
            }
            var count = parseInt(badge.textContent, 10);
            if (isNaN(count)) {
                count = 0;
            }
            badge.textContent = String(Math.max(0, count + delta));
        }

        function scheduleSync(key, quantity) {
            var state = syncState[key];
            if (!state) {
                state = { generation: 0, timer: 0, controller: null };
                syncState[key] = state;
            }
            state.generation += 1;
            var generation = state.generation;
            if (state.timer) {
                clearTimeout(state.timer);
            }
            if (state.controller) {
                state.controller.abort();
                state.controller = null;
            }
            state.timer = setTimeout(function () {
                state.timer = 0;
                sendUpdate(key, quantity, generation, false);
            }, 280);
        }

        function sendUpdate(key, quantity, generation, retried) {
            var state = syncState[key];
            if (!state || state.generation !== generation) {
                return;
            }
            var controller = new AbortController();
            state.controller = controller;
            var body = new URLSearchParams();
            body.set('action', 'moderncart_update_cart');
            body.set('quantity', String(quantity));
            body.set('cart_key', key);
            body.set('notice_action', 'true');
            body.set('moderncart_nonce', object.ajax_nonce);
            fetch(object.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                signal: controller.signal,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (response) {
                return response.text().then(function (text) {
                    return { status: response.status, text: text };
                });
            }).then(function (result) {
                if (!syncState[key] || syncState[key].generation !== generation) {
                    return;
                }
                var trimmed = (result.text || '').trim();
                if (trimmed === '' || trimmed === '0' || trimmed === '-1') {
                    if (!retried && config && config.nonceUrl) {
                        return fetchNonce().then(function () {
                            sendUpdate(key, quantity, generation, true);
                        });
                    }
                    return;
                }
                var data = null;
                try {
                    data = JSON.parse(result.text);
                } catch (error) {
                    data = null;
                }
                if (!data || typeof data.content !== 'string') {
                    return;
                }
                applyCart(data);
            }).catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
            });
        }

        function applyCart(data) {
            var modal = document.getElementById('moderncart-slide-out-modal');
            if (modal) {
                modal.innerHTML = data.content;
            }
            var count = null;
            if (typeof data.cart_count === 'number') {
                count = data.cart_count;
            } else if (modal) {
                count = 0;
                var inputs = modal.querySelectorAll('.moderncart-quantity__input');
                for (var i = 0; i < inputs.length; i++) {
                    var qty = parseInt(inputs[i].value, 10);
                    if (!isNaN(qty) && qty > 0) {
                        count += qty;
                    }
                }
            }
            var badge = document.querySelector('#moderncart-floating-cart .moderncart-floating-cart-count');
            if (badge && count !== null) {
                badge.textContent = String(count);
            }
            if (count === 0 && config && config.nonceUrl) {
                post('moderncart_refresh_floating_cart').then(function (floating) {
                    if (!floating || typeof floating.content !== 'string') {
                        return;
                    }
                    apply('#moderncart-floating-cart', floating.content);
                    if (floating.hide_if_empty) {
                        var launcher = document.getElementById('moderncart-floating-cart');
                        if (launcher) {
                            launcher.classList.toggle('moderncart-floating-cart-empty', !(floating.cart_count > 0));
                        }
                    }
                });
            }
        }
    }
}());
