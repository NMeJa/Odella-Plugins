<?php
/**
 * CSV round trip. The delimiter is detected, because some Excels expect a semicolon.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param array<int, array<int, string>> $rows
 */
function ops_csv_write(string $path, array $rows): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException('The CSV could not be created.');
    }
    fwrite($handle, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    fclose($handle);
}

/**
 * @return array<int, array<int, array{text: string, formula: string, runs: array<int, array{text: string, bold: bool, italic: bool, underline: bool}>}>>
 */
function ops_csv_read(string $path): array
{
    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('The CSV could not be read.');
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    $first = strtok($raw, "\r\n");
    $delimiter = ',';
    if (is_string($first)) {
        $counts = [
            ',' => substr_count($first, ','),
            ';' => substr_count($first, ';'),
            "\t" => substr_count($first, "\t"),
        ];
        arsort($counts);
        $top = (int) reset($counts);
        if ($top > 0) {
            $delimiter = (string) array_key_first($counts);
        }
    }
    $rows = [];
    $handle = fopen('php://memory', 'rb+');
    if ($handle === false) {
        throw new RuntimeException('The CSV could not be read.');
    }
    fwrite($handle, $raw);
    rewind($handle);
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        if ($data === [null] || $data === false) {
            continue;
        }
        $line = [];
        foreach ($data as $value) {
            $text = (string) $value;
            $line[] = ['text' => $text, 'formula' => '', 'runs' => [ops_plain_run($text)]];
        }
        $rows[] = $line;
    }
    fclose($handle);
    return $rows;
}
