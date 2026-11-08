<?php
/**
 * Checkout field rules.
 *
 * The phone plugin already writes billing_phone as E.164 on submit
 * (intl-tel-input getNumber(), nationalMode false). This file adds the
 * Georgian mobile check on the server.
 */

defined('ABSPATH') || exit;

add_action('woocommerce_after_checkout_validation', 'nmj_validate_georgian_mobile', 10, 2);

/**
 * @param array<string, mixed> $data
 * @param \WP_Error            $errors
 */
function nmj_validate_georgian_mobile($data, $errors): void
{
    if (!is_object($errors) || !method_exists($errors, 'add')) {
        return;
    }

    $raw = isset($data['billing_phone']) ? (string) $data['billing_phone'] : '';
    $raw = preg_replace('/[^\d+]/', '', $raw);
    if (!is_string($raw) || $raw === '') {
        return;
    }

    $national = nmj_georgian_national_number($raw);
    if ($national === null) {
        return;
    }

    if (!preg_match('/^5\d{8}$/', $national)) {
        $errors->add('billing_phone', nmj_t('phone_ge_invalid'));
    }
}

/**
 * National digits when the number is Georgian. Null means another country.
 *
 * A leading +995 is the country the phone plugin posts. A number with no
 * country prefix is Georgian too, because the field defaults to Georgia.
 */
function nmj_georgian_national_number(string $raw): ?string
{
    if (strpos($raw, '+995') === 0) {
        return substr($raw, 4);
    }
    if (strpos($raw, '00995') === 0) {
        return substr($raw, 5);
    }
    if ($raw[0] === '+') {
        return null;
    }
    if (strpos($raw, '995') === 0 && strlen($raw) > 9) {
        return substr($raw, 3);
    }

    return $raw;
}
