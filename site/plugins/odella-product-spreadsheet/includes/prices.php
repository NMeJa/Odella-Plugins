<?php
/**
 * Price cell parsing. Blank leaves the stored price. (clear) is explicit.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array{kind: string, value: string, message: string}
 */
function ops_parse_price(string $raw): array
{
    $trim = trim($raw);
    if ($trim === '') {
        return ['kind' => 'blank', 'value' => '', 'message' => ''];
    }
    if (mb_strtolower($trim, 'UTF-8') === '(clear)') {
        return ['kind' => 'clear', 'value' => '', 'message' => ''];
    }
    if (preg_match('/[^\d\s.,]/u', $trim)) {
        return ['kind' => 'invalid', 'value' => '', 'message' => 'Use a plain number, or (clear). A currency symbol is not allowed.'];
    }
    $compact = str_replace(' ', '', $trim);
    if (substr_count($compact, ',') === 1 && !str_contains($compact, '.')) {
        $compact = str_replace(',', '.', $compact);
    }
    if (substr_count($compact, '.') > 1 || str_contains($compact, ',')) {
        return ['kind' => 'invalid', 'value' => '', 'message' => 'This number could not be read. Use 295, 295.50, or 295,50.'];
    }
    if (!is_numeric($compact) || (float) $compact < 0) {
        return ['kind' => 'invalid', 'value' => '', 'message' => 'This number could not be read. Use 295, 295.50, or 295,50.'];
    }
    $number = (float) $compact;
    if (abs($number - round($number)) < 0.00001) {
        $value = (string) (int) round($number);
    } else {
        $value = number_format($number, 2, '.', '');
    }
    return ['kind' => 'value', 'value' => $value, 'message' => ''];
}

function ops_prices_equal(string $left, string $right): bool
{
    if ($left === $right) {
        return true;
    }
    if ($left === '' || $right === '') {
        return false;
    }
    if (!is_numeric($left) || !is_numeric($right)) {
        return false;
    }
    return abs((float) $left - (float) $right) < 0.00001;
}
