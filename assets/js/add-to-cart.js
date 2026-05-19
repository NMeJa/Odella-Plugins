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
        var isQV = (state === 'awaiting' && $btn.data('variable-behavior') === 'quick_view');

        if (isInteractive) {
            $btn.addClass('add_to_cart_button button');
            if ($btn.data('is-variable') === 1 || $btn.data('is-variable') === '1') {
                // variable inline ready — needs ajax class
                $btn.addClass('ajax_add_to_cart');
            } else {
                $btn.addClass('ajax_add_to_cart');
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
            if (isInteractive && state === 'in-stock') {
                $qtyWrap.removeClass('nmj-atc-qty-wrap--hidden');
            } else {
                $qtyWrap.addClass('nmj-atc-qty-wrap--hidden');
            }
        }
    }

    // ── Quantity → data-quantity sync ──────────────────────────────────────────
    $(document).on('input', '.nmj-atc-qty', function () {
        var val = parseInt(this.value, 10) || 1;
        $(this).closest('.nmj-atc-wrap').find('.nmj-atc-btn').attr('data-quantity', val);
    });

    // ── WC AJAX Add to Cart Success ───────────────────────────────────────────
    $(document.body).on('added_to_cart', function (e, fragments, cart_hash, $btn) {
        if (!$btn || !$btn.hasClass('nmj-atc-btn')) return;

        var $wrap = $btn.closest('.nmj-atc-wrap');
        var lock = $btn.attr('data-lock-after-add') === '1';

        $btn.removeClass('loading');

        if (lock) {
            // Permanent lock → Added state
            setTimeout(function () {
                nmjSetState($wrap, 'added');
            }, 800);
        } else {
            // Temporary flash → return to in-stock
            nmjSetState($wrap, 'added');
            setTimeout(function () {
                nmjSetState($wrap, 'in-stock');
            }, 1500);
        }
    });

    // ── Variable Product: Custom AJAX Handler ─────────────────────────────────
    $(document).on('click', '.nmj-atc-btn.ajax_add_to_cart[data-is-variable="1"]', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var productId = $btn.data('product_id');
        var variationId = $btn.data('variation_id');
        var qty = $btn.attr('data-quantity') || 1;

        if (!variationId) return;

        var $form = $('.nmj-variations-form[data-product_id="' + productId + '"]');
        var formData = $form.length ? $form.serializeArray() : [];

        var data = {
            action: 'woocommerce_add_to_cart',
            product_id: productId,
            variation_id: variationId,
            quantity: qty
        };

        $.each(formData, function (i, field) {
            if (field.name.indexOf('attribute_') === 0) {
                data[field.name] = field.value;
            }
        });

        $btn.removeClass('added').addClass('loading');

        var ajaxUrl = (typeof wc_add_to_cart_params !== 'undefined')
            ? wc_add_to_cart_params.ajax_url
            : '/wp-admin/admin-ajax.php';

        $.post(ajaxUrl, data, function (response) {
            if (!response) return;
            if (response.error && response.product_url) {
                window.location = response.product_url;
                return;
            }
            $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $btn]);
        });
    });

    // ── Variation Selector Integration (inline behavior) ──────────────────────
    $(document.body).on('found_variation', function (e, variation) {
        var $form = $(e.target);
        var productId = $form.data('product_id');

        // Find all ATC widgets for this product with inline behavior
        $('.nmj-atc-btn[data-product_id="' + productId + '"].nmj--variable-inline, ' +
            '.nmj-atc-btn[data-product_id="' + productId + '"][data-variable-behavior="inline"]').each(function () {
                var $btn = $(this);
                var $wrap = $btn.closest('.nmj-atc-wrap');

                if (variation.is_in_stock) {
                    // Ready to add — switch to in-stock
                    $btn.attr('data-variation_id', variation.variation_id);
                    $btn.addClass('nmj--variable-inline');
                    nmjSetState($wrap, 'in-stock');
                } else {
                    // Variation exists but is out of stock
                    $btn.removeAttr('data-variation_id');
                    nmjSetState($wrap, 'oos');
                }
            });
    });

    $(document.body).on('reset_data', function (e) {
        var $form = $(e.target);
        var productId = $form.data('product_id');

        $('.nmj-atc-btn[data-product_id="' + productId + '"][data-variable-behavior="inline"]').each(function () {
            var $btn = $(this);
            var $wrap = $btn.closest('.nmj-atc-wrap');

            $btn.removeAttr('data-variation_id');
            $btn.addClass('nmj--variable-inline');
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

        var $btn      = $(this);
        var productId = parseInt($btn.attr('data-product_id'), 10) || 0;
        var popupId   = parseInt($btn.attr('data-popup-id'), 10) || 0;

        if (!popupId) {
            console.warn('[NMJ ATC] Quick View: No Popup ID set. Check the widget "Variable Product" settings.');
            return;
        }

        nmjOpenProductPopup(productId, popupId);
    });

    // ── Product Popup: AJAX render → Elementor dialog shell ──────────────────
    function nmjOpenProductPopup(productId, popupId) {
        var dialogId = 'elementor-popup-modal-' + popupId;
        var $dialog  = $('#' + dialogId);

        // Build the Elementor dialog shell once. Elementor's own popup CSS
        // (returned in the <style> block of the AJAX response) targets:
        //   #elementor-popup-modal-{id}          → backdrop + flex centering
        //   #elementor-popup-modal-{id} .dialog-widget-content → shadow, animation
        //   #elementor-popup-modal-{id} .dialog-message → dimensions
        if (!$dialog.length) {
            $dialog = $(
                '<div id="' + dialogId + '" class="dialog-widget elementor-popup-modal nmj-dynamic-popup"' +
                '     role="dialog" aria-modal="true"' +
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
            $('body').append($dialog);
        }

        // Show loading state
        $dialog.find('.nmj-qv-content').html('<p style="text-align:center;padding:60px 20px;">Loading...</p>');
        $dialog.css({ display: 'flex', opacity: 0 }).animate({ opacity: 1 }, 200);

        var ajaxUrl = (typeof wc_add_to_cart_params !== 'undefined')
            ? wc_add_to_cart_params.ajax_url
            : '/wp-admin/admin-ajax.php';

        $.ajax({
            url:  ajaxUrl,
            type: 'POST',
            data: { action: 'nmj_load_quick_view', product_id: productId, template_id: popupId },
            success: function (r) {
                if (r.success) {
                    // Inject full Elementor-rendered HTML (includes <style> block
                    // with popup CSS that targets #elementor-popup-modal-{id})
                    $dialog.find('.nmj-qv-content').html(r.data.html);

                    // Re-initialise Elementor widgets rendered inside the dialog
                    if (window.elementorFrontend && window.elementorFrontend.hooks) {
                        window.elementorFrontend.hooks.doAction('frontend/element_ready/global', $dialog);
                    }
                    // Re-initialise WooCommerce variation form if present
                    var $form = $dialog.find('.variations_form');
                    if ($form.length && $.fn.wc_variation_form) $form.wc_variation_form();
                } else {
                    $dialog.find('.nmj-qv-content').html('<p style="text-align:center;">Failed to load product.</p>');
                }
            },
            error: function (xhr, status, err) {
                console.error('[NMJ ATC] Quick view AJAX error:', status, err);
                $dialog.find('.nmj-qv-content').html('<p style="text-align:center;">Network error loading product.</p>');
            }
        });
    }

    // ── Close handlers ────────────────────────────────────────────────────────
    function nmjClosePopup() {
        $('.nmj-dynamic-popup').animate({ opacity: 0 }, 200, function () {
            $(this).css('display', 'none');
        });
    }
    $(document).on('click', '.nmj-qv-close', nmjClosePopup);
    $(document).on('click', '.nmj-dynamic-popup', function (e) {
        if ($(e.target).is('.nmj-dynamic-popup') || $(e.target).is('.dialog-message')) {
            nmjClosePopup();
        }
    });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') nmjClosePopup(); });

})(jQuery);