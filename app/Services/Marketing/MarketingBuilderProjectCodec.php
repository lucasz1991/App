<?php

namespace App\Services\Marketing;

use App\Support\MarketingBrandAssets;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;

/**
 * Validates the persistable, script-free GrapesJS project projection used by
 * the marketing builder and binds it to the canonical server HTML.
 *
 * GrapesJS V2 projects keep their structured component tree. Replacing that
 * tree with an HTML string would discard stable component IDs and make future
 * edits lossy. The canonical HTML is therefore bound through a SHA-256 value
 * in the narrow RailTime metadata contract. Only the legacy one-page string
 * representation is synchronised directly.
 */
final class MarketingBuilderProjectCodec
{
    public const CODEC_VERSION = 2;

    public const MODE = 'marketing';

    private const DEFAULT_MAX_ASSET_BYTES = 8 * 1024 * 1024;

    private const DEFAULT_MAX_ASSET_PIXELS = 40_000_000;

    private const MAX_COMPONENTS = 10_000;

    private const MAX_COMPONENT_DEPTH = 64;

    /** @var list<string> */
    private const ROOT_KEYS = [
        'dataSources', 'assets', 'styles', 'pages', 'symbols', 'railtime',
    ];

    /** @var list<string> */
    private const PAGE_KEYS = [
        'id', 'name', 'type', 'frames', 'component', 'styles',
    ];

    /** @var list<string> */
    private const FRAME_KEYS = [
        'id', 'name', 'component', 'styles',
    ];

    /** @var list<string> */
    private const COMPONENT_KEYS = [
        'type', 'id', 'tagName', 'name', 'attributes', 'style', 'classes',
        'components', 'content', 'void', 'draggable', 'droppable', 'stylable',
        'unstylable', 'highlightable', 'copyable', 'removable', 'editable',
        'selectable', 'hoverable', 'layerable', 'badgable', 'textable',
        'locked', 'propagate', 'src', 'href', 'head', 'docEl',
    ];

    /** @var list<string> */
    private const STYLE_RULE_KEYS = [
        'id', 'selectors', 'selectorsAdd', 'style', 'state', 'mediaText',
        'atRuleType', 'singleAtRule', 'important',
    ];

    /** @var list<string> */
    private const SELECTOR_KEYS = [
        'id', 'name', 'label', 'type', 'active', 'private', 'protected',
    ];

    /** @var list<string> */
    private const ASSET_KEYS = [
        'id', 'type', 'src', 'name', 'width', 'height', 'category', 'mime',
        'mime_type', 'content_type', 'animated', 'fallback_source',
        'fallback_src', 'fallbackSource', 'fallback_label', 'fallbackLabel',
        'bytes', 'file_size', 'size_bytes', 'metadata',
    ];

    /** @var list<string> */
    private const ASSET_METADATA_KEYS = [
        'name', 'width', 'height', 'category', 'mime', 'mime_type',
        'content_type', 'animated', 'fallback_source', 'fallback_src',
        'fallbackSource', 'fallback_label', 'fallbackLabel', 'bytes',
        'file_size', 'size_bytes',
    ];

    /** @var list<string> */
    private const RAILTIME_KEYS = [
        'template', 'format', 'schema', 'design_preset', 'mode',
        'codec_version', 'source_html_sha256',
    ];

    /** @var list<string> */
    private const SAFE_COMPONENT_TYPES = [
        'wrapper', 'default', 'text', 'textnode', 'comment', 'image', 'link',
        'label', 'table', 'thead', 'tbody', 'tfoot', 'row', 'cell',
    ];

    /** @var list<string> */
    private const SAFE_TAGS = [
        'a', 'abbr', 'address', 'article', 'aside', 'b', 'bdi', 'bdo',
        'blockquote', 'br', 'caption', 'cite', 'code', 'col', 'colgroup',
        'data', 'dd', 'del', 'details', 'dfn', 'div', 'dl', 'dt', 'em',
        'figcaption', 'figure', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5',
        'h6', 'header', 'hgroup', 'hr', 'i', 'img', 'ins', 'kbd', 'li',
        'main', 'mark', 'menu', 'nav', 'ol', 'p', 'picture', 'pre', 'q',
        'rp', 'rt', 'ruby', 's', 'samp', 'section', 'small', 'span',
        'strong', 'sub', 'summary', 'sup', 'table', 'tbody', 'td', 'tfoot',
        'th', 'thead', 'time', 'tr', 'u', 'ul', 'var', 'wbr',
    ];

    /** @var list<string> */
    private const FORBIDDEN_HTML_TAGS = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'form',
        'input', 'button', 'select', 'option', 'textarea', 'meta', 'link',
        'base', 'style', 'svg', 'math', 'applet', 'audio', 'video', 'source',
        'track',
    ];

    /** @var list<string> */
    private const RESOURCE_ATTRIBUTES = [
        'src', 'poster', 'background', 'data',
    ];

    /** @var list<string> */
    private const NAVIGATION_ATTRIBUTES = [
        'href', 'action', 'formaction', 'xlink:href',
    ];

    /** @var list<string> */
    private const ALLOWED_IMAGE_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    ];

    private readonly int $maxAssetBytes;

    private readonly int $maxAssetPixels;

    public function __construct(?int $maxAssetBytes = null, ?int $maxAssetPixels = null)
    {
        $this->maxAssetBytes = max(1, $maxAssetBytes ?? $this->configuredAssetBytes());
        $this->maxAssetPixels = max(1, $maxAssetPixels ?? $this->configuredAssetPixels());
    }

    /**
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function decodeAndSynchronize(array $project, ?string $canonicalHtml = null): array
    {
        $rootPath = 'builder_data';
        $this->assertObject($project, $rootPath);
        $this->assertKnownKeys($project, self::ROOT_KEYS, $rootPath);

        foreach (['dataSources', 'symbols'] as $runtimeCollection) {
            if (! array_key_exists($runtimeCollection, $project)) {
                continue;
            }

            $this->assertList($project[$runtimeCollection], $rootPath.'.'.$runtimeCollection);
            if ($project[$runtimeCollection] !== []) {
                $this->fail(
                    $rootPath.'.'.$runtimeCollection.'.0',
                    'Dynamische Datenquellen und Symbole sind im statischen Marketing-Editor nicht erlaubt.',
                );
            }
        }

        if (array_key_exists('assets', $project)) {
            $this->validateAssets($project['assets'], $rootPath.'.assets');
        }

        if (array_key_exists('styles', $project)) {
            $this->validateStyles($project['styles'], $rootPath.'.styles');
        } else {
            $project['styles'] = [];
        }

        if (! array_key_exists('pages', $project)) {
            $this->fail($rootPath.'.pages', 'Das Marketing-Projekt benötigt genau eine Seite.');
        }

        $this->assertList($project['pages'], $rootPath.'.pages');
        if (count($project['pages']) !== 1) {
            $path = count($project['pages']) > 1 ? $rootPath.'.pages.1' : $rootPath.'.pages';
            $this->fail($path, 'Das Marketing-Projekt benötigt genau eine Seite.');
        }

        $pagePath = $rootPath.'.pages.0';
        $page = $project['pages'][0];
        $this->assertObject($page, $pagePath);
        $this->assertKnownKeys($page, self::PAGE_KEYS, $pagePath);
        $this->validateIdentifier($page['id'] ?? null, $pagePath.'.id', required: false);
        $this->validateOptionalShortString($page['name'] ?? null, $pagePath.'.name', 160);

        if (array_key_exists('type', $page) && $page['type'] !== 'main') {
            $this->fail($pagePath.'.type', 'Nur die statische GrapesJS-Hauptseite ist erlaubt.');
        }

        $hasFrames = array_key_exists('frames', $page);
        $hasLegacyComponent = array_key_exists('component', $page);
        if ($hasFrames && $hasLegacyComponent) {
            $this->fail(
                $pagePath.'.component',
                'V2-Frames und die Legacy-Komponente dürfen nicht gleichzeitig vorhanden sein.',
            );
        }

        if ($hasFrames) {
            $canonicalHtml = $this->validateV2Page($page, $canonicalHtml, $pagePath);
        } elseif ($hasLegacyComponent) {
            $canonicalHtml = $this->validateLegacyPage($page, $canonicalHtml, $pagePath);
            $page['component'] = $canonicalHtml;
        } else {
            $this->fail($pagePath.'.frames', 'Die Seite benötigt genau einen V2-Frame.');
        }

        $project['pages'][0] = $page;
        $incomingMetadata = is_array($project['railtime'] ?? null) ? $project['railtime'] : [];
        $normalizedMetadata = $this->railtimeMetadata(
            $project['railtime'] ?? [],
            hash('sha256', $canonicalHtml),
            $rootPath.'.railtime',
        );
        // Historische String-Projekte bleiben byte-/hash-kompatibel mit den
        // gepinnten Seed- und Migrationsvertraegen. Sie tragen ihr kanonisches
        // HTML bereits direkt in pages[0].component und benoetigen deshalb
        // keine neuen Codec-Felder. V2-Projekte brauchen diese Bindung, weil
        // HTML und strukturierter Komponentenbaum getrennte Kanaele sind.
        $project['railtime'] = $hasFrames
            ? $normalizedMetadata
            : array_intersect_key($normalizedMetadata, $incomingMetadata);

        return $project;
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function validateV2Page(array $page, ?string $canonicalHtml, string $path): string
    {
        $this->assertList($page['frames'], $path.'.frames');
        if (count($page['frames']) !== 1) {
            $errorPath = count($page['frames']) > 1 ? $path.'.frames.1' : $path.'.frames';
            $this->fail($errorPath, 'Die Marketing-Seite benötigt genau einen Frame.');
        }

        if (array_key_exists('styles', $page)) {
            $this->assertEmptyList($page['styles'], $path.'.styles');
        }

        $framePath = $path.'.frames.0';
        $frame = $page['frames'][0];
        $this->assertObject($frame, $framePath);
        $this->assertKnownKeys($frame, self::FRAME_KEYS, $framePath);
        $this->validateIdentifier($frame['id'] ?? null, $framePath.'.id', required: false);
        $this->validateOptionalShortString($frame['name'] ?? null, $framePath.'.name', 160);

        if (array_key_exists('styles', $frame)) {
            $this->assertEmptyList($frame['styles'], $framePath.'.styles');
        }

        if (! array_key_exists('component', $frame)) {
            $this->fail($framePath.'.component', 'Der Marketing-Frame benötigt genau eine Wrapper-Komponente.');
        }

        $componentPath = $framePath.'.component';
        $this->assertObject($frame['component'], $componentPath);
        if (($frame['component']['type'] ?? null) !== 'wrapper') {
            $this->fail($componentPath.'.type', 'Die V2-Frame-Komponente muss ein GrapesJS-Wrapper sein.');
        }

        $componentCount = 0;
        $componentIds = [];
        $this->validateComponent(
            $frame['component'],
            $componentPath,
            0,
            $componentCount,
            $componentIds,
        );

        if (! is_string($canonicalHtml) || trim($canonicalHtml) === '') {
            $this->fail('html', 'Für ein V2-Projekt wird die kanonische HTML-Fassung benötigt.');
        }

        $this->validateStaticHtml($canonicalHtml, 'html');

        return $canonicalHtml;
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function validateLegacyPage(array $page, ?string $canonicalHtml, string $path): string
    {
        if (! is_string($page['component'])) {
            $this->fail($path.'.component', 'Die Legacy-Komponente muss ein HTML-String sein.');
        }

        $this->validateStaticHtml($page['component'], $path.'.component');

        if (array_key_exists('styles', $page)) {
            $this->assertEmptyList($page['styles'], $path.'.styles');
        }

        if (! is_string($canonicalHtml) || trim($canonicalHtml) === '') {
            $canonicalHtml = $page['component'];
        }

        $this->validateStaticHtml($canonicalHtml, 'html');

        return $canonicalHtml;
    }

    /**
     * @param  array<string, mixed>  $component
     * @param  array<string, string>  $componentIds
     */
    private function validateComponent(
        array $component,
        string $path,
        int $depth,
        int &$componentCount,
        array &$componentIds,
    ): void {
        if ($depth > self::MAX_COMPONENT_DEPTH) {
            $this->fail($path, 'Der Komponentenbaum überschreitet die erlaubte Verschachtelung.');
        }

        $componentCount++;
        if ($componentCount > self::MAX_COMPONENTS) {
            $this->fail($path, 'Der Komponentenbaum enthält zu viele Elemente.');
        }

        $this->assertObject($component, $path);
        $this->assertKnownKeys($component, self::COMPONENT_KEYS, $path);

        if (array_key_exists('type', $component)) {
            if (! is_string($component['type'])
                || ! in_array($component['type'], self::SAFE_COMPONENT_TYPES, true)) {
                $this->fail($path.'.type', 'Dieser Komponententyp ist im statischen Marketing-Projekt nicht erlaubt.');
            }
        }

        if (array_key_exists('id', $component)) {
            $this->validateIdentifier($component['id'], $path.'.id');
            if (isset($componentIds[$component['id']])) {
                $this->fail($path.'.id', 'Komponenten-IDs müssen innerhalb des Projekts eindeutig sein.');
            }
            $componentIds[$component['id']] = $path.'.id';
        }

        if (array_key_exists('tagName', $component)) {
            if (! is_string($component['tagName'])
                || ! in_array(strtolower($component['tagName']), self::SAFE_TAGS, true)) {
                $this->fail($path.'.tagName', 'Dieses HTML-Element ist im statischen Marketing-Projekt nicht erlaubt.');
            }
        }

        $this->validateOptionalShortString($component['name'] ?? null, $path.'.name', 160);

        if (array_key_exists('attributes', $component)) {
            $this->validateAttributes($component['attributes'], $path.'.attributes');
        }

        if (array_key_exists('style', $component)) {
            $this->validateStyleMap($component['style'], $path.'.style');
        }

        if (array_key_exists('classes', $component)) {
            $this->validateClasses($component['classes'], $path.'.classes');
        }

        foreach (['void', 'highlightable', 'copyable', 'removable', 'editable', 'selectable', 'hoverable', 'layerable', 'badgable', 'textable', 'locked'] as $flag) {
            if (array_key_exists($flag, $component) && ! is_bool($component[$flag])) {
                $this->fail($path.'.'.$flag, 'Diese Komponentenoption muss boolesch sein.');
            }
        }

        foreach (['draggable', 'droppable', 'stylable', 'unstylable'] as $capability) {
            if (array_key_exists($capability, $component)) {
                $this->validateCapability($component[$capability], $path.'.'.$capability);
            }
        }

        if (array_key_exists('propagate', $component)) {
            $this->assertList($component['propagate'], $path.'.propagate');
            foreach ($component['propagate'] as $index => $property) {
                if (! is_string($property) || ! preg_match('/^[a-z][a-zA-Z0-9_-]{0,79}$/', $property)) {
                    $this->fail($path.'.propagate.'.$index, 'Die weitergegebene Komponenteneigenschaft ist ungültig.');
                }
            }
        }

        if (array_key_exists('content', $component)) {
            if (! is_string($component['content'])) {
                $this->fail($path.'.content', 'Komponenteninhalt muss ein String sein.');
            }
            $this->validateStaticHtml($component['content'], $path.'.content', allowPlainText: true);
        }

        if (array_key_exists('src', $component)) {
            $this->validateResourceSource($component['src'], $path.'.src');
        }

        if (array_key_exists('href', $component)) {
            $this->validateNavigationUrl($component['href'], $path.'.href');
        }

        if (array_key_exists('head', $component)) {
            $this->validateHead($component['head'], $path.'.head');
        }

        if (array_key_exists('docEl', $component)) {
            $this->validateDocumentElement($component['docEl'], $path.'.docEl');
        }

        if (! array_key_exists('components', $component)) {
            return;
        }

        if (is_string($component['components'])) {
            $this->validateStaticHtml($component['components'], $path.'.components', allowPlainText: true);

            return;
        }

        $this->assertList($component['components'], $path.'.components');
        foreach ($component['components'] as $index => $child) {
            $childPath = $path.'.components.'.$index;
            if (is_string($child)) {
                $this->validateStaticHtml($child, $childPath, allowPlainText: true);

                continue;
            }

            $this->assertObject($child, $childPath);
            $this->validateComponent($child, $childPath, $depth + 1, $componentCount, $componentIds);
        }
    }

    private function validateHead(mixed $head, string $path): void
    {
        $this->assertObject($head, $path);
        $this->assertKnownKeys($head, ['type', 'components'], $path);
        if (($head['type'] ?? null) !== 'head') {
            $this->fail($path.'.type', 'Der GrapesJS-Head muss den festen Typ head besitzen.');
        }

        if (array_key_exists('components', $head)) {
            $this->assertEmptyList($head['components'], $path.'.components');
        }
    }

    private function validateDocumentElement(mixed $docEl, string $path): void
    {
        $this->assertObject($docEl, $path);
        $this->assertKnownKeys($docEl, ['tagName', 'attributes'], $path);
        if (($docEl['tagName'] ?? null) !== 'html') {
            $this->fail($path.'.tagName', 'Das GrapesJS-Dokumentelement muss html sein.');
        }

        if (array_key_exists('attributes', $docEl)) {
            $this->validateAttributes($docEl['attributes'], $path.'.attributes');
        }
    }

    private function validateCapability(mixed $value, string $path): void
    {
        if (is_bool($value)) {
            return;
        }

        if (is_string($value)) {
            $this->validateCssFragment($value, $path);

            return;
        }

        $this->assertList($value, $path);
        foreach ($value as $index => $property) {
            if (! is_string($property) || ! preg_match('/^(?:--[a-z0-9_-]+|[a-z][a-z0-9-]*)$/i', $property)) {
                $this->fail($path.'.'.$index, 'Die CSS-Eigenschaft ist ungültig.');
            }
        }
    }

    private function validateClasses(mixed $classes, string $path): void
    {
        $this->assertList($classes, $path);
        foreach ($classes as $index => $class) {
            $itemPath = $path.'.'.$index;
            if (is_string($class)) {
                if (! preg_match('/^-?[_a-z][_a-z0-9-]{0,127}$/i', $class)) {
                    $this->fail($itemPath, 'Der CSS-Klassenname ist ungültig.');
                }

                continue;
            }

            $this->validateSelector($class, $itemPath);
        }
    }

    private function validateAttributes(mixed $attributes, string $path): void
    {
        $this->assertObject($attributes, $path);
        foreach ($attributes as $name => $value) {
            $attributePath = $path.'.'.$name;
            if (! is_string($name) || ! preg_match('/^[a-z_:][a-z0-9_.:-]{0,127}$/i', $name)) {
                $this->fail($attributePath, 'Der Attributname ist ungültig.');
            }

            $normalized = strtolower($name);
            if (str_starts_with($normalized, 'on')
                || in_array($normalized, ['srcdoc', 'srcset', 'imagesrcset', 'xmlns', 'form', 'ping'], true)) {
                $this->fail($attributePath, 'Aktive oder externe Laufzeitattribute sind nicht erlaubt.');
            }

            if (! is_scalar($value) && $value !== null) {
                $this->fail($attributePath, 'Attributwerte müssen skalare Werte sein.');
            }

            $stringValue = (string) ($value ?? '');
            if (strlen($stringValue) > 20_000 || str_contains($stringValue, "\0")) {
                $this->fail($attributePath, 'Der Attributwert ist ungültig oder zu lang.');
            }

            if ($normalized === 'style') {
                $this->validateInlineStyle($stringValue, $attributePath);
            } elseif (in_array($normalized, self::RESOURCE_ATTRIBUTES, true)) {
                $this->validateResourceSource($stringValue, $attributePath, allowEmpty: true);
            } elseif (in_array($normalized, self::NAVIGATION_ATTRIBUTES, true)) {
                $this->validateNavigationUrl($stringValue, $attributePath, allowEmpty: true);
            } elseif ($normalized === 'id' && $stringValue !== '') {
                $this->validateIdentifier($stringValue, $attributePath);
            }
        }
    }

    private function validateInlineStyle(string $style, string $path): void
    {
        foreach (explode(';', $style) as $index => $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '') {
                continue;
            }

            if (! str_contains($declaration, ':')) {
                $this->fail($path, 'Inline-CSS enthält eine ungültige Deklaration.');
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $this->validateCssProperty($property, $path.'.'.$index);
            $this->validateCssValue($value, $path.'.'.$property);
        }
    }

    private function validateStyles(mixed $styles, string $path): void
    {
        $this->assertList($styles, $path);
        if (count($styles) > 5_000) {
            $this->fail($path.'.5000', 'Das Projekt enthält zu viele CSS-Regeln.');
        }

        foreach ($styles as $index => $rule) {
            $rulePath = $path.'.'.$index;
            $this->assertObject($rule, $rulePath);
            $this->assertKnownKeys($rule, self::STYLE_RULE_KEYS, $rulePath);
            $this->validateIdentifier($rule['id'] ?? null, $rulePath.'.id', required: false);

            if (! array_key_exists('selectors', $rule)) {
                $this->fail($rulePath.'.selectors', 'Eine strukturierte CSS-Regel benötigt Selektoren.');
            }
            $this->assertList($rule['selectors'], $rulePath.'.selectors');
            foreach ($rule['selectors'] as $selectorIndex => $selector) {
                $selectorPath = $rulePath.'.selectors.'.$selectorIndex;
                if (is_string($selector)) {
                    $this->validateCssFragment($selector, $selectorPath);
                } else {
                    $this->validateSelector($selector, $selectorPath);
                }
            }

            if (array_key_exists('selectorsAdd', $rule)) {
                $this->validateCssFragment($rule['selectorsAdd'], $rulePath.'.selectorsAdd');
            }

            if (! array_key_exists('style', $rule)) {
                $this->fail($rulePath.'.style', 'Eine strukturierte CSS-Regel benötigt Deklarationen.');
            }
            $this->validateStyleMap($rule['style'], $rulePath.'.style');

            if (array_key_exists('state', $rule)) {
                $this->validateCssFragment($rule['state'], $rulePath.'.state');
            }

            if (array_key_exists('mediaText', $rule)) {
                $this->validateCssFragment($rule['mediaText'], $rulePath.'.mediaText');
            }

            if (array_key_exists('atRuleType', $rule)) {
                if (! is_string($rule['atRuleType'])
                    || ! in_array($rule['atRuleType'], ['media', 'supports', 'container', 'layer', 'keyframes', 'font-face', 'page'], true)) {
                    $this->fail($rulePath.'.atRuleType', 'Dieser CSS-At-Rule-Typ ist nicht erlaubt.');
                }
            }

            foreach (['singleAtRule', 'important'] as $flag) {
                if (array_key_exists($flag, $rule) && ! is_bool($rule[$flag])) {
                    $this->fail($rulePath.'.'.$flag, 'Diese CSS-Regeloption muss boolesch sein.');
                }
            }
        }
    }

    private function validateSelector(mixed $selector, string $path): void
    {
        $this->assertObject($selector, $path);
        $this->assertKnownKeys($selector, self::SELECTOR_KEYS, $path);
        $this->validateIdentifier($selector['id'] ?? null, $path.'.id', required: false);

        if (! array_key_exists('name', $selector) || ! is_string($selector['name'])) {
            $this->fail($path.'.name', 'Ein strukturierter Selektor benötigt einen Namen.');
        }
        $this->validateCssFragment($selector['name'], $path.'.name');
        $this->validateOptionalShortString($selector['label'] ?? null, $path.'.label', 160);

        if (array_key_exists('type', $selector)
            && ! in_array($selector['type'], [1, 2, 'class', 'id'], true)) {
            $this->fail($path.'.type', 'Der Selektortyp ist ungültig.');
        }

        foreach (['active', 'private', 'protected'] as $flag) {
            if (array_key_exists($flag, $selector) && ! is_bool($selector[$flag])) {
                $this->fail($path.'.'.$flag, 'Diese Selektoroption muss boolesch sein.');
            }
        }
    }

    private function validateStyleMap(mixed $style, string $path): void
    {
        $this->assertObject($style, $path);
        foreach ($style as $property => $value) {
            $propertyPath = $path.'.'.$property;
            $this->validateCssProperty($property, $propertyPath);
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                $this->fail($propertyPath, 'CSS-Werte müssen Strings oder Zahlen sein.');
            }
            $this->validateCssValue((string) $value, $propertyPath);
        }
    }

    private function validateCssProperty(mixed $property, string $path): void
    {
        if (! is_string($property)
            || ! preg_match('/^(?:--[a-z0-9_-]+|[a-z][a-z0-9-]*)$/i', $property)
            || in_array(strtolower($property), ['behavior', '-moz-binding'], true)) {
            $this->fail($path, 'Diese CSS-Eigenschaft ist nicht erlaubt.');
        }
    }

    private function validateCssValue(string $value, string $path): void
    {
        if (strlen($value) > 50_000
            || preg_match('/[\x00\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
            || preg_match('/(?:<\s*\/\s*style|<!--|-->|@import\b|expression\s*\(|javascript\s*:|vbscript\s*:|-moz-binding\s*:|behavior\s*:)/iu', $value)
            || preg_match('/(?:^|[^a-z-])(?:(?:-webkit-)?image-set|cross-fade|image)\s*\(/iu', $value)) {
            $this->fail($path, 'Dieser CSS-Wert enthält nicht erlaubte Laufzeit- oder Importinhalte.');
        }

        preg_match_all('/url\s*\(\s*(["\']?)(.*?)\1\s*\)/iu', $value, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $this->validateResourceSource(html_entity_decode(trim($match[2]), ENT_QUOTES | ENT_HTML5), $path);
        }

        $withoutUrls = preg_replace('/url\s*\(\s*(["\']?)(.*?)\1\s*\)/iu', '', $value) ?? $value;
        if (preg_match('/(?:https?:)?\/\//iu', $withoutUrls)) {
            $this->fail($path, 'Freie Remote-Ressourcen sind in Projekt-CSS nicht erlaubt.');
        }
    }

    private function validateCssFragment(mixed $fragment, string $path): void
    {
        if (! is_string($fragment)
            || trim($fragment) === ''
            || strlen($fragment) > 2_000
            || preg_match('/[{};\x00\r\n]|<\s*\/|@import|javascript\s*:/iu', $fragment)) {
            $this->fail($path, 'Das CSS-Fragment ist ungültig oder enthält Laufzeitinhalt.');
        }
    }

    private function validateAssets(mixed $assets, string $path): void
    {
        $this->assertList($assets, $path);
        if (count($assets) > 1_000) {
            $this->fail($path.'.1000', 'Das Projekt enthält zu viele Medienreferenzen.');
        }

        foreach ($assets as $index => $asset) {
            $assetPath = $path.'.'.$index;
            if (is_string($asset)) {
                $this->validateResourceSource($asset, $assetPath);

                continue;
            }

            $this->assertObject($asset, $assetPath);
            $this->assertKnownKeys($asset, self::ASSET_KEYS, $assetPath);
            $this->validateIdentifier($asset['id'] ?? null, $assetPath.'.id', required: false);

            if (($asset['type'] ?? 'image') !== 'image') {
                $this->fail($assetPath.'.type', 'Nur statische oder animierte Bildmedien sind erlaubt.');
            }

            if (! array_key_exists('src', $asset)) {
                $this->fail($assetPath.'.src', 'Das Bildmedium benötigt eine freigegebene Quelle.');
            }
            $this->validateResourceSource($asset['src'], $assetPath.'.src');

            foreach (['name', 'category', 'fallback_label', 'fallbackLabel'] as $field) {
                $this->validateOptionalShortString($asset[$field] ?? null, $assetPath.'.'.$field, 500);
            }

            foreach (['width', 'height'] as $dimension) {
                if (array_key_exists($dimension, $asset)
                    && (! is_int($asset[$dimension]) || $asset[$dimension] < 1 || $asset[$dimension] > $this->maxAssetPixels)) {
                    $this->fail($assetPath.'.'.$dimension, 'Die Bildabmessung ist ungültig.');
                }
            }

            if (isset($asset['width'], $asset['height'])
                && $asset['width'] > intdiv($this->maxAssetPixels, $asset['height'])) {
                $this->fail($assetPath.'.height', 'Das Bild überschreitet die erlaubte Pixelanzahl.');
            }

            $this->validateAssetBytes($asset, $assetPath);
            $this->validateAssetMimeFields($asset, $assetPath);

            if (array_key_exists('animated', $asset) && ! is_bool($asset['animated'])) {
                $this->fail($assetPath.'.animated', 'Die Animationsangabe muss boolesch sein.');
            }

            foreach (['fallback_source', 'fallback_src', 'fallbackSource'] as $fallbackField) {
                if (array_key_exists($fallbackField, $asset)) {
                    $this->validateResourceSource($asset[$fallbackField], $assetPath.'.'.$fallbackField);
                }
            }

            if (array_key_exists('metadata', $asset)) {
                $this->validateAssetMetadata($asset['metadata'], $assetPath.'.metadata');
            }

            if (is_string($asset['src']) && str_starts_with(strtolower($asset['src']), 'data:')) {
                $this->validateInlineAssetMatchesMetadata($asset, $assetPath);
            }
        }
    }

    private function validateAssetMetadata(mixed $metadata, string $path): void
    {
        $this->assertObject($metadata, $path);
        $this->assertKnownKeys($metadata, self::ASSET_METADATA_KEYS, $path);

        foreach (['name', 'category', 'fallback_label', 'fallbackLabel'] as $field) {
            $this->validateOptionalShortString($metadata[$field] ?? null, $path.'.'.$field, 500);
        }

        foreach (['width', 'height'] as $dimension) {
            if (array_key_exists($dimension, $metadata)
                && (! is_int($metadata[$dimension]) || $metadata[$dimension] < 1 || $metadata[$dimension] > $this->maxAssetPixels)) {
                $this->fail($path.'.'.$dimension, 'Die Bildabmessung ist ungültig.');
            }
        }

        if (isset($metadata['width'], $metadata['height'])
            && $metadata['width'] > intdiv($this->maxAssetPixels, $metadata['height'])) {
            $this->fail($path.'.height', 'Das Bild überschreitet die erlaubte Pixelanzahl.');
        }

        $this->validateAssetBytes($metadata, $path);
        $this->validateAssetMimeFields($metadata, $path);

        if (array_key_exists('animated', $metadata) && ! is_bool($metadata['animated'])) {
            $this->fail($path.'.animated', 'Die Animationsangabe muss boolesch sein.');
        }

        foreach (['fallback_source', 'fallback_src', 'fallbackSource'] as $fallbackField) {
            if (array_key_exists($fallbackField, $metadata)) {
                $this->validateResourceSource($metadata[$fallbackField], $path.'.'.$fallbackField);
            }
        }
    }

    /** @param array<string, mixed> $asset */
    private function validateAssetBytes(array $asset, string $path): void
    {
        foreach (['bytes', 'file_size', 'size_bytes'] as $field) {
            if (array_key_exists($field, $asset)
                && (! is_int($asset[$field]) || $asset[$field] < 1 || $asset[$field] > $this->maxAssetBytes)) {
                $this->fail($path.'.'.$field, 'Das Bild überschreitet die erlaubte Dateigröße.');
            }
        }
    }

    /** @param array<string, mixed> $asset */
    private function validateAssetMimeFields(array $asset, string $path): void
    {
        $declared = [];
        foreach (['mime', 'mime_type', 'content_type'] as $field) {
            if (! array_key_exists($field, $asset)) {
                continue;
            }

            if (! is_string($asset[$field])) {
                $this->fail($path.'.'.$field, 'Der Bild-MIME-Type muss ein String sein.');
            }

            $mime = strtolower(trim(explode(';', $asset[$field], 2)[0]));
            $mime = $mime === 'image/jpg' ? 'image/jpeg' : $mime;
            if (! in_array($mime, self::ALLOWED_IMAGE_MIME_TYPES, true)) {
                $this->fail($path.'.'.$field, 'Dieser Bild-MIME-Type ist nicht erlaubt.');
            }
            $declared[$field] = $mime;
        }

        if (count(array_unique($declared)) > 1) {
            $this->fail($path.'.mime_type', 'Die angegebenen Bild-MIME-Types widersprechen sich.');
        }
    }

    /** @param array<string, mixed> $asset */
    private function validateInlineAssetMatchesMetadata(array $asset, string $path): void
    {
        preg_match('#^data:(image/(?:png|jpeg|jpg|gif|webp));base64,([a-z0-9+/]+={0,2})$#i', $asset['src'], $matches);
        $contents = isset($matches[2]) ? base64_decode($matches[2], true) : false;
        $dimensions = is_string($contents) ? @getimagesizefromstring($contents) : false;
        if (! is_array($dimensions)) {
            $this->fail($path.'.src', 'Das eingebettete Bild konnte nicht validiert werden.');
        }

        foreach (['width' => 0, 'height' => 1] as $field => $offset) {
            if (isset($asset[$field]) && $asset[$field] !== (int) $dimensions[$offset]) {
                $this->fail($path.'.'.$field, 'Die Bildmetadaten stimmen nicht mit dem eingebetteten Bild überein.');
            }
        }

        $declaredMime = strtolower((string) ($asset['mime_type'] ?? $asset['mime'] ?? $asset['content_type'] ?? ''));
        $declaredMime = $declaredMime === 'image/jpg' ? 'image/jpeg' : $declaredMime;
        if ($declaredMime !== '' && $declaredMime !== strtolower((string) $dimensions['mime'])) {
            $this->fail($path.'.mime_type', 'Der Bild-MIME-Type stimmt nicht mit dem eingebetteten Bild überein.');
        }
    }

    private function validateResourceSource(mixed $source, string $path, bool $allowEmpty = false): void
    {
        if (! is_string($source)) {
            $this->fail($path, 'Die Ressourcenquelle muss ein String sein.');
        }

        $source = trim(html_entity_decode($source, ENT_QUOTES | ENT_HTML5));
        if ($source === '') {
            if ($allowEmpty) {
                return;
            }
            $this->fail($path, 'Die Ressourcenquelle darf nicht leer sein.');
        }

        if (strlen($source) > max(4_096, (int) ceil($this->maxAssetBytes * 1.4))) {
            $this->fail($path, 'Die Ressourcenquelle ist zu lang.');
        }

        if (preg_match('#^rtmedia://media-[a-f0-9]{64}$#i', $source)) {
            return;
        }

        if (str_starts_with(strtolower($source), 'data:')) {
            $this->validateInlineImageSource($source, $path);

            return;
        }

        $decoded = $source;
        for ($round = 0; $round < 2; $round++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        $collapsed = preg_replace('/[\x00-\x20\x7f]+/u', '', $decoded) ?? '';
        if ($collapsed === ''
            || str_contains($collapsed, '\\')
            || str_starts_with($collapsed, '//')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $collapsed)
            || (preg_match('/^[a-z][a-z0-9+.-]*:/i', $collapsed)
                && ! preg_match('#^https?://#i', $collapsed))) {
            $this->fail($path, 'Nur lokale/private Bildrouten, RailTime-Medientokens oder geprüfte Inline-Bilder sind erlaubt.');
        }

        $parts = parse_url($collapsed);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            $this->fail($path, 'Die lokale Ressourcenquelle ist ungültig.');
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            $application = parse_url((string) config('app.url'));
            if (! is_array($application)
                || strtolower((string) ($parts['scheme'] ?? '')) !== strtolower((string) ($application['scheme'] ?? ''))
                || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($application['host'] ?? ''))
                || (int) ($parts['port'] ?? 0) !== (int) ($application['port'] ?? 0)) {
                $this->fail($path, 'Nur Ressourcen der eigenen RailTime-Anwendung sind erlaubt.');
            }
        }

        $resourcePath = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');
        $versionQueryIsSafe = $query === '' || (bool) preg_match('/^v=[a-f0-9]{8,64}$/i', $query);
        if (MarketingBrandAssets::allows($resourcePath) && $versionQueryIsSafe) {
            return;
        }

        if (preg_match('#^/administrator/marketing/dateien/[1-9][0-9]*$#', $resourcePath)
            && $versionQueryIsSafe) {
            return;
        }

        $this->fail(
            $path,
            'Nur freigegebene RailTime-Markenmedien und private Marketing-Dateirouten sind erlaubt.',
        );
    }

    private function validateInlineImageSource(string $source, string $path): void
    {
        if (! preg_match('#^data:(image/(?:png|jpeg|jpg|gif|webp));base64,([a-z0-9+/]+={0,2})$#i', $source, $matches)) {
            $this->fail($path, 'Nur Base64-Bilder der freigegebenen Typen sind erlaubt.');
        }

        $contents = base64_decode($matches[2], true);
        if (! is_string($contents) || $contents === '' || strlen($contents) > $this->maxAssetBytes) {
            $this->fail($path, 'Das eingebettete Bild ist ungültig oder zu groß.');
        }

        $dimensions = @getimagesizefromstring($contents);
        if (! is_array($dimensions) || ! isset($dimensions[0], $dimensions[1], $dimensions['mime'])) {
            $this->fail($path, 'Das eingebettete Bild konnte nicht validiert werden.');
        }

        $declaredMime = strtolower($matches[1]) === 'image/jpg' ? 'image/jpeg' : strtolower($matches[1]);
        $actualMime = strtolower((string) $dimensions['mime']);
        if ($declaredMime !== $actualMime || ! in_array($actualMime, self::ALLOWED_IMAGE_MIME_TYPES, true)) {
            $this->fail($path, 'Der tatsächliche Bildtyp stimmt nicht mit dem Inline-MIME-Type überein.');
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        if ($width < 1 || $height < 1 || $width > intdiv($this->maxAssetPixels, $height)) {
            $this->fail($path, 'Das eingebettete Bild überschreitet die erlaubte Pixelanzahl.');
        }
    }

    private function validateNavigationUrl(mixed $url, string $path, bool $allowEmpty = false): void
    {
        if (! is_string($url)) {
            $this->fail($path, 'Die Linkadresse muss ein String sein.');
        }

        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if ($url === '') {
            if ($allowEmpty) {
                return;
            }
            $this->fail($path, 'Die Linkadresse darf nicht leer sein.');
        }

        $decoded = rawurldecode(rawurldecode($url));
        $normalized = strtolower(preg_replace('/[\x00-\x20\x7f]+/u', '', $decoded) ?? '');
        if ($normalized === '' || str_contains($decoded, '\\') || str_starts_with($normalized, '//')) {
            $this->fail($path, 'Die Linkadresse ist ungültig.');
        }

        if (str_starts_with($normalized, '#')
            || str_starts_with($normalized, '/')
            || str_starts_with($normalized, './')
            || ! preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized)) {
            return;
        }

        $scheme = strtolower((string) parse_url($decoded, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
            $this->fail($path, 'Dieses Linkprotokoll ist nicht erlaubt.');
        }
    }

    private function validateStaticHtml(string $html, string $path, bool $allowPlainText = false): void
    {
        if (strlen($html) > 600_000 || str_contains($html, "\0") || ! mb_check_encoding($html, 'UTF-8')) {
            $this->fail($path, 'Die HTML-Quelle ist ungültig oder zu umfangreich.');
        }

        if (trim($html) === '') {
            if ($allowPlainText) {
                return;
            }
            $this->fail($path, 'Die HTML-Quelle darf nicht leer sein.');
        }

        if ($allowPlainText && ! str_contains($html, '<')) {
            return;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div data-rt-codec-root="1">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            $this->fail($path, 'Die HTML-Quelle konnte nicht sicher gelesen werden.');
        }

        $xpath = new DOMXPath($document);
        foreach (self::FORBIDDEN_HTML_TAGS as $tag) {
            $nodes = $xpath->query('//'.$tag);
            if ($nodes !== false && $nodes->length > 0) {
                $this->fail($path, 'Aktive HTML-Elemente sind im statischen Marketing-Projekt nicht erlaubt.');
            }
        }

        $elements = $xpath->query('//*');
        if ($elements === false) {
            $this->fail($path, 'Die HTML-Elemente konnten nicht validiert werden.');
        }

        /** @var DOMElement $element */
        foreach ($elements as $element) {
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $attributePath = $path.'.@'.$name;
                if (str_starts_with($name, 'on')
                    || in_array($name, ['srcdoc', 'srcset', 'imagesrcset', 'xmlns', 'form', 'ping'], true)) {
                    $this->fail($attributePath, 'Aktive oder externe Laufzeitattribute sind nicht erlaubt.');
                }

                if ($name === 'style') {
                    $this->validateInlineStyle($attribute->value, $attributePath);
                } elseif (in_array($name, self::RESOURCE_ATTRIBUTES, true)) {
                    $this->validateResourceSource($attribute->value, $attributePath, allowEmpty: true);
                } elseif (in_array($name, self::NAVIGATION_ATTRIBUTES, true)) {
                    $this->validateNavigationUrl($attribute->value, $attributePath, allowEmpty: true);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function railtimeMetadata(mixed $metadata, string $sourceHash, string $path): array
    {
        $this->assertObject($metadata, $path);
        $this->assertKnownKeys($metadata, self::RAILTIME_KEYS, $path);

        if (array_key_exists('template', $metadata)) {
            if (! is_string($metadata['template'])
                || ! preg_match('/^[a-z0-9][a-z0-9._:-]{0,119}$/i', $metadata['template'])) {
                $this->fail($path.'.template', 'Die Marketing-Vorlagenkennung ist ungültig.');
            }
        }

        if (array_key_exists('format', $metadata)
            && ! in_array($metadata['format'], ['story', 'post', 'web'], true)) {
            $this->fail($path.'.format', 'Das Marketing-Format ist ungültig.');
        }

        if (array_key_exists('schema', $metadata)
            && (! is_int($metadata['schema']) || $metadata['schema'] < 1 || $metadata['schema'] > 1_000)) {
            $this->fail($path.'.schema', 'Die Marketing-Schemaversion ist ungültig.');
        }

        if (array_key_exists('design_preset', $metadata)
            && $metadata['design_preset'] !== 'railtime_modern') {
            $this->fail($path.'.design_preset', 'Dieses Marketing-Designprofil ist nicht erlaubt.');
        }

        if (array_key_exists('mode', $metadata) && $metadata['mode'] !== self::MODE) {
            $this->fail($path.'.mode', 'Die Builder-Daten gehören nicht zum Marketing-Modus.');
        }

        if (array_key_exists('codec_version', $metadata)
            && $metadata['codec_version'] !== self::CODEC_VERSION) {
            $this->fail($path.'.codec_version', 'Die Builder-Codec-Version ist nicht kompatibel.');
        }

        if (array_key_exists('source_html_sha256', $metadata)
            && (! is_string($metadata['source_html_sha256'])
                || ! preg_match('/^[a-f0-9]{64}$/', $metadata['source_html_sha256']))) {
            $this->fail($path.'.source_html_sha256', 'Der gespeicherte HTML-Quellhash ist ungültig.');
        }

        $metadata['mode'] = self::MODE;
        $metadata['codec_version'] = self::CODEC_VERSION;
        $metadata['source_html_sha256'] = $sourceHash;

        return $metadata;
    }

    private function validateIdentifier(mixed $id, string $path, bool $required = true): void
    {
        if ($id === null && ! $required) {
            return;
        }

        if (! is_string($id) || ! preg_match('/^[a-z0-9][a-z0-9:_-]{0,127}$/i', $id)) {
            $this->fail($path, 'Die persistente Builder-ID ist ungültig.');
        }
    }

    private function validateOptionalShortString(mixed $value, string $path, int $maxLength): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value)
            || mb_strlen($value) > $maxLength
            || str_contains($value, "\0")
            || ! mb_check_encoding($value, 'UTF-8')) {
            $this->fail($path, 'Der Textwert ist ungültig oder zu lang.');
        }
    }

    private function assertObject(mixed $value, string $path): void
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->fail($path, 'An dieser Stelle wird ein JSON-Objekt erwartet.');
        }
    }

    private function assertList(mixed $value, string $path): void
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'An dieser Stelle wird eine JSON-Liste erwartet.');
        }
    }

    private function assertEmptyList(mixed $value, string $path): void
    {
        $this->assertList($value, $path);
        if ($value !== []) {
            $this->fail($path.'.0', 'Diese Laufzeitliste muss im statischen Marketing-Projekt leer bleiben.');
        }
    }

    /** @param array<string, mixed> $value */
    private function assertKnownKeys(array $value, array $allowed, string $path): void
    {
        foreach ($value as $key => $_unused) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                $this->fail($path.'.'.$key, 'Dieses unbekannte oder ausführbare Laufzeitfeld ist nicht erlaubt.');
            }
        }
    }

    private function configuredAssetBytes(): int
    {
        if (function_exists('app') && app()->bound('config')) {
            return max(1, (int) config('marketing.assets.max_kilobytes', 8192)) * 1024;
        }

        return self::DEFAULT_MAX_ASSET_BYTES;
    }

    private function configuredAssetPixels(): int
    {
        if (function_exists('app') && app()->bound('config')) {
            return max(1, (int) config('marketing.assets.max_pixels', self::DEFAULT_MAX_ASSET_PIXELS));
        }

        return self::DEFAULT_MAX_ASSET_PIXELS;
    }

    private function fail(string $path, string $message): never
    {
        throw ValidationException::withMessages([
            $path => $message.' (JSON-Pfad: '.$this->jsonPath($path).')',
        ]);
    }

    private function jsonPath(string $path): string
    {
        $segments = explode('.', $path);
        if (($segments[0] ?? null) === 'builder_data') {
            array_shift($segments);
        }

        $jsonPath = '$';
        foreach ($segments as $segment) {
            if (ctype_digit($segment)) {
                $jsonPath .= '['.$segment.']';
            } elseif (preg_match('/^[a-z_$][a-z0-9_$]*$/i', $segment)) {
                $jsonPath .= '.'.$segment;
            } else {
                $jsonPath .= '['.json_encode($segment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).']';
            }
        }

        return $jsonPath;
    }
}
