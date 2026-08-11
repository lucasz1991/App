<?php

namespace App\Services\Marketing;

use App\Support\MarketingBrandAssets;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class MarketingHtmlSanitizer
{
    public function __construct(private readonly MarketingFileSourceService $files) {}

    /** @var list<string> */
    private const FORBIDDEN_ELEMENTS = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'form',
        'input', 'button', 'select', 'option', 'textarea', 'meta', 'link',
        'base', 'style', 'svg', 'math', 'applet', 'audio', 'video', 'source', 'track',
    ];

    /** @var list<string> */
    private const URL_ATTRIBUTES = [
        'href', 'src', 'poster', 'background', 'action', 'formaction', 'data', 'xlink:href',
    ];

    public function html(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div data-rt-sanitizer-root="1">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return '';
        }

        $xpath = new DOMXPath($document);

        foreach (self::FORBIDDEN_ELEMENTS as $tag) {
            $nodes = $xpath->query('//'.$tag);
            if ($nodes === false) {
                continue;
            }

            for ($index = $nodes->length - 1; $index >= 0; $index--) {
                $node = $nodes->item($index);
                $node?->parentNode?->removeChild($node);
            }
        }

        $comments = $xpath->query('//comment()');
        if ($comments !== false) {
            for ($index = $comments->length - 1; $index >= 0; $index--) {
                $comment = $comments->item($index);
                $comment?->parentNode?->removeChild($comment);
            }
        }

        $elements = $xpath->query('//*');
        if ($elements !== false) {
            /** @var DOMElement $element */
            foreach ($elements as $element) {
                $attributes = [];
                foreach ($element->attributes as $attribute) {
                    $attributes[] = [$attribute->name, $attribute->value];
                }

                foreach ($attributes as [$name, $value]) {
                    $normalized = strtolower($name);

                    if (str_starts_with($normalized, 'on')
                        || in_array($normalized, ['srcdoc', 'srcset', 'imagesrcset', 'xmlns', 'form', 'ping'], true)) {
                        $element->removeAttribute($name);

                        continue;
                    }

                    if ($normalized === 'style') {
                        $style = $this->inlineCss($value);
                        if ($style === '') {
                            $element->removeAttribute($name);
                        } else {
                            $element->setAttribute($name, $style);
                        }

                        continue;
                    }

                    if (in_array($normalized, self::URL_ATTRIBUTES, true)
                        && ! $this->isSafeUrl(
                            $value,
                            in_array($normalized, ['src', 'poster', 'background', 'data'], true),
                        )) {
                        $element->removeAttribute($name);
                    }
                }
            }
        }

        $root = $xpath->query('//*[@data-rt-sanitizer-root="1"]')?->item(0);
        if (! $root instanceof DOMElement) {
            return '';
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child) ?: '';
        }

        return trim($output);
    }

    public function css(string $css): string
    {
        $css = $this->decodeCssEscapes($css);
        $css = preg_replace('#/\*.*?\*/#su', '', $css) ?? '';
        $css = preg_replace('/<\/style/iu', '', $css) ?? '';
        $css = str_replace(['<!--', '-->'], '', $css);
        $css = preg_replace('/@(?:import|charset|namespace)\b[^;]*(?:;|$)/iu', '', $css) ?? '';
        $css = preg_replace('/expression\s*\([^)]*\)/iu', '', $css) ?? '';
        $css = preg_replace('/(?:-moz-binding|behavior)\s*:[^;}]+[;}]/iu', '', $css) ?? '';
        // CSS image functions may load a quoted remote URL without url(...),
        // e.g. image-set("https://tracker.test/pixel.png" 1x). V1 only
        // accepts the explicitly validated url(...) contract below, so every
        // declaration using another image-producing function fails closed.
        $css = preg_replace_callback(
            '/(?<prefix>(?:^|[;{])\s*(?:--[a-z0-9_-]+|[a-z-]+)\s*:)(?<value>[^;{}]*)(?<end>;|(?=})|$)/iu',
            static function (array $match): string {
                if (preg_match('/(?:^|[^a-z-])(?:(?:-webkit-)?image-set|cross-fade|image)\s*\(/iu', (string) $match['value'])) {
                    return (string) $match['prefix'].' none'.(string) ($match['end'] ?? '');
                }

                return (string) $match[0];
            },
            $css,
        ) ?? '';
        $css = preg_replace_callback('/url\s*\(\s*(["\']?)(.*?)\1\s*\)/iu', function (array $match): string {
            $rawUrl = html_entity_decode(trim($match[2]), ENT_QUOTES | ENT_HTML5);
            if (str_contains($rawUrl, '\\')) {
                return 'none';
            }
            $url = $this->decodeCssEscapes($rawUrl);

            return $this->isSafeUrl($url, true)
                ? 'url("'.str_replace(['"', "\r", "\n"], ['\\"', '', ''], trim($match[2])).'")'
                : 'none';
        }, $css) ?? '';
        $css = preg_replace('/url\s*\(\s*[^)]*(?=[;}]|$)/iu', 'none', $css) ?? $css;

        return trim($css);
    }

    private function inlineCss(string $css): string
    {
        $css = $this->css($css);
        $declarations = [];

        foreach (explode(';', $css) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            if ($property === '' || $value === '' || ! preg_match('/^(?:--[a-z0-9_-]+|[a-z-]+)$/i', $property)) {
                continue;
            }

            $declarations[] = $property.': '.$value;
        }

        return implode('; ', $declarations).($declarations === [] ? '' : ';');
    }

    private function isSafeUrl(string $url, bool $resource): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if ($url === '') {
            return true;
        }

        $decoded = $url;
        for ($round = 0; $round < 2; $round++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        $collapsed = (string) preg_replace('/[\x00-\x20\x7f]+/u', '', $decoded);
        $normalized = strtolower($collapsed);
        if (str_contains($collapsed, '\\')
            || str_starts_with($normalized, '//')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $normalized)) {
            return false;
        }

        if ($resource) {
            return $this->isSafeResourceUrl($collapsed);
        }

        if (str_starts_with($normalized, '#')
            || str_starts_with($normalized, '/')
            || str_starts_with($normalized, './')) {
            return true;
        }

        if (! preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized)) {
            return true;
        }

        $scheme = strtolower((string) parse_url($collapsed, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }

    private function isSafeResourceUrl(string $url): bool
    {
        if (str_starts_with(strtolower($url), 'data:')) {
            return $this->isSafeInlineImage($url);
        }

        $path = $url;
        $query = '';
        if (preg_match('/^https?:/i', $url)) {
            $candidate = parse_url($url);
            $application = parse_url((string) config('app.url'));
            if (! is_array($candidate)
                || ! is_array($application)
                || strtolower((string) ($candidate['host'] ?? '')) !== strtolower((string) ($application['host'] ?? ''))
                || strtolower((string) ($candidate['scheme'] ?? '')) !== strtolower((string) ($application['scheme'] ?? ''))
                || (int) ($candidate['port'] ?? 0) !== (int) ($application['port'] ?? 0)
                || isset($candidate['user'])
                || isset($candidate['pass'])
                || isset($candidate['fragment'])) {
                return false;
            }

            $path = (string) ($candidate['path'] ?? '');
            $query = (string) ($candidate['query'] ?? '');
        } else {
            $candidate = parse_url($url);
            if (! is_array($candidate)
                || isset($candidate['scheme'])
                || isset($candidate['host'])
                || isset($candidate['user'])
                || isset($candidate['pass'])
                || isset($candidate['fragment'])) {
                return false;
            }

            $path = (string) ($candidate['path'] ?? '');
            $query = (string) ($candidate['query'] ?? '');
        }

        if (MarketingBrandAssets::allows($path)) {
            return $query === '' || (bool) preg_match('/^v=[a-f0-9]{8,64}$/i', $query);
        }

        return $this->files->urlIsAllowed($url);
    }

    private function isSafeInlineImage(string $url): bool
    {
        if (! preg_match('#^data:(image/(?:png|jpeg|jpg|gif|webp));base64,([a-z0-9+/=]+)$#i', $url, $matches)) {
            return false;
        }

        $contents = base64_decode($matches[2], true);
        $maxBytes = max(1, (int) config('marketing.assets.max_kilobytes', 8192)) * 1024;
        if (! is_string($contents) || $contents === '' || strlen($contents) > $maxBytes) {
            return false;
        }

        $dimensions = @getimagesizefromstring($contents);
        if (! is_array($dimensions) || ! isset($dimensions[0], $dimensions[1], $dimensions['mime'])) {
            return false;
        }

        $declaredMime = strtolower($matches[1]) === 'image/jpg' ? 'image/jpeg' : strtolower($matches[1]);
        $actualMime = strtolower((string) $dimensions['mime']);
        $allowedMimeTypes = config('marketing.assets.allowed_mime_types', [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        ]);
        if ($declaredMime !== $actualMime
            || ! is_array($allowedMimeTypes)
            || ! in_array($actualMime, $allowedMimeTypes, true)) {
            return false;
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        $maxPixels = max(1, (int) config('marketing.assets.max_pixels', 40_000_000));

        return $width > 0 && $height > 0 && $width <= intdiv($maxPixels, $height);
    }

    private function decodeCssEscapes(string $value): string
    {
        $value = preg_replace_callback('/\\\\([0-9a-f]{1,6})(?:\s)?/iu', static function (array $match): string {
            $codepoint = hexdec($match[1]);

            return $codepoint > 0 && $codepoint <= 0x10FFFF
                ? mb_chr($codepoint, 'UTF-8')
                : '';
        }, $value) ?? $value;

        return preg_replace('/\\\\(.)/su', '$1', $value) ?? $value;
    }
}
