<?php

namespace App\Services\Marketing;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Arr;

final class MarketingContentBinder
{
    /** @param array<string, mixed> $content */
    public function bindHtml(string $html, array $content): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div data-rt-binding-root="1">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return $html;
        }

        $xpath = new DOMXPath($document);
        $this->bindScalars($xpath, $content);
        $this->bindLists($document, $xpath, $content);
        $this->bindFacts($document, $xpath, $content);
        $this->bindAttributes($xpath, $content);

        $root = $xpath->query('//*[@data-rt-binding-root="1"]')?->item(0);
        if (! $root instanceof DOMElement) {
            return $html;
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child) ?: '';
        }

        return trim($output);
    }

    /** @param array<string, mixed> $builderData */
    public function syncBuilderData(array $builderData, string $html): array
    {
        if (isset($builderData['pages'][0]) && is_array($builderData['pages'][0])) {
            $builderData['pages'][0]['component'] = $html;
        } elseif (array_key_exists('components', $builderData)) {
            $builderData['components'] = $html;
        } else {
            $builderData['pages'] = [[
                'name' => 'Motiv',
                'component' => $html,
            ]];
        }

        return $builderData;
    }

    /** @param array<string, mixed> $content */
    private function bindScalars(DOMXPath $xpath, array $content): void
    {
        $nodes = $xpath->query('//*[@data-rt-binding]');
        if ($nodes === false) {
            return;
        }

        /** @var DOMElement $node */
        foreach ($nodes as $node) {
            $value = Arr::get($content, $node->getAttribute('data-rt-binding'));
            if (is_scalar($value) || $value === null) {
                $node->textContent = (string) ($value ?? '');
            }
        }
    }

    /** @param array<string, mixed> $content */
    private function bindLists(DOMDocument $document, DOMXPath $xpath, array $content): void
    {
        $nodes = $xpath->query('//*[@data-rt-binding-list]');
        if ($nodes === false) {
            return;
        }

        /** @var DOMElement $node */
        foreach ($nodes as $node) {
            $values = Arr::get($content, $node->getAttribute('data-rt-binding-list'), []);
            if (! is_array($values)) {
                continue;
            }

            while ($node->firstChild) {
                $node->removeChild($node->firstChild);
            }

            foreach (array_slice($values, 0, 12) as $value) {
                if (! is_scalar($value)) {
                    continue;
                }

                $item = $document->createElement('li');
                $item->appendChild($document->createTextNode((string) $value));
                $node->appendChild($item);
            }
        }
    }

    /** @param array<string, mixed> $content */
    private function bindFacts(DOMDocument $document, DOMXPath $xpath, array $content): void
    {
        $nodes = $xpath->query('//*[@data-rt-binding-facts]');
        if ($nodes === false) {
            return;
        }

        /** @var DOMElement $node */
        foreach ($nodes as $node) {
            $facts = Arr::get($content, $node->getAttribute('data-rt-binding-facts'), []);
            if (! is_array($facts)) {
                continue;
            }

            while ($node->firstChild) {
                $node->removeChild($node->firstChild);
            }

            foreach (array_slice($facts, 0, 6) as $fact) {
                if (! is_array($fact)) {
                    continue;
                }

                $wrapper = $document->createElement('div');
                $strong = $document->createElement('strong');
                $strong->appendChild($document->createTextNode((string) ($fact['value'] ?? '')));
                $label = $document->createElement('span');
                $label->appendChild($document->createTextNode((string) ($fact['label'] ?? '')));
                $wrapper->appendChild($strong);
                $wrapper->appendChild($label);
                $node->appendChild($wrapper);
            }
        }
    }

    /** @param array<string, mixed> $content */
    private function bindAttributes(DOMXPath $xpath, array $content): void
    {
        foreach (['href', 'src'] as $attribute) {
            $bindingAttribute = 'data-rt-binding-'.$attribute;
            $nodes = $xpath->query('//*[@'.$bindingAttribute.']');
            if ($nodes === false) {
                continue;
            }

            /** @var DOMElement $node */
            foreach ($nodes as $node) {
                $value = Arr::get($content, $node->getAttribute($bindingAttribute));
                if (is_string($value) && $value !== '') {
                    $node->setAttribute($attribute, $value);
                } else {
                    $node->removeAttribute($attribute);
                }
            }
        }
    }
}
