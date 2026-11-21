<?php
/**
 * Pricing rule checks. No WordPress required.
 *   php tools/test_pricing_rules.php
 */

define('ABSPATH', __DIR__);

function add_action(...$args) {}
function add_filter(...$args) {}

require dirname(__DIR__) . '/site/mu-plugins/odella-pricing.php';

$failed = 0;
$check = static function ($name, $ok, $detail = '') use (&$failed): void {
    if ($ok) {
        echo "ok $name\n";
        return;
    }
    $failed++;
    echo "FAIL $name " . json_encode($detail) . "\n";
};

$cases = [
    [148.3, '149'],
    [149, '149'],
    [149.01, '159'],
    [150.2, '159'],
    [9, '9'],
    [9.5, '19'],
];
foreach ($cases as $case) {
    $out = odella_round_up_9($case[0]);
    $check('round ' . $case[0], (string) $out === (string) $case[1], $out);
}

$settings = odella_pricing_settings_defaults();
$check('fee zero keeps 148.3', odella_pricing_apply_fee(148.3, $settings) === 148.3);
$check('default rounding', odella_pricing_apply_rounding(148.3, $settings) === '149');

$settings['fee_percent'] = 10.0;
$with_fee = odella_pricing_apply_fee(100, $settings);
$check('fee 10 percent', abs((float) $with_fee - 110) < 0.00001, $with_fee);
$check('fee then end in 9', odella_pricing_apply_rounding($with_fee, $settings) === '119', odella_pricing_apply_rounding($with_fee, $settings));

$settings['rounding'] = 'whole';
$settings['reduce_by'] = '1';
$check('whole then reduce', odella_pricing_apply_rounding(148.3, $settings) === '148', odella_pricing_apply_rounding(148.3, $settings));

$settings['rounding'] = 'end_in_9';
$check('end in 9 ignores reduce', odella_pricing_apply_rounding(148.3, $settings) === '149');

if ($failed) {
    echo "failed=$failed\n";
    exit(1);
}
echo "passed\n";
