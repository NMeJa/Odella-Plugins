<?php
/**
 * Shop UI strings for Georgian and English.
 *
 * nmj_t() is the only map. Widgets call it when a text setting is empty or
 * still the English default. Elementor core widgets are swapped by element ID
 * on Georgian pages, in the filter below.
 */

defined('ABSPATH') || exit;

/**
 * @return array<string, array{ka: string, en: string}>
 */
function nmj_catalog(): array
{
    return [
        'load_more'        => ['ka' => 'მეტის ნახვა',          'en' => 'Load More'],
        'showing_x_of_y'   => ['ka' => 'ნაჩვენებია %1$d / %2$d', 'en' => 'Showing %1$d of %2$d'],
        'nothing_found'    => ['ka' => 'პროდუქტი ვერ მოიძებნა',   'en' => 'No products found'],
        'search_all_store' => ['ka' => 'მთელ მაღაზიაში ძებნა',    'en' => 'Search the whole store'],
        'related_heading'  => ['ka' => 'მსგავსი პროდუქტები',     'en' => 'You may also like'],
        'out_of_stock'     => ['ka' => 'მარაგში არ არის',         'en' => 'Out of Stock'],
        'clear'            => ['ka' => 'გასუფთავება',             'en' => 'Clear'],
        'select_option'    => ['ka' => 'აირჩიეთ ვარიანტი',        'en' => 'Select option'],
        'quick_view'       => ['ka' => 'სწრაფი ნახვა',            'en' => 'Quick View'],
        'filter'           => ['ka' => 'ფილტრი',                 'en' => 'Filter'],
        'search_ph'        => ['ka' => 'ძებნა…',                 'en' => 'Search…'],
        'sort_newest'      => ['ka' => 'უახლესი',                'en' => 'Newest'],
        'sort_price_asc'   => ['ka' => 'ფასი: ზრდადობით',         'en' => 'Price: low to high'],
        'sort_price_desc'  => ['ka' => 'ფასი: კლებადობით',        'en' => 'Price: high to low'],
        'all_products'     => ['ka' => 'ყველა პროდუქტი',          'en' => 'All products'],
        'products_count'   => ['ka' => '%d პროდუქტი',             'en' => '%d products'],
    ];
}

function nmj_current_language(): string
{
    if (function_exists('pll_current_language')) {
        $lang = pll_current_language();
        if (is_string($lang) && $lang !== '') {
            return $lang;
        }
    }
    return 'ka';
}

function nmj_t(string $key): string
{
    $catalog = nmj_catalog();
    if (!isset($catalog[$key])) {
        return $key;
    }
    $lang = nmj_current_language();
    if (!isset($catalog[$key][$lang])) {
        $lang = 'ka';
    }
    return $catalog[$key][$lang];
}

/**
 * Use the map when the saved (or dynamic) text is empty or still English.
 * A custom string is returned unchanged.
 */
function nmj_setting_text(string $value, string $key): string
{
    $value = trim($value);
    $english = [nmj_t_english($key)];
    if ($key === 'select_option') {
        $english[] = 'Select options';
    }
    if ($value === '' || in_array($value, $english, true)) {
        return nmj_t($key);
    }
    return $value;
}

function nmj_t_english(string $key): string
{
    $catalog = nmj_catalog();
    return $catalog[$key]['en'] ?? '';
}

/**
 * Georgian-only swaps for Elementor core widgets, matched by element ID.
 *
 * @param string $content
 * @param mixed  $widget
 */
function nmj_translate_elementor_widget($content, $widget)
{
    if (!is_string($content) || nmj_current_language() !== 'ka') {
        return $content;
    }
    if (!is_object($widget) || !method_exists($widget, 'get_id')) {
        return $content;
    }

    $id = $widget->get_id();
    if ($id === 'c490a4e') {
        $content = nmj_replace_first(
            '/(<div class="e-loop__load-more\b[^>]*>.*?<span class="elementor-button-text">)(.*?)(<\/span>)/s',
            $content,
            nmj_t('load_more')
        );
        $content = nmj_replace_first(
            '/(<[^>]*class="[^"]*\be-loop-nothing-found-message__text\b[^"]*"[^>]*>)(.*?)(<\/[a-z0-9]+>)/s',
            $content,
            nmj_t('nothing_found')
        );
        return $content;
    }

    if ($id === '7bc6c2db') {
        return nmj_replace_first(
            '/(<h[1-6][^>]*class="[^"]*\belementor-heading-title\b[^"]*"[^>]*>)(.*?)(<\/h[1-6]>)/s',
            $content,
            nmj_t('related_heading')
        );
    }

    return $content;
}

function nmj_replace_first(string $pattern, string $content, string $text): string
{
    $replaced = preg_replace($pattern, '$1' . esc_html($text) . '$3', $content, 1);
    return is_string($replaced) ? $replaced : $content;
}

add_filter('elementor/widget/render_content', 'nmj_translate_elementor_widget', 20, 2);
