<?php

declare(strict_types=1);

namespace App\Support\Mail;

use RuntimeException;
use Throwable;

/**
 * Inactive Stage-2 candidate reader. It neither authorizes nor rewrites mail.
 * The future caller must prove connector/TLS, tenant and allowed envelope sender
 * before setting trustedIngress, and admit the header version against its source.
 */
final class OutlookUniversalTransitCandidate
{
    public static function inspect(string $html, array $headers, bool $trustedIngress = false): array
    {
        $result = ['eligible' => false, 'reason' => 'untrusted_ingress', 'version' => null,
            'candidate' => null, 'quotedCandidateCount' => 0];
        if (! $trustedIngress) {
            return $result;
        }
        $values = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && strtolower($name) === 'x-railtime-template') {
                array_push($values, ...(is_array($value) ? $value : [$value]));
            }
        }
        if (count($values) !== 1) {
            return array_replace($result, ['reason' => $values === [] ? 'header_missing' : 'header_ambiguous']);
        }
        if (! is_string($values[0]) || ! preg_match('/\Au2e-([0-9a-f]{24})\z/', trim($values[0], " \t"), $match)) {
            return array_replace($result, ['reason' => 'header_invalid']);
        }
        $result['version'] = $match[1];
        if ($html === '' || strlen($html) > 1000000 || str_contains($html, "\0") || ! mb_check_encoding($html, 'UTF-8')) {
            return array_replace($result, ['reason' => 'html_invalid']);
        }
        try {
            $nodes = self::nodes($html);
            $boundary = strlen($html);
            foreach ($nodes as $i => $node) {
                $id = strtolower($node['attrs']['id'] ?? '');
                $classes = preg_split('/\s+/', strtolower($node['attrs']['class'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                $quoted = $node['tag'] === 'blockquote'
                    || in_array($id, ['divrplyfwdmsg', 'x_divrplyfwdmsg', 'mail-editor-reference-message-container', 'stopspelling', 'x_stopspelling', '_mailoriginal'], true)
                    || array_intersect($classes, ['gmail_quote', 'yahoo_quoted', 'moz-cite-prefix']) !== [];
                if ($node['tag'] === 'div' && str_contains(strtolower($node['attrs']['style'] ?? ''), 'border-top')) {
                    $text = self::text(substr($html, $node['openEnd'], $node['close'] - $node['openEnd']));
                    $quoted = $quoted || preg_match('/(?:Von:.*Gesendet:.*An:.*Betreff:|From:.*Sent:.*To:.*Subject:)/iu', $text) === 1;
                }
                if ($quoted) {
                    $boundary = min($boundary, $node['start']);
                }
            }
            $current = [];
            foreach ($nodes as $i => $node) {
                if ($node['tag'] !== 'table') {
                    continue;
                }
                $candidate = self::candidate($nodes, $i);
                if ($candidate === null) {
                    continue;
                }
                if ($node['start'] >= $boundary) {
                    $result['quotedCandidateCount']++;
                } elseif ($candidate['end'] > $boundary) {
                    return array_replace($result, ['reason' => 'quote_boundary_crossed']);
                } else {
                    $current[] = $candidate;
                }
            }
            if (count($current) !== 1) {
                return array_replace($result, ['reason' => count($current) > 1 ? 'structure_ambiguous'
                    : ($result['quotedCandidateCount'] > 0 ? 'quoted_only' : 'structure_absent')]);
            }
            $candidate = $current[0];
            if (self::text(substr($html, 0, $candidate['start'])) !== '') {
                return array_replace($result, ['reason' => 'ambiguous_prefix']);
            }
            $candidate['htmlHash'] = hash('sha256', substr($html, $candidate['start'], $candidate['end'] - $candidate['start']));

            return array_replace($result, ['eligible' => true, 'reason' => 'candidate', 'candidate' => $candidate]);
        } catch (Throwable) {
            return array_replace($result, ['reason' => 'html_invalid']);
        }
    }

    private static function candidate(array $nodes, int $root): ?array
    {
        if (! self::dimension($nodes[$root], 'width', 1040) || self::hidden($nodes, $root)) {
            return null;
        }
        $rows = array_keys(array_filter($nodes, static fn (array $node, int $i): bool => $node['tag'] === 'tr'
            && self::nearest($nodes, $i, 'table') === $root, ARRAY_FILTER_USE_BOTH));
        // Only the received four-row combined pilot source is evidenced here.
        // Standalone signatures after free author text remain unmatched.
        if (count($rows) !== 4) {
            return null;
        }
        $author = count($rows) === 4 ? array_shift($rows) : null;
        $cells = static fn (int $row): array => array_keys(array_filter($nodes, static fn (array $n): bool => $n['parent'] === $row
            && in_array($n['tag'], ['td', 'th'], true)));
        $grid = $cells($rows[0]);
        $train = $cells($rows[1]);
        $legal = $cells($rows[2]);
        if (count($grid) !== 4 || count($train) !== 1 || count($legal) !== 1
            || self::span($nodes[$train[0]], 'colspan') !== 3 || self::span($nodes[$legal[0]], 'colspan') !== 4) {
            return null;
        }
        foreach ($grid as $at => $cell) {
            if ($nodes[$cell]['tag'] !== 'td' || ! self::dimension($nodes[$cell], 'width', [260, 280, 213, 287][$at])
                || self::span($nodes[$cell], 'rowspan') !== ($at === 3 ? 2 : 1)
                || self::span($nodes[$cell], 'colspan') !== 1 || self::hidden($nodes, $cell)) {
                return null;
            }
        }
        $authorCell = $author === null ? [] : $cells($author);
        if ($author !== null && (count($authorCell) !== 1 || self::span($nodes[$authorCell[0]], 'colspan') !== 4)) {
            return null;
        }
        $dispatch = 0;
        $images = 0;
        $trainImages = 0;
        foreach ($nodes as $i => $node) {
            if (! self::inside($nodes, $i, $root) || ($author !== null && self::inside($nodes, $i, $author))) {
                continue;
            }
            if ($node['tag'] === 'table' && self::inside($nodes, $i, $grid[3]) && self::dimension($node, 'width', 247) && ! self::hidden($nodes, $i)) {
                $dispatch++;
            }
            if ($node['tag'] === 'img') {
                $images++;
                if (self::inside($nodes, $i, $train[0]) && self::dimension($node, 'width', 500) && self::dimension($node, 'height', 83) && ! self::hidden($nodes, $i)) {
                    $trainImages++;
                }
            }
        }
        if ($dispatch !== 1 || ! (($images >= 8 && $images <= 10) || ($images >= 12 && $images <= 14)) || $trainImages !== 1) {
            return null;
        }

        return ['start' => $nodes[$root]['start'], 'end' => $nodes[$root]['end'],
            'kind' => $author === null ? 'signature' : 'combined', 'imageCount' => $images,
            'authorStart' => $author === null ? null : $nodes[$authorCell[0]]['openEnd'],
            'authorEnd' => $author === null ? null : $nodes[$authorCell[0]]['close']];
    }

    private static function dimension(array $node, string $property, int $expected): bool
    {
        $values = isset($node['attrs'][$property]) ? [$node['attrs'][$property]] : [];
        foreach (explode(';', $node['attrs']['style'] ?? '') as $declaration) {
            [$name, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            if (strtolower(trim($name)) === $property) {
                $values[] = preg_replace('/\s*!important\s*$/i', '', trim($value));
            }
        }
        foreach ($values as $value) {
            if (! preg_match('/\A([0-9]+(?:\.[0-9]+)?|\.[0-9]+)(px|pt|in)?\z/i', $value, $match)
                || abs((float) $match[1] * match (strtolower($match[2] ?? '')) {
                    'pt' => 4 / 3, 'in' => 96, default => 1,
                } - $expected) >= 0.1) {
                return false;
            }
        }

        return $values !== [];
    }

    private static function span(array $node, string $name): int
    {
        return ! isset($node['attrs'][$name]) ? 1 : (ctype_digit($node['attrs'][$name]) ? (int) $node['attrs'][$name] : -1);
    }

    private static function nearest(array $nodes, int $i, string $tag): ?int
    {
        for ($at = $nodes[$i]['parent']; $at !== null; $at = $nodes[$at]['parent']) {
            if ($nodes[$at]['tag'] === $tag) {
                return $at;
            }
        }

        return null;
    }

    private static function inside(array $nodes, int $i, int $root): bool
    {
        for ($at = $i; $at !== null; $at = $nodes[$at]['parent']) {
            if ($at === $root) {
                return true;
            }
        }

        return false;
    }

    private static function hidden(array $nodes, int $i): bool
    {
        for ($at = $i; $at !== null; $at = $nodes[$at]['parent']) {
            if (isset($nodes[$at]['attrs']['hidden']) || preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden|mso-hide\s*:\s*all)(?:\s*!important)?\s*(?:;|$)/i', $nodes[$at]['attrs']['style'] ?? '')) {
                return true;
            }
        }

        return false;
    }

    private static function text(string $html): string
    {
        $html = preg_replace('~<!--.*?-->|<(head|style|script)\b[^>]*>.*?</\1\s*>~is', '', $html);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{feff}", '', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }

    /** Offsets refer to the input bytes; no DOM serialization or mail rewrite. */
    private static function nodes(string $html): array
    {
        preg_match_all('~<!--.*?-->|<(?:style|script)\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</(?:style|script)\s*>|<![^>]*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is', $html, $tokens, PREG_OFFSET_CAPTURE);
        $nodes = $stack = [];
        $cursor = 0;
        foreach ($tokens[0] as [$token, $offset]) {
            if (str_contains(substr($html, $cursor, $offset - $cursor), '<')) {
                throw new RuntimeException('INVALID_HTML');
            }
            $cursor = $offset + strlen($token);
            if (str_starts_with($token, '<!')) {
                continue;
            }
            preg_match('~\A<(\/?)([a-z][a-z0-9:-]*)\b~i', $token, $name);
            $tag = strtolower($name[2]);
            if ($name[1] !== '') {
                $i = array_pop($stack);
                if (! is_int($i) || $nodes[$i]['tag'] !== $tag) {
                    throw new RuntimeException('INVALID_HTML');
                }
                $nodes[$i]['close'] = $offset;
                $nodes[$i]['end'] = $cursor;

                continue;
            }
            preg_match('~\A<[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~i', $token, $opening);
            $attrs = [];
            preg_match_all('~\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', substr($opening[0], strlen($name[0]), -1), $attributes, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
            foreach ($attributes as $attribute) {
                $key = strtolower($attribute[1]);
                if (array_key_exists($key, $attrs)) {
                    throw new RuntimeException('INVALID_HTML');
                }
                $attrs[$key] = html_entity_decode($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (in_array($tag, ['script', 'iframe', 'object', 'embed', 'form', 'input'], true)) {
                throw new RuntimeException('INVALID_HTML');
            }
            $raw = $tag === 'style';
            $leaf = $raw || in_array($tag, ['img', 'br', 'hr', 'meta', 'link', 'col', 'area', 'base', 'param', 'source', 'wbr'], true);
            $nodes[] = ['tag' => $tag, 'attrs' => $attrs, 'parent' => $stack === [] ? null : end($stack),
                'start' => $offset, 'openEnd' => $offset + strlen($opening[0]),
                'close' => $raw ? $offset + strripos($token, '</style') : ($leaf ? $cursor : null), 'end' => $leaf ? $cursor : null];
            if (! $leaf) {
                $stack[] = count($nodes) - 1;
            }
            if (count($nodes) > 10000) {
                throw new RuntimeException('INVALID_HTML');
            }
        }
        if ($stack !== [] || str_contains(substr($html, $cursor), '<')) {
            throw new RuntimeException('INVALID_HTML');
        }

        return $nodes;
    }
}
