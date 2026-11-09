<?php
/**
 * My Account menu.
 *
 * Downloads and payment methods are not used: there are no digital products,
 * and payment gateways stay off.
 */

defined('ABSPATH') || exit;

add_filter('woocommerce_account_menu_items', 'nmj_account_menu_items');
add_filter('gettext', 'nmj_translate_account', 10, 3);
add_filter('woocommerce_get_privacy_policy_text', 'nmj_account_privacy_text', 10, 2);

/**
 * @param array<string, string> $items
 * @return array<string, string>
 */
function nmj_account_menu_items(array $items): array
{
    unset($items['downloads'], $items['payment-methods']);
    return $items;
}

/**
 * WooCommerce's KA file leaves a few account-form strings in English.
 */
function nmj_translate_account(string $translation, string $text, string $domain): string
{
    if (nmj_current_language() !== 'ka' || ($domain !== 'woocommerce' && $domain !== 'default')) {
        return $translation;
    }
    $keys = [
        'A link to set a new password will be sent to your email address.' => 'account_set_password',
        'Show password' => 'account_show_password',
        'Hide password' => 'account_hide_password',
    ];
    if (!isset($keys[$text])) {
        return $translation;
    }
    return nmj_t($keys[$text]);
}

/**
 * The saved registration privacy sentence is English. Keep the option as it
 * is and swap it on the Georgian site, leaving the [privacy_policy] placeholder
 * for WooCommerce to turn into the policy link.
 *
 * @param mixed $text
 * @param mixed $type
 */
function nmj_account_privacy_text($text, $type): string
{
    $text = is_string($text) ? $text : '';
    if ($type !== 'registration' || nmj_current_language() !== 'ka') {
        return $text;
    }
    if (strpos($text, 'Your personal data will be used') === false) {
        return $text;
    }
    return nmj_t('account_privacy');
}
