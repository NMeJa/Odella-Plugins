(function ($) {
    'use strict';

    // ── State Machine Helper ──────────────────────────────────────────────────
    function nmjSetState($wrap, state) {
        var $btn      = $wrap.find('.nmj-atc-btn');
        var $text     = $btn.find('.nmj-atc-text');
        var $icon     = $btn.find('.nmj-atc-icon');
        var stTexts   = {};

        try { stTexts = JSON.parse($btn.attr('data-state-texts') || '{}'); } catch(e) {}

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
            $btn.css({'pointer-events':'', 'cursor':''});
            $btn.removeAttr('aria-disabled');
        } else if (isQV) {
            $btn.addClass('nmj-qv-trigger');
            $btn.css({'pointer-events':'', 'cursor':'pointer'});
            $btn.removeAttr('aria-disabled');
        } else {
            $btn.css({'pointer-events':'none', 'cursor':'not-allowed'});
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
        var lock  = $btn.attr('data-lock-after-add') === '1';

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
        var productId   = $btn.data('product_id');
        var variationId = $btn.data('variation_id');
        var qty         = $btn.attr('data-quantity') || 1;

        if (!variationId) return;

        var $form    = $('.nmj-variations-form[data-product_id="' + productId + '"]');
        var formData = $form.length ? $form.serializeArray() : [];

        var data = {
            action:       'woocommerce_add_to_cart',
            product_id:   productId,
            variation_id: variationId,
            quantity:     qty
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
            var $btn  = $(this);
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
            var $btn  = $(this);
            var $wrap = $btn.closest('.nmj-atc-wrap');

            $btn.removeAttr('data-variation_id');
            $btn.addClass('nmj--variable-inline');
            nmjSetState($wrap, 'awaiting');
        });
    });

    // ── Quick View / Popup Handler ────────────────────────────────────────────
    $(document).on('click', '.nmj-qv-trigger', function (e) {
        e.preventDefault();
        var $btn       = $(this);
        var productId  = $btn.data('product_id');
        var popupId    = $btn.data('popup-id');

        if (!popupId) {
            console.warn('NMJ ATC: No popup ID configured for quick view.');
            return;
        }

        // Elementor Pro Popup method
        if (window.elementorProFrontend && window.elementorProFrontend.modules &&
            window.elementorProFrontend.modules.popup) {
            // Set product context for dynamic tags inside popup
            if (window.elementorFrontend && window.elementorFrontend.config) {
                window.elementorFrontend.config.post = {id: productId};
            }
            elementorProFrontend.modules.popup.showPopup({id: popupId});
            return;
        }

        // Fallback: custom AJAX modal (no Elementor Pro)
        nmjOpenFallbackModal(productId, popupId);
    });

    // ── Fallback Modal (when Elementor Pro is not available) ───────────────────
    function nmjOpenFallbackModal(productId, templateId) {
        var $modal = $('#nmj-qv-modal');

        if (!$modal.length) {
            $('body').append(
                '<div id="nmj-qv-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:99999;align-items:center;justify-content:center;">' +
                '<div style="background:#fff;width:90%;max-width:900px;max-height:90vh;overflow-y:auto;position:relative;border-radius:8px;padding:20px;">' +
                '<button class="nmj-modal-close" style="position:absolute;top:10px;right:15px;border:none;background:none;font-size:24px;cursor:pointer;">×</button>' +
                '<div class="nmj-modal-body"></div></div></div>'
            );
            $modal = $('#nmj-qv-modal');
        }

        var $body = $modal.find('.nmj-modal-body');
        $body.html('<p style="text-align:center;padding:40px;">Loading...</p>');
        $modal.css('display','flex').hide().fadeIn();

        var ajaxUrl = (typeof wc_add_to_cart_params !== 'undefined')
            ? wc_add_to_cart_params.ajax_url
            : '/wp-admin/admin-ajax.php';

        $.ajax({
            url:  ajaxUrl,
            type: 'POST',
            data: { action:'nmj_load_quick_view', product_id:productId, template_id:templateId },
            success: function (r) {
                if (r.success) {
                    $body.html(r.data.html);
                    if (window.elementorFrontend && window.elementorFrontend.hooks) {
                        window.elementorFrontend.hooks.doAction('frontend/element_ready/global', $body);
                    }
                    var $form = $body.find('.variations_form');
                    if ($form.length && $.fn.wc_variation_form) $form.wc_variation_form();
                } else {
                    $body.html('<p style="text-align:center;">Failed to load product.</p>');
                }
            }
        });
    }

    // Fallback modal close handlers
    $(document).on('click', '.nmj-modal-close', function () { $('#nmj-qv-modal').fadeOut(); });
    $(document).on('click', '#nmj-qv-modal', function (e) { if (e.target === this) $(this).fadeOut(); });

})(jQuery);