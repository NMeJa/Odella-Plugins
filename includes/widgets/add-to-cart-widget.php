<?php

/**
 * Widget: WooCommerce Add to Cart (AJAX)
 */

namespace NMJ\Widgets;

defined('ABSPATH') || exit;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Icons_Manager;

// Load traits
require_once __DIR__ . '/traits/atc-content-controls.php';
require_once __DIR__ . '/traits/atc-style-controls.php';

class Add_To_Cart_Widget extends Widget_Base
{
    use Traits\ATC_Content_Controls;
    use Traits\ATC_Style_Controls;

    public function get_name(): string
    {
        return 'nmj_add_to_cart';
    }
    public function get_title(): string
    {
        return esc_html__('Add to Cart (AJAX)', 'nmj');
    }
    public function get_icon(): string
    {
        return 'eicon-cart-solid';
    }
    public function get_categories(): array
    {
        return ['nmj-widgets'];
    }
    public function get_keywords(): array
    {
        return ['woocommerce', 'cart', 'add', 'buy', 'ajax', 'variation', 'quick view'];
    }
    public function get_script_depends(): array
    {
        return ['wc-add-to-cart', 'wc-add-to-cart-variation', 'nmj-add-to-cart'];
    }
    public function get_style_depends(): array
    {
        return ['nmj-add-to-cart'];
    }

    /**
     * Never cache this widget's HTML. The rendered state depends on live stock
     * data and on the current visitor's cart, so a cached copy would show one
     * visitor's "Added" state to everyone else.
     */
    protected function is_dynamic_content(): bool
    {
        return true;
    }

    protected function register_controls(): void
    {
        $this->register_content_controls();
        $this->register_style_controls();
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function is_product_in_cart(int $pid, int $variation_id = 0): bool
    {
        if (!function_exists('WC') || is_null(WC()->cart) || !did_action('woocommerce_cart_loaded_from_session'))
            return false;
        foreach (WC()->cart->get_cart() as $item) {
            if ((int) $item['product_id'] !== $pid)
                continue;
            // For variable products, also verify the variation matches
            if ($variation_id > 0 && (int) $item['variation_id'] !== $variation_id)
                continue;
            return true;
        }
        return false;
    }

    /**
     * Variation IDs of this product currently in the cart.
     *
     * At page-load time a variable product has no variation selected, so the
     * lock state can only be resolved once the visitor picks one. Shipping the
     * list lets the found_variation handler decide per variation instead of
     * locking every variation because one of them is in the cart.
     *
     * @return int[]
     */
    private function get_cart_variation_ids(int $pid): array
    {
        if (!function_exists('WC') || is_null(WC()->cart) || !did_action('woocommerce_cart_loaded_from_session'))
            return [];

        $ids = [];
        foreach (WC()->cart->get_cart() as $item) {
            if ((int) $item['product_id'] !== $pid)
                continue;
            $variation_id = (int) ($item['variation_id'] ?? 0);
            if ($variation_id > 0)
                $ids[] = $variation_id;
        }
        return array_values(array_unique($ids));
    }

    private function capture_icon(array $settings, string $key): string
    {
        if (empty($settings[$key]['value']))
            return '';
        ob_start();
        Icons_Manager::render_icon($settings[$key], ['aria-hidden' => 'true']);
        return ob_get_clean();
    }

    private function editor_notice(string $msg): void
    {
        if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
            printf(
                '<p style="background:#fff3cd;color:#856404;padding:10px 14px;font-size:13px;border-radius:4px;margin:0;">%s</p>',
                esc_html($msg)
            );
        }
    }

    // ── Render ─────────────────────────────────────────────────────────────────

    protected function render(): void
    {
        $s = $this->get_settings_for_display();

        // Resolve product
        $pid = !empty($s['product_id']) ? absint($s['product_id']) : get_the_ID();
        if (!$pid) {
            $this->editor_notice('⚠ Set a Product ID or use on a product page.');
            return;
        }

        $product = wc_get_product($pid);
        if (!$product) {
            $this->editor_notice('⚠ Product not found (ID: ' . $pid . ').');
            return;
        }

        // Settings
        $in_stock = $product->is_in_stock();
        $is_var = $product->is_type('variable');
        $lock = ($s['lock_after_add'] ?? 'yes') === 'yes';
        $show_qty = ($s['show_quantity_input'] ?? 'no') === 'yes';
        $qty_pos = $s['qty_position'] ?? 'left';
        $qty_val = max(1, absint($s['qty_default'] ?? 1));
        $var_beh = $s['variable_behavior'] ?? 'inline';

        // ── State machine ─────────────────────────────────────────────────
        $state = 'in-stock';
        $interact = true;
        $hide_qty = false;

        if (!$in_stock) {
            // Out of stock — highest priority, nothing else matters
            $state = 'oos';
            $interact = false;
            $hide_qty = true;
        } elseif ($lock && !$is_var && $this->is_product_in_cart($pid)) {
            // Already in cart + lock enabled. Simple products only: a variable
            // product has no variation selected at page-load, so locking on the
            // parent ID alone would lock every variation because one of them is
            // in the cart. Variable products resolve this client-side instead,
            // from data-cart-variations on found_variation.
            $state = 'added';
            $interact = false;
            $hide_qty = true;
        } elseif ($is_var && $var_beh === 'inline') {
            $state = 'awaiting';
            $interact = false;
            $hide_qty = true;
        } elseif ($is_var && $var_beh === 'quick_view') {
            $state = 'awaiting';
            $interact = true;
            $hide_qty = true;
        } elseif ($is_var && $var_beh === 'redirect') {
            $state = 'in-stock';
            $interact = true;
        }

        // ── Resolve current text ──────────────────────────────────────────
        $text_map = [
            'in-stock' => $s['in_stock_text'] ?? '',
            'added' => $s['added_text'] ?? '',
            'oos' => $s['out_of_stock_text'] ?? '',
            'awaiting' => ($is_var && $var_beh === 'quick_view')
                ? ($s['quick_view_text'] ?? '')
                : ($s['awaiting_text'] ?? ''),
        ];
        $cur_text = $text_map[$state];

        // ── Resolve current icon key ──────────────────────────────────────
        $icon_keys = [
            'in-stock' => 'in_stock_icon',
            'added' => 'added_icon',
            'oos' => 'out_of_stock_icon',
            'awaiting' => 'awaiting_icon',
        ];
        // $cur_icon_key omitted — icons[$state] is used directly below.

        // ── Capture all icon HTML for JS templates ────────────────────────
        $icons = [];
        foreach ($icon_keys as $st => $ik) {
            $icons[$st] = $this->capture_icon($s, $ik);
        }

        // ── Button classes ────────────────────────────────────────────────
        $cls = ['nmj-atc-btn', 'nmj--' . $state];

        if ($interact && $state === 'in-stock') {
            $cls[] = 'add_to_cart_button';
            $cls[] = 'button';
            if (!$is_var || $var_beh !== 'redirect') {
                if (!$is_var) {
                    $cls[] = 'ajax_add_to_cart';
                } else {
                    $cls[] = 'nmj_custom_ajax_add_to_cart';
                }
            }
        }
        if ($is_var && $var_beh === 'quick_view' && $state === 'awaiting') {
            $cls[] = 'nmj-qv-trigger';
        }
        if ($is_var && $var_beh === 'inline') {
            $cls[] = 'nmj--variable-inline';
        }

        // ── Data attributes ───────────────────────────────────────────────
        $data = [
            'data-product_id' => $pid,
            'data-product_sku' => $product->get_sku(),
            'data-quantity' => $qty_val,
            'data-lock-after-add' => $lock ? '1' : '0',
            'data-state' => $state,
            'data-state-texts' => wp_json_encode($text_map),
        ];

        if ($is_var) {
            $data['data-is-variable'] = '1';
            $data['data-variable-behavior'] = $var_beh;
            // Which variations of this product are already in the cart, so the
            // found_variation handler can lock per variation rather than for
            // the whole product.
            if ($lock) {
                $data['data-cart-variations'] = wp_json_encode($this->get_cart_variation_ids($pid));
            }
        }
        if ($is_var && $var_beh === 'quick_view') {
            $data['data-popup-id'] = absint($s['quick_view_popup_id'] ?? 0);
        }
        if ($is_var && $var_beh === 'inline') {
            $data['data-in-stock-text'] = $s['in_stock_text'] ?? '';
        }

        $data_str = '';
        foreach ($data as $k => $v) {
            $data_str .= ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
        }

        $cls_str = implode(' ', $cls);
        $qty_hidden = ($hide_qty || !$show_qty) ? ' nmj-atc-qty-wrap--hidden' : '';
        $tag_open = '';
        $tag_close = '';

        if ($interact) {
            if ($is_var && $var_beh === 'redirect') {
                $href = get_permalink($pid);
            } elseif ($is_var && $var_beh === 'quick_view') {
                $href = '#'; // JS intercepts click; href must not navigate
            } else {
                $href = $product->add_to_cart_url();
            }
            $tag_open = '<a href="' . esc_url($href) . '" class="' . esc_attr($cls_str) . '"' . $data_str
                . ' aria-label="' . esc_attr(sprintf(__('Add "%s" to your cart', 'nmj'), $product->get_name())) . '" rel="nofollow">';
            $tag_close = '</a>';
        } else {
            $tag_open = '<span class="' . esc_attr($cls_str) . '"' . $data_str . ' aria-disabled="true">';
            $tag_close = '</span>';
        }

        // ── OUTPUT ────────────────────────────────────────────────────────
        ?>
        <div class="nmj-atc-wrap" data-qty-position="<?php echo esc_attr($qty_pos); ?>">

            <?php if ($show_qty): ?>
                <div class="nmj-atc-qty-wrap<?php echo esc_attr($qty_hidden); ?>">
                    <input type="number" class="nmj-atc-qty" value="<?php echo esc_attr($qty_val); ?>" min="1"
                        aria-label="<?php esc_attr_e('Quantity', 'nmj'); ?>" />
                </div>
            <?php endif; ?>

            <?php echo $tag_open; ?>

            <?php
            // Both spans are always emitted, even when empty for the current
            // state. Rendering them conditionally meant a button that starts
            // with (say) no "Select options" label had no .nmj-atc-text node,
            // so nmjSetState() had nothing to write into and the button stayed
            // blank forever once a variation was picked. Empty inline-flex
            // children collapse to zero width, so this costs no layout.
            ?>
            <span class="nmj-atc-icon"><?php echo $icons[$state]; ?></span>
            <span class="nmj-atc-text"><?php echo esc_html($cur_text); ?></span>

            <?php echo $tag_close; ?>

            <?php // Hidden icon templates for JS state swaps ?>
            <?php foreach ($icons as $st => $html): ?>
                <span class="nmj-atc-icon-tpl" data-state="<?php echo esc_attr($st); ?>"><?php echo $html; ?></span>
            <?php endforeach; ?>

        </div>
        <?php
    }

    // ── Editor preview ─────────────────────────────────────────────────────────

    protected function content_template(): void
    {
        ?>
        <# var state=settings.editor_preview_state || 'in-stock' ; var textMap={ 'in-stock' : settings.in_stock_text
            !==undefined ? settings.in_stock_text : 'Add to Cart' , 'added' : settings.added_text !==undefined ?
            settings.added_text : 'Added' , 'oos' : settings.out_of_stock_text !==undefined ? settings.out_of_stock_text
            : 'Out of Stock' , 'awaiting' : (settings.variable_behavior==='quick_view' ) ? (settings.quick_view_text
            !==undefined ? settings.quick_view_text : 'Quick View' ) : (settings.awaiting_text !==undefined ?
            settings.awaiting_text : 'Select options' ) }; var btnText=textMap[state]; var iconKeyMap={ 'in-stock'
            : 'in_stock_icon' , 'added' : 'added_icon' , 'oos' : 'out_of_stock_icon' , 'awaiting' : 'awaiting_icon' }; var
            iconKey=iconKeyMap[state]; var iconHTML=elementor.helpers.renderIcon(view, settings[iconKey], {'aria-hidden': 'true'
            }, 'i' , 'object' ); var hasIcon=iconHTML && iconHTML.value; var hasText=btnText !=='' ; var stateClass='nmj--' +
            state; var extraClass='' ; if (state==='in-stock' ) {
            extraClass='button add_to_cart_button nmj_custom_ajax_add_to_cart' ; } else if (state==='awaiting' &&
            settings.variable_behavior==='quick_view' ) { extraClass='nmj-qv-trigger' ; } var
            showQty=settings.show_quantity_input==='yes' ; var qtyPos=settings.qty_position || 'left' ; var
            qty=settings.qty_default || 1; var qtyHidden=(state !=='in-stock' ) ? ' nmj-atc-qty-wrap--hidden' : '' ; #>
            <div class="nmj-atc-wrap" data-qty-position="{{ qtyPos }}">
                <# if (showQty) { #>
                    <div class="nmj-atc-qty-wrap{{ qtyHidden }}">
                        <input type="number" class="nmj-atc-qty" value="{{ qty }}" min="1" disabled />
                    </div>
                    <# } #>
                        <a href="#" class="nmj-atc-btn {{ stateClass }} {{ extraClass }}">
                            <# if (hasIcon) { #>
                                <span class="nmj-atc-icon">{{{ iconHTML.value }}}</span>
                                <# } #>
                                    <# if (hasText) { #>
                                        <span class="nmj-atc-text">{{ btnText }}</span>
                                        <# } #>
                        </a>
            </div>
            <?php
    }
}