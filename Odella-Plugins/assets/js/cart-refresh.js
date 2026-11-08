(function () {
    var object = window.moderncart_ajax_object;
    var config = window.nmjCart;
    if (!object || !config || !config.nonceUrl) {
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
}());
