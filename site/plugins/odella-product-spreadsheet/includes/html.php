<?php
/**
 * Spreadsheet text and HTML. A "<" is a character only when the next character is a space.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array<string, array<string, bool>>
 */
function ops_allowed_html(): array
{
    $text = [];
    $block = ['class' => true];
    $link = ['href' => true, 'rel' => true, 'target' => true];
    $cell = ['colspan' => true, 'rowspan' => true];
    return [
        'p' => $block,
        'div' => $block,
        'span' => $block,
        'br' => $text,
        'hr' => $text,
        'strong' => $text,
        'b' => $text,
        'em' => $text,
        'i' => $text,
        'u' => $text,
        's' => $text,
        'strike' => $text,
        'del' => $text,
        'small' => $text,
        'sub' => $text,
        'sup' => $text,
        'ul' => $text,
        'ol' => $text,
        'li' => $text,
        'blockquote' => $text,
        'h1' => $text,
        'h2' => $text,
        'h3' => $text,
        'h4' => $text,
        'h5' => $text,
        'h6' => $text,
        'a' => $link,
        'img' => ['src' => true, 'alt' => true, 'class' => true],
        'table' => ['class' => true],
        'thead' => $text,
        'tbody' => $text,
        'tr' => $text,
        'td' => $cell,
        'th' => $cell,
        'figure' => ['class' => true],
        'figcaption' => $text,
    ];
}

function ops_is_complex_html(string $html): bool
{
    return (bool) preg_match('/<\s*\/?\s*(img|h[1-6]|table|figure)\b/i', $html);
}

/**
 * @param array<int, array{text: string, bold: bool, italic: bool, underline: bool}> $runs
 * @return array{html: string, errors: array<int, string>}
 */
function ops_cell_to_html(array $runs): array
{
    $plain = '';
    $formatted = false;
    foreach ($runs as $run) {
        $plain .= $run['text'];
        if (!empty($run['bold']) || !empty($run['italic']) || !empty($run['underline'])) {
            $formatted = true;
        }
    }
    if (trim($plain) === '') {
        return ['html' => '', 'errors' => []];
    }
    if (!$formatted && preg_match('/<(?!\s)/u', $plain)) {
        return ops_normalize_html($plain);
    }
    $errors = ops_bare_angles($plain);
    if ($errors) {
        return ['html' => '', 'errors' => $errors];
    }
    return ['html' => ops_runs_to_html($runs), 'errors' => []];
}

/**
 * @return array<int, string>
 */
function ops_bare_angles(string $text): array
{
    $errors = [];
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
        if ($text[$i] !== '<') {
            continue;
        }
        $next = $i + 1 < $length ? $text[$i + 1] : '';
        if ($next !== '' && preg_match('/\s/u', $next)) {
            continue;
        }
        $snippet = substr($text, max(0, $i - 12), 36);
        $errors[] = '“' . trim($snippet) . '” has a < that is not a tag and is not a less-than sign. Put a space after < when you mean the character.';
        if (count($errors) >= 5) {
            break;
        }
    }
    return $errors;
}

/**
 * @param array<int, array{text: string, bold: bool, italic: bool, underline: bool}> $runs
 */
function ops_runs_to_html(array $runs): string
{
    $lines = [[]];
    foreach ($runs as $run) {
        $text = str_replace("\r\n", "\n", str_replace("\r", "\n", $run['text']));
        $parts = explode("\n", $text);
        $last = count($parts) - 1;
        foreach ($parts as $index => $part) {
            if ($part !== '') {
                $lines[count($lines) - 1][] = [
                    'text' => ops_escape_literals($part),
                    'bold' => !empty($run['bold']),
                    'italic' => !empty($run['italic']),
                    'underline' => !empty($run['underline']),
                ];
            }
            if ($index < $last) {
                $lines[] = [];
            }
        }
    }
    $html = '';
    $list = '';
    $items = '';
    $close = static function () use (&$html, &$list, &$items): void {
        if ($list === '') {
            return;
        }
        $html .= '<' . $list . '>' . $items . '</' . $list . '>';
        $list = '';
        $items = '';
    };
    foreach ($lines as $line) {
        $text = '';
        foreach ($line as $piece) {
            $text .= $piece['text'];
        }
        $kind = '';
        $content = $line;
        if (preg_match('/^(?:•|-|\*)\s+/u', $text)) {
            $kind = 'ul';
            $content = ops_strip_line_prefix($line, '/^(?:•|-|\*)\s+/u');
        } elseif (preg_match('/^\d+\.\s+/u', $text)) {
            $kind = 'ol';
            $content = ops_strip_line_prefix($line, '/^\d+\.\s+/u');
        }
        if ($kind !== '') {
            if ($list !== '' && $list !== $kind) {
                $close();
            }
            $list = $kind;
            $items .= '<li>' . ops_linkify_runs($content) . '</li>';
            continue;
        }
        $close();
        if (trim(strip_tags(implode('', array_column($line, 'text')))) === '' && $line === []) {
            continue;
        }
        $inner = ops_linkify_runs($content);
        if ($inner !== '') {
            $html .= '<p>' . $inner . '</p>';
        }
    }
    $close();
    return $html;
}

/**
 * @param array<int, array{text: string, bold: bool, italic: bool, underline: bool}> $line
 * @return array<int, array{text: string, bold: bool, italic: bool, underline: bool}>
 */
function ops_strip_line_prefix(array $line, string $pattern): array
{
    if (!$line) {
        return $line;
    }
    $line[0]['text'] = preg_replace($pattern, '', $line[0]['text'], 1) ?? $line[0]['text'];
    return $line;
}

/**
 * @param array<int, array{text: string, bold: bool, italic: bool, underline: bool}> $runs
 */
function ops_linkify_runs(array $runs): string
{
    $html = '';
    foreach ($runs as $run) {
        $text = $run['text'];
        $text = preg_replace_callback(
            '/(.*?)(\s*)\((https?:\/\/[^\s)]+)\)/u',
            static function (array $match): string {
                $label = $match[1] !== '' ? $match[1] : $match[3];
                return $match[2] . '<a href="' . htmlspecialchars($match[3], ENT_QUOTES | ENT_XML1, 'UTF-8') . '">' . $label . '</a>';
            },
            $text
        ) ?? $text;
        if (!empty($run['bold'])) {
            $text = '<strong>' . $text . '</strong>';
        }
        if (!empty($run['italic'])) {
            $text = '<em>' . $text . '</em>';
        }
        if (!empty($run['underline'])) {
            $text = '<u>' . $text . '</u>';
        }
        $html .= $text;
    }
    return $html;
}

function ops_escape_literals(string $text): string
{
    $text = str_replace('&', '&amp;', $text);
    $text = preg_replace('/<\s/u', '&lt; ', $text) ?? $text;
    $text = preg_replace('/ >/u', ' &gt;', $text) ?? $text;
    return $text;
}

/**
 * @return array{html: string, errors: array<int, string>}
 */
function ops_normalize_html(string $html): array
{
    $allowed = ops_allowed_html();
    $void = ['br' => true, 'hr' => true, 'img' => true];
    $length = strlen($html);
    $i = 0;
    $out = '';
    $stack = [];
    $errors = [];
    $note = static function (string $html, int $at) use (&$errors): void {
        if (count($errors) >= 8) {
            return;
        }
        $errors[] = '“' . trim(substr($html, max(0, $at - 16), 40)) . '” is not a complete tag.';
    };
    while ($i < $length) {
        $lt = strpos($html, '<', $i);
        if ($lt === false) {
            $chunk = substr($html, $i);
            if (ops_bad_greater($chunk)) {
                $note($html, $i);
            }
            $out .= ops_escape_text_node($chunk);
            break;
        }
        if ($lt > $i) {
            $chunk = substr($html, $i, $lt - $i);
            if (ops_bad_greater($chunk)) {
                $note($html, $i);
            }
            $out .= ops_escape_text_node($chunk);
        }
        $next = $lt + 1 < $length ? $html[$lt + 1] : '';
        if ($next !== '' && preg_match('/\s/', $next)) {
            $out .= '&lt;';
            $i = $lt + 1;
            continue;
        }
        $gt = strpos($html, '>', $lt);
        if ($gt === false) {
            $note($html, $lt);
            break;
        }
        $inside = substr($html, $lt + 1, $gt - $lt - 1);
        $closing = false;
        if (isset($inside[0]) && $inside[0] === '/') {
            $closing = true;
            $inside = substr($inside, 1);
        }
        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9]*)\s*(.*)$/s', $inside, $match)) {
            $note($html, $lt);
            $i = $gt + 1;
            continue;
        }
        $name = strtolower($match[1]);
        $attr_raw = rtrim($match[2]);
        $self = false;
        if (str_ends_with($attr_raw, '/')) {
            $self = true;
            $attr_raw = rtrim(substr($attr_raw, 0, -1));
        }
        if (!isset($allowed[$name])) {
            $errors[] = '<' . $name . '> is not a tag this import accepts.';
            $i = $gt + 1;
            continue;
        }
        if ($closing) {
            if (!$stack || end($stack) !== $name) {
                $errors[] = '</' . $name . '> does not close the tag that is open.';
            } else {
                array_pop($stack);
                $out .= '</' . $name . '>';
            }
            $i = $gt + 1;
            continue;
        }
        $attrs = ops_parse_attrs($attr_raw, $name, $errors);
        if ($attrs === null) {
            $i = $gt + 1;
            continue;
        }
        $out .= '<' . $name . $attrs;
        if (!empty($void[$name]) || $self) {
            $out .= $name === 'br' ? '>' : ' />';
        } else {
            $out .= '>';
            $stack[] = $name;
        }
        $i = $gt + 1;
    }
    foreach (array_reverse($stack) as $open) {
        $errors[] = '<' . $open . '> is missing its closing tag.';
    }
    if ($errors) {
        return ['html' => '', 'errors' => $errors];
    }
    return ['html' => $out, 'errors' => []];
}

/**
 * @param array<int, string> $errors
 */
function ops_parse_attrs(string $raw, string $tag, array &$errors): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        if ($tag === 'a' || $tag === 'img') {
            $errors[] = '<' . $tag . '> needs an address.';
            return null;
        }
        return '';
    }
    $allowed = [
        'a' => ['href', 'rel', 'target'],
        'img' => ['src', 'alt', 'class'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan'],
        'table' => ['class'],
        'figure' => ['class'],
        'p' => ['class'],
        'div' => ['class'],
        'span' => ['class'],
    ];
    $keep = [];
    if (!preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\']+))/', $raw, $found, PREG_SET_ORDER)) {
        $errors[] = '<' . $tag . '> has an attribute that could not be read.';
        return null;
    }
    foreach ($found as $attr) {
        $name = strtolower($attr[1]);
        $value = $attr[5] ?? '';
        if (isset($attr[2][0]) && $attr[2][0] === '"') {
            $value = $attr[3];
        } elseif (isset($attr[2][0]) && $attr[2][0] === "'") {
            $value = $attr[4];
        }
        if (str_starts_with($name, 'on')) {
            $errors[] = '<' . $tag . '> cannot use ' . $name . '.';
            return null;
        }
        if (!in_array($name, $allowed[$tag] ?? [], true)) {
            continue;
        }
        if (($name === 'href' || $name === 'src') && !ops_safe_url($value)) {
            $errors[] = '<' . $tag . '> has an address that is not http or https.';
            return null;
        }
        $keep[$name] = $value;
    }
    if (($tag === 'a' && !isset($keep['href'])) || ($tag === 'img' && !isset($keep['src']))) {
        $errors[] = '<' . $tag . '> needs an http or https address.';
        return null;
    }
    $built = '';
    foreach (['href', 'src', 'alt', 'class', 'rel', 'target', 'colspan', 'rowspan'] as $name) {
        if (!isset($keep[$name]) || $keep[$name] === '') {
            continue;
        }
        $built .= ' ' . $name . '="' . htmlspecialchars($keep[$name], ENT_QUOTES | ENT_XML1, 'UTF-8') . '"';
    }
    return $built;
}

function ops_safe_url(string $url): bool
{
    $url = trim($url);
    if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
        return true;
    }
    return (bool) preg_match('#^https?://#i', $url);
}

function ops_bad_greater(string $text): bool
{
    $stripped = preg_replace('/&(?:[a-zA-Z][a-zA-Z0-9]+|#\d+|#x[0-9a-fA-F]+);/', '', $text) ?? $text;
    return (bool) preg_match('/(?<![\s])>/', $stripped);
}

function ops_escape_text_node(string $text): string
{
    $text = preg_replace('/&(?!(?:[a-zA-Z][a-zA-Z0-9]+|#\d+|#x[0-9a-fA-F]+);)/', '&amp;', $text) ?? $text;
    return preg_replace('/(?<=[\s])>/', '&gt;', $text) ?? $text;
}

/**
 * @return array<int, array{text: string, bold: bool, italic: bool, underline: bool}>
 */
function ops_html_to_runs(string $html): array
{
    if (ops_is_complex_html($html)) {
        return [['text' => $html, 'bold' => false, 'italic' => false, 'underline' => false]];
    }
    $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/p>\s*<p[^>]*>/i', "\n", $html) ?? $html;
    $html = preg_replace('/<\/?(p|ul|ol)>/i', '', $html) ?? $html;
    $html = preg_replace_callback('/<li[^>]*>(.*?)<\/li>/is', static function (array $match) use ($html): string {
        $prefix = stripos($html, '<ol') !== false ? '1. ' : '• ';
        return $prefix . $match[1] . "\n";
    }, $html) ?? $html;
    $html = preg_replace_callback('/<a\s+[^>]*href=("|\')(.*?)\1[^>]*>(.*?)<\/a>/is', static function (array $match): string {
        $label = trim(strip_tags($match[3]));
        $url = $match[2];
        if ($label === '' || $label === $url) {
            return $url;
        }
        return $label . ' (' . $url . ')';
    }, $html) ?? $html;
    $runs = [];
    $pattern = '/<(strong|em|u|b|i)>(.*?)<\/\1>/is';
    $offset = 0;
    $length = strlen($html);
    while ($offset < $length && preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE, $offset)) {
        $at = $match[0][1];
        if ($at > $offset) {
            $runs[] = ops_plain_run(ops_decode_text(substr($html, $offset, $at - $offset)));
        }
        $tag = strtolower($match[1][0]);
        $runs[] = [
            'text' => ops_decode_text(strip_tags($match[2][0])),
            'bold' => $tag === 'strong' || $tag === 'b',
            'italic' => $tag === 'em' || $tag === 'i',
            'underline' => $tag === 'u',
        ];
        $offset = $at + strlen($match[0][0]);
    }
    if ($offset < $length) {
        $runs[] = ops_plain_run(ops_decode_text(substr($html, $offset)));
    }
    return $runs ?: [ops_plain_run('')];
}

/**
 * @return array{text: string, bold: bool, italic: bool, underline: bool}
 */
function ops_plain_run(string $text): array
{
    return ['text' => $text, 'bold' => false, 'italic' => false, 'underline' => false];
}

function ops_decode_text(string $text): string
{
    return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function ops_descriptions_match(string $stored, string $incoming): bool
{
    return ops_canonical_description($stored) === ops_canonical_description($incoming);
}

function ops_canonical_description(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    if (ops_is_complex_html($html)) {
        $parsed = ops_normalize_html($html);
        return $parsed['errors'] ? $html : $parsed['html'];
    }
    if (!preg_match('/<(?!\s)/', $html)) {
        return ops_runs_to_html([ops_plain_run($html)]);
    }
    $parsed = ops_normalize_html($html);
    if ($parsed['errors']) {
        return $html;
    }
    return ops_cell_to_html(ops_html_to_runs($parsed['html']))['html'];
}
