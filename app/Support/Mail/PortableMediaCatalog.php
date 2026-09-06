<?php

namespace App\Support\Mail;

use App\Enums\MailDocumentKind;
use DOMDocument;
use DOMElement;
use DOMXPath;

/** Verbindliche Systemmedien der portablen Maildokument-Bundles. */
final class PortableMediaCatalog
{
    /**
     * Vollstaendiger Pflichtbestand eines portablen Bundles.
     *
     * Neben den versionsgebundenen RailTime-Systemmedien muessen alle im
     * Kandidaten tatsaechlich verwendeten, inhaltsadressierten Importbilder
     * enthalten sein. Die Erkennung betrachtet nur echte HTML-Bildattribute
     * und CSS-url()-Werte; freie Textvorkommen machen kein Medium erforderlich.
     *
     * @return list<string>
     */
    public static function requiredBundleAssetIds(
        MailDocumentKind|string $kind,
        string $html,
        string $css = '',
    ): array {
        $kind = is_string($kind) ? MailDocumentKind::tryFrom($kind) : $kind;

        return array_values(array_unique([
            ...self::requiredSystemAssetIds(
                $kind ?? '',
                SignatureArtifactVersion::detect($kind ?? '', $html),
            ),
            ...array_keys(self::referencedImportedAssetSources($html, $css)),
        ]));
    }

    /**
     * Ordnet jedes referenzierte Importmedium seinen konkreten Quellwerten zu.
     * Die Werte werden spaeter ausschliesslich lokal auf die neue, anhand des
     * eingebetteten SHA-256 berechnete Zieladresse umgeschrieben. Es findet
     * kein HTTP-Abruf einer Bundle- oder Fremdadresse statt.
     *
     * @return array<string, list<string>> keyed by mail-imports/<sha>.<ext>
     */
    public static function referencedImportedAssetSources(string $html, string $css = ''): array
    {
        $sources = [];

        foreach (self::referencedImageSources($html, $css) as $url) {
            $id = self::importedAssetIdFromUrl($url);
            if ($id === null) {
                continue;
            }

            $sources[$id] ??= [];
            if (! in_array($url, $sources[$id], true)) {
                $sources[$id][] = $url;
            }
        }

        ksort($sources, SORT_STRING);

        return $sources;
    }

    /** @return list<string> */
    public static function referencedImageSources(string $html, string $css = ''): array
    {
        return array_values(array_unique(array_filter(
            [...self::htmlImageUrls($html), ...self::cssImageUrls($css)],
            static fn (string $url): bool => $url !== '',
        )));
    }

    /**
     * Direkte CID-Bildquellen eines gespeicherten Editor-Dokuments.
     *
     * Ein Entwurf besitzt keinen MIME-Anhangvertrag. RailTime-interne CIDs
     * werden deshalb erst nach der serverseitigen Tokenbindung im jeweiligen
     * Transportweg erzeugt; eine hier bereits feste cid:-Quelle waere fuer
     * Save, Import oder Publish nicht transportierbar.
     *
     * @return list<string>
     */
    public static function untransportableCidImageSources(string $html, string $css = ''): array
    {
        return array_values(array_filter(
            self::referencedImageSources($html, $css),
            static function (string $url): bool {
                $url = html_entity_decode(
                    trim($url),
                    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                    'UTF-8',
                );
                $probe = preg_replace('/[\s\x00-\x1f\x7f]+/', '', $url) ?? $url;

                return str_starts_with(strtolower($probe), 'cid:');
            },
        ));
    }

    /** Normalisiert nur inhaltsadressierte Import-IDs; Systemdateinamen bleiben exakt. */
    public static function canonicalAssetId(string $id): string
    {
        $id = trim($id);
        if (preg_match(
            '~^mail-imports/([a-f0-9]{64})\.(gif|png|jpg|webp)$~i',
            $id,
            $match,
        ) !== 1) {
            return $id;
        }

        return 'mail-imports/'.strtolower($match[1].'.'.$match[2]);
    }

    /**
     * Vom Browser verwendete, serverautoritative Medienvertraege.
     *
     * Der aktuell geoeffnete Entwurf darf nicht bestimmen, welche Medien ein
     * neu ausgewaehltes Bundle benoetigt: gerade beim Wechsel von v7 auf v8
     * unterscheiden sich die Zug- und Idle-Dateien absichtlich.
     *
     * @return array<string, list<string>>
     */
    public static function requiredSystemAssetContracts(MailDocumentKind|string $kind): array
    {
        $kind = is_string($kind) ? MailDocumentKind::tryFrom($kind) : $kind;

        return match ($kind) {
            MailDocumentKind::Signature => [
                SignatureArtifactVersion::V7 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V7,
                ),
                SignatureArtifactVersion::V8 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V8,
                ),
                SignatureArtifactVersion::V9 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V9,
                ),
                SignatureArtifactVersion::V10 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V10,
                ),
                SignatureArtifactVersion::V11 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V11,
                ),
                SignatureArtifactVersion::V12 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V12,
                ),
                SignatureArtifactVersion::V13 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V13,
                ),
                SignatureArtifactVersion::V14 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V14,
                ),
                SignatureArtifactVersion::V15 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V15,
                ),
                SignatureArtifactVersion::V16 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V16,
                ),
                SignatureArtifactVersion::V17 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V17,
                ),
                SignatureArtifactVersion::V18 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V18,
                ),
                SignatureArtifactVersion::V19 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V19,
                ),
                SignatureArtifactVersion::V20 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V20,
                ),
                SignatureArtifactVersion::V21 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V21,
                ),
                SignatureArtifactVersion::V22 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V22,
                ),
                SignatureArtifactVersion::V23 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V23,
                ),
                SignatureArtifactVersion::V25 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V25,
                ),
                SignatureArtifactVersion::V26 => self::requiredSystemAssetIds(
                    MailDocumentKind::Signature,
                    SignatureArtifactVersion::V26,
                ),
            ],
            MailDocumentKind::Template => [
                'default' => self::requiredSystemAssetIds(MailDocumentKind::Template),
            ],
            default => ['default' => []],
        };
    }

    /** @return list<string> */
    public static function requiredSystemAssetIds(
        MailDocumentKind|string $kind,
        ?string $artifactVersion = null,
    ): array {
        $kind = is_string($kind) ? MailDocumentKind::tryFrom($kind) : $kind;

        return match ($kind) {
            MailDocumentKind::Signature => array_merge([
                'contact-email.png',
                'contact-location.png',
                'contact-mobile.png',
                'contact-phone.png',
                'contact-web.png',
            ], SignatureArtifactVersion::usesV19MailAssets($artifactVersion)
                ? [
                    'icon-rt-v19-light.gif',
                    'icon-rt-v19-light.png',
                    'icon-rt-v19-dark.gif',
                    'icon-rt-v19-dark.png',
                    'wortmarke-signature-v19-light.gif',
                    'wortmarke-signature-v19-light.png',
                    'wortmarke-mail-v19-dark.gif',
                    'wortmarke-mail-v19-dark.png',
                ]
                : (SignatureArtifactVersion::usesOptimizedMailAssets($artifactVersion)
                ? [
                    'wortmarke-signature-v15-light.gif',
                    'wortmarke-signature-v15-light.png',
                    'wortmarke-mail-v15-dark.gif',
                    'wortmarke-mail-v15-dark.png',
                ]
                : [
                    'wortmarke-signature-light.gif',
                    'wortmarke-signature-light.png',
                    'wortmarke-mail-dark.gif',
                    'wortmarke-mail-dark.png',
                ]), SignatureArtifactVersion::usesV19MailAssets($artifactVersion)
                ? [
                    'zug-dampf-v19-light.gif',
                    'zug-dampf-v19-light.png',
                    'zug-dampf-v19-dark.gif',
                    'zug-dampf-v19-dark.png',
                ]
                : (SignatureArtifactVersion::usesV17TrainAssets($artifactVersion)
                ? [
                    'zug-dampf-v17-light.gif',
                    'zug-dampf-v17-light.png',
                    'zug-dampf-v17-dark.gif',
                    'zug-dampf-v17-dark.png',
                ]
                : (SignatureArtifactVersion::usesOptimizedMailAssets($artifactVersion)
                ? [
                    'zug-dampf-v15-light.gif',
                    'zug-dampf-v15-light.png',
                    'zug-dampf-v15-dark.gif',
                    'zug-dampf-v15-dark.png',
                ]
                : (SignatureArtifactVersion::usesSmokeSafeArrivalTrain($artifactVersion)
                ? [
                    'zug-dampf-v13-light.gif',
                    'zug-dampf-v13-light.png',
                    'zug-dampf-v13-dark.gif',
                    'zug-dampf-v13-dark.png',
                ]
                : (SignatureArtifactVersion::usesOptimizedArrivalTrain($artifactVersion)
                ? [
                    'zug-dampf-v12-light.gif',
                    'zug-dampf-v12-light.png',
                    'zug-dampf-v12-dark.gif',
                    'zug-dampf-v12-dark.png',
                ]
                : (SignatureArtifactVersion::usesArrivalHoldTrain($artifactVersion)
                ? [
                    'zug-dampf-v8-light.gif',
                    'zug-dampf-v8-light.png',
                    'zug-dampf-v8-dark.gif',
                    'zug-dampf-v8-dark.png',
                ]
                : [
                    'zug-dampf-light.gif',
                    'zug-dampf-light.png',
                    'zug-dampf-dark.gif',
                    'zug-dampf-dark.png',
                    'zug-dampf-idle-light.gif',
                    'zug-dampf-idle-dark.gif',
                ])))))),
            MailDocumentKind::Template => [
                'icon-rt-light.gif',
                'icon-rt-light.png',
                'icon-rt-dark.gif',
                'icon-rt-dark.png',
            ],
            default => [],
        };
    }

    /** @return list<string> */
    private static function htmlImageUrls(string $html, int $depth = 0): array
    {
        if ($html === '' || $depth > 3) {
            return [];
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            return [];
        }

        $urls = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach (['src', 'background'] as $attribute) {
                if ($element->hasAttribute($attribute)) {
                    $urls[] = trim($element->getAttribute($attribute));
                }
            }

            if ($element->hasAttribute('style')) {
                array_push($urls, ...self::cssImageUrls($element->getAttribute('style')));
            }

            if (strtolower($element->tagName) === 'style') {
                array_push($urls, ...self::cssImageUrls((string) $element->textContent));
            }
        }

        // Outlook fuehrt das Markup in erlaubten bedingten MSO-Kommentaren
        // aus. DOMDocument behandelt es korrekt als Kommentar, deshalb wird
        // nur genau dessen HTML-Inhalt nochmals ohne Netzwerkkontakt gelesen.
        $xpath = new DOMXPath($document);
        foreach ($xpath->query('//comment()') ?: [] as $comment) {
            if (preg_match(
                '/^\s*\[if\s+(?:(?:gte|lte|gt|lt)\s+)?mso(?:\s+\d{1,4})?\]>(.*)<!\[endif\]\s*$/si',
                (string) $comment->nodeValue,
                $match,
            ) === 1) {
                array_push($urls, ...self::htmlImageUrls((string) $match[1], $depth + 1));
            }
        }

        return array_values(array_filter($urls, static fn (string $url): bool => $url !== ''));
    }

    /**
     * Liest url()-Funktionen ausserhalb von Kommentaren und Zeichenketten.
     * CSS-Escapes muessen nicht aufgeloest werden: der Mail-Sanitizer lehnt
     * Backslashes bewusst ab, bevor ein solcher Wert gespeichert werden kann.
     *
     * @return list<string>
     */
    private static function cssImageUrls(string $css): array
    {
        $urls = [];
        $length = strlen($css);

        for ($index = 0; $index < $length;) {
            if (substr($css, $index, 2) === '/*') {
                $end = strpos($css, '*/', $index + 2);
                $index = $end === false ? $length : $end + 2;

                continue;
            }

            if ($css[$index] === '"' || $css[$index] === "'") {
                $index = self::skipCssString($css, $index);

                continue;
            }

            if (strncasecmp(substr($css, $index, 3), 'url', 3) !== 0
                || ($index > 0 && preg_match('/[A-Za-z0-9_-]/', $css[$index - 1]) === 1)) {
                $index++;

                continue;
            }

            $open = $index + 3;
            while ($open < $length && ctype_space($css[$open])) {
                $open++;
            }
            if ($open >= $length || $css[$open] !== '(') {
                $index++;

                continue;
            }

            $cursor = $open + 1;
            while ($cursor < $length && ctype_space($css[$cursor])) {
                $cursor++;
            }

            if ($cursor < $length && ($css[$cursor] === '"' || $css[$cursor] === "'")) {
                $quote = $css[$cursor];
                $start = ++$cursor;
                while ($cursor < $length && $css[$cursor] !== $quote) {
                    $cursor += $css[$cursor] === '\\' && $cursor + 1 < $length ? 2 : 1;
                }
                if ($cursor < $length) {
                    $urls[] = trim(substr($css, $start, $cursor - $start));
                    $cursor++;
                }
            } else {
                $start = $cursor;
                while ($cursor < $length && $css[$cursor] !== ')') {
                    $cursor++;
                }
                $urls[] = trim(substr($css, $start, $cursor - $start));
            }

            while ($cursor < $length && $css[$cursor] !== ')') {
                $cursor++;
            }
            $index = $cursor < $length ? $cursor + 1 : $length;
        }

        return array_values(array_filter($urls, static fn (string $url): bool => $url !== ''));
    }

    private static function skipCssString(string $css, int $start): int
    {
        $quote = $css[$start];
        $length = strlen($css);

        for ($index = $start + 1; $index < $length; $index++) {
            if ($css[$index] === '\\' && $index + 1 < $length) {
                $index++;

                continue;
            }

            if ($css[$index] === $quote) {
                return $index + 1;
            }
        }

        return $length;
    }

    private static function importedAssetIdFromUrl(string $url): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        if ($url === '' || str_contains($url, '{{')) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== '' && ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        // Der HTML-Sanitizer akzeptiert auch relative Pfade mit Backslashes.
        // Fuer die lokale Katalogpruefung werden nur deren Trenner normalisiert;
        // der originale Attributwert bleibt fuer die exakte Ersetzung erhalten.
        $path = str_replace('\\', '/', rawurldecode($path));
        if (preg_match(
            '~(?:^|/)(?:storage/)?mail-imports/([a-f0-9]{64})\.(gif|png|jpg|webp)$~i',
            $path,
            $match,
        ) !== 1) {
            return null;
        }

        return 'mail-imports/'.strtolower($match[1].'.'.$match[2]);
    }
}
