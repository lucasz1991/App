<?php

namespace App\Services\Marketing;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class MarketingHtmlSanitizer
{
    /** @var list<string> */
    private const FORBIDDEN_ELEMENTS = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'form',
        'input', 'button', 'select', 'option', 'textarea', 'meta', 'link',
        'base', 'svg', 'math', 'applet', 'audio', 'video', 'source', 'track',
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
                        || in_array($normalized, ['srcdoc', 'xmlns', 'form', 'ping'], true)) {
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
                        && ! $this->isSafeUrl($value, $normalized === 'src')) {
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
        $css = preg_replace('/<\/style/iu', '', $css) ?? '';
        $css = str_replace(['<!--', '-->'], '', $css);
        $css = preg_replace('/@(?:import|charset|namespace)\b[^;]*(?:;|$)/iu', '', $css) ?? '';
        $css = preg_replace('/expression\s*\([^)]*\)/iu', '', $css) ?? '';
        $css = preg_replace('/(?:-moz-binding|behavior)\s*:[^;}]+[;}]/iu', '', $css) ?? '';
        $css = preg_replace_callback('/url\s*\(\s*(["\']?)(.*?)\1\s*\)/iu', function (array $match): string {
            return $this->isSafeUrl(html_entity_decode(trim($match[2]), ENT_QUOTES | ENT_HTML5), true)
                ? 'url("'.str_replace(['"', "\r", "\n"], ['\\"', '', ''], trim($match[2])).'")'
                : 'none';
        }, $css) ?? '';

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

    private function isSafeUrl(string $url, bool $allowImageData): bool
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

        $collapsed = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/u', '', $decoded));
        if ($allowImageData && preg_match('#^data:image/(?:png|jpeg|jpg|gif|webp);base64,[a-z0-9+/=]+$#i', $collapsed)) {
            return true;
        }

        if (str_starts_with($collapsed, '#')
            || str_starts_with($collapsed, '/')
            || str_starts_with($collapsed, './')
            || str_starts_with($collapsed, '../')) {
            return true;
        }

        if (! preg_match('/^[a-z][a-z0-9+.-]*:/i', $collapsed)) {
            return true;
        }

        $scheme = strtolower((string) parse_url($collapsed, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }
}
