<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

/** Reject rather than repair a redacted signature pair or text-free boundary topology. */
final class OutlookUniversalDiagnosticFixtureValidator
{
    public const BOUNDARY_FORMAT = 'u2e-redacted-compose-boundary-v1';

    private const TAGS = [
        'table', 'tbody', 'thead', 'tfoot', 'tr',
        'td', 'th', 'div', 'p', 'span',
        'font', 'b', 'strong', 'i', 'em',
        'u', 's', 'a', 'img', 'br',
        'wbr', 'o:p',
    ];

    private const ATTRIBUTES = [
        'id', 'class', 'style', 'width', 'height',
        'border', 'cellpadding', 'cellspacing', 'rowspan', 'colspan',
        'align', 'valign', 'bgcolor', 'color', 'src',
        'href', 'alt', 'title', 'dir', 'role',
        'face', 'data-rt-redacted',
    ];

    private const STYLE_PROPERTIES = [
        'width', 'height', 'min-width', 'max-width', 'min-height',
        'max-height', 'padding', 'padding-top', 'padding-right', 'padding-bottom',
        'padding-left', 'margin', 'margin-top', 'margin-right', 'margin-bottom',
        'margin-left', 'border', 'border-top', 'border-right', 'border-bottom',
        'border-left', 'border-width', 'border-color', 'border-style', 'border-collapse', 'border-spacing',
        'border-top-width', 'border-top-color', 'border-top-style', 'mso-border-top-alt',
        'border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-left-radius', 'border-bottom-right-radius',
        'border-image', 'border-image-source', 'border-image-slice', 'border-image-width', 'border-image-outset', 'border-image-repeat',
        '-webkit-border-image', 'all',
        'border-block', 'border-block-width', 'border-block-style', 'border-block-color',
        'border-block-start', 'border-block-start-width', 'border-block-start-style', 'border-block-start-color',
        'border-block-end', 'border-block-end-width', 'border-block-end-style', 'border-block-end-color',
        'border-inline', 'border-inline-width', 'border-inline-style', 'border-inline-color',
        'border-inline-start', 'border-inline-start-width', 'border-inline-start-style', 'border-inline-start-color',
        'border-inline-end', 'border-inline-end-width', 'border-inline-end-style', 'border-inline-end-color',
        '-webkit-border-before', '-webkit-border-before-width', '-webkit-border-before-style', '-webkit-border-before-color',
        '-webkit-border-after', '-webkit-border-after-width', '-webkit-border-after-style', '-webkit-border-after-color',
        '-webkit-border-start', '-webkit-border-start-width', '-webkit-border-start-style', '-webkit-border-start-color',
        '-webkit-border-end', '-webkit-border-end-width', '-webkit-border-end-style', '-webkit-border-end-color',
        'mso-border-alt', 'mso-border-top', 'mso-border-top-color', 'mso-border-top-style', 'mso-border-top-width',
        'mso-border-top-color-alt', 'mso-border-top-style-alt', 'mso-border-top-width-alt',
        'background', 'background-color', 'background-image', 'color',
        'text-align', 'text-indent', 'line-height', 'display', 'visibility',
        'opacity', 'float', 'clear', 'vertical-align', 'table-layout',
        'font-size', 'font-family', 'font-weight', 'font-style', 'text-decoration',
        'white-space', 'word-break', 'overflow-wrap', 'box-sizing', 'mso-line-height-rule', 'mso-table-lspace',
        'mso-table-rspace', 'mso-padding-alt', 'mso-margin-top-alt', 'mso-margin-bottom-alt', 'mso-hide',
        'mso-element',
    ];

    private const CLASSES = [
        'rt-col', 'rt-col-company', 'rt-col-direct', 'rt-col-dispo', 'rt-col-person',
        'rt-contact', 'rt-cta-br', 'rt-cta-cell', 'rt-cta-dispo', 'rt-cta-erreichbar',
        'rt-cta-gap', 'rt-cta-icon', 'rt-cta-line', 'rt-cta-number', 'rt-cta-time', 'rt-dispo',
        'rt-dispo-actions', 'rt-dispo-cap', 'rt-dispo-corner', 'rt-dispo-head', 'rt-dispo-row', 'rt-legal',
        'rt-logo', 'rt-logo-link', 'rt-pad', 'rt-pad-dispo', 'rt-shell',
        'rt-sig-row', 'rt-text', 'rt-train', 'rt-train-cell', 'MsoNormal',
        'MsoTableGrid', 'ElementToProof', 'ContentPasted0',
    ];

    private const CSS_WORDS = [
        'auto', 'none', 'normal', 'initial', 'inherit',
        'unset', 'transparent', 'currentcolor', 'white', 'black',
        'red', 'blue', 'green', 'gray', 'grey',
        'collapse', 'separate', 'solid', 'dashed', 'dotted',
        'double', 'left', 'right', 'center', 'justify',
        'start', 'end', 'top', 'bottom', 'middle',
        'baseline', 'text-top', 'text-bottom', 'block', 'inline',
        'inline-block', 'table', 'table-cell', 'hidden', 'visible',
        'both', 'fixed', 'bold', 'bolder', 'lighter',
        'italic', 'oblique', 'underline', 'line-through', 'nowrap',
        'pre', 'pre-wrap', 'pre-line', 'break-word', 'break-all',
        'keep-all', 'anywhere', 'exactly', 'at-least', 'all',
        'para-border-div', 'border-box', 'arial', 'calibri', 'aptos', 'sans-serif',
        'serif', 'tahoma', 'verdana', 'times', 'new',
        'roman', 'segoe', 'ui', 'helvetica', 'courier',
        'cambria', 'rt-redacted',
    ];

    private const ASSETS = ['b983af0049.png', '9dfa25b253.png', 'd92bd192b6.png', '1593d63cca.png', 'a38bc7d27c.png',
        '8a9ee1e161.png', '4b1a2aaba8.png', '19125261c2.png', 'f9d88b899d.png', '1111670ac8.png', 'b4b1fbea07.png', 'f1b083c20b.png', '14d7943c88.png',
        'c01c48d975.png', '8a5901e027.png', '033a29c0b7.png', '097c3bb121.png'];

    public static function valid(array $fixture): bool
    {
        if (count($fixture) !== 7 || array_diff(array_keys($fixture), ['schema', 'format', 'html', 'expectedHtml', 'sourceLength', 'sha256', 'expectedSha256']) !== []
            || ($fixture['schema'] ?? null) !== 1 || ! in_array($fixture['format'] ?? null, ['u2e-redacted-html-v1', self::BOUNDARY_FORMAT], true)
            || ! is_int($fixture['sourceLength'] ?? null) || $fixture['sourceLength'] < 0 || $fixture['sourceLength'] > 500000
            || ! is_string($fixture['html'] ?? null) || ! is_string($fixture['expectedHtml'] ?? null)
            || strlen($fixture['html']) + strlen($fixture['expectedHtml']) > OutlookUniversalDiagnosticFixtureStore::MAX_HTML_BYTES) {
            return false;
        }
        foreach (['html' => 'sha256', 'expectedHtml' => 'expectedSha256'] as $field => $digest) {
            if (! is_string($fixture[$digest] ?? null) || ! preg_match('/\A[0-9a-f]{64}\z/', $fixture[$digest])
                || ! hash_equals(hash('sha256', $fixture[$field]), $fixture[$digest])
                || ! ($field === 'html' && $fixture['format'] === self::BOUNDARY_FORMAT
                    ? self::boundaryHtml($fixture[$field]) : self::html($fixture[$field]))) {
                return false;
            }
        }

        return true;
    }

    /** Only inert tree shape and five fixed Outlook marker names. No authored
     * text, links, CSS, arbitrary identifiers or attributes can cross this gate. */
    private static function boundaryHtml(string $html): bool
    {
        $prefix = '<div data-rt-boundary="1">';
        if (strlen($html) > 98304 || ! str_starts_with($html, $prefix) || ! str_ends_with($html, '</div>')
            || preg_match('/[^\x09\x0a\x0d\x20-\x7e]/', $html)) {
            return false;
        }
        $inner = substr($html, strlen($prefix), -6);
        preg_match_all('/<(\/?)(div|p|span|br|hr|blockquote|table|tbody|tr|td)((?: [^<>]*)?)>/', $inner, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (count($tokens) > 8192) {
            return false;
        }
        $offset = 0;
        $stack = [];
        $elements = 1;
        $ids = ['ms-outlook-mobile-body-separator-line', 'ms-outlook-mobile-signature', 'divrplyfwdmsg',
            'mail-editor-reference-message-container', 'stopspelling'];
        foreach ($tokens as $token) {
            if (substr($inner, $offset, $token[0][1] - $offset) !== '') {
                return false;
            }
            $offset = $token[0][1] + strlen($token[0][0]);
            $tag = $token[2][0];
            $attributes = $token[3][0];
            if ($token[1][0] === '/') {
                if ($attributes !== '' || $stack === [] || array_pop($stack) !== $tag) {
                    return false;
                }

                continue;
            }
            if (++$elements > 4096 || count($stack) + 2 > 64) {
                return false;
            }
            $seen = [];
            while ($attributes !== '') {
                if (! preg_match('/\A ([a-z]+)="([a-z-]+)"/', $attributes, $attribute)
                    || isset($seen[$attribute[1]])) {
                    return false;
                }
                $name = $attribute[1];
                $value = $attribute[2];
                if (! ($name === 'id' && in_array($value, $ids, true))
                    && ! ($tag === 'blockquote' && $name === 'type' && $value === 'cite')) {
                    return false;
                }
                $seen[$name] = true;
                $attributes = substr($attributes, strlen($attribute[0]));
            }
            if (! in_array($tag, ['br', 'hr'], true)) {
                $stack[] = $tag;
            }
        }

        return $stack === [] && substr($inner, $offset) === '';
    }

    private static function html(string $html): bool
    {
        if ($html === '' || preg_match('/[^\x09\x0a\x0d\x20-\x7e]/', $html)) {
            return false;
        }
        if (str_starts_with($html, '<div')) {
            if (! str_starts_with($html, '<div align="center">') || ! str_ends_with($html, '</div>')) {
                return false;
            }
            $inner = substr($html, strlen('<div align="center">'), -strlen('</div>'));

            return preg_match('/\A<table(?: |>)/', $inner) && self::html($inner);
        }
        preg_match_all('/<(\/?)([a-z][a-z0-9:]*)([^<>]*?)(\/?)>/', $html, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if ($tokens === [] || count($tokens) > 3000) {
            return false;
        }
        $offset = 0;
        $stack = [];
        $nodes = [];
        $textCount = 0;
        foreach ($tokens as $token) {
            $between = substr($html, $offset, $token[0][1] - $offset);
            if (trim($between) !== '') {
                if ($stack === [] || trim($between) !== 'rt-redacted') {
                    return false;
                }
                $textCount++;
            }
            $offset = $token[0][1] + strlen($token[0][0]);
            $tag = $token[2][0];
            if (! in_array($tag, self::TAGS, true)) {
                return false;
            }
            if ($token[1][0] === '/') {
                if ($token[3][0] !== '' || $token[4][0] !== '' || $stack === [] || $nodes[array_pop($stack)]['tag'] !== $tag) {
                    return false;
                }

                continue;
            }
            $raw = $token[3][0];
            $attributes = [];
            while ($raw !== '') {
                if (! preg_match('/\A\s+([a-z][a-z0-9:-]*)="([^"]*)"/', $raw, $attribute)) {
                    return false;
                }
                $raw = substr($raw, strlen($attribute[0]));
                $name = $attribute[1];
                $value = html_entity_decode($attribute[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (array_key_exists($name, $attributes) || ! in_array($name, self::ATTRIBUTES, true) || ! self::attribute($name, $value)) {
                    return false;
                }
                $attributes[$name] = $value;
            }
            $parent = $stack === [] ? null : $stack[count($stack) - 1];
            if (count($stack) > 64 || $nodes !== [] && $parent === null) {
                return false;
            }
            $index = count($nodes);
            $nodes[] = ['tag' => $tag, 'attrs' => $attributes, 'parent' => $parent, 'index' => $index];
            if (! in_array($tag, ['img', 'br', 'wbr'], true)) {
                if ($token[4][0] !== '') {
                    return false;
                }
                $stack[] = $index;
            }
        }
        if (trim(substr($html, $offset)) !== '' || $stack !== [] || $textCount !== 5
            || $nodes[0]['tag'] !== 'table') {
            return false;
        }
        $rows = [];
        foreach ($nodes as $index => $node) {
            if ($node['tag'] === 'tr' && self::ancestor($nodes, $index, 'table') === 0) {
                $rows[] = $index;
            }
        }
        if (count($rows) !== 3) {
            return false;
        }
        $cells = [];
        foreach ($rows as $row) {
            $cells[] = array_values(array_filter($nodes, static fn (array $node): bool => in_array($node['tag'], ['td', 'th'], true) && $node['parent'] === $row));
        }

        if (count($cells[0]) !== 4 || count($cells[1]) !== 1 || ($cells[1][0]['attrs']['colspan'] ?? null) !== '3'
            || count($cells[2]) !== 1 || ($cells[2][0]['attrs']['colspan'] ?? null) !== '4') {
            return false;
        }
        $versions = [];
        foreach ($nodes as $node) {
            if (! isset($node['attrs']['id'])) {
                continue;
            }
            if (! in_array($node['index'], [0, $cells[0][0]['index']], true)
                || ! preg_match('/\A(?:x_)?rt-sig-u2-([0-9a-f]{24})(-cell)?\z/', $node['attrs']['id'], $marker)
                || $node['index'] === 0 && isset($marker[2])) {
                return false;
            }
            $versions[] = $marker[1];
        }

        return $versions !== [] && count(array_unique($versions)) === 1;
    }

    private static function ancestor(array $nodes, int $index, string $tag): ?int
    {
        $index = $nodes[$index]['parent'];
        while ($index !== null) {
            if ($nodes[$index]['tag'] === $tag) {
                return $index;
            }
            $index = $nodes[$index]['parent'];
        }

        return null;
    }

    private static function attribute(string $name, string $value): bool
    {
        return match ($name) {
            'id' => (bool) preg_match('/\A(?:x_)?rt-sig-u2-[0-9a-f]{24}(?:-cell)?\z/', $value),
            'class' => self::classes($value),
            'style' => self::style($value),
            'src' => (bool) preg_match('/\Art-redacted-(?:https|http|cid|data|blob|other|empty)-[0-9a-f]{64}\z/', $value)
                || (bool) preg_match('/\A\/m\/('.implode('|', array_map(static fn (string $file): string => preg_quote($file, '/'), self::ASSETS)).')#rt-redacted-[0-9a-f]{64}\z/', $value),
            'href' => (bool) preg_match('/\A#rt-redacted-(?:invalid-)?[0-9a-f]{64}\z/', $value),
            'alt', 'title' => $value === 'rt-redacted',
            'align', 'valign' => in_array($value, ['left', 'right', 'center', 'justify', 'top', 'bottom', 'middle', 'baseline'], true),
            'dir' => in_array($value, ['ltr', 'rtl', 'auto'], true),
            'role' => in_array($value, ['presentation', 'none'], true),
            'data-rt-redacted' => in_array($value, ['active', 'unknown'], true),
            'bgcolor', 'color' => self::color($value),
            'face' => self::cssValue($value),
            default => strlen($value) <= 32 && (bool) preg_match('/\A-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:px|pt|%)?\z/i', $value) && abs((float) $value) <= 1000000,
        };
    }

    private static function classes(string $value): bool
    {
        $classes = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (count($classes) > 16) {
            return false;
        }
        foreach ($classes as $class) {
            if (! in_array(preg_replace('/\Ax_/', '', $class), self::CLASSES, true) && ! preg_match('/\Art-redacted-[0-9a-f]{64}\z/', $class)) {
                return false;
            }
        }

        return true;
    }

    private static function style(string $value): bool
    {
        if (strlen($value) > 16384) {
            return false;
        }
        foreach (explode(';', $value) as $declaration) {
            if ($declaration === '') {
                continue;
            }
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2 || ! in_array($parts[0], self::STYLE_PROPERTIES, true) || ! self::cssValue($parts[1])) {
                return false;
            }
        }

        return true;
    }

    private static function color(string $value): bool
    {
        return (bool) preg_match('/\A#[0-9a-f]{3,8}\z/', $value) || (bool) preg_match('/\Argb\(\s*(?:[0-9]{1,3})\s*,\s*(?:[0-9]{1,3})\s*,\s*(?:[0-9]{1,3})\s*\)\z/', $value);
    }

    private static function cssValue(string $value): bool
    {
        if ($value === '' || strlen($value) > 256 || $value !== strtolower($value) || ! preg_match('/\A[a-z0-9#.,%()!\s\/-]+\z/', $value)) {
            return false;
        }
        $value = preg_replace('/\s*!important\z/', '', $value);
        $value = preg_replace('/#[0-9a-f]{3,8}\b/', ' ', $value);
        $valid = true;
        $value = preg_replace_callback('/rgb\(\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*,\s*([0-9]{1,3})\s*\)/', static function (array $match) use (&$valid): string {
            $valid = $valid && max((int) $match[1], (int) $match[2], (int) $match[3]) <= 255;

            return ' ';
        }, $value);
        $value = preg_replace_callback('/-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:px|pt|%|em|rem)?/', static function (array $match) use (&$valid): string {
            $valid = $valid && abs((float) $match[0]) <= 1000000;

            return ' ';
        }, $value);
        $words = preg_split('/[\s,\/]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

        return $valid && array_diff($words, self::CSS_WORDS) === [];
    }
}
