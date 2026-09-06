<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Kompakter Outlook-Ausschnitt aus dem kanonischen System-Mail-CSS.
 *
 * setSignatureAsync akzeptiert hoechstens 30.000 Zeichen. Deshalb darf der
 * Add-in-Pfad nicht den kompletten, vorlagenweiten Runtime-Block einbetten.
 * Statt eine zweite Handkopie der Signaturregeln zu pflegen, filtert diese
 * Klasse ausschliesslich relevante Regeln aus TrustedEmailCss und entfernt
 * dabei Regeln anderer Signaturartefakte.
 */
final class TrustedOutlookSignatureCss
{
    public const RUNTIME_MARKER = 'RT_OUTLOOK_SIGNATURE_RUNTIME_START';

    private const MAX_CSS_BYTES = 12000;

    /** Eigenes Transportbudget fuer eine gesamte Compose-Vorlage. */
    private const MAX_TEMPLATE_CSS_BYTES = 24576;

    /**
     * Internal compiler entry for TrustedEmailCss::forDocument(). The caller
     * supplies the canonical responsive source, never editable document CSS.
     * Keeping this method free of TrustedEmailCss calls avoids recursion.
     * Do not prune by actual HTML classes here: stored signature source and
     * rendered full mail must share the same canonical runtime bytes. Only
     * the second, Outlook-specific pass removes unused document selectors.
     */
    public static function filterDocumentRuntime(string $css, string $html, bool $includeTemplate = true): string
    {
        if (substr_count($css, TrustedEmailCss::RUNTIME_MARKER) !== 1 || stripos($css, '</style') !== false) {
            throw new RuntimeException('Die dokumentgebundene Mail-Runtime besitzt keine eindeutige interne Marke.');
        }

        $version = SignatureArtifactVersion::detect(MailDocumentKind::Signature, $html);
        if (! self::hasIndependentTrainGeometry($version)) {
            return $css;
        }

        return '/* '.TrustedEmailCss::RUNTIME_MARKER.' */'.self::filterRuntimeStylesheet(
            $css,
            $version,
            self::documentTraits($html),
            $includeTemplate,
            filterDocumentSelectors: false,
        );
    }

    /**
     * Nutzt denselben Selektor-Scanner wie Signaturen, bezieht aber auch die
     * tatsaechlich vorhandenen Nachrichten-/Kartenklassen ein. Nur die exakt
     * erkannte Server-Runtime darf um fremde Artefakte/Animationen gekuerzt
     * werden; freie veroeffentlichte Regeln werden lediglich sicher gescopt.
     *
     * @param  list<string>  $styles
     */
    public static function composeStylesheet(string $html, array $styles, string $scopeClass, string $border): string
    {
        if (preg_match('/\Artt[0-9a-f]{12}\z/', $scopeClass) !== 1) {
            throw new RuntimeException('Der interne Outlook-Vorlagen-Scope ist ungueltig.');
        }

        $version = SignatureArtifactVersion::detect(MailDocumentKind::Signature, $html);
        $traits = self::documentTraits($html);
        $runtime = TrustedEmailCss::forDocument($html, $border, SignatureArtifactVersion::usesOptionalBackground($version));
        $filteredRuntime = self::filterRuntimeStylesheet(
            $runtime,
            $version,
            $traits,
            includeTemplate: true,
            geometryAlreadyIsolated: self::hasIndependentTrainGeometry($version),
        );
        $result = '';

        foreach ($styles as $css) {
            if (str_contains($css, TrustedEmailCss::RUNTIME_MARKER)) {
                if (substr_count($css, $runtime) !== 1) {
                    throw new RuntimeException('Die Outlook-Vorlage besitzt keine eindeutige kanonische Runtime-CSS.');
                }
                $css = str_replace($runtime, $filteredRuntime, $css);
            }
            if (stripos($css, '</style') !== false) {
                throw new RuntimeException('Das Outlook-Vorlagen-CSS kann nicht sicher eingebettet werden.');
            }
            $result .= self::scopeStylesheet($css, '.'.$scopeClass, $traits);
        }

        if (strlen($result) > self::MAX_TEMPLATE_CSS_BYTES) {
            throw new RuntimeException('Das Outlook-Vorlagen-CSS ueberschreitet das sichere Transportbudget von 24 KiB.');
        }

        return $result;
    }

    public static function responsive(
        string $signatureHtml,
        ?string $border = '#dfe3e6',
        ?string $scopeClass = null,
    ): string {
        $artifactVersion = SignatureArtifactVersion::detect(
            MailDocumentKind::Signature,
            $signatureHtml,
        );
        $documentTraits = self::documentTraits($signatureHtml);
        $runtime = self::filterRuntimeStylesheet(
            TrustedEmailCss::forDocument(
                $signatureHtml,
                $border,
                SignatureArtifactVersion::usesOptionalBackground($artifactVersion),
            ),
            $artifactVersion,
            $documentTraits,
            geometryAlreadyIsolated: self::hasIndependentTrainGeometry($artifactVersion),
        );
        $css = '/* '.self::RUNTIME_MARKER.' */'
            .self::scopeStylesheet(
                $runtime,
                '.'.self::validatedScopeClass(
                    $scopeClass ?? self::scopeClass($signatureHtml, '', $border),
                ),
            );

        self::assertResponsive($css, $artifactVersion, $documentTraits);

        return $css;
    }

    public static function style(
        string $signatureHtml,
        ?string $border = '#dfe3e6',
        ?string $scopeClass = null,
    ): string {
        $scopeClass ??= self::scopeClass($signatureHtml, '', $border);

        return '<style data-rt-outlook-signature-css="1">'
            .self::responsive($signatureHtml, $border, $scopeClass)
            .'</style>'
            .self::backgroundStyle($signatureHtml, $scopeClass);
    }

    /**
     * Office.js does not guarantee inline CSS in setSignatureAsync. Preserve
     * the verified V22/V23 source in internal CSS as well as the original
     * inline fallback. This adds no layer, image row or free CSS permission.
     * CID localization still runs afterwards and attaches the same GIF once.
     */
    private static function backgroundStyle(string $signatureHtml, string $scopeClass): string
    {
        $scopeClass = self::validatedScopeClass($scopeClass);
        if (! SignatureBackgroundContract::applies($signatureHtml)) {
            return '';
        }

        SignatureBackgroundContract::assertRuntime($signatureHtml);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><table><tbody>'
                .$signatureHtml.'</tbody></table>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('Der Outlook-Signaturhintergrund konnte nicht gelesen werden.');
        }

        foreach ($dom->getElementsByTagName('td') as $carrier) {
            if (! $carrier instanceof DOMElement
                || ! in_array('rt-sign-cell', preg_split('/\s+/', trim($carrier->getAttribute('class'))) ?: [], true)) {
                continue;
            }
            if ($carrier->getAttribute('data-rt-signature-background') !== '1') {
                return '';
            }

            // The contract above has already rejected duplicate declarations,
            // ambiguous quotes, foreign URLs and CSS-breaking image sources.
            if (preg_match('/(?:^|;)\s*background-image\s*:\s*(url\(([\'"])[^\'"]+\2\))\s*(?:!important\s*)?(?:;|$)/i',
                $carrier->getAttribute('style'), $match) !== 1) {
                throw new RuntimeException('Der Outlook-Signaturhintergrund besitzt keine eindeutige Bildbindung.');
            }

            // Use a class selector: Outlook may remove editor-only data
            // attributes. Breakpoint rules remain !important and win over
            // this canonical desktop fallback without affecting old replies.
            $contain = $carrier->getAttribute('data-rt-bg-desktop-fit') === 'contain';
            $css = '.'.$scopeClass.' .rt-sign-cell{background-image:'.$match[1].';'
                .'background-repeat:no-repeat;background-position:'.($contain ? 'left bottom' : '65% bottom').';'
                .'background-size:'.($contain ? 'contain' : $carrier->getAttribute('data-rt-bg-desktop').'% auto').';}';
            if (strlen($css) > self::MAX_CSS_BYTES || stripos($css, '</style') !== false) {
                throw new RuntimeException('Der Outlook-Signaturhintergrund ueberschreitet das sichere CSS-Budget.');
            }

            return '<style data-rt-outlook-signature-background-css="1">'.$css.'</style>';
        }

        throw new RuntimeException('Der Outlook-Signaturhintergrund besitzt keinen gebundenen Carrier.');
    }

    /**
     * Kurzer, inhaltsgebundener Scope: wenig Overhead im 30.000-Zeichen-Limit
     * und keine Kollision mit aelteren Signaturfassungen im zitierten Verlauf.
     */
    public static function scopeClass(
        string $signatureHtml,
        string $publishedCss = '',
        ?string $border = '#dfe3e6',
    ): string {
        return 'rts'.substr(hash(
            'sha256',
            $signatureHtml."\0".$publishedCss."\0".TrustedEmailCss::responsive($border),
        ), 0, 10);
    }

    public static function publishedStyle(
        string $signatureHtml,
        string $publishedCss,
        ?string $scopeClass = null,
    ): string {
        $publishedCss = trim($publishedCss);
        if ($publishedCss === '') {
            return '';
        }

        if (stripos($publishedCss, '</style') !== false) {
            throw new RuntimeException('Das veroeffentlichte Signatur-CSS kann nicht sicher eingebettet werden.');
        }

        if (CssSemantic::containsForbiddenAnimationOrProtectedSelector($publishedCss)) {
            throw new RuntimeException(CssSemantic::PROTECTED_EDITABLE_CSS_MESSAGE);
        }

        $css = self::scopeStylesheet(
            $publishedCss,
            '.'.self::validatedScopeClass(
                $scopeClass ?? self::scopeClass($signatureHtml, $publishedCss),
            ),
            self::documentTraits($signatureHtml),
        );

        return $css === ''
            ? ''
            : '<style data-rt-mail-document-css="signature">'.$css.'</style>';
    }

    private static function validatedScopeClass(string $scopeClass): string
    {
        if (preg_match('/\Arts[0-9a-f]{10}\z/', $scopeClass) !== 1) {
            throw new RuntimeException('Der interne Outlook-Signatur-Scope ist ungueltig.');
        }

        return $scopeClass;
    }

    /** @param array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string, background_fit: ?string} $documentTraits */
    private static function filterRuntimeStylesheet(
        string $css,
        ?string $artifactVersion,
        array $documentTraits,
        bool $includeTemplate = false,
        bool $geometryAlreadyIsolated = false,
        bool $filterDocumentSelectors = true,
    ): string {
        $result = '';
        $length = strlen($css);
        $offset = 0;

        while ($offset < $length) {
            self::skipTrivia($css, $offset);
            if ($offset >= $length) {
                break;
            }

            $openingBrace = self::nextOpeningBrace($css, $offset);
            if ($openingBrace === null) {
                break;
            }

            $prelude = trim(substr($css, $offset, $openingBrace - $offset));
            $closingBrace = self::matchingBrace($css, $openingBrace);
            $body = substr($css, $openingBrace + 1, $closingBrace - $openingBrace - 1);
            $offset = $closingBrace + 1;

            if ($prelude === '') {
                continue;
            }

            if (str_starts_with(strtolower($prelude), '@media')) {
                if (! str_contains(strtolower($prelude), 'max-width')) {
                    continue;
                }

                $filteredBody = self::filterRuntimeStylesheet(
                    $body,
                    $artifactVersion,
                    $documentTraits,
                    $includeTemplate,
                    $geometryAlreadyIsolated,
                    $filterDocumentSelectors,
                );
                if ($filteredBody !== '') {
                    $result .= self::compactPrelude($prelude).'{'.$filteredBody.'}';
                }

                continue;
            }

            // Keyframes und @supports betreffen nur die im Add-in bewusst
            // statisch transportierten Animationen und werden nicht benoetigt.
            if (str_starts_with($prelude, '@')) {
                continue;
            }

            $selectors = self::relevantSelectors(
                $prelude,
                $artifactVersion,
                $documentTraits,
                $includeTemplate,
                $filterDocumentSelectors,
            );
            if ($selectors === []) {
                continue;
            }

            $body = self::compactDeclarations($body);
            if (self::hasIndependentTrainGeometry($artifactVersion)) {
                $result .= self::isolatedArtifactRule($selectors, $body, $artifactVersion, ! $geometryAlreadyIsolated);

                continue;
            }

            if (SignatureArtifactVersion::usesFlowSafeTrain($artifactVersion)
                && self::selectorsContain($selectors, '.rt-sign-train-layer')) {
                $body = preg_replace_callback(
                    '/(?:^|;)margin-bottom:-[^;}]+;?/i',
                    static fn (array $match): string => str_starts_with($match[0], ';') ? ';' : '',
                    $body,
                ) ?? $body;
            }

            if ($body !== '') {
                $result .= implode(',', $selectors).'{'.$body.'}';
            }
        }

        return $result;
    }

    private static function hasIndependentTrainGeometry(?string $artifactVersion): bool
    {
        // Explicit opt-ins only. Older releases intentionally inherit the
        // fixed stage, while future artifacts must choose their own policy.
        return in_array($artifactVersion, [SignatureArtifactVersion::V25, SignatureArtifactVersion::V26], true);
    }

    /**
     * Compile one artifact, rather than transporting legacy geometry followed
     * by version-specific undo rules. Office can reduce data attributes during
     * insertion; no 200/296-px train table may survive that reduction in V25.
     *
     * Only canonical runtime rules reach this method. Published user CSS stays
     * on its unchanged, separately validated path. Shared typography, contact
     * spacing and table semantics remain available at every breakpoint.
     *
     * @param  list<string>  $selectors
     */
    private static function isolatedArtifactRule(
        array $selectors,
        string $body,
        string $artifactVersion,
        bool $filterLegacyGeometry = true,
    ): string {
        $groups = [];
        foreach ($selectors as $selector) {
            $ownArtifact = str_contains($selector, 'data-rt-artifact-version');
            $declarations = $body;
            if ($filterLegacyGeometry && ! $ownArtifact && preg_match(
                '/\.rt-sign-(?:stage|content-frame|train-layer|train-frame|train-slot|train|train-mso)(?:\[[^\]]+\])*\z/i',
                $selector,
            ) === 1) {
                // Filter per selector: a comma-separated contact selector must
                // not lose its own dimensions merely by sharing this rule.
                $declarations = preg_replace(
                    '/(?:\A|(?<=;))(?:position|z-index|(?:min-|max-)?(?:width|height)|margin(?:-(?:top|right|bottom|left))?|(?:top|right|bottom|left)|overflow(?:-[xy])?):[^;]*(?:;|\z)/i',
                    '',
                    $declarations,
                ) ?? $declarations;
            }
            if ($declarations === '') {
                continue;
            }

            // matchesArtifact() already selected the single current artifact.
            // The content-bound rts/rtt scope is added afterwards, so removing
            // this redundant source marker cannot address another signature.
            $selector = preg_replace(
                '/\Atr\[data-rt-artifact-version=(["\'])'.preg_quote($artifactVersion, '/').'\1\]\s+/i',
                '',
                $selector,
            ) ?? $selector;
            if ($ownArtifact) {
                $selector = str_replace('.rt-sign-train-layer[data-rt-layer-train]', '.rt-sign-train-layer', $selector);
            }
            $groups[$declarations][] = $selector;
        }

        $result = '';
        foreach ($groups as $declarations => $group) {
            $result .= implode(',', array_unique($group)).'{'.$declarations.'}';
        }

        return $result;
    }

    /**
     * Das bereits veroeffentlichte Pagebuilder-CSS ist zuvor durch den
     * Mail-Sanitizer gelaufen. Im Add-in darf es daher nicht nochmals anhand
     * einer unvollstaendigen Selektor-Allowlist ausgeduennt werden. Wir
     * begrenzen jede Regel stattdessen auf die transportierte Signaturwurzel,
     * damit weder der Compose-Body noch zitierte Nachrichten erfasst werden.
     */
    /** @param null|array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string, background_fit: ?string} $documentTraits */
    private static function scopeStylesheet(
        string $css,
        string $scopeSelector,
        ?array $documentTraits = null,
    ): string {
        $result = '';
        $length = strlen($css);
        $offset = 0;

        while ($offset < $length) {
            self::skipTrivia($css, $offset);
            if ($offset >= $length) {
                break;
            }

            $openingBrace = self::nextOpeningBrace($css, $offset);
            if ($openingBrace === null) {
                throw new RuntimeException('Das veroeffentlichte Signatur-CSS besitzt eine unvollstaendige Regel.');
            }

            $prelude = trim(substr($css, $offset, $openingBrace - $offset));
            $closingBrace = self::matchingBrace($css, $openingBrace);
            $body = substr($css, $openingBrace + 1, $closingBrace - $openingBrace - 1);
            $offset = $closingBrace + 1;

            if ($prelude === '') {
                continue;
            }

            $atRuleName = self::atRuleName($prelude);
            if ($atRuleName === 'media') {
                $scopedBody = self::scopeStylesheet($body, $scopeSelector, $documentTraits);
                if ($scopedBody !== '') {
                    $result .= $prelude.'{'.$scopedBody.'}';
                }

                continue;
            }

            if ($atRuleName !== null || str_starts_with($prelude, '@')) {
                throw new RuntimeException('Das veroeffentlichte Signatur-CSS enthaelt eine nicht unterstuetzte At-Regel.');
            }

            $selectors = [];
            foreach (self::splitSelectorList($prelude) as $selector) {
                if ($documentTraits !== null
                    && self::selectorDefinitelyUnused($selector, $documentTraits)) {
                    continue;
                }

                $selectors[] = self::scopeSelector($selector, $scopeSelector);
            }

            $selectors = array_values(array_unique(array_filter($selectors)));
            $body = trim($body);
            if ($selectors !== [] && $body !== '') {
                $result .= implode(',', $selectors).'{'.$body.'}';
            }
        }

        return $result;
    }

    private static function scopeSelector(string $selector, string $scopeSelector): string
    {
        $selector = trim($selector);
        if ($selector === '') {
            throw new RuntimeException('Das veroeffentlichte Signatur-CSS enthaelt einen leeren Selektor.');
        }

        $offset = 0;
        self::skipTrivia($selector, $offset);
        if (isset($selector[$offset]) && in_array($selector[$offset], ['+', '~'], true)) {
            throw new RuntimeException('Das veroeffentlichte Signatur-CSS darf keine Elemente ausserhalb der Signatur adressieren.');
        }

        // Einige Mailclients setzen ihren Kompatibilitaetskontext ausserhalb
        // des eigentlichen Signaturfragments. Der Scope muss deshalb zwischen
        // diesem bekannten Ahnen und dem eigentlichen Ziel stehen. Nur exakt
        // erkannte, begrenzte Kontexte werden umgestellt; alles andere bleibt
        // beim konservativen Descendant-Scope.
        $externalContext = self::externalClientContext($selector, $offset);
        if ($externalContext !== null) {
            [$contextEnd, $remainderOffset] = $externalContext;
            $context = trim(substr($selector, $offset, $contextEnd - $offset));
            $remainder = substr($selector, $remainderOffset);

            return $context.' '.self::scopeSelector($remainder, $scopeSelector);
        }

        // Exportierte Vollseiten koennen body/html als Kontext enthalten.
        // Im Add-in entspricht diese Wurzel ausschliesslich der Signatur. Ein
        // kleiner Token-Scanner erkennt auch gueltige CSS-Escapes und Kommentare,
        // ohne Kommas oder Kombinatoren per Regex fehlzuinterpretieren.
        $rootEnd = self::rootContextEnd($selector, $offset);
        if ($rootEnd === null) {
            return $scopeSelector.' '.$selector;
        }

        self::assertRootCompoundContained($selector, $rootEnd);
        $remainderOffset = $rootEnd;
        self::skipTrivia($selector, $remainderOffset);
        if ($remainderOffset >= strlen($selector)) {
            return $scopeSelector;
        }

        $combinator = $selector[$remainderOffset];
        if (in_array($combinator, ['+', '~'], true)) {
            throw new RuntimeException('Das veroeffentlichte Signatur-CSS darf keine Elemente ausserhalb der Signatur adressieren.');
        }

        $remainder = substr($selector, $remainderOffset);
        if ($combinator === '>') {
            return $scopeSelector.$remainder;
        }

        return $remainderOffset === $rootEnd
            ? $scopeSelector.$remainder
            : $scopeSelector.' '.$remainder;
    }

    private static function rootContextEnd(string $selector, int $offset): ?int
    {
        $cursor = $offset;
        if (($selector[$cursor] ?? '') === ':') {
            $cursor++;
            $pseudo = self::readCssIdentifier($selector, $cursor);
            if (in_array($pseudo, ['root', 'scope'], true)) {
                return self::optionalBodyContextEnd($selector, $cursor);
            }

            if (in_array($pseudo, ['is', 'where'], true)) {
                return self::rootFunctionContextEnd($selector, $cursor);
            }

            return null;
        }

        $root = self::readCssIdentifier($selector, $cursor);
        if ($root === 'body') {
            return $cursor;
        }

        if ($root !== 'html') {
            return null;
        }

        return self::optionalBodyContextEnd($selector, $cursor);
    }

    /**
     * :is() und :where() duerfen nur dann die Dokumentwurzel vertreten, wenn
     * jede ihrer Alternativen selbst vollstaendig ein bekannter Rootkontext
     * ist. Gemischte Listen wie :is(body, .karte) bleiben damit konservativ
     * unterhalb des Signatur-Scopes und koennen ihn nie ersetzen.
     */
    private static function rootFunctionContextEnd(string $selector, int $functionNameEnd): ?int
    {
        if (($selector[$functionNameEnd] ?? '') !== '(') {
            return null;
        }

        $closingParenthesis = self::matchingSelectorParenthesis($selector, $functionNameEnd);
        $arguments = substr(
            $selector,
            $functionNameEnd + 1,
            $closingParenthesis - $functionNameEnd - 1,
        );

        foreach (self::splitSelectorList($arguments) as $argument) {
            $argumentOffset = 0;
            self::skipTrivia($argument, $argumentOffset);
            $argumentEnd = self::simpleRootContextEnd($argument, $argumentOffset);
            if ($argumentEnd === null) {
                return null;
            }

            self::skipTrivia($argument, $argumentEnd);
            if ($argumentEnd !== strlen($argument)) {
                return null;
            }
        }

        return self::optionalBodyContextEnd($selector, $closingParenthesis + 1);
    }

    /** Nur die nicht-funktionalen Rootformen; verhindert mehrdeutige Rekursion. */
    private static function simpleRootContextEnd(string $selector, int $offset): ?int
    {
        $cursor = $offset;
        if (($selector[$cursor] ?? '') === ':') {
            $cursor++;

            return in_array(self::readCssIdentifier($selector, $cursor), ['root', 'scope'], true)
                ? self::optionalBodyContextEnd($selector, $cursor)
                : null;
        }

        $root = self::readCssIdentifier($selector, $cursor);
        if ($root === 'body') {
            return $cursor;
        }

        return $root === 'html'
            ? self::optionalBodyContextEnd($selector, $cursor)
            : null;
    }

    private static function optionalBodyContextEnd(string $selector, int $rootEnd): int
    {
        $cursor = $rootEnd;
        self::skipTrivia($selector, $cursor);
        $hasDescendantSeparator = $cursor > $rootEnd;

        if (($selector[$cursor] ?? '') === '>') {
            $cursor++;
            self::skipTrivia($selector, $cursor);
        } elseif (! $hasDescendantSeparator) {
            return $rootEnd;
        }

        $bodyEnd = $cursor;
        if (self::readCssIdentifier($selector, $bodyEnd) === 'body') {
            return $bodyEnd;
        }

        return $rootEnd;
    }

    private static function matchingSelectorParenthesis(string $selector, int $openingParenthesis): int
    {
        $length = strlen($selector);
        $depth = 0;
        $quote = null;

        for ($index = $openingParenthesis; $index < $length; $index++) {
            $character = $selector[$index];
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '\\') {
                $escapedEnd = $index;
                self::readCssIdentifier($selector, $escapedEnd);
                $index = max($index, $escapedEnd - 1);

                continue;
            }

            if (substr($selector, $index, 2) === '/*') {
                $end = strpos($selector, '*/', $index + 2);
                if ($end === false) {
                    throw new RuntimeException('Das Signatur-CSS enthaelt einen offenen Kommentar.');
                }

                $index = $end + 1;

                continue;
            }

            if ($character === '(') {
                $depth++;

                continue;
            }

            if ($character === ')') {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        throw new RuntimeException('Das Signatur-CSS besitzt eine unvollstaendige Selektorfunktion.');
    }

    /**
     * @return array{0: int, 1: int}|null Ende des Ahnenkontexts und Beginn des Zielselektors
     */
    private static function externalClientContext(string $selector, int $offset): ?array
    {
        $contextEnd = self::externalClientAnchorEnd($selector, $offset);
        if ($contextEnd === null) {
            return null;
        }

        $remainderOffset = $contextEnd;
        self::skipTrivia($selector, $remainderOffset);
        if ($remainderOffset === $contextEnd
            || $remainderOffset >= strlen($selector)
            || in_array($selector[$remainderOffset], ['>', '+', '~'], true)
            || substr($selector, $remainderOffset, 2) === '||') {
            return null;
        }

        return [$contextEnd, $remainderOffset];
    }

    private static function externalClientAnchorEnd(string $selector, int $offset): ?int
    {
        if (($selector[$offset] ?? '') === '.') {
            $cursor = $offset + 1;

            return self::readCssIdentifier($selector, $cursor) === 'externalclass'
                ? $cursor
                : null;
        }

        if (($selector[$offset] ?? '') === '[') {
            return self::externalClientAttributeEnd($selector, $offset);
        }

        // Outlook kann die bekannten Dark-Mode-Marker direkt auf html/body
        // setzen. Der Elementname gehoert dann zum externen Client-Kontext;
        // andernfalls wuerde der Marker faelschlich auf den Signatur-Scope
        // verschoben und die veroeffentlichte Regel waere wirkungslos.
        $elementCursor = $offset;
        $element = self::readCssIdentifier($selector, $elementCursor);
        if (in_array($element, ['html', 'body'], true)
            && ($selector[$elementCursor] ?? '') === '[') {
            $attributeEnd = self::externalClientAttributeEnd($selector, $elementCursor);
            if ($attributeEnd !== null) {
                return $attributeEnd;
            }
        }

        $cursor = $offset;
        if (self::readCssIdentifier($selector, $cursor) !== 'u') {
            return null;
        }

        self::skipTrivia($selector, $cursor);
        if (($selector[$cursor] ?? '') !== '+') {
            return null;
        }

        $cursor++;
        self::skipTrivia($selector, $cursor);
        if (($selector[$cursor] ?? '') !== '#') {
            return null;
        }

        $cursor++;

        return self::readCssIdentifier($selector, $cursor) === 'body'
            ? $cursor
            : null;
    }

    private static function externalClientAttributeEnd(string $selector, int $offset): ?int
    {
        $cursor = $offset + 1;
        self::skipTrivia($selector, $cursor);
        $attribute = self::readCssIdentifier($selector, $cursor);
        if (! in_array($attribute, ['data-ogsc', 'data-outlook-cycle'], true)) {
            return null;
        }

        self::skipTrivia($selector, $cursor);

        return ($selector[$cursor] ?? '') === ']'
            ? $cursor + 1
            : null;
    }

    private static function readCssIdentifier(string $css, int &$offset): ?string
    {
        $start = $offset;
        $decoded = '';
        $length = strlen($css);

        while ($offset < $length) {
            $character = $css[$offset];
            if (preg_match('/[a-z0-9_-]/i', $character) === 1) {
                $decoded .= $character;
                $offset++;

                continue;
            }

            if ($character !== '\\') {
                break;
            }

            $offset++;
            if ($offset >= $length) {
                break;
            }

            $hexStart = $offset;
            while ($offset < $length
                && $offset - $hexStart < 6
                && ctype_xdigit($css[$offset])) {
                $offset++;
            }

            if ($offset > $hexStart) {
                $codepoint = hexdec(substr($css, $hexStart, $offset - $hexStart));
                $decoded .= $codepoint > 0 && $codepoint <= 0x7F
                    ? chr($codepoint)
                    : "\x80";

                if ($offset < $length
                    && $css[$offset] === "\r"
                    && ($css[$offset + 1] ?? '') === "\n") {
                    $offset += 2;
                } elseif ($offset < $length && preg_match('/\s/', $css[$offset]) === 1) {
                    $offset++;
                }

                continue;
            }

            if ($css[$offset] === "\r" && ($css[$offset + 1] ?? '') === "\n") {
                $offset += 2;

                continue;
            }

            if ($css[$offset] === "\r" || $css[$offset] === "\n" || $css[$offset] === "\f") {
                $offset++;

                continue;
            }

            $decoded .= $css[$offset];
            $offset++;
        }

        return $offset === $start ? null : strtolower($decoded);
    }

    private static function assertRootCompoundContained(string $selector, int $offset): void
    {
        $length = strlen($selector);
        $quote = null;
        $roundDepth = 0;
        $squareDepth = 0;

        for ($index = $offset; $index < $length; $index++) {
            $character = $selector[$index];
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '\\') {
                $escapedEnd = $index;
                self::readCssIdentifier($selector, $escapedEnd);
                $index = max($index, $escapedEnd - 1);

                continue;
            }

            if (substr($selector, $index, 2) === '/*') {
                $end = strpos($selector, '*/', $index + 2);
                if ($end === false) {
                    throw new RuntimeException('Das Signatur-CSS enthaelt einen offenen Kommentar.');
                }

                $index = $end + 1;

                continue;
            }

            if ($character === '(') {
                $roundDepth++;

                continue;
            }

            if ($character === ')') {
                $roundDepth--;

                continue;
            }

            if ($character === '[') {
                $squareDepth++;

                continue;
            }

            if ($character === ']') {
                $squareDepth--;

                continue;
            }

            if ($roundDepth !== 0 || $squareDepth !== 0) {
                continue;
            }

            if (in_array($character, ['+', '~'], true)
                || substr($selector, $index, 2) === '||') {
                throw new RuntimeException('Das veroeffentlichte Signatur-CSS darf keine Elemente ausserhalb der Signatur adressieren.');
            }

            if ($character === '>') {
                return;
            }

            if (preg_match('/\s/', $character) === 1) {
                $next = $index;
                self::skipTrivia($selector, $next);
                if (isset($selector[$next])
                    && (in_array($selector[$next], ['+', '~'], true)
                        || substr($selector, $next, 2) === '||')) {
                    throw new RuntimeException('Das veroeffentlichte Signatur-CSS darf keine Elemente ausserhalb der Signatur adressieren.');
                }

                return;
            }
        }
    }

    private static function atRuleName(string $prelude): ?string
    {
        if (($prelude[0] ?? '') !== '@') {
            return null;
        }

        $offset = 1;

        return self::readCssIdentifier($prelude, $offset);
    }

    /**
     * Entfernt nur Selektoren, deren einfache, positiv verlangte Klasse im
     * konkreten Fragment sicher fehlt. Funktions-Pseudoklassen und CSS-Escapes
     * bleiben unangetastet, weil :is(), :not() und aehnliche Konstrukte keine
     * pauschale Klassenkonjunktion bilden.
     *
     * @param  array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string, background_fit: ?string}  $documentTraits
     */
    private static function selectorDefinitelyUnused(string $selector, array $documentTraits): bool
    {
        if (str_contains($selector, '(') || str_contains($selector, '\\')) {
            return false;
        }

        $scanOffset = 0;
        self::skipTrivia($selector, $scanOffset);
        while (($externalContext = self::externalClientContext($selector, $scanOffset)) !== null) {
            [, $scanOffset] = $externalContext;
            self::skipTrivia($selector, $scanOffset);
        }

        $length = strlen($selector);
        $quote = null;
        $squareDepth = 0;

        for ($offset = $scanOffset; $offset < $length; $offset++) {
            $character = $selector[$offset];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if (substr($selector, $offset, 2) === '/*') {
                $end = strpos($selector, '*/', $offset + 2);
                if ($end === false) {
                    return false;
                }

                $offset = $end + 1;

                continue;
            }

            if ($character === '[') {
                $squareDepth++;

                continue;
            }

            if ($character === ']') {
                $squareDepth = max(0, $squareDepth - 1);

                continue;
            }

            if ($character !== '.' || $squareDepth !== 0) {
                continue;
            }

            $classOffset = $offset + 1;
            $className = self::readCssIdentifier($selector, $classOffset);
            if ($className !== null
                && ! isset($documentTraits['classes'][$className])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string, background_fit: ?string}  $documentTraits
     * @return list<string>
     */
    private static function relevantSelectors(
        string $prelude,
        ?string $artifactVersion,
        array $documentTraits,
        bool $includeTemplate = false,
        bool $filterDocumentSelectors = true,
    ): array {
        $selectors = self::splitSelectorList($prelude);

        $relevant = [];
        foreach ($selectors as $selector) {
            $selector = trim($selector);
            if ($selector === ''
                || (! $includeTemplate && str_contains($selector, 'data-rt-signature-density'))
                || ! self::matchesArtifact($selector, $artifactVersion)
                || (! $filterDocumentSelectors && self::hasIndependentTrainGeometry($artifactVersion)
                    && str_contains($selector, 'rt-train-idle'))
                || ($filterDocumentSelectors
                    && (! self::isSignatureSelector($selector, $documentTraits)
                        || ! self::matchesDocument($selector, $documentTraits, $includeTemplate)))) {
                continue;
            }

            if ($filterDocumentSelectors && $documentTraits['background_fit'] !== 'contain') {
                $selector = str_replace(':not([data-rt-bg-desktop-fit="contain"])', '', $selector);
            }
            $relevant[] = self::compactPrelude($selector);
        }

        return array_values(array_unique($relevant));
    }

    /**
     * @return list<string>
     */
    private static function splitSelectorList(string $prelude): array
    {
        $selectors = [];
        $start = 0;
        $length = strlen($prelude);
        $quote = null;
        $roundDepth = 0;
        $squareDepth = 0;

        for ($index = 0; $index < $length; $index++) {
            $character = $prelude[$index];

            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '\\') {
                $index++;

                continue;
            }

            if (substr($prelude, $index, 2) === '/*') {
                $end = strpos($prelude, '*/', $index + 2);
                if ($end === false) {
                    throw new RuntimeException('Das Signatur-CSS enthaelt einen offenen Kommentar.');
                }

                $index = $end + 1;

                continue;
            }

            if ($character === '(') {
                $roundDepth++;
            } elseif ($character === ')') {
                $roundDepth--;
            } elseif ($character === '[') {
                $squareDepth++;
            } elseif ($character === ']') {
                $squareDepth--;
            } elseif ($character === ',' && $roundDepth === 0 && $squareDepth === 0) {
                $selectors[] = trim(substr($prelude, $start, $index - $start));
                $start = $index + 1;
            }

            if ($roundDepth < 0 || $squareDepth < 0) {
                throw new RuntimeException('Das Signatur-CSS besitzt einen ungueltigen Selektor.');
            }
        }

        if ($quote !== null || $roundDepth !== 0 || $squareDepth !== 0) {
            throw new RuntimeException('Das Signatur-CSS besitzt einen unvollstaendigen Selektor.');
        }

        $selectors[] = trim(substr($prelude, $start));
        foreach ($selectors as $selector) {
            if ($selector === '') {
                throw new RuntimeException('Das Signatur-CSS enthaelt einen leeren Selektor.');
            }
        }

        return $selectors;
    }

    private static function matchesArtifact(string $selector, ?string $artifactVersion): bool
    {
        preg_match_all(
            '/\[data-rt-artifact-version\s*=\s*(["\'])(v\d+)\1\]/i',
            $selector,
            $matches,
        );
        $versions = $matches[2] ?? [];

        if ($versions === []) {
            return true;
        }

        return $artifactVersion !== null
            && count(array_filter(
                $versions,
                static fn (string $version): bool => $version === $artifactVersion,
            )) === count($versions);
    }

    /**
     * @param  array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string, background_fit: ?string}  $documentTraits
     */
    private static function isSignatureSelector(string $selector, array $documentTraits): bool
    {
        $selector = strtolower(trim($selector));
        if (in_array($selector, ['a', 'img', 'table', 'td'], true)) {
            return true;
        }

        // Pagebuilder-Klassen sind nicht auf RailTime-Praefixe beschraenkt.
        // Zulassen duerfen wir sie trotzdem nur, wenn mindestens eine Klasse
        // des Selektors im konkreten, bereits validierten Dokument vorkommt;
        // matchesDocument() prueft anschliessend auch alle weiteren Klassen.
        if (preg_match_all('/\.([a-z_][a-z0-9_-]*)/i', $selector, $matches) > 0) {
            foreach ($matches[1] ?? [] as $className) {
                if (isset($documentTraits['classes'][strtolower($className)])) {
                    return true;
                }
            }
        }

        foreach ([
            '.rt-sign',
            '.rt-contact',
            '.rt-company-contact',
            '.rt-person-kopf',
            '.rt-pad',
            '.rt-logo',
            '.rt-train',
            '.rt-stack',
        ] as $needle) {
            if (str_contains($selector, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verwirft Varianten, die im konkreten Dokument nicht vorkommen. Das ist
     * nicht nur eine Groessenoptimierung: eine fremde Layer-Variante darf die
     * kanonische Geometrie des aktiven Artefakts nicht spaeter uebersteuern.
     *
     * @param  array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string, background_fit: ?string}  $documentTraits
     */
    private static function matchesDocument(string $selector, array $documentTraits, bool $includeTemplate = false): bool
    {
        $lowerSelector = strtolower($selector);
        if ((! $includeTemplate && str_contains($lowerSelector, '.rt-card'))
            || (str_contains($lowerSelector, 'rt-train-idle') && ! $documentTraits['has_idle'])) {
            return false;
        }
        if (str_contains($lowerSelector, 'data-rt-bg-desktop-fit')) {
            $legacyOnly = str_contains($lowerSelector, ':not([data-rt-bg-desktop-fit="contain"])');
            if ($legacyOnly === ($documentTraits['background_fit'] === 'contain')) {
                return false;
            }
        }

        preg_match_all('/\.([a-z_][a-z0-9_-]*)/i', $selector, $classMatches);
        foreach ($classMatches[1] ?? [] as $className) {
            if (! isset($documentTraits['classes'][strtolower($className)])) {
                return false;
            }
        }

        foreach (['align', 'size', 'mobile'] as $trait) {
            preg_match_all(
                '/\[data-rt-layer-'.$trait.'\s*=\s*(["\'])([^"\']+)\1\]/i',
                $selector,
                $attributeMatches,
            );
            foreach ($attributeMatches[2] ?? [] as $expected) {
                if ($documentTraits[$trait] === null
                    || strcasecmp($documentTraits[$trait], trim($expected)) !== 0) {
                    return false;
                }
            }

            if (preg_match('/\[data-rt-layer-'.$trait.'(?:\s|\])/i', $selector) === 1
                && $documentTraits[$trait] === null) {
                return false;
            }
        }

        return true;
    }

    /** @return array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string, background_fit: ?string} */
    private static function documentTraits(string $signatureHtml): array
    {
        preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/is', $signatureHtml, $classMatches);
        $classes = [];
        foreach ($classMatches[2] ?? [] as $classAttribute) {
            $classAttribute = html_entity_decode(
                $classAttribute,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            );
            foreach (preg_split('/\s+/u', trim($classAttribute)) ?: [] as $className) {
                if ($className !== '') {
                    $classes[strtolower($className)] = true;
                }
            }
        }

        return [
            'classes' => $classes,
            'has_idle' => str_contains(strtolower($signatureHtml), 'rt-train-idle'),
            'align' => self::attributeValue($signatureHtml, 'data-rt-layer-align'),
            'size' => self::attributeValue($signatureHtml, 'data-rt-layer-size'),
            'mobile' => self::attributeValue($signatureHtml, 'data-rt-layer-mobile'),
            'background_fit' => self::attributeValue($signatureHtml, 'data-rt-bg-desktop-fit'),
        ];
    }

    private static function attributeValue(string $html, string $attribute): ?string
    {
        if (preg_match(
            '/\b'.preg_quote($attribute, '/').'\s*=\s*(["\'])(.*?)\1/is',
            $html,
            $match,
        ) !== 1) {
            return null;
        }

        $value = trim(html_entity_decode(
            $match[2],
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        ));

        return $value !== '' ? $value : null;
    }

    /** @param list<string> $selectors */
    private static function selectorsContain(array $selectors, string $needle): bool
    {
        foreach ($selectors as $selector) {
            if (str_contains(strtolower($selector), strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private static function compactPrelude(string $prelude): string
    {
        $prelude = preg_replace('/\s+/u', ' ', trim($prelude)) ?? trim($prelude);

        return preg_replace('/\s*>\s*/u', '>', $prelude) ?? $prelude;
    }

    private static function compactDeclarations(string $body): string
    {
        $body = preg_replace('/\/\*.*?\*\//s', '', trim($body)) ?? trim($body);
        $body = preg_replace('/\s*!important\b/i', '!important', $body) ?? $body;

        return preg_replace('/\s*([:;,])\s*/u', '$1', $body) ?? $body;
    }

    private static function skipTrivia(string $css, int &$offset): void
    {
        $length = strlen($css);
        while ($offset < $length) {
            if (preg_match('/\s/A', $css, $match, 0, $offset) === 1) {
                $offset += strlen($match[0]);

                continue;
            }

            if (substr($css, $offset, 2) === '/*') {
                $end = strpos($css, '*/', $offset + 2);
                if ($end === false) {
                    throw new RuntimeException('Das kanonische Signatur-CSS enthaelt einen offenen Kommentar.');
                }

                $offset = $end + 2;

                continue;
            }

            break;
        }
    }

    private static function nextOpeningBrace(string $css, int $offset): ?int
    {
        $length = strlen($css);
        $quote = null;

        for ($index = $offset; $index < $length; $index++) {
            $character = $css[$index];
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '\\') {
                $index++;

                continue;
            }

            if (substr($css, $index, 2) === '/*') {
                $end = strpos($css, '*/', $index + 2);
                if ($end === false) {
                    throw new RuntimeException('Das Signatur-CSS enthaelt einen offenen Kommentar.');
                }

                $index = $end + 1;

                continue;
            }

            if ($character === '{') {
                return $index;
            }
        }

        return null;
    }

    private static function matchingBrace(string $css, int $openingBrace): int
    {
        $length = strlen($css);
        $depth = 0;
        $quote = null;

        for ($index = $openingBrace; $index < $length; $index++) {
            $character = $css[$index];
            if ($quote !== null) {
                if ($character === '\\') {
                    $index++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '\\') {
                $index++;

                continue;
            }

            if (substr($css, $index, 2) === '/*') {
                $end = strpos($css, '*/', $index + 2);
                if ($end === false) {
                    throw new RuntimeException('Das Signatur-CSS enthaelt einen offenen Kommentar.');
                }

                $index = $end + 1;

                continue;
            }

            if ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;
                if ($depth === 0) {
                    return $index;
                }
            }
        }

        throw new RuntimeException('Das kanonische Signatur-CSS besitzt eine offene Regel.');
    }

    /** @param array{classes: array<string, true>, has_idle: bool, align: ?string, size: ?string, mobile: ?string, background_fit: ?string} $documentTraits */
    private static function assertResponsive(
        string $css,
        ?string $artifactVersion,
        array $documentTraits,
    ): void {
        if (substr_count($css, self::RUNTIME_MARKER) !== 1
            || strlen($css) > self::MAX_CSS_BYTES
            || stripos($css, '</style') !== false
            || ! str_contains($css, '@media only screen and (max-width: 860px)')
            || ! str_contains($css, '@media only screen and (max-width: 480px)')) {
            throw new RuntimeException('Das kompakte Outlook-Signatur-CSS ist ungueltig oder zu gross.');
        }

        if ($artifactVersion === SignatureArtifactVersion::V20
            && (! str_contains($css, 'margin-bottom:-304px!important')
                || ! str_contains($css, 'margin-bottom:-272px!important'))) {
            throw new RuntimeException('Das Outlook-Signatur-CSS enthaelt nicht die kanonische V20-Mobilgeometrie.');
        }

        if ($artifactVersion === SignatureArtifactVersion::V20
            && $documentTraits['mobile'] === 'stop60'
            && (! str_contains($css, 'width:164%!important')
                || ! str_contains($css, 'margin-left:-40%!important'))) {
            throw new RuntimeException('Das Outlook-Signatur-CSS enthaelt nicht die kanonische V20-stop60-Geometrie.');
        }

        if (SignatureArtifactVersion::usesFlowSafeTrain($artifactVersion)
            && preg_match('/margin-bottom:\s*-\d/i', $css) === 1) {
            throw new RuntimeException('Das Outlook-Signatur-CSS darf den normalen Signaturfluss nicht ueberlagern.');
        }
    }
}
