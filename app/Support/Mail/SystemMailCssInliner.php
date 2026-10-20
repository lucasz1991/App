<?php

declare(strict_types=1);

namespace App\Support\Mail;

use DOMDocument;
use DOMElement;
use DOMXPath;
use TijsVerkoyen\CssToInlineStyles\Css\Property\Property;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/** Only the compiled system-mail projection gets responsive inline fallbacks. */
final class SystemMailCssInliner extends CssToInlineStyles
{
    private bool $systemMail = false;

    public function convert($html, $css = null)
    {
        $this->systemMail = substr_count($html, SystemMailInlineImageEmbedder::RUNTIME_ATTRIBUTE) === 1
            && str_contains($html, '<!-- RT_TEMPLATE_MARK_START -->')
            && str_contains($html, '<!-- RT_TEMPLATE_MARK_END -->')
            && str_contains($html, TrustedEmailCss::RUNTIME_MARKER);

        try {
            if ($this->systemMail) {
                // Mail clients may discard data-* attributes. These selectors
                // must still match the actual delivery rows after that cleanup.
                $html = preg_replace_callback('~<style\b[^>]*>(.*?)</style>~is', static function (array $match): string {
                    return str_replace(
                        array_map(static fn (string $version): string => 'tr[data-rt-artifact-version="'.$version.'"]', ['v27', 'v28', 'v29']),
                        array_map(static fn (string $version): string => 'tr.rt-system-signature-'.$version, ['v27', 'v28', 'v29']),
                        $match[0],
                    );
                }, $html) ?? $html;
            }

            return parent::convert($html, $css);
        } finally {
            $this->systemMail = false;
        }
    }

    protected function createDomDocumentFromHtml($html): DOMDocument
    {
        $document = parent::createDomDocumentFromHtml($html);
        if ($this->systemMail) {
            foreach ((new DOMXPath($document))->query('//tr[@data-rt-artifact-version]') as $row) {
                $version = $row->getAttribute('data-rt-artifact-version');
                if (in_array($version, ['v27', 'v28', 'v29'], true)) {
                    $class = 'rt-system-signature-'.$version;
                    $classes = preg_split('/\s+/', trim($row->getAttribute('class'))) ?: [];
                    if (! in_array($class, $classes, true)) {
                        $row->setAttribute('class', trim($row->getAttribute('class').' '.$class));
                    }
                }
            }
        }

        return $document;
    }

    public function inlineCssOnElement(DOMElement $element, array $properties)
    {
        if ($this->systemMail) {
            // Specificity has already been resolved by the inliner. Generated
            // desktop fallbacks must not outrank the retained mobile queries.
            // Parent keeps explicitly authored inline declarations untouched.
            $properties = array_map(static fn (Property $property): Property => new Property(
                $property->getName(),
                preg_replace('/\s*!\s*important\s*$/i', '', $property->getValue()) ?? $property->getValue(),
                $property->getOriginalSpecificity(),
            ), $properties);
        }

        return parent::inlineCssOnElement($element, $properties);
    }
}
