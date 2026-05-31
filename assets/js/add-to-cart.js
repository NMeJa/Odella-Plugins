(function ($) {
    'use strict';

    // ── State Machine Helper ──────────────────────────────────────────────────
    function nmjSetState($wrap, state) {
        var $btn = $wrap.find('.nmj-atc-btn');
        var $text = $btn.find('.nmj-atc-text');
        var $icon = $btn.find('.nmj-atc-icon');
        var stTexts = {};

        try { stTexts = JSON.parse($btn.attr('data-state-texts') || '{}'); } catch (e) { }

        // Get icon template for this state
        var $tpl = $wrap.find('.nmj-atc-icon-tpl[data-state="' + state + '"]');
        var tplHTML = $tpl.length ? $tpl.html() : '';
        var newText = stTexts[state] || '';

        // Remove all state classes
        $btn.removeClass('nmj--in-stock nmj--added nmj--oos nmj--awaiting nmj-qv-trigger nmj--variable-inline');
        $btn.removeClass('add_to_cart_button ajax_add_to_cart button added loading');

        // Add new state class
        $btn.addClass('nmj--' + state);
        $btn.attr('data-state', state);

        // Swap text
        if (newText && $text.length) {
            $text.text(newText);
        } else if (!newText && $text.length) {
            $text.text('');
        }

        // Swap icon
        if (tplHTML && $icon.length) {
            $icon.html(tplHTML);
        } else if ($icon.length && !tplHTML) {
            $icon.html('');
        }

        // Interactivity
        var isInteractive = (state === 'in-stock');
        var isQV = (state === 'awaiting' && $btn.attr('data-variable-behavior') === 'quick_view');

        if (isInteractive) {
            $btn.addClass('add_to_cart_button button');
            if ($btn.attr('data-is-variable') !== '1') {
                $btn.addClass('ajax_add_to_cart');
            } else {
                $btn.addClass('nmj_custom_ajax_add_to_cart');
            }
            $btn.css({ 'pointer-events': '', 'cursor': '' });
            $btn.removeAttr('aria-disabled');
        } else if (isQV) {
            $btn.addClass('nmj-qv-trigger');
            $btn.css({ 'pointer-events': '', 'cursor': 'pointer' });
            $btn.removeAttr('aria-disabled');
        } else {
            $btn.css({ 'pointer-events': 'none', 'cursor': 'not-allowed' });
            $btn.attr('aria-disabled', 'true');
        }

        // Quantity field visibility
        var $qtyWrap = $wrap.find('.nmj-atc-qty-wrap');
        if ($qtyWrap.length) {
            if (isInteractive) {
                $qtyWrap.removeClass('nmj-atc-qty-wrap--hidden');
            } else {
                $qtyWrap.addClass('nmj-atc-qty-wrap--hidden');
            }
        }

        // Preserve variable-inline marker through state swaps
        if ($btn.attr('data-variable-behavior') === 'inline') {
            $btn.addClass('nmj--variable-inline');
        }
    }

    // ── Quantity → data-quantity sync ──────────────────────────────────────────
    $(document).on('input', '.nmj-atc-qty', function () {
        var val = parseInt(this.value, 10) || 1;
        $(this).closest('.nmj-atc-wrap').find('.nmj-atc-btn').attr('data-quantity', val);
    });

    // ── WC AJAX Add to Cart Success ───────────────────────────────────────────
    // Store timer handles per wrap element to cancel on rapid re-clicks.
    var nmjTimers = new WeakMap();

    $(document.body).on('added_to_cart', function (e, fragments, cart_hash, $btn) {
        if (!$btn || !$btn.hasClass('nmj-atc-btn')) return;

        var $wrap = $btn.closest('.nmj-atc-wrap');
        var wrap = $wrap[0];
        var lock = $btn.attr('data-lock-after-add') === '1';

        // Cancel any pending transition from a previous click
        clearTimeout(nmjTimers.get(wrap));
        $btn.removeClass('loading');

        if (lock) {
            nmjTimers.set(wrap, setTimeout(function () { nmjSetState($wrap, 'added'); }, 800));
        } else {
            nmjSetState($wrap, 'added');
            nmjTimers.set(wrap, setTimeout(function () { nmjSetState($wrap, 'in-stock'); }, 1500));
        }
    });

    // ── Variable Product: Custom AJAX Handler ─────────────────────────────────
    $(document).on('click', '.nmj-atc-btn.nmj_custom_ajax_add_to_cart[data-is-variable="1"]', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $wrap = $btn.closest('.nmj-atc-wrap');
        var productId = $btn.data('product_id');
        var variationId = $btn.attr('data-variation_id');
        var qty = parseInt($btn.attr('data-quantity'), 10) || 1;

        if (!variationId) return;

        // Cancel any pending state-transition timer from a previous click
        clearTimeout(nmjTimers.get($wrap[0]));

        var $form = $('.nmj-variations-form[data-product_id="' + productId + '"]');
        var formData = $form.length ? $form.serializeArray() : [];

        var data = {
            // TRICK: Native WC add-to-cart endpoint handles variables perfectly
            // IF you pass the variation ID as the main product_id.
            product_id: variationId,
            quantity: qty
        };

        // WC validation requires attributes at the root of $_POST
        $.each(formData, function (i, field) {
            if (field.name.indexOf('attribute_') === 0) data[field.name] = field.value;
        });

        $btn.removeClass('added').addClass('loading');

        // Use the WC AJAX endpoint (?wc-ajax=add_to_cart)
        var wcAjaxUrl = (typeof wc_add_to_cart_params !== 'undefined')
            ? wc_add_to_cart_params.wc_ajax_url.replace('%%endpoint%%', 'add_to_cart')
            : '/?wc-ajax=add_to_cart';

        $.post(wcAjaxUrl, data, function (response) {
            if (!response) return;
            if (response.error && response.product_url) {
                window.location = response.product_url;
                return;
            }
            $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $btn]);
        });
    });

    // ── Price Update Helper ───────────────────────────────────────────────────
    // After swapping price HTML, the new .price DOM nodes lack whatever
    // "loaded/ready" state the theme's CSS expects (many themes fade-in prices
    // via class toggle or transition). Force visibility so the price never
    // gets stuck in a loading/skeleton state.
    function nmjUpdatePrice($el, html) {
        $el.html(html);
        $el.find('.price').css({ opacity: 1, visibility: 'visible', animation: 'none' });
    }

    // ── Variation Selector Integration (inline behavior) ──────────────────────
    $(document.body).on('found_variation', function (e, variation) {
        var $form = $(e.target);
        var productId = $form.data('product_id');

        // --- Product Price Widget Integration ---
        // Defer with setTimeout(0) so this runs after any theme/WC handlers
        // that also listen to found_variation and might override prices.
        var $priceWrap = $('.nmj-product-price-wrapper[data-product_id="' + productId + '"] .nmj-price-in-stock');
        if ($priceWrap.length) {
            setTimeout(function () {
                if (variation.price_html) {
                    nmjUpdatePrice($priceWrap, variation.price_html);
                } else if ($priceWrap.attr('data-original-price')) {
                    nmjUpdatePrice($priceWrap, $priceWrap.attr('data-original-price'));
                }
            }, 0);
        }

        // Find all ATC widgets for this product with inline behavior
        $('.nmj-atc-btn[data-product_id="' + productId + '"].nmj--variable-inline, ' +
            '.nmj-atc-btn[data-product_id="' + productId + '"][data-variable-behavior="inline"]').each(function () {
                var $btn = $(this);
                var $wrap = $btn.closest('.nmj-atc-wrap');

                if (variation.is_in_stock) {
                    $btn.attr('data-variation_id', variation.variation_id);
                    nmjSetState($wrap, 'in-stock');
                } else {
                    $btn.removeAttr('data-variation_id');
                    nmjSetState($wrap, 'oos');
                }
            });
    });

    $(document.body).on('reset_data', function (e) {
        var $form = $(e.target);
        var productId = $form.data('product_id');

        // --- Product Price Widget Integration ---
        var $priceWrap = $('.nmj-product-price-wrapper[data-product_id="' + productId + '"] .nmj-price-in-stock');
        if ($priceWrap.length && $priceWrap.attr('data-original-price')) {
            setTimeout(function () {
                nmjUpdatePrice($priceWrap, $priceWrap.attr('data-original-price'));
            }, 0);
        }

        $('.nmj-atc-btn[data-product_id="' + productId + '"][data-variable-behavior="inline"]').each(function () {
            var $btn = $(this);
            var $wrap = $btn.closest('.nmj-atc-wrap');

            $btn.removeAttr('data-variation_id');
            nmjSetState($wrap, 'awaiting');
        });
    });

    // ── Quick View / Popup Handler ────────────────────────────────────────────
    // Strategy: AJAX-render the Elementor popup template server-side with the
    // correct product $post context, then inject the HTML into an Elementor-
    // compatible dialog structure so the popup's own CSS (width, shadow,
    // backdrop, animation) applies exactly as designed in the popup builder.
    // No "Display Conditions" needed — popup is rendered on-demand per product.
    $(document).on('click', '.nmj-qv-trigger', function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        var productId = parseInt($btn.attr('data-product_id'), 10) || 0;
        var popupId = parseInt($btn.attr('data-popup-id'), 10) || 0;

        if (!popupId) {
            console.warn('[NMJ ATC] Quick View: No Popup ID set. Check the widget "Variable Product" settings.');
            return;
        }

        // Add loading state to the trigger button so the user gets immediate
        // feedback while the AJAX request is in flight.
        $btn.addClass('nmj-qv-loading');
        nmjLastTrigger = this; // store for focus-return on close
        nmjOpenProductPopup(productId, popupId, $btn);
    });

    // ── Product Popup: AJAX render → Elementor dialog shell ──────────────────
    // Single reusable shell — we swap the ID attribute on each open so the
    // popup-specific CSS (#elementor-popup-modal-{id}) always targets it.
    // This prevents DOM accumulation when many different popup IDs are used.
    var $nmjPopupShell = null;
    var nmjLastTrigger = null; // track trigger for focus-return on close

    function nmjEnsureShell() {
        if ($nmjPopupShell && $nmjPopupShell.length) return;

        if (!$('#nmj-qv-base-css').length) {
            $('head').append(
                '<style id="nmj-qv-base-css">' +
                '.nmj-dynamic-popup .dialog-widget-content { background-color: #fff; position: relative; max-width: 100%; max-height: 100vh; overflow-y: auto; }' +
                '.nmj-dynamic-popup .dialog-message { display: flex; flex-direction: column; width: 100%; }' +
                '.nmj-dynamic-popup .nmj-qv-content { width: 100%; }' +
                '.nmj-dynamic-popup .nmj-qv-close { position: absolute; right: 15px; top: 15px; z-index: 9999; cursor: pointer; font-size: 20px; font-weight: bold; line-height: 1; }' +
                '.nmj-dynamic-popup .nmj-qv-content .elementor { display: block !important; }' +
                '.nmj-dynamic-popup .nmj-qv-content .e-con { display: var(--display, flex) !important; }' +
                '.nmj-dynamic-popup .nmj-qv-content .elementor-section { display: block !important; }' +
                '</style>'
            );
        }

        $nmjPopupShell = $(
            '<div class="dialog-widget elementor-popup-modal nmj-dynamic-popup"' +
            '     role="dialog" aria-modal="true" aria-label="Quick view"' +
            '     style="display:none;position:fixed;inset:0;z-index:9999;">' +
            '  <div class="dialog-widget-content elementor-popup-modal-content">' +
            '    <div class="dialog-lightbox-close-button dialog-close-button nmj-qv-close"' +
            '         role="button" tabindex="0" aria-label="Close">&#x2715;</div>' +
            '    <div class="dialog-message dialog-type-widget">' +
            '      <div class="dialog-widget-message nmj-qv-content"></div>' +
            '    </div>' +
            '  </div>' +
            '</div>'
        );
        $('body').append($nmjPopupShell);

        // ── Focus trap: keep Tab/Shift+Tab cycling inside dialog ──────────
        $nmjPopupShell.on('keydown', function (e) {
            if (e.key !== 'Tab') return;
            var focusable = $nmjPopupShell.find(
                'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),' +
                'textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
            ).filter(':visible').toArray();
            if (!focusable.length) { e.preventDefault(); return; }
            var first = focusable[0], last = focusable[focusable.length - 1];
            if (e.shiftKey) {
                if (document.activeElement === first) { e.preventDefault(); last.focus(); }
            } else {
                if (document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        });
    }

    // Track in-flight XHR so concurrent quick-view opens can abort the previous
    // request before firing a new one, preventing race conditions on the content area.
    var nmjQvXHR = null;

    function nmjOpenProductPopup(productId, popupId, $triggerBtn) {
        nmjEnsureShell();

        // Abort any in-flight request before starting a new one
        if (nmjQvXHR) { nmjQvXHR.abort(); nmjQvXHR = null; }

        // Swap the ID so popup-specific CSS (#elementor-popup-modal-{id}) applies
        $nmjPopupShell.attr('id', 'elementor-popup-modal-' + popupId);

        $nmjPopupShell.find('.nmj-qv-content').html('<p style="text-align:center;padding:60px 20px;">Loading...</p>');
        $nmjPopupShell.css({ display: 'flex', opacity: 0 }).animate({ opacity: 1 }, 200);

        // Move focus into dialog for accessibility
        $nmjPopupShell.attr('tabindex', '-1').focus();

        nmjQvXHR = $.ajax({
            url: (typeof nmj_params !== 'undefined') ? nmj_params.ajax_url : '/wp-admin/admin-ajax.php',
            type: 'POST',
            data: {
                action: 'nmj_load_quick_view',
                product_id: productId,
                template_id: popupId,
                nonce: (typeof nmj_params !== 'undefined') ? nmj_params.nonce : ''
            },
            success: function (r) {
                nmjQvXHR = null;
                if ($triggerBtn) $triggerBtn.removeClass('nmj-qv-loading');

                if (r.success) {
                    var $content = $nmjPopupShell.find('.nmj-qv-content');

                    // Inject rendered HTML. Elementor's inline <style> blocks
                    // (column widths, spacing, custom CSS) are part of the response
                    // body and must be kept — do NOT strip them.
                    $content.html(r.data.html);

                    // Properly re-initialise each Elementor widget individually
                    if (window.elementorFrontend && window.elementorFrontend.hooks) {
                        $content.find('.elementor-widget').each(function () {
                            var widgetType = $(this).data('widget_type');
                            window.elementorFrontend.hooks.doAction(
                                'frontend/element_ready/' + widgetType, $(this), $
                            );
                        });
                        window.elementorFrontend.hooks.doAction('frontend/element_ready/global', $content);
                    }

                    var $form = $content.find('.variations_form');
                    if ($form.length && $.fn.wc_variation_form) $form.wc_variation_form();

                    // Initialize WooCommerce Product Gallery (fixes opacity: 0 issue)
                    var $gallery = $content.find('.woocommerce-product-gallery');
                    if ($gallery.length) {
                        if (typeof $.fn.wc_product_gallery !== 'undefined') {
                            $gallery.each(function () { $(this).wc_product_gallery(); });
                        } else {
                            $gallery.css({ opacity: 1, transition: 'none' });
                        }
                    }
                } else {
                    $nmjPopupShell.find('.nmj-qv-content').html('<p style="text-align:center;">Failed to load product.</p>');
                }
            },
            error: function (xhr, status) {
                nmjQvXHR = null;
                if ($triggerBtn) $triggerBtn.removeClass('nmj-qv-loading');
                if (status === 'abort') return; // intentional abort — no error to show
                console.error('[NMJ ATC] Quick view AJAX error:', status);
                $nmjPopupShell.find('.nmj-qv-content').html('<p style="text-align:center;">Network error loading product.</p>');
            }
        });
    }

    // ── Close handlers ────────────────────────────────────────────────────────
    function nmjClosePopup() {
        if (!$nmjPopupShell) return;
        $nmjPopupShell.animate({ opacity: 0 }, 200, function () {
            $(this).css('display', 'none');
            // Return focus to the element that opened the popup
            if (nmjLastTrigger) { nmjLastTrigger.focus(); nmjLastTrigger = null; }
        });
    }
    $(document).on('click', '.nmj-qv-close', nmjClosePopup);
    $(document).on('click', '.nmj-dynamic-popup', function (e) {
        if ($(e.target).is('.nmj-dynamic-popup') || $(e.target).is('.dialog-message')) nmjClosePopup();
    });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') nmjClosePopup(); });

})(jQuery);