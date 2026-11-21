<?php
/**
 * Column keys, English titles, and header matching.
 * Older Georgian titles still match so a file exported before this change can be imported.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array<string, array{ka: string, en: string, label: string, default: bool, locked: bool}>
 */
function ops_columns(): array
{
    return [
        'id' => ['ka' => 'ID', 'en' => 'ID', 'label' => 'ID', 'default' => true, 'locked' => true],
        'sku' => ['ka' => 'არტიკული', 'en' => 'SKU', 'label' => 'SKU', 'default' => true, 'locked' => true],
        'name' => ['ka' => 'სახელი', 'en' => 'Name', 'label' => 'Name', 'default' => true, 'locked' => true],
        'name_en' => ['ka' => 'Name_En', 'en' => 'Name (EN)', 'label' => 'Name (EN)', 'default' => true, 'locked' => false],
        'categories' => ['ka' => 'კატეგორიები', 'en' => 'Categories', 'label' => 'Categories', 'default' => true, 'locked' => false],
        'categories_en' => ['ka' => 'Categories_En', 'en' => 'Categories (EN)', 'label' => 'Categories (EN)', 'default' => true, 'locked' => false],
        'product_type' => ['ka' => 'Product type', 'en' => 'Product type', 'label' => 'Product type', 'default' => true, 'locked' => false],
        'hover' => ['ka' => 'Hover image', 'en' => 'Hover image', 'label' => 'Hover image', 'default' => true, 'locked' => false],
        'featured' => ['ka' => 'მთავარი სურათი', 'en' => 'Featured image', 'label' => 'Featured image', 'default' => true, 'locked' => false],
        'gallery' => ['ka' => 'გალერეა', 'en' => 'Gallery images', 'label' => 'Gallery images', 'default' => true, 'locked' => false],
        'status' => ['ka' => 'სტატუსი', 'en' => 'Status', 'label' => 'Status', 'default' => true, 'locked' => false],
        'regular_price' => ['ka' => 'ჩვეულებრივი ფასი (GEL)', 'en' => 'Regular price (GEL)', 'label' => 'Regular price (GEL)', 'default' => true, 'locked' => false],
        'sale_price' => ['ka' => 'ფასდაკლებული ფასი (GEL)', 'en' => 'Sale price (GEL)', 'label' => 'Sale price (GEL)', 'default' => true, 'locked' => false],
        'short_description' => ['ka' => 'მოკლე აღწერა', 'en' => 'Short description', 'label' => 'Short description', 'default' => true, 'locked' => false],
        'short_description_en' => ['ka' => 'Short description_En', 'en' => 'Short description (EN)', 'label' => 'Short description (EN)', 'default' => true, 'locked' => false],
        'description' => ['ka' => 'აღწერა', 'en' => 'Description', 'label' => 'Description', 'default' => true, 'locked' => false],
        'description_en' => ['ka' => 'Description_En', 'en' => 'Description (EN)', 'label' => 'Description (EN)', 'default' => true, 'locked' => false],
        'eur_regular' => ['ka' => 'EUR ჩვეულებრივი ფასი', 'en' => 'EUR regular price', 'label' => 'EUR regular price', 'default' => true, 'locked' => false],
        'eur_sale' => ['ka' => 'EUR ფასდაკლებული ფასი', 'en' => 'EUR sale price', 'label' => 'EUR sale price', 'default' => true, 'locked' => false],
        'usd_regular' => ['ka' => 'USD ჩვეულებრივი ფასი', 'en' => 'USD regular price', 'label' => 'USD regular price', 'default' => true, 'locked' => false],
        'usd_sale' => ['ka' => 'USD ფასდაკლებული ფასი', 'en' => 'USD sale price', 'label' => 'USD sale price', 'default' => true, 'locked' => false],
        'attributes' => ['ka' => 'Attributes', 'en' => 'Attributes', 'label' => 'Attributes', 'default' => true, 'locked' => false],
        'tags' => ['ka' => 'ჭდეები', 'en' => 'Tags', 'label' => 'Tags', 'default' => false, 'locked' => false],
    ];
}

function ops_column_header(string $key): string
{
    $columns = ops_columns();
    if (isset($columns[$key])) {
        return $columns[$key]['label'];
    }
    if (function_exists('ops_meta_field')) {
        $field = ops_meta_field($key);
        if ($field) {
            return $field['label'];
        }
    }
    return $key;
}

function ops_column_is_image(string $key): bool
{
    if ($key === 'featured' || $key === 'gallery' || $key === 'hover') {
        return true;
    }
    if (!function_exists('ops_meta_field')) {
        return false;
    }
    $field = ops_meta_field($key);
    return $field !== null && ($field['kind'] === 'image' || $field['kind'] === 'gallery');
}

function ops_status_label(string $status, string $lang = 'en'): string
{
    unset($lang);
    if ($status === 'publish') {
        return 'publish';
    }
    if ($status === 'draft') {
        return 'draft';
    }
    return $status;
}

function ops_parse_status(string $raw): ?string
{
    $key = mb_strtolower(trim($raw), 'UTF-8');
    $map = [
        '' => '',
        'draft' => 'draft',
        'publish' => 'publish',
        'published' => 'publish',
        'მონახაზი' => 'draft',
        'გამოქვეყნებული' => 'publish',
    ];
    return array_key_exists($key, $map) ? $map[$key] : null;
}

function ops_header_key(string $header): string
{
    $header = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $header) ?? ''), 'UTF-8');
    if ($header === '' || ops_header_is_ignored($header)) {
        return '';
    }
    foreach (ops_columns() as $key => $column) {
        $names = [$column['label'], $column['en'], $column['ka']];
        foreach ($names as $name) {
            if ($header === mb_strtolower($name, 'UTF-8')) {
                return $key;
            }
        }
    }
    if (function_exists('ops_meta_fields')) {
        foreach (ops_meta_fields() as $field) {
            $names = [$field['label'], $field['meta_key'], str_replace(' ', '_', $field['label'])];
            foreach ($names as $name) {
                if ($header === mb_strtolower($name, 'UTF-8')) {
                    return $field['column'];
                }
            }
        }
    }
    return '';
}

function ops_header_is_ignored(string $header): bool
{
    if (str_ends_with($header, ' preview') || $header === 'image preview' || $header === 'სურათის ნახვა') {
        return true;
    }
    if (preg_match('/^gallery preview \d+$/', $header) || preg_match('/^gallery url \d+$/', $header)) {
        return true;
    }
    return $header === 'add category' || $header === 'add tag';
}

/**
 * ID, SKU, and Name stay first. $order is the on-screen order of the other columns.
 *
 * @param array<int, string> $selected
 * @param array<int, string> $order
 * @return array<int, string>
 */
function ops_selected_columns(array $selected, array $order = []): array
{
    $columns = ops_columns();
    $allowed = $columns;
    if (function_exists('ops_meta_fields')) {
        foreach (ops_meta_fields() as $field) {
            $allowed[$field['column']] = ['locked' => false];
        }
    }
    $chosen = ['id', 'sku', 'name'];
    $seen = array_fill_keys($chosen, true);
    $wanted = [];
    foreach ($selected as $key) {
        if (isset($allowed[$key])) {
            $wanted[$key] = true;
        }
    }
    $sequence = $order;
    if (!$sequence) {
        $sequence = array_keys($columns);
        if (function_exists('ops_meta_fields')) {
            foreach (ops_meta_fields() as $field) {
                $sequence[] = $field['column'];
            }
        }
    }
    foreach ($sequence as $key) {
        if (!isset($allowed[$key]) || isset($seen[$key]) || !isset($wanted[$key])) {
            continue;
        }
        $chosen[] = $key;
        $seen[$key] = true;
    }
    return $chosen;
}

function ops_clean_column_key(string $key): string
{
    $key = trim($key);
    if (isset(ops_columns()[$key])) {
        return $key;
    }
    if (function_exists('ops_meta_field') && ops_meta_field($key)) {
        $field = ops_meta_field($key);
        return $field['column'];
    }
    return '';
}
