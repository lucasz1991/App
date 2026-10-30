<?php

declare(strict_types=1);

namespace App\Support\Mail;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/** Only the factory's empty, application-owned writing paragraph is eligible. */
final class OutlookOptionalTemplateWording
{
    public const REVISION = 'optional-template-wording-v1';

    public static function eligible(string $html): bool
    {
        return self::anchor($html, source: true) !== null;
    }

    public static function render(string $html, ?array $preferences, string $name): string
    {
        $greeting = $preferences['showGreeting'] ?? false;
        $farewell = $preferences['showFarewell'] ?? false;
        if (! is_bool($greeting) || ! is_bool($farewell)) {
            throw new RuntimeException('Die Anzeige der persönlichen Vorlagentexte ist ungültig.');
        }
        if (! $greeting && ! $farewell) {
            return $html;
        }
        $anchor = self::anchor($html);
        if ($anchor === null) {
            return $html;
        }
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $style = 'margin:0 0 20px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:26px;color:inherit;'.OutlookTemplateWording::WRAP_DECLARATIONS;
        $before = $greeting ? '<p class="design-salutation" style="'.$style.'font-weight:700;">'.$escape($preferences['greeting'] ?? OutlookTemplateWording::DEFAULT_GREETING).'</p>' : '';
        $after = $farewell ? '<p class="design-greeting" style="'.$style.'margin-top:20px;">'.$escape($preferences['farewell'] ?? OutlookTemplateWording::DEFAULT_FAREWELL).($name !== '' ? '<br>'.$escape($name) : '').'</p>' : '';

        return substr_replace($html, $before.$anchor[0].$after, $anchor[1], strlen($anchor[0]));
    }

    /** @return array{string,int}|null */
    private static function anchor(string $html, bool $source = false): ?array
    {
        if (OutlookTemplateWording::requested($html)
            || substr_count($html, '<!-- RT_APPLICATION_CONTENT_START -->') !== 1
            || substr_count($html, '<!-- RT_APPLICATION_CONTENT_END -->') !== 1
            || preg_match('~class\s*=\s*["\'][^"\']*\bdesign-(?:salutation|greeting)\b~i', $html)) {
            return null;
        }
        if ($source) {
            $html = str_replace('{{APPLICATION_CONTENT}}', '', $html);
        }
        preg_match_all('~<p\b[^>]*\bclass=["\'][^"\']*\bdesign-spacer\b[^"\']*["\'][^>]*>(?:\s|&nbsp;|&#160;|<br\s*/?>)*</p\s*>~i', $html, $matches, PREG_OFFSET_CAPTURE);
        if (count($matches[0]) !== 1) {
            return null;
        }
        $anchor = $matches[0][0];
        if ($anchor[1] <= strpos($html, '<!-- RT_APPLICATION_CONTENT_START -->')
            || $anchor[1] >= strpos($html, '<!-- RT_APPLICATION_CONTENT_END -->')) {
            return null;
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $nodes = $loaded ? (new DOMXPath($document))->query('//p[contains(concat(" ",normalize-space(@class)," ")," design-spacer ")]') : false;
        if ($nodes === false || $nodes->length !== 1) {
            return null;
        }
        $node = $nodes->item(0);
        if (! $node?->parentNode instanceof DOMElement || strtolower($node->parentNode->tagName) !== 'td') {
            return null;
        }
        for ($ancestor = $node; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
            if (in_array(strtolower($ancestor->tagName), ['blockquote', 'template', 'script'], true)
                || $ancestor->hasAttribute('hidden') || $ancestor->getAttribute('aria-hidden') === 'true'
                || preg_match('/(?:display\s*:\s*none|visibility\s*:\s*hidden)/i', $ancestor->getAttribute('style'))) {
                return null;
            }
        }

        return $anchor;
    }
}
