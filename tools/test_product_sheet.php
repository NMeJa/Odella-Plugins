<?php
/**
 * Pure checks for the product spreadsheet. No WordPress required.
 *   php tools/test_product_sheet.php
 */

define('ABSPATH', __DIR__);

if (!function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null) { return strtolower((string) $string); }
    function mb_strlen($string, $encoding = null) { return strlen((string) $string); }
    function mb_substr($string, $start, $length = null, $encoding = null) {
        return $length === null ? substr((string) $string, $start) : substr((string) $string, $start, $length);
    }
}

require dirname(__DIR__) . '/site/plugins/odella-product-spreadsheet/includes/columns.php';
require dirname(__DIR__) . '/site/plugins/odella-product-spreadsheet/includes/prices.php';
require dirname(__DIR__) . '/site/plugins/odella-product-spreadsheet/includes/categories.php';
require dirname(__DIR__) . '/site/plugins/odella-product-spreadsheet/includes/html.php';
require dirname(__DIR__) . '/site/plugins/odella-product-spreadsheet/includes/xlsx.php';
require dirname(__DIR__) . '/site/plugins/odella-product-spreadsheet/includes/csv.php';
require dirname(__DIR__) . '/site/plugins/odella-product-spreadsheet/includes/attributes.php';

$failed = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$failed): void {
    if ($ok) {
        echo "ok $name\n";
        return;
    }
    $failed++;
    echo "FAIL $name $detail\n";
};

$price = ops_parse_price('1 250');
$check('thousands', $price['kind'] === 'value' && $price['value'] === '1250', json_encode($price));
$price = ops_parse_price('295,50');
$check('comma decimal', $price['kind'] === 'value' && $price['value'] === '295.50', json_encode($price));
$price = ops_parse_price('295 ₾');
$check('currency rejected', $price['kind'] === 'invalid');
$price = ops_parse_price('(clear)');
$check('clear', $price['kind'] === 'clear');
$price = ops_parse_price('');
$check('blank price', $price['kind'] === 'blank');

$terms = [
    ['id' => 1, 'parent' => 0, 'name' => 'ქალი'],
    ['id' => 2, 'parent' => 1, 'name' => 'მოსაცმელი'],
    ['id' => 3, 'parent' => 2, 'name' => 'მოკლე'],
    ['id' => 4, 'parent' => 0, 'name' => 'Other'],
];
$index = ops_term_index($terms);
$paths = ops_category_paths('ქალი -> მოსაცმელი ->მოკლე, other');
$matched = ops_match_category_paths($paths, $index);
sort($matched['ids']);
$check('paths and case', $matched['ids'] === [1, 2, 3, 4] && $matched['errors'] === [], json_encode($matched));
$bad = ops_match_category_paths(ops_category_paths('parent-child->child'), $index);
$check('hyphen is not a separator', $bad['errors'] !== []);
$label = ops_category_export_label([1, 2, 3, 4], $index['by_id']);
$check('deepest paths', $label === 'Other->, ქალი->მოსაცმელი->მოკლე' || str_contains($label, 'ქალი->მოსაცმელი->მოკლე'), $label);

$html = ops_normalize_html('<strong>silk</strong>');
$check('strong', $html['errors'] === [] && $html['html'] === '<strong>silk</strong>', json_encode($html));
$html = ops_normalize_html('<stron>silk</stron>');
$check('unknown tag', $html['errors'] !== []);
$html = ops_normalize_html('5<10');
$check('bare less-than', $html['errors'] !== []);
$html = ops_cell_to_html([ops_plain_run('price < 10')]);
$check('spaced less-than', $html['errors'] === [] && str_contains($html['html'], '&lt;'), json_encode($html));
$html = ops_cell_to_html([
    ['text' => 'silk', 'bold' => true, 'italic' => false, 'underline' => false],
]);
$check('bold run', $html['errors'] === [] && str_contains($html['html'], '<strong>silk</strong>'), json_encode($html));
$link = ops_normalize_html('<a href="https://odella.ge">shop</a>');
$check('link', $link['errors'] === [] && str_contains($link['html'], 'https://odella.ge'), json_encode($link));
$bad_link = ops_normalize_html('<a href="javascript:alert(1)">x</a>');
$check('javascript link', $bad_link['errors'] !== []);

$check('header key', ops_header_key('სახელი') === 'name' && ops_header_key('Name') === 'name');
$check('english status', ops_status_label('publish') === 'publish' && ops_status_label('draft') === 'draft');
$check('preview header ignored', ops_header_key('Featured image preview') === '' && ops_header_key('სურათის ნახვა') === '');
$ordered = ops_selected_columns(['gallery', 'status'], ['status', 'gallery', 'name']);
$check('column order', $ordered === ['id', 'sku', 'name', 'status', 'gallery'], implode(',', $ordered));
$check('gallery helpers ignored', ops_header_key('Gallery url 1') === '' && ops_header_key('Gallery preview 2') === '');
$defaults = [];
foreach (ops_columns() as $key => $column) {
    if (!empty($column['default'])) {
        $defaults[] = $key;
    }
}
$check('default columns', $defaults === ['id', 'sku', 'name', 'categories', 'product_type', 'hover', 'featured', 'gallery', 'status', 'regular_price', 'sale_price', 'short_description', 'description', 'eur_regular', 'eur_sale', 'usd_regular', 'usd_sale', 'attributes'], implode(',', $defaults));
$catalog = [[
    'label' => 'Size',
    'taxonomy' => 'pa_size',
    'terms' => [
        ['id' => 1, 'name' => '46x46', 'slug' => '46x46'],
        ['id' => 2, 'name' => '70x70', 'slug' => '70x70'],
    ],
]];
$parsed = ops_parse_attribute_list('Size: 46x46, Size: 70x70', $catalog);
$check('attribute list', $parsed['errors'] === [] && isset($parsed['chosen']['pa_size'][1], $parsed['chosen']['pa_size'][2]), json_encode($parsed));
$unknown = ops_parse_attribute_list('Size: nope', $catalog);
$check('unknown attribute value', $unknown['errors'] !== []);

$dir = sys_get_temp_dir() . '/ops-test-' . getmypid();
if (class_exists('ZipArchive')) {
mkdir($dir);
$path = $dir . '/book.xlsx';
ops_xlsx_write($path, [[
    'name' => 'Products',
    'table' => true,
    'validations' => [['sqref' => 'C2:C10', 'formula' => 'Lists!$A$1:$A$2']],
    'rows' => [
        ['ID', 'სახელი', 'სტატუსი'],
        ['15', ['text' => 'silk', 'runs' => [['text' => 'silk', 'bold' => true, 'italic' => false, 'underline' => false]]], ''],
        ['', 'New', 'მონახაზი'],
    ],
]]);
$read = ops_xlsx_read($path);
$check('xlsx sheet', isset($read['Products']), implode(',', array_keys($read)));
$check('xlsx id', ($read['Products'][1][0]['text'] ?? '') === '15', json_encode($read['Products'][1][0] ?? null));
$check('xlsx bold', !empty($read['Products'][1][1]['runs'][0]['bold']), json_encode($read['Products'][1][1] ?? null));
$check('status ka', ops_parse_status('გამოქვეყნებული') === 'publish' && ops_parse_status('draft') === 'draft');
} else {
    echo "skip xlsx (ZipArchive missing locally)\n";
    $check('status ka', ops_parse_status('გამოქვეყნებული') === 'publish' && ops_parse_status('draft') === 'draft');
}

if ($failed) {
    echo "failed=$failed\n";
    exit(1);
}
echo "passed\n";
