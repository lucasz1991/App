<?php

declare(strict_types=1);

namespace App\Support\Mail;

use RuntimeException;
use Throwable;

/**
 * Inactive, pure HTML part of the IT-only Stage-2 pilot.
 *
 * The caller must establish connector/TLS, tenant, authenticated envelope sender,
 * MIME safety and publication/version admission before invoking this class. The
 * projection must be the admitted B1 send result, not message-supplied JSON.
 * This class does not decode MIME/TNEF, contact a mailbox or authorize transport.
 */
final class OutlookUniversalTransitTransformer
{
    private const PILOT_SENDER = 'it-technik@rail-time.de';

    // Admit the C-K21 B1 send projection; older published template pins fail closed.
    private const TEMPLATE_HASH = '942f8bf2e36712fb616d5acd3096d642baf3731c7952f72cadd1a474796600ba';

    private const DESIGN_HASH = 'c7efab2c64890dc57386448be880122e1e284c6c35a2392fcad5ed5094f94b6b';

    /**
     * Every declined operation returns the original input bytes.
     *
     * @return array{html:string,changed:bool,reason:string,version:?string,scopeId:?string,headCss:string,preservation:array}
     */
    public function transform(string $html, array $headers, array $publishedSendProjection,
        bool $trustedIngress = false, string $envelopeSender = ''): array
    {
        $result = ['html' => $html, 'changed' => false, 'reason' => 'untrusted_ingress',
            'version' => null, 'scopeId' => null, 'headCss' => '', 'preservation' => []];
        if (! $trustedIngress) {
            return $result;
        }
        if ($envelopeSender !== self::PILOT_SENDER) {
            return array_replace($result, ['reason' => 'sender_not_admitted']);
        }
        $incoming = OutlookUniversalTransitCandidate::inspect($html, $headers, true);
        $result['version'] = $incoming['version'];
        if (! $incoming['eligible']) {
            return array_replace($result, ['reason' => $incoming['reason']]);
        }
        try {
            [$css, $source, $sourceNodes, $sourceRoot, $templateNodes, $template] = self::projection($publishedSendProjection);
            if ($publishedSendProjection['version'] !== $incoming['version']) {
                return array_replace($result, ['reason' => 'version_not_admitted']);
            }
            $nodes = self::nodes($html);
            $candidate = $incoming['candidate'];
            $root = self::nodeAt($nodes, $candidate['start'], 'table');
            foreach (preg_split('/\s+/', $nodes[$root]['attrs']['class'] ?? '', -1, PREG_SPLIT_NO_EMPTY) as $class) {
                if (preg_match('/\Art-transit-[0-9a-f]{32}\z/', $class)) {
                    return array_replace($result, ['reason' => 'already_transformed']);
                }
            }
            $heads = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'head'));
            $bodies = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'body'));
            $documents = array_keys(array_filter($nodes, static fn (array $node): bool => $node['tag'] === 'html'));
            if (count($heads) !== 1 || $nodes[$heads[0]]['end'] > $candidate['start']
                || count($bodies) !== 1 || count($documents) !== 1
                || $nodes[$heads[0]]['parent'] !== $documents[0]
                || $nodes[$bodies[0]]['parent'] !== $documents[0]
                || $nodes[$heads[0]]['end'] > $nodes[$bodies[0]]['start']
                || self::ancestor($nodes, $root, 'body') !== $bodies[0]) {
                return array_replace($result, ['reason' => 'head_unavailable']);
            }
            $head = $heads[0];
            $metas = self::missingMetas($nodes, $head, $templateNodes, $template);
            $author = substr($html, $candidate['authorStart'], $candidate['authorEnd'] - $candidate['authorStart']);
            $scope = self::scope($html, $incoming['version']);
            $scopedCss = self::scopedCss($css, $scope);
            $sourceCandidate = $source['candidate'];
            $body = $publishedSendProjection['bodyHtml'];
            // All template edits precede substitution of opaque author bytes.
            $opening = substr($body, $sourceNodes[$sourceRoot]['start'], $sourceNodes[$sourceRoot]['openEnd'] - $sourceNodes[$sourceRoot]['start']);
            $scopedOpening = str_replace('class="rt-shell"', 'class="rt-shell '.$scope.'"', $opening, $count);
            if ($count !== 1) {
                throw new RuntimeException('projection_invalid');
            }
            $replacement = substr($body, $sourceCandidate['start'], $sourceCandidate['authorStart'] - $sourceCandidate['start'])
                .$author.substr($body, $sourceCandidate['authorEnd'], $sourceCandidate['end'] - $sourceCandidate['authorEnd']);
            $replacement = $scopedOpening.substr($replacement, strlen($opening));
            $addition = "\n".$metas.'<style type="text/css" data-rt-transit-style="'.$scope.'">'."\n".$scopedCss."\n</style>\n";
            $prefix = substr($html, 0, $candidate['start']);
            $suffix = substr($html, $candidate['end']);
            $headClose = $nodes[$head]['close'];
            $output = substr($prefix, 0, $headClose).$addition.substr($prefix, $headClose).$replacement.$suffix;
            if (strlen($output) > 1500000) {
                throw new RuntimeException('output_too_large');
            }

            return array_replace($result, ['html' => $output, 'changed' => true, 'reason' => 'transformed',
                'scopeId' => $scope, 'headCss' => $scopedCss, 'preservation' => [
                    'authorSha256' => hash('sha256', $author),
                    'bodyPrefixSha256' => hash('sha256', substr($html, $nodes[$head]['end'], $candidate['start'] - $nodes[$head]['end'])),
                    'suffixSha256' => hash('sha256', $suffix),
                    'authorPreserved' => true, 'bodyPrefixPreserved' => true, 'suffixPreserved' => true,
                ]]);
        } catch (Throwable $error) {
            $reason = $error instanceof RuntimeException && in_array($error->getMessage(), [
                'projection_invalid', 'meta_conflict', 'head_invalid', 'output_too_large',
            ], true) ? $error->getMessage() : 'html_invalid';

            return array_replace($result, ['reason' => $reason]);
        }
    }

    /** Validate the immutable renderer basis; publication admission remains the caller's duty. */
    private static function projection(array $projection): array
    {
        foreach (['bodyHtml', 'html', 'headCss', 'headCssHash', 'templateHash', 'designSourceHash', 'sourceHash', 'version', 'marker', 'id', 'header', 'mode'] as $key) {
            if (! isset($projection[$key]) || ! is_string($projection[$key])) {
                throw new RuntimeException('projection_invalid');
            }
        }
        if ($projection['mode'] !== 'send' || $projection['templateHash'] !== self::TEMPLATE_HASH
            || $projection['designSourceHash'] !== self::DESIGN_HASH
            || ! preg_match('/\A[0-9a-f]{64}\z/', $projection['sourceHash'])
            || ! preg_match('/\A[0-9a-f]{24}\z/', $projection['version'])
            || $projection['marker'] !== 'rt-sig-u2-'.$projection['version'] || $projection['id'] !== $projection['marker']
            || $projection['header'] !== 'u2e-'.$projection['version']
            || ($projection['headers']['X-RailTime-Template'] ?? null) !== $projection['header']
            || $projection['bodyHtml'] === '' || strlen($projection['bodyHtml']) > 500000
            || strlen($projection['html']) > 600000 || str_contains($projection['html'], "\0")
            || ! mb_check_encoding($projection['html'], 'UTF-8')
            || substr_count($projection['html'], $projection['bodyHtml']) !== 1) {
            throw new RuntimeException('projection_invalid');
        }
        $template = file_get_contents(dirname(__DIR__, 3).'/resources/mail-templates/u2e-universal-v1.html');
        if (! is_string($template)) {
            throw new RuntimeException('projection_invalid');
        }
        $template = str_replace("\r\n", "\n", $template);
        if (! hash_equals(self::TEMPLATE_HASH, hash('sha256', $template))
            || ! preg_match('~<style type="text/css">(.*?)</style>~s', $template, $match)
            || ! hash_equals($match[1], $projection['headCss'])
            || ! hash_equals(hash('sha256', $match[1]), $projection['headCssHash'])) {
            throw new RuntimeException('projection_invalid');
        }
        $source = OutlookUniversalTransitCandidate::inspect($projection['bodyHtml'], $projection['headers'], true);
        if (! $source['eligible']) {
            throw new RuntimeException('projection_invalid');
        }
        $sourceNodes = self::nodes($projection['bodyHtml']);
        $root = self::nodeAt($sourceNodes, $source['candidate']['start'], 'table');
        if (($sourceNodes[$root]['attrs']['id'] ?? '') !== $projection['marker']
            || ($sourceNodes[$root]['attrs']['class'] ?? '') !== 'rt-shell') {
            throw new RuntimeException('projection_invalid');
        }

        return [$match[1], $source, $sourceNodes, $root, self::nodes($template), $template];
    }

    /** Additional HEAD bytes only: retain every existing declaration, refuse conflicts. */
    private static function missingMetas(array $nodes, int $head, array $templateNodes, string $template): string
    {
        $expected = [];
        foreach ($templateNodes as $node) {
            if ($node['tag'] === 'meta') {
                $expected[self::metaKey($node['attrs'])] = $node;
            }
        }
        $seen = [];
        foreach ($nodes as $i => $node) {
            if ($node['tag'] !== 'meta') {
                continue;
            }
            if (self::ancestor($nodes, $i, 'head') !== $head) {
                throw new RuntimeException('head_invalid');
            }
            $key = self::metaKey($node['attrs']);
            // A contradictory legacy charset cannot coexist safely with UTF-8.
            if ($key === 'http-equiv:content-type' && ! preg_match('/\Atext\/html\s*;\s*charset\s*=\s*utf-8\z/i', trim($node['attrs']['content'] ?? ''))) {
                throw new RuntimeException('meta_conflict');
            }
            if (! isset($expected[$key])) {
                continue;
            }
            if (isset($seen[$key]) || self::metaValue($node['attrs']) !== self::metaValue($expected[$key]['attrs'])) {
                throw new RuntimeException('meta_conflict');
            }
            $seen[$key] = true;
        }
        $addition = '';
        foreach ($expected as $key => $node) {
            if (! isset($seen[$key])) {
                $addition .= substr($template, $node['start'], $node['end'] - $node['start'])."\n";
            }
        }

        return $addition;
    }

    private static function metaKey(array $attrs): string
    {
        if (array_key_exists('charset', $attrs)) {
            return 'charset';
        }
        foreach (['name', 'http-equiv'] as $kind) {
            if (isset($attrs[$kind])) {
                return $kind.':'.strtolower($attrs[$kind]);
            }
        }

        return 'other';
    }

    private static function metaValue(array $attrs): string
    {
        return array_key_exists('charset', $attrs) ? strtolower($attrs['charset']) : ($attrs['content'] ?? '');
    }

    private static function scope(string $html, string $version): string
    {
        for ($salt = 0; $salt < 100; $salt++) {
            $scope = 'rt-transit-'.substr(hash('sha256', "u2e-transit-v1\0".$version."\0".$salt."\0".$html), 0, 32);
            if (! str_contains($html, $scope)) {
                return $scope;
            }
        }

        throw new RuntimeException('html_invalid');
    }

    /** Pinned CSS has three media blocks and flat, class-led rule groups. */
    private static function scopedCss(string $css, string $scope): string
    {
        return (string) preg_replace_callback('~(?<=[{}])([\s]*(?:/\*.*?\*/\s*)?)(\.[^{}]+)(?=\{)~s', static function (array $match) use ($scope): string {
            $selectors = explode(',', trim($match[2]));
            foreach ($selectors as &$selector) {
                $selector = trim($selector);
                $selector = str_starts_with($selector, '.rt-shell') ? '.'.$scope.$selector : '.'.$scope.' '.$selector;
            }

            return $match[1].implode(', ', $selectors).' ';
        }, $css);
    }

    private static function nodeAt(array $nodes, int $offset, string $tag): int
    {
        foreach ($nodes as $i => $node) {
            if ($node['start'] === $offset && $node['tag'] === $tag) {
                return $i;
            }
        }

        throw new RuntimeException('html_invalid');
    }

    private static function ancestor(array $nodes, int $index, string $tag): ?int
    {
        for ($at = $nodes[$index]['parent']; $at !== null; $at = $nodes[$at]['parent']) {
            if ($nodes[$at]['tag'] === $tag) {
                return $at;
            }
        }

        return null;
    }

    /** Bounded byte-offset lexer; never serializes the incoming document. */
    private static function nodes(string $html): array
    {
        preg_match_all('~<!--.*?-->|<style\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>.*?</style\s*>|<![^>]*>|</?[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~is', $html, $tokens, PREG_OFFSET_CAPTURE);
        $nodes = $stack = [];
        $cursor = 0;
        foreach ($tokens[0] as [$token, $offset]) {
            if (str_contains(substr($html, $cursor, $offset - $cursor), '<')) {
                throw new RuntimeException('html_invalid');
            }
            $cursor = $offset + strlen($token);
            if (str_starts_with($token, '<!')) {
                continue;
            }
            preg_match('~\A<(/?)([a-z][a-z0-9:-]*)\b~i', $token, $name);
            $tag = strtolower($name[2]);
            if ($name[1] !== '') {
                $i = array_pop($stack);
                if (! is_int($i) || $nodes[$i]['tag'] !== $tag || ! preg_match('~\A</[a-z][a-z0-9:-]*\s*>\z~i', $token)) {
                    throw new RuntimeException('html_invalid');
                }
                $nodes[$i]['close'] = $offset;
                $nodes[$i]['end'] = $cursor;

                continue;
            }
            preg_match('~\A<[a-z][a-z0-9:-]*(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~i', $token, $opening);
            $remaining = substr($opening[0], strlen($name[0]), -1);
            $attrs = [];
            while (trim($remaining) !== '' && trim($remaining) !== '/') {
                if (! preg_match('~\A\s+([a-z_:][a-z0-9_:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i', $remaining, $attr, PREG_UNMATCHED_AS_NULL)) {
                    throw new RuntimeException('html_invalid');
                }
                $key = strtolower($attr[1]);
                if (array_key_exists($key, $attrs)) {
                    throw new RuntimeException('html_invalid');
                }
                $attrs[$key] = html_entity_decode($attr[2] ?? $attr[3] ?? $attr[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $remaining = substr($remaining, strlen($attr[0]));
            }
            if (in_array($tag, ['script', 'iframe', 'object', 'embed', 'form', 'input', 'base'], true)) {
                throw new RuntimeException('html_invalid');
            }
            $raw = $tag === 'style';
            $leaf = $raw || in_array($tag, ['img', 'br', 'hr', 'meta', 'link', 'col', 'area', 'param', 'source', 'wbr'], true);
            $nodes[] = ['tag' => $tag, 'attrs' => $attrs, 'parent' => $stack === [] ? null : end($stack),
                'start' => $offset, 'openEnd' => $offset + strlen($opening[0]),
                'close' => $raw ? $offset + strripos($token, '</style') : ($leaf ? $cursor : null), 'end' => $leaf ? $cursor : null];
            if (! $leaf) {
                $stack[] = count($nodes) - 1;
            }
            if (count($nodes) > 10000) {
                throw new RuntimeException('html_invalid');
            }
        }
        if ($stack !== [] || str_contains(substr($html, $cursor), '<')) {
            throw new RuntimeException('html_invalid');
        }

        return $nodes;
    }
}
