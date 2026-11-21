<?php
/**
 * Category paths and tag names. Nothing here creates terms.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array<int, array<int, string>>
 */
function ops_category_paths(string $cell): array
{
    $cell = trim($cell);
    if ($cell === '') {
        return [];
    }
    $chunks = preg_split('/\s*,\s*/u', $cell) ?: [];
    $paths = [];
    foreach ($chunks as $chunk) {
        $chunk = trim((string) $chunk);
        if ($chunk === '') {
            continue;
        }
        if (str_contains($chunk, '->')) {
            $segments = explode('->', $chunk);
        } else {
            $chunk = preg_replace('/\s*\/\s*/u', '->', $chunk) ?? $chunk;
            $segments = explode('->', $chunk);
        }
        $clean = [];
        foreach ($segments as $segment) {
            $segment = trim(preg_replace('/\s+/u', ' ', $segment) ?? '');
            if ($segment !== '') {
                $clean[] = $segment;
            }
        }
        if ($clean) {
            $paths[] = $clean;
        }
    }
    return $paths;
}

/**
 * @param array<int, array{id: int, parent: int, name: string}> $terms
 * @return array{by_id: array<int, array{id: int, parent: int, name: string}>, by_parent: array<int, array<string, array<int, array{id: int, parent: int, name: string}>>>}
 */
function ops_term_index(array $terms): array
{
    $by_id = [];
    $by_parent = [];
    foreach ($terms as $term) {
        $by_id[$term['id']] = $term;
        $key = mb_strtolower($term['name'], 'UTF-8');
        $by_parent[$term['parent']][$key][] = $term;
    }
    return ['by_id' => $by_id, 'by_parent' => $by_parent];
}

/**
 * @param array<int, array<int, string>> $paths
 * @param array{by_id: array<int, array{id: int, parent: int, name: string}>, by_parent: array<int, array<string, array<int, array{id: int, parent: int, name: string}>>>} $index
 * @return array{ids: array<int, int>, errors: array<int, string>}
 */
function ops_match_category_paths(array $paths, array $index): array
{
    $ids = [];
    $errors = [];
    foreach ($paths as $segments) {
        $parent = 0;
        $walk = [];
        foreach ($segments as $segment) {
            $key = mb_strtolower($segment, 'UTF-8');
            $found = $index['by_parent'][$parent][$key] ?? [];
            if (count($found) > 1) {
                $errors[] = 'More than one category is named “' . $segment . '” in the same place. The file cannot choose.';
                $walk = [];
                break;
            }
            if (count($found) === 0) {
                $errors[] = 'No category matches “' . implode('->', $segments) . '”. Categories cannot be created from the file. A hyphen is not a separator.';
                $walk = [];
                break;
            }
            $walk[] = $found[0]['id'];
            $parent = $found[0]['id'];
        }
        foreach ($walk as $id) {
            $ids[$id] = $id;
        }
    }
    return ['ids' => array_values($ids), 'errors' => $errors];
}

/**
 * @param array<int, int> $assigned
 * @param array<int, array{id: int, parent: int, name: string}> $by_id
 */
function ops_category_export_label(array $assigned, array $by_id): string
{
    $assigned = array_values(array_unique(array_map('intval', $assigned)));
    $ancestor = [];
    foreach ($assigned as $id) {
        $walk = $by_id[$id]['parent'] ?? 0;
        while ($walk && isset($by_id[$walk])) {
            $ancestor[$walk] = true;
            $walk = $by_id[$walk]['parent'];
        }
    }
    $labels = [];
    foreach ($assigned as $id) {
        if (!isset($by_id[$id]) || !empty($ancestor[$id])) {
            continue;
        }
        $parts = [];
        $walk = $id;
        while ($walk && isset($by_id[$walk])) {
            array_unshift($parts, $by_id[$walk]['name']);
            $walk = $by_id[$walk]['parent'];
        }
        if ($parts) {
            $labels[] = implode('->', $parts);
        }
    }
    sort($labels, SORT_STRING);
    return implode(', ', $labels);
}

/**
 * @param array<int, string> $existing
 * @return array{names: array<int, string>, ids: array<int, int>, errors: array<int, string>}
 */
function ops_match_tags(string $cell, array $existing): array
{
    $cell = trim($cell);
    if ($cell === '') {
        return ['names' => [], 'ids' => [], 'errors' => []];
    }
    $lookup = [];
    foreach ($existing as $tag) {
        $lookup[mb_strtolower($tag['name'], 'UTF-8')][] = $tag;
    }
    $names = [];
    $ids = [];
    $errors = [];
    foreach (preg_split('/\s*,\s*/u', $cell) ?: [] as $piece) {
        $piece = trim(preg_replace('/\s+/u', ' ', (string) $piece) ?? '');
        if ($piece === '') {
            continue;
        }
        $found = $lookup[mb_strtolower($piece, 'UTF-8')] ?? [];
        if (count($found) > 1) {
            $errors[] = 'More than one tag is named “' . $piece . '”. The file cannot choose.';
            continue;
        }
        if (count($found) === 0) {
            $errors[] = 'No tag matches “' . $piece . '”. Tags cannot be created from the file.';
            continue;
        }
        $ids[$found[0]['id']] = $found[0]['id'];
        $names[] = $found[0]['name'];
    }
    return ['names' => $names, 'ids' => array_values($ids), 'errors' => $errors];
}
