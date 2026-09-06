<?php

declare(strict_types=1);

namespace App\Support\Mail;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;
use TijsVerkoyen\CssToInlineStyles\Css\Processor as CssProcessor;

/** Kataloggesteuerter, rein lesender Kompatibilitaetsauditor. */
final class EmailCompatibilityAuditor
{
    public const HTML_WARN_BYTES = 80 * 1024;

    public const HTML_BLOCK_BYTES = 90 * 1024;

    public const STYLE_WARN_BYTES = 12 * 1024;

    /** @var list<string> */
    private const ALLOWED_HANDLERS = [
        'html_size',
        'style_size',
        'forbidden_elements',
        'layout_table_role',
        'image_alt',
        'image_dimensions',
        'link_absolute',
        'doctype_lang',
        'source_order',
        'css_forbidden_features',
        'plain_text_required',
        'fallback_required',
        'used_layout_risk',
    ];

    /** @var list<array{rule_id: string, diagnostic_code: string, enforcement: string, message: string, fix: string, client_profiles: list<string>}> */
    private array $findings = [];

    private string $html = '';

    private string $css = '';

    private string $allCss = '';

    private int $htmlBytes = 0;

    private int $styleBytes = 0;

    private DOMDocument $document;

    private DOMXPath $xpath;

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array<string, true> */
    private array $automatedRules = [];

    /** @var array<string, true> */
    private array $manualRules = [];

    /** @var list<array{0: string, 1: string}>|null */
    private ?array $usedDeclarations = null;

    private bool $unmappedCss = false;

    public function __construct(private readonly EmailCompatibilityCatalog $catalog) {}

    /**
     * @param array{
     *     document_kind?: string,
     *     plain_text?: string|null,
     *     trusted_system_css?: bool,
     *     allow_template_tokens?: bool
     * } $context
     */
    public function audit(string $html, string $css = '', array $context = []): EmailCompatibilityReport
    {
        $this->findings = [];
        $this->html = $html;
        $this->css = $css;
        $this->context = $context;
        $this->automatedRules = [];
        $this->manualRules = [];
        $this->usedDeclarations = null;
        $this->unmappedCss = false;
        $this->htmlBytes = strlen($html);
        $this->document = $this->parseDocument($html);
        $this->xpath = new DOMXPath($this->document);

        $embeddedCss = [];
        foreach ($this->document->getElementsByTagName('style') as $style) {
            $embeddedCss[] = $style->textContent;
        }
        // Die 12-KB-Projektpolitik betrifft eingebettete/separate Stylesheets.
        // Inline-Deklarationen bleiben fuer Technikpruefungen sichtbar, werden
        // aber nicht faelschlich als <style>-Budget doppelt eingerechnet.
        $this->styleBytes = strlen($css) + array_sum(array_map('strlen', $embeddedCss));
        $inlineCss = [];
        foreach ($this->xpath->query('//*[@style]') ?: [] as $element) {
            if ($element instanceof DOMElement) {
                $inlineCss[] = $element->getAttribute('style');
            }
        }
        $this->allCss = implode("\n", array_merge([$css], $embeddedCss, $inlineCss));

        $coverage = ['required' => 0, 'supported' => 0, 'unknown' => 0];
        $seenSizeHandlers = ['html_size' => false, 'style_size' => false];

        foreach ($this->catalog->activeRuleGroups() as $group) {
            $rule = $group['definition'];
            if (! $this->appliesToDocumentKind($rule) || $rule['enforcement'] === 'OFF') {
                continue;
            }

            foreach ($group['profiles'] as $profile) {
                if (! in_array($profile['client_profile_id'], EmailCompatibilityCatalog::REQUIRED_CLIENT_PROFILES, true)) {
                    continue;
                }
                $coverage['required']++;
                if ($profile['support_status'] === 'UNKNOWN') {
                    $coverage['unknown']++;
                } else {
                    // Auch UNSUPPORTED ist bei MUST_NOT eine belastbare,
                    // bekannte Aussage. Compliance bewertet der Handler.
                    $coverage['supported']++;
                }
            }

            $handler = $rule['validator_handler'];
            if ($handler === '') {
                $this->manualRules[$rule['rule_id']] = true;

                continue;
            }
            if (! in_array($handler, self::ALLOWED_HANDLERS, true)) {
                $this->manualRules[$rule['rule_id']] = true;
                $this->addFinding(
                    $rule,
                    $group['profiles'],
                    'INFO',
                    "Die Regel benoetigt eine manuelle Pruefung; der Handler {$handler} ist nicht automatisiert.",
                );

                continue;
            }

            $config = EmailCompatibilityCatalog::decodeJson($rule, 'validator_config_json');
            if (($handler === 'source_order' && empty($config['id_sequence']))
                || ($handler === 'plain_text_required' && ! array_key_exists('plain_text', $context))
                || ($handler === 'css_forbidden_features' && ($context['trusted_system_css'] ?? false) === true)) {
                $this->manualRules[$rule['rule_id']] = true;
            } else {
                $this->automatedRules[$rule['rule_id']] = true;
            }
            if ($handler === 'plain_text_required'
                && array_intersect(['preserve_links', 'require_links', 'require_legal_content'], array_keys($config)) !== []) {
                // The handler checks non-empty text, not semantic equivalence
                // of all personal information, links and legal content.
                $this->manualRules[$rule['rule_id']] = true;
            }

            if (array_key_exists($handler, $seenSizeHandlers)) {
                $seenSizeHandlers[$handler] = true;
            }
            $this->runHandler($handler, $rule, $group['profiles']);
        }

        // Die feste Groessenpolitik bleibt selbst dann aktiv, wenn ein noch
        // unvollstaendiger Katalog die beiden Datenzeilen nicht enthaelt.
        if (! $seenSizeHandlers['html_size']) {
            $this->auditHtmlSize(null, []);
        }
        if (! $seenSizeHandlers['style_size']) {
            $this->auditStyleSize(null, []);
        }

        $this->findings = $this->deduplicate($this->findings);
        $counts = ['block' => 0, 'warn' => 0, 'info' => 0];
        foreach ($this->findings as $finding) {
            $key = strtolower($finding['enforcement']);
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }

        return new EmailCompatibilityReport(
            $this->catalog->catalogVersion(),
            $counts,
            $coverage,
            $this->htmlBytes,
            $this->styleBytes,
            $this->findings,
            [
                'automated' => count($this->automatedRules),
                'manual' => count($this->manualRules),
                'manual_rule_ids' => array_keys($this->manualRules),
            ],
        );
    }

    /** @param array<string, string> $rule */
    private function appliesToDocumentKind(array $rule): bool
    {
        $documentKind = $this->context['document_kind'] ?? null;
        if (! is_string($documentKind) || $documentKind === '') {
            return true;
        }

        $kinds = EmailCompatibilityCatalog::decodeJson($rule, 'document_kinds_json');

        return in_array($documentKind, $kinds, true);
    }

    /**
     * @param  array<string, string>  $rule
     * @param  list<array<string, string>>  $profiles
     */
    private function runHandler(string $handler, array $rule, array $profiles): void
    {
        match ($handler) {
            'html_size' => $this->auditHtmlSize($rule, $profiles),
            'style_size' => $this->auditStyleSize($rule, $profiles),
            'forbidden_elements' => $this->auditForbiddenElements($rule, $profiles),
            'layout_table_role' => $this->auditLayoutTableRole($rule, $profiles),
            'image_alt' => $this->auditImageAlt($rule, $profiles),
            'image_dimensions' => $this->auditImageDimensions($rule, $profiles),
            'link_absolute' => $this->auditAbsoluteLinks($rule, $profiles),
            'doctype_lang' => $this->auditDoctypeAndLang($rule, $profiles),
            'source_order' => $this->auditSourceOrder($rule, $profiles),
            'css_forbidden_features' => $this->auditForbiddenCss($rule, $profiles),
            'plain_text_required' => $this->auditPlainText($rule, $profiles),
            'fallback_required' => $this->auditFallback($rule, $profiles),
            'used_layout_risk' => $this->auditUsedLayoutRisk($rule, $profiles),
        };
    }

    /** @param array<string, string>|null $rule @param list<array<string, string>> $profiles */
    private function auditHtmlSize(?array $rule, array $profiles): void
    {
        if ($rule !== null) {
            $config = EmailCompatibilityCatalog::decodeJson($rule, 'validator_config_json');
            $threshold = $config['bytes'] ?? null;
            if (is_int($threshold) && $threshold > 0 && $this->htmlBytes >= $threshold) {
                $this->addFinding($rule, $profiles);
            }

            return;
        }
        if ($this->htmlBytes >= self::HTML_BLOCK_BYTES) {
            $this->addPolicyFinding($rule, $profiles, 'BLOCK', 'EMAIL_SIZE_HTML_BLOCK', 'Das finale HTML ist mindestens 90 KB gross.');
        } elseif ($this->htmlBytes >= self::HTML_WARN_BYTES) {
            $this->addPolicyFinding($rule, $profiles, 'WARN', 'EMAIL_SIZE_HTML_WARN', 'Das finale HTML ist mindestens 80 KB gross.');
        }
    }

    /** @param array<string, string>|null $rule @param list<array<string, string>> $profiles */
    private function auditStyleSize(?array $rule, array $profiles): void
    {
        if ($rule !== null) {
            $config = EmailCompatibilityCatalog::decodeJson($rule, 'validator_config_json');
            $threshold = $config['bytes'] ?? null;
            if (is_int($threshold) && $threshold > 0 && $this->styleBytes >= $threshold) {
                $this->addFinding($rule, $profiles);
            }

            return;
        }
        if ($this->styleBytes >= self::STYLE_WARN_BYTES) {
            $this->addPolicyFinding($rule, $profiles, 'WARN', 'EMAIL_SIZE_STYLE_WARN', 'Eingebettete und separate Styles sind mindestens 12 KB gross.');
        }
    }

    /** @param array<string, string> $rule @param list<array<string, string>> $profiles */
    private function auditForbiddenElements(array $rule, array $profiles): void
    {
        $config = EmailCompatibilityCatalog::decodeJson($rule, 'validator_config_json');
        $elements = $config['elements'] ?? [];
        if (! is_array($elements)) {
            $elements = [];
        }
        foreach ($elements as $element) {
            if (is_string($element)
                && preg_match('/^[a-z][a-z0-9:-]*$/D', $element) === 1
                && $this->document->getElementsByTagName($element)->length > 0) {
                $this->addFinding($rule, $profiles);

                return;
            }
        }

        if (($config['forbid_event_attributes'] ?? false) === true) {
            foreach ($this->xpath->query('//*') ?: [] as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                foreach ($node->attributes as $attribute) {
                    if (str_starts_with(strtolower($attribute->name), 'on')) {
                        $this->addFinding($rule, $profiles);

                        return;
                    }
                }
            }
        }

        $cssFunctions = $config['css_functions'] ?? [];
        foreach (is_array($cssFunctions) ? $cssFunctions : [] as $function) {
            if (is_string($function) && stripos($this->allCss, $function.'(') !== false) {
                $this->addFinding($rule, $profiles);

                return;
            }
        }

        $configuredCssProperties = $config['css_properties'] ?? [];
        $cssProperties = array_values(array_map(
            'strtolower',
            array_filter(is_array($configuredCssProperties) ? $configuredCssProperties : [], 'is_string'),
        ));
        foreach ($this->cssDeclarations($this->allCss) as [$property]) {
            if (in_array(strtolower($property), $cssProperties, true)) {
                $this->addFinding($rule, $profiles);

                return;
            }
        }
    }

    /** @param array<string, string> $rule @param list<array<string, string>> $profiles */
    private function auditLayoutTableRole(array $rule, array $profiles): void
    {
        foreach ($this->document->getElementsByTagName('table') as $table) {
            if (! $table instanceof DOMElement || strtolower($table->getAttribute('role')) === 'presentation') {
                continue;
            }
            $isDataTable = $table->getElementsByTagName('th')->length > 0
                || $table->getElementsByTagName('caption')->length > 0
                || in_array(strtolower($table->getAttribute('role')), ['table', 'grid', 'treegrid'], true);
            if (! $isDataTable) {
                $this->addFinding($rule, $profiles);

                return;
            }
        }
    }

    /** @param array<string, string> $rule @param list<array<string, string>> $profiles */
    private function auditImageAlt(array $rule, array $profiles): void
    {
        $config = EmailCompatibilityCatalog::decodeJson($rule, 'validator_config_json');
        $allowEmpty = ($config['allow_empty'] ?? $config['allow_empty_for_decorative'] ?? true) === true;
        foreach ($this->document->getElementsByTagName('img') as $image) {
            $linked = $image instanceof DOMElement && $image->parentNode instanceof DOMElement
                && strtolower($image->parentNode->tagName) === 'a';
            if ($image instanceof DOMElement
                && (! $image->hasAttribute('alt')
                    || (! $allowEmpty && trim($image->getAttribute('alt')) === '')
                    || (($config['require_nonempty_for_linked'] ?? false) === true
                        && $linked
                        && trim($image->getAttribute('alt')) === ''))) {
                $this->addFinding($rule, $profiles);

                return;
            }
        }
    }

    /** @param array<string, string> $rule @param list<array<string, string>> $profiles */
    private function auditImageDimensions(array $rule, array $profiles): void
    {
        $config = EmailCompatibilityCatalog::decodeJson($rule, 'validator_config_json');
        foreach ($this->document->getElementsByTagName('img') as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }
            $styles = [];
            foreach ($this->cssDeclarations($image->getAttribute('style')) as [$property, $value]) {
                $styles[strtolower($property)] = $this->bareCssValue($value);
            }
            $width = $image->getAttribute('width');
            $height = $image->getAttribute('height');
            $missingWidth = ($config['require_width'] ?? true) === true
                && ! $this->positiveDimension($width);
            $proportional = ($styles['height'] ?? '') === 'auto'
                && ($this->positiveDimension($width) || $this->positiveDimension($styles['width'] ?? ''));
            $ratio = $styles['aspect-ratio'] ?? '';
            $hasRatio = preg_match('/^(\d+(?:\.\d+)?)(?:\s*\/\s*(\d+(?:\.\d+)?))?$/D', $ratio, $parts) === 1
                && (float) $parts[1] > 0 && (! isset($parts[2]) || (float) $parts[2] > 0);
            $missingHeight = ($config['require_height_or_aspect_ratio'] ?? true) === true
                && ! $this->positiveDimension($height) && ! $proportional && ! $hasRatio;
            $invalidExplicitHeight = $image->hasAttribute('height') && ! $this->positiveDimension($height);
            if (! $proportional) {
                // Dimensions are declarations, not the intrinsic media ratio.
                // This auditor never fetches image sources to claim that ratio.
                $this->manualRules[$rule['rule_id']] = true;
            }
            if ($missingWidth || $missingHeight || $invalidExplicitHeight) {
                $this->addFinding($rule, $profiles);

                return;
            }
        }
    }

    private function positiveDimension(string $value): bool
    {
        $value = trim($value);

        return preg_match('/^(?:\d+(?:\.\d+)?|\.\d+)(?:px|%)?$/iD', $value) === 1
            && (float) $value > 0;
    }

    private function bareCssValue(string $value): string
    {
        return strtolower(trim(preg_replace('/\s*!\s*important\s*$/i', '', $value) ?? $value));
    }

    /** Warnings inspect used markup, never every unused legacy runtime rule. */
    private function auditUsedLayoutRisk(array $rule, array $profiles): void
    {
        $feature = EmailCompatibilityCatalog::decodeJson($rule, 'validator_config_json')['feature'] ?? '';
        $declarations = $this->usedCssDeclarations();
        if ($this->unmappedCss) {
            $this->manualRules[$rule['rule_id']] = true;
        }

        if ($feature === 'background_image' && ($this->xpath->query('//*[@background and normalize-space(@background)!=""]')?->length ?? 0) > 0) {
            $this->addFinding($rule, $profiles);

            return;
        }
        foreach ($declarations as [$property, $value]) {
            $property = strtolower($property);
            $value = $this->bareCssValue($value);
            $negativeMargin = false;
            if (str_starts_with($property, 'margin')) {
                preg_match_all('/(?:^|[\s(,])-(\d+(?:\.\d+)?|\.\d+)/', $value, $negativeParts);
                $negativeMargin = count(array_filter($negativeParts[1], static fn (string $part): bool => (float) $part > 0)) > 0;
                if (preg_match('/(?:calc|var|env)\s*\(/i', $value) === 1) {
                    $this->manualRules[$rule['rule_id']] = true;
                }
            }
            $positioning = ($property === 'position' && in_array($value, ['absolute', 'fixed', 'sticky'], true))
                || ($property === 'z-index' && $value !== 'auto');
            $background = in_array($property, ['background', 'background-image'], true)
                && preg_match('/(?:url|(?:repeating-)?(?:linear|radial|conic)-gradient|image-set)\s*\(/i', $value) === 1;
            if (($feature === 'negative_margin' && $negativeMargin)
                || ($feature === 'positioning' && $positioning)
                || ($feature === 'background_image' && $background)) {
                $this->addFinding($rule, $profiles);

                return;
            }
        }
    }

    /** @return list<array{0: string, 1: string}> */
    private function usedCssDeclarations(): array
    {
        if ($this->usedDeclarations !== null) {
            return $this->usedDeclarations;
        }
        $this->usedDeclarations = [];
        foreach ($this->xpath->query('//*[@style]') ?: [] as $element) {
            if ($element instanceof DOMElement) {
                array_push($this->usedDeclarations, ...$this->cssDeclarations($element->getAttribute('style')));
            }
        }
        $stylesheets = [$this->css];
        foreach ($this->document->getElementsByTagName('style') as $style) {
            // Canonical runtime versions are optional branches. Their actual
            // geometry is already present inline on validated source elements.
            if (($this->context['trusted_system_css'] ?? false) === true
                && str_contains($style->textContent, TrustedEmailCss::RUNTIME_MARKER)) {
                $this->unmappedCss = true;

                continue;
            }
            $stylesheets[] = $style->textContent;
        }
        $converter = new CssSelectorConverter;
        foreach ($stylesheets as $css) {
            if (trim($css) === '') {
                continue;
            }
            if (preg_match('/(?:^|[;{}])\s*@/m', $css) === 1) {
                // Viewport and client conditions cannot be resolved statically.
                $this->unmappedCss = true;

                continue;
            }
            try {
                foreach ((new CssProcessor)->getRules($css) as $cssRule) {
                    $selector = $cssRule->getSelector();
                    if (str_contains($selector, ':') || str_contains($selector, '\\')) {
                        $this->unmappedCss = true;

                        continue;
                    }
                    $nodes = $this->xpath->query($converter->toXPath($selector));
                    if ($nodes === false) {
                        $this->unmappedCss = true;

                        continue;
                    }
                    if ($nodes->length === 0) {
                        continue;
                    }
                    foreach ($cssRule->getProperties() as $property) {
                        $this->usedDeclarations[] = [strtolower($property->getName()), $property->getValue()];
                    }
                }
            } catch (\Exception) {
                $this->unmappedCss = true;
            }
        }

        return $this->usedDeclarations;
    }

    /** @param array<string, string> $rule @param list<array<string, string>> $profiles */
    private function auditAbsoluteLinks(array $rule, array $profiles): void
    {
        $config = EmailCompatibilityCatalog::decodeJson($rule, 'validator_config_json');
        $allowedSchemes = $config['schemes'] ?? $config['allowed_schemes'] ?? ['https', 'http', 'mailto', 'tel', 'cid'];
        if (! is_array($allowedSchemes)) {
            $allowedSchemes = [];
        }
        foreach ($this->document->getElementsByTagName('a') as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $href = trim($link->getAttribute('href'));
            if (($config['allow_hash'] ?? false) === true && str_starts_with($href, '#') && strlen($href) > 1) {
                continue;
            }
            if (($this->context['allow_template_tokens'] ?? true) === true
                && preg_match('/^\{\{[A-Z][A-Z0-9_]*\}\}$/D', $href) === 1) {
                continue;
            }
            $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
            if ($href === '' || ! in_array($scheme, $allowedSchemes, true)) {
                $this->addFinding($rule, $profiles);

                return;
            }
        }
    }

    /** @param array<string, string> $rule @param list<array<string, string>> $profiles */
    private function auditDoctypeAndLang(array $rule, array $profiles): void
    {
        // Signaturen sind absichtlich HTML-Fragmente und werden erst durch
        // den gemeinsamen Ausgabekompiler in ein vollständiges Dokument
        // eingesetzt. Dokumentregeln dürfen dieses gültige Fragment nicht
        // so behandeln, als hätte es seine eigene <html>-Hülle verloren.
        if ($rule['match_target'] === 'full_document'
            && preg_match('/(?:<!doctype\s+html\b|<html(?:\s|>))/i', $this->html) !== 1) {
            return;
        }

        $config = EmailCompatibilityCatalog::decodeJson($rule, 'validator_config_json');
        if (($config['require_doctype'] ?? true) === true && $this->document->doctype === null) {
            $this->addFinding($rule, $profiles);

            return;
        }
        $html = $this->document->documentElement;
        if (($config['require_lang'] ?? true) === true
            && (! $html instanceof DOMElement || trim($html->getAttribute('lang')) === '')) {
            $this->addFinding($rule, $profiles);
        }
    }

    /** @param array<string, string> $rule @param list<array<string, string>> $profiles */
    private function auditSourceOrder(array $rule, array $profiles): void
    {
        $config = EmailCompatibilityCatalog::decodeJson($rule, 'validator_config_json');
        $ids = $config['id_sequence'] ?? [];
        if (! is_array($ids) || $ids === []) {
            return;
        }

        $positions = [];
        $position = 0;
        foreach ($this->xpath->query('//*') ?: [] as $node) {
            if ($node instanceof DOMElement && $node->hasAttribute('id')) {
                $positions[$node->getAttribute('id')] = $position;
            }
            $position++;
        }
        $last = -1;
        foreach ($ids as $id) {
            if (! is_string($id) || ! isset($positions[$id]) || $positions[$id] <= $last) {
                $this->addFinding($rule, $profiles);

                return;
            }
            $last = $positions[$id];
        }
    }

    /** @param array<string, string> $rule @param list<array<string, string>> $profiles */
    private function auditForbiddenCss(array $rule, array $profiles): void
    {
        if (($this->context['trusted_system_css'] ?? false) === true) {
            return;
        }

        $config = EmailCompatibilityCatalog::decodeJson($rule, 'validator_config_json');
        $atRules = $config['at_rules'] ?? [];
        foreach (is_array($atRules) ? $atRules : [] as $atRule) {
            if (is_string($atRule) && stripos($this->allCss, $atRule) !== false) {
                $this->addFinding($rule, $profiles);

                return;
            }
        }

        $elements = $config['elements'] ?? [];
        if (is_array($elements)
            && in_array('link[rel=stylesheet]', $elements, true)
            && ($this->xpath->query('//link[translate(@rel,"STYLESHEET","stylesheet")="stylesheet"]')?->length ?? 0) > 0) {
            $this->addFinding($rule, $profiles);

            return;
        }

        // Die Test-Fixture verwendet die groben Features, der produktive
        // Katalog exakte Eigenschafts- oder Eigenschaft:Wert-Paare.
        $features = $config['features'] ?? [];
        $features = is_array($features) ? $features : [];
        $properties = $config['properties'] ?? [];
        $properties = is_array($properties) ? $properties : [];
        $declarations = $this->cssDeclarations($this->allCss);
        foreach ($declarations as [$property, $value]) {
            $property = strtolower($property);
            $value = strtolower($value);
            if ((in_array('flex', $features, true) && ($property === 'flex' || str_starts_with($property, 'flex-') || ($property === 'display' && str_contains($value, 'flex'))))
                || (in_array('grid', $features, true) && ($property === 'grid' || str_starts_with($property, 'grid-') || ($property === 'display' && str_contains($value, 'grid'))))
                || (in_array('transition', $features, true) && str_starts_with($property, 'transition'))
                || (in_array('transform', $features, true) && str_starts_with($property, 'transform'))) {
                $this->addFinding($rule, $profiles);

                return;
            }
            foreach ($properties as $forbidden) {
                if (! is_string($forbidden) || $forbidden === '') {
                    continue;
                }
                [$forbiddenProperty, $forbiddenValue] = array_pad(explode(':', strtolower($forbidden), 2), 2, null);
                if ($property === trim($forbiddenProperty)
                    && ($forbiddenValue === null || trim($value) === trim($forbiddenValue))) {
                    $this->addFinding($rule, $profiles);

                    return;
                }
            }
        }

        if (array_intersect(['animation', 'transition', 'transform'], $properties) !== []
            && CssSemantic::containsForbiddenAnimationOrProtectedSelector($this->allCss)) {
            $this->addFinding($rule, $profiles);
        }
    }

    /** @param array<string, string> $rule @param list<array<string, string>> $profiles */
    private function auditPlainText(array $rule, array $profiles): void
    {
        if (! array_key_exists('plain_text', $this->context)) {
            return;
        }
        $plainText = $this->context['plain_text'] ?? null;
        if (! is_string($plainText) || trim($plainText) === '') {
            $this->addFinding($rule, $profiles);
        }
    }

    /** @param array<string, string> $rule @param list<array<string, string>> $profiles */
    private function auditFallback(array $rule, array $profiles): void
    {
        $config = EmailCompatibilityCatalog::decodeJson($rule, 'validator_config_json');
        $used = $this->usedCssDeclarations();
        $usesBackground = false;
        $usesBackgroundColor = false;
        foreach ($used as [$property, $value]) {
            $property = strtolower($property);
            $usesBackground = $usesBackground || (in_array($property, ['background', 'background-image'], true)
                && preg_match('/(?:url|(?:repeating-)?(?:linear|radial|conic)-gradient|image-set)\s*\(/i', $value) === 1);
            $usesBackgroundColor = $usesBackgroundColor || $property === 'background-color';
        }
        if ($this->unmappedCss || $usesBackground) {
            // The static presence check cannot prove a fallback belongs to a
            // particular layer, or that a mail client resolves its CID.
            $this->manualRules[$rule['rule_id']] = true;
        }
        $fallbackId = $config['fallback_id'] ?? null;
        if (is_string($fallbackId) && $fallbackId !== '' && $this->document->getElementById($fallbackId) === null) {
            $this->addFinding($rule, $profiles);

            return;
        }
        if (($config['feature'] ?? null) === 'background-image'
            && ($config['require_background_color'] ?? false) === true
            && $usesBackground
            && ! $usesBackgroundColor) {
            $this->addFinding($rule, $profiles);

            return;
        }
        $accepted = $config['accepted'] ?? [];
        if (($config['profile'] ?? null) === 'outlook-classic-windows'
            && is_array($accepted)
            && $usesBackground
            && $this->document->getElementsByTagName('v:rect')->length === 0
            && $this->document->getElementsByTagName('img')->length === 0) {
            $this->addFinding($rule, $profiles);
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function cssDeclarations(string $css): array
    {
        $css = CssSemantic::decodeHtmlEntitiesOnce($css);
        $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
        preg_match_all('/(?:^|[;{])\s*(--?[A-Za-z_][A-Za-z0-9_-]*|[A-Za-z_][A-Za-z0-9_-]*)\s*:\s*([^;{}]*)/', $css, $matches, PREG_SET_ORDER);

        return array_values(array_map(
            static fn (array $match): array => [$match[1], trim($match[2])],
            $matches,
        ));
    }

    private function parseDocument(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            // Ein minimales Dokument erlaubt einen strukturierten Bericht;
            // der Parserfehler selbst blockiert die Veroeffentlichung.
            $document->loadHTML('<!doctype html><html lang="de"><body></body></html>', LIBXML_HTML_NODEFDTD | LIBXML_NONET);
            $this->findings[] = [
                'rule_id' => 'EMAIL-HTML-PARSE',
                'diagnostic_code' => 'EMAIL_HTML_PARSE',
                'enforcement' => 'BLOCK',
                'message' => 'Das E-Mail-HTML konnte nicht gelesen werden.',
                'fix' => 'Das HTML syntaktisch korrigieren.',
                'client_profiles' => ['all'],
            ];
        }

        foreach (iterator_to_array($document->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $document->removeChild($node);
            }
        }

        return $document;
    }

    /**
     * @param  array<string, string>|null  $rule
     * @param  list<array<string, string>>  $profiles
     */
    private function addPolicyFinding(?array $rule, array $profiles, string $enforcement, string $code, string $message): void
    {
        if ($rule !== null) {
            $this->addFinding($rule, $profiles, $enforcement, $message, $code);

            return;
        }
        $this->findings[] = [
            'rule_id' => str_contains($code, 'STYLE') ? 'EMAIL-SIZE-STYLE-POLICY' : 'EMAIL-SIZE-HTML-POLICY',
            'diagnostic_code' => $code,
            'enforcement' => $enforcement,
            'message' => $message,
            'fix' => 'Unnoetiges Markup beziehungsweise CSS entfernen und die Ausgabe erneut pruefen.',
            'client_profiles' => ['all'],
        ];
    }

    /**
     * @param  array<string, string>  $rule
     * @param  list<array<string, string>>  $profiles
     */
    private function addFinding(
        array $rule,
        array $profiles,
        ?string $enforcement = null,
        ?string $message = null,
        ?string $diagnosticCode = null,
    ): void {
        $effectiveEnforcement = $enforcement ?? $rule['enforcement'];
        if ($effectiveEnforcement === 'OFF') {
            return;
        }
        $clientProfiles = array_values(array_unique(array_column($profiles, 'client_profile_id')));
        $this->findings[] = [
            'rule_id' => $rule['rule_id'],
            'diagnostic_code' => $diagnosticCode ?? $rule['diagnostic_code'],
            'enforcement' => $effectiveEnforcement,
            'message' => $message ?? $rule['diagnostic_message_de'],
            'fix' => $rule['fix_guidance_de'],
            'client_profiles' => $clientProfiles === [] ? ['all'] : $clientProfiles,
        ];
    }

    /**
     * @param  list<array{rule_id: string, diagnostic_code: string, enforcement: string, message: string, fix: string, client_profiles: list<string>}>  $findings
     * @return list<array{rule_id: string, diagnostic_code: string, enforcement: string, message: string, fix: string, client_profiles: list<string>}>
     */
    private function deduplicate(array $findings): array
    {
        $unique = [];
        foreach ($findings as $finding) {
            sort($finding['client_profiles']);
            $key = implode('|', [
                $finding['rule_id'],
                $finding['diagnostic_code'],
                $finding['enforcement'],
                $finding['message'],
                implode(',', $finding['client_profiles']),
            ]);
            $unique[$key] = $finding;
        }

        return array_values($unique);
    }
}
