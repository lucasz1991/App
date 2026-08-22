<?php

namespace App\Support\Mail;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Ein gemeinsamer, fail-closed Vertrag fuer den Zug-Carrier im Editor und
 * beim Versand. Gueltige Legacy-Longhands werden dabei nur im Runtime-Ergebnis
 * auf den heutigen Hauptzug normalisiert; das gespeicherte HTML bleibt gleich.
 */
final class SignatureTrainCarrier
{
    private const STAGE_HEIGHT = '200px';

    private const STAGE_HEIGHT_ATTRIBUTE = '200';

    private const TRAIN_OVERLAP = '-200px';

    /** @var array<string, array{width:string,maxWidth:string,centerLeft:string,rightLeft:string}> */
    private const CANONICAL_LAYER_SIZE = [
        '100' => ['width' => '100%', 'maxWidth' => '1815px', 'centerLeft' => '0', 'rightLeft' => '0'],
        '125' => ['width' => '125%', 'maxWidth' => '2269px', 'centerLeft' => '-12.5%', 'rightLeft' => '-25%'],
        '150' => ['width' => '150%', 'maxWidth' => '2723px', 'centerLeft' => '-25%', 'rightLeft' => '-50%'],
        '200' => ['width' => '200%', 'maxWidth' => '3630px', 'centerLeft' => '-50%', 'rightLeft' => '-100%'],
    ];

    /** @var list<string> */
    private const CANONICAL_MOBILE_CROPS = [
        'left',
        'center',
        'train',
        'right',
    ];

    /** @var list<string> */
    private const ALLOWED_STYLE_TOKENS = [
        'SIGNATURE_BG',
        'GRUND_RASTER_SRC',
        'GRUND_MARKE_SRC',
        'SIGNATURE_TRAIN_WASH',
        'TRAIN_SRC',
        'TRAIN_IDLE_SRC',
    ];

    /** @var list<string> */
    private const ALLOWED_TRAIN_POSITIONS = [
        'left bottom',
        '25% bottom',
        '50% bottom',
        '75% bottom',
        'right bottom',
    ];

    public static function isValid(string $html): bool
    {
        try {
            self::normalize($html);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function normalize(string $html): string
    {
        if (self::hasCanonicalImage($html)) {
            try {
                self::assertCanonicalImage($html);

                return $html;
            } catch (RuntimeException) {
                try {
                    self::assertCanonicalImage(
                        $html,
                        allowLegacyContentFirst: true,
                        allowLegacyExpandedFlowLayer: true,
                    );
                } catch (RuntimeException) {
                    try {
                        self::assertCanonicalImage(
                            $html,
                            allowLegacyContentFirst: true,
                            allowLegacyPercentHeight: true,
                            allowLegacyAbsoluteLayer: true,
                        );
                    } catch (RuntimeException) {
                        self::assertCanonicalImage(
                            $html,
                            allowLegacyDirectLayer: true,
                            allowLegacyPercentHeight: true,
                            allowLegacyAbsoluteLayer: true,
                        );
                        $html = self::wrapLegacyDirectCarrierInStage($html);
                    }
                }
            }

            $html = self::withCanonicalTrainFirstStage($html);
            $html = self::normalizeImageToCanonicalFlow($html);
            self::assertCanonicalImage($html);

            return $html;
        }

        if (substr_count($html, '{{TRAIN_SRC}}') !== 1) {
            throw new RuntimeException(
                'Die veroeffentlichte Signatur besitzt keinen eindeutigen Zug-Platzhalter.'
            );
        }

        if (substr_count($html, '{{TRAIN_IDLE_SRC}}') > 1) {
            throw new RuntimeException(
                'Die veroeffentlichte Signatur besitzt mehrere Idle-Zug-Platzhalter.'
            );
        }

        $carrier = self::inspectCarrier($html);
        $styles = $carrier['attributes']['style'] ?? [];
        if (count($styles) !== 1 || $styles[0]['valueOffset'] === null) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier besitzt kein eindeutiges style-Attribut.'
            );
        }

        $styleAttribute = $styles[0];
        $style = $styleAttribute['raw'];
        // Genau eine HTML-Entity-Dekodierung spiegelt den Zustand wider, den
        // der Mailclient aus dem Attribut als CSS liest. Andernfalls koennte
        // etwa `&#59;background:none` fuer den Rohparser unsichtbar bleiben.
        $decodedStyle = CssSemantic::decodeHtmlEntitiesOnce($style);
        $normalizedStyle = htmlspecialchars(
            self::normalizeStyle($decodedStyle),
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        $normalized = $normalizedStyle === $style
            ? $html
            : substr_replace(
                $html,
                $normalizedStyle,
                $styleAttribute['valueOffset'],
                $styleAttribute['valueLength'],
            );

        if (substr_count($normalized, '{{TRAIN_SRC}}') !== 1
            || str_contains($normalized, '{{TRAIN_IDLE_SRC}}')) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier konnte nicht eindeutig normalisiert werden.'
            );
        }

        return $normalized;
    }

    /**
     * Loest den nach demselben Vertrag validierten Hauptzug aus den vier
     * parallelen Background-Listen. Raster, Wasserzeichen und Grundschleier
     * bleiben unveraendert; der Zug kann danach genau einmal als regulaeres
     * IMG ausgegeben werden.
     */
    public static function withoutMainLayer(string $html): string
    {
        $normalized = self::normalize($html);
        $carrier = self::inspectCarrier($normalized);
        $styles = $carrier['attributes']['style'] ?? [];
        if (count($styles) !== 1 || $styles[0]['valueOffset'] === null) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier besitzt kein eindeutiges style-Attribut.'
            );
        }

        $styleAttribute = $styles[0];
        $projectedStyle = htmlspecialchars(
            self::normalizeStyle(
                CssSemantic::decodeHtmlEntitiesOnce($styleAttribute['raw']),
                removeMainLayer: true,
            ),
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        $projected = substr_replace(
            $normalized,
            $projectedStyle,
            $styleAttribute['valueOffset'],
            $styleAttribute['valueLength'],
        );

        if (str_contains($projected, '{{TRAIN_SRC}}')
            || str_contains($projected, '{{TRAIN_IDLE_SRC}}')) {
            throw new RuntimeException(
                'Der Hauptzug konnte nicht eindeutig aus dem Background-Carrier geloest werden.'
            );
        }

        return $projected;
    }

    /**
     * Projiziert den streng validierten Carrier fuer alle Ausgaben in den
     * Ein-GIF-Vertrag von Logo und RT-Icon. Der Bild-Layer steht im DOM vor
     * der Kontakttabelle und wird durch eine negative untere Margin mit dem
     * danach folgenden Inhalt ueberlappt. Die sichtbare Reihenfolge ist damit
     * auch ohne z-index eindeutig und in Outlook reproduzierbar.
     */
    public static function projectAsImage(string $html, string $source, string $padding = '0'): string
    {
        $source = trim($source);
        if ($source === '') {
            throw new RuntimeException('Die Zuganimation besitzt keine eindeutige Bildquelle.');
        }

        if (self::hasCanonicalImage($html)) {
            $html = self::normalize($html);
        } elseif (self::hasCanonicalBackground($html)) {
            $html = self::projectCanonicalBackgroundToImage($html);
        } else {
            $html = self::withoutMainLayer($html);
            $html = self::compactDefaultContentPadding($html);
            $marker = '<!-- RT_SIGNATURE_MAIN_END -->';
            if (substr_count($html, $marker) !== 1) {
                throw new RuntimeException(
                    'Die veroeffentlichte Signatur besitzt keinen eindeutigen Zug-Layer-Anker.'
                );
            }

            $markerOffset = strpos($html, $marker);
            $beforeMarker = substr($html, 0, $markerOffset);
            if (preg_match('/<\/td>[ \t\r\n\f]*<\/tr>[ \t\r\n\f]*$/i', $beforeMarker, $match, PREG_OFFSET_CAPTURE) !== 1) {
                throw new RuntimeException('Der Zug-Layer konnte nicht innerhalb des Carriers verankert werden.');
            }

            $carrierCloseOffset = $match[0][1];
            $carrier = self::inspectCarrier($html);
            $contentOffset = $carrier['tagEnd'] + 1;
            if ($contentOffset <= 0 || $contentOffset > $carrierCloseOffset) {
                throw new RuntimeException('Der Zug-Carrier konnte nicht in eine sichere Buehne projiziert werden.');
            }

            $content = substr($html, $contentOffset, $carrierCloseOffset - $contentOffset);
            $stage = self::canonicalStageMarkup($content, self::canonicalLayerMarkup('{{TRAIN_SRC}}'));
            $html = substr_replace(
                $html,
                $stage,
                $contentOffset,
                $carrierCloseOffset - $contentOffset,
            );
            $html = self::normalizeImageToCanonicalFlow($html);
        }

        self::assertCanonicalImage($html);
        $escapedSource = htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $replacements = 0;
        $projected = preg_replace_callback(
            '/\bsrc\s*=\s*(["\'])\{\{TRAIN_SRC\}\}\1/i',
            static function (array $match) use ($escapedSource, &$replacements): string {
                $replacements++;

                return 'src='.$match[1].$escapedSource.$match[1];
            },
            $html,
        );
        if (! is_string($projected) || $replacements !== 1) {
            throw new RuntimeException('Das kanonische Zugbild konnte nicht eindeutig befuellt werden.');
        }

        return self::compactDefaultContentPadding($projected);
    }

    /**
     * Outlook-Desktop erhaelt dasselbe mail-sichere Bildprinzip wie Logo und
     * RT-Zeichen: ein bedingtes, regulaeres IMG am Anfang des normal
     * fliessenden Zug-Layers. Damit wirken dieselbe Layer-Margin und dieselbe
     * Quellreihenfolge auf Hauptbild und Outlook-Standbild; VML oder CSS-
     * Backgrounds sind nicht erforderlich.
     */
    public static function withMsoFallback(string $html, string $source): string
    {
        $source = trim($source);
        if ($source === '' || ! self::isAllowedMailImageSource($source, staticOnly: true)) {
            throw new RuntimeException('Das Outlook-Standbild des Zuges besitzt keine Bildquelle.');
        }

        self::assertRuntimeImages($html);
        if (preg_match(
            '/<!--\s*\[if\s+mso\]\s*>.*?\brt-sign-train-mso\b.*?<!\s*\[endif\]\s*-->/is',
            $html,
        ) === 1) {
            throw new RuntimeException('Der Outlook-Zugfallback kann nicht eindeutig verankert werden.');
        }

        $layers = [];
        $slots = [];
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] === 'div' && self::sourceTagHasClass($tag, 'rt-sign-train-layer')) {
                $layers[] = $tag;
            }
            if ($tag['name'] === 'td' && self::sourceTagHasClass($tag, 'rt-sign-train-slot')) {
                $slots[] = $tag;
            }
        }
        if (count($layers) !== 1 || count($slots) !== 1) {
            throw new RuntimeException('Der Outlook-Zugfallback besitzt keinen eindeutigen Zug-Layer.');
        }
        $sizeName = strtolower(self::singleTagAttributeValue($layers[0], 'data-rt-layer-size'));
        $alignment = strtolower(self::singleTagAttributeValue($layers[0], 'data-rt-layer-align'));
        $size = self::CANONICAL_LAYER_SIZE[$sizeName] ?? null;
        if (! is_array($size) || ! in_array($alignment, ['left', 'center', 'right'], true)) {
            throw new RuntimeException('Der Outlook-Zugfallback besitzt keine kanonische Bildgroesse.');
        }

        $escapedSource = htmlspecialchars(
            $source,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        $fallback = '<!--[if mso]><img class="rt-sign-train-mso" data-rt-train-mso="1" src="'.$escapedSource.'" width="720" alt="" '
            .'style="display:inline-block;width:'.$size['width'].';max-width:none;height:auto;margin:'.self::imageMargin($alignment, $size).';border:0;outline:none;text-decoration:none;vertical-align:bottom;"><![endif]-->';

        $html = substr_replace($html, $fallback, $slots[0]['endOffset'] + 1, 0);
        self::assertRuntimeImages($html, expectedMsoSource: $source);

        return $html;
    }

    /**
     * Legt die transparente Endlos-Rauchschleife als echtes IMG in einen
     * hoehenlosen Holder direkt vor das Haupt-GIF. Moderne Clients legen den
     * Holder absolut ueber den Hauptzug. Entfernt ein Mailclient dagegen die
     * absolute Positionierung, bleibt der Holder nullhoch und abgeschnitten;
     * nur das danach folgende Haupt-IMG kann dann noch Flow-Hoehe belegen.
     */
    public static function withIdleOverlay(string $html, string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            return $html;
        }
        if (preg_match('/\b(?:data-rt-train-idle-(?:overlay|image)|rt-train-idle-(?:overlay|image))\b/i', $html) === 1) {
            throw new RuntimeException('Die Signatur enthaelt bereits eine unzulaessige Idle-Rauchebene.');
        }

        self::assertRuntimeImages($html);

        $layers = [];
        $images = [];
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] === 'div' && self::sourceTagHasClass($tag, 'rt-sign-train-layer')) {
                $layers[] = $tag;
            }
            if ($tag['name'] === 'img' && self::sourceTagHasClass($tag, 'rt-sign-train')) {
                $images[] = $tag;
            }
        }
        if (count($layers) !== 1 || count($images) !== 1) {
            throw new RuntimeException('Die Idle-Rauchebene besitzt keinen eindeutigen Zugbild-Anker.');
        }

        if (! self::isAllowedMailImageSource($source)) {
            throw new RuntimeException('Die Idle-Rauchquelle ist fuer ein E-Mail-Bild nicht zulaessig.');
        }

        $escapedSource = htmlspecialchars(
            $source,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        $sizeName = strtolower(self::singleTagAttributeValue($layers[0], 'data-rt-layer-size'));
        $alignment = strtolower(self::singleTagAttributeValue($layers[0], 'data-rt-layer-align'));
        $size = self::CANONICAL_LAYER_SIZE[$sizeName] ?? null;
        if (! is_array($size) || ! in_array($alignment, ['left', 'center', 'right'], true)) {
            throw new RuntimeException('Die Idle-Rauchebene besitzt keine kanonische Bildgroesse.');
        }
        $overlay = '<span class="rt-train-idle-overlay" data-rt-train-idle-overlay '
            .'style="position:absolute;left:0;right:auto;top:auto;bottom:0;display:block;width:100%;max-width:none;height:0;max-height:0;margin:0;overflow:hidden;z-index:1;font-size:0;line-height:0;text-align:left;opacity:0;visibility:hidden;animation:rt-train-idle-reveal 1ms step-start 13s forwards;mso-hide:all;">'
            .'<img class="rt-train-idle-image" data-rt-train-idle-image src="'.$escapedSource.'" width="720" alt="" '
            .'style="position:absolute;left:0;right:auto;bottom:0;display:inline-block;width:'.$size['width'].';max-width:none;height:auto;margin:'.self::imageMargin($alignment, $size).';border:0;outline:none;text-decoration:none;vertical-align:bottom;z-index:1;mso-hide:all;">'
            .'</span>';

        $html = substr_replace($html, $overlay, $images[0]['startOffset'], 0);
        self::assertRuntimeImages($html, expectedIdleSource: $source);

        return $html;
    }

    /**
     * Entfernt ein altes HTML-background-Attribut ausschliesslich am zuvor
     * DOM- und quellseitig korrelierten Carrier. Aehnlich benannte data-*
     * Attribute oder Attributtexte koennen den positionssicheren Scanner
     * nicht umlenken.
     */
    public static function withoutLegacyBackgroundAttribute(string $html): string
    {
        $carrier = self::inspectCarrier($html);
        $attributes = $carrier['attributes']['background'] ?? [];
        if ($attributes === []) {
            return $html;
        }
        if (count($attributes) !== 1) {
            throw new RuntimeException('Der Zug-Carrier besitzt das background-Attribut mehrfach.');
        }

        $attribute = $attributes[0];

        return substr_replace(
            $html,
            '',
            $attribute['attributeOffset'],
            $attribute['attributeLength'],
        );
    }

    public static function hasCanonicalImage(string $html): bool
    {
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] === 'img'
                && (isset($tag['attributes']['data-rt-train'])
                    || self::sourceTagHasClass($tag, 'rt-sign-train'))) {
                return true;
            }
        }

        return false;
    }

    public static function hasCanonicalBackground(string $html): bool
    {
        return preg_match('/<td\b[^>]*\bdata-rt-train-background\s*=\s*(["\'])1\1/i', $html) === 1;
    }

    /** Schema 20: genau zwei Zellen-Backgrounds, Zug zuerst und Wash darunter. */
    public static function assertCanonicalBackground(string $html): void
    {
        if (substr_count($html, '{{TRAIN_SRC}}') !== 1
            || str_contains($html, '{{TRAIN_IDLE_SRC}}')
            || self::hasCanonicalImage($html)) {
            throw new RuntimeException('Der CSS-Zughintergrund muss genau einmal und ohne zusaetzliches Zug-IMG vorliegen.');
        }

        $carrier = self::inspectCarrier($html);
        if (count($carrier['attributes']['data-rt-train-background'] ?? []) !== 1
            || self::singleCarrierAttributeValue($carrier, 'data-rt-train-background') !== '1') {
            throw new RuntimeException('Der Zug-Carrier besitzt nicht den kanonischen Background-Marker.');
        }
        $styles = $carrier['attributes']['style'] ?? [];
        if (count($styles) !== 1) {
            throw new RuntimeException('Der Zug-Carrier besitzt kein eindeutiges style-Attribut.');
        }
        $style = CssSemantic::decodeHtmlEntitiesOnce((string) $styles[0]['raw']);
        $allowed = str_replace(['{{TRAIN_SRC}}', '{{SIGNATURE_BG}}', '{{SIGNATURE_TRAIN_WASH}}'], '', $style);
        if (preg_match('/[{}]/', $allowed) === 1) {
            throw new RuntimeException('Der CSS-Zughintergrund enthaelt einen fremden Platzhalter.');
        }
        foreach ([
            'background-image' => "url('{{TRAIN_SRC}}'),linear-gradient({{SIGNATURE_TRAIN_WASH}},{{SIGNATURE_TRAIN_WASH}})",
            'background-repeat' => 'no-repeat,no-repeat',
            'background-position' => 'left bottom,center center',
            'background-size' => '100% auto,100% 100%',
        ] as $property => $expected) {
            if (preg_match('/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*([^;]+)/i', $style, $match) !== 1
                || self::normalizedCssValue($match[1]) !== self::normalizedCssValue($expected)) {
                throw new RuntimeException('Der CSS-Zughintergrund besitzt keine kanonische '.$property.'-Angabe.');
            }
        }
    }

    /**
     * Neuer Import-/Editorvertrag: TRAIN_SRC lebt ausschliesslich im src
     * eines einzigen normalen Bildes. Der statische Flow-Layer steht als erstes
     * Element der Buehne; seine negative untere Margin zieht den danach
     * folgenden Inhaltsblock mailclient-sicher ueber das Motiv.
     */
    public static function assertCanonicalImage(
        string $html,
        bool $allowLegacyDirectLayer = false,
        bool $allowLegacyContentFirst = false,
        bool $allowLegacyPercentHeight = false,
        bool $allowLegacyAbsoluteLayer = false,
        bool $allowLegacyExpandedFlowLayer = false,
    ): void {
        if (substr_count($html, '{{TRAIN_SRC}}') !== 1
            || str_contains($html, '{{TRAIN_IDLE_SRC}}')) {
            throw new RuntimeException('Die Signatur benoetigt genau ein kanonisches Zugbild.');
        }

        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><table id="rt-train-image-contract"><tbody>'.$html.'</tbody></table>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $wrapper = $loaded ? $document->getElementById('rt-train-image-contract') : null;
        if (! $wrapper instanceof DOMElement) {
            throw new RuntimeException('Das kanonische Zugbild konnte nicht gelesen werden.');
        }

        $carriers = [];
        $images = [];
        $layers = [];
        $stages = [];
        $trainFrames = [];
        $trainSlots = [];
        $contentFrames = [];
        foreach ($wrapper->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            $classes = preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($element->tagName === 'td' && in_array('rt-sign-cell', $classes, true)) {
                $carriers[] = $element;
            }
            if ($element->tagName === 'img'
                && ($element->hasAttribute('data-rt-train') || in_array('rt-sign-train', $classes, true))) {
                $images[] = $element;
            }
            if ($element->tagName === 'div'
                && ($element->hasAttribute('data-rt-layer-train') || in_array('rt-sign-train-layer', $classes, true))) {
                $layers[] = $element;
            }
            if ($element->tagName === 'div' && in_array('rt-sign-stage', $classes, true)) {
                $stages[] = $element;
            }
            if ($element->tagName === 'table' && in_array('rt-sign-train-frame', $classes, true)) {
                $trainFrames[] = $element;
            }
            if ($element->tagName === 'td' && in_array('rt-sign-train-slot', $classes, true)) {
                $trainSlots[] = $element;
            }
            if ($element->tagName === 'table' && in_array('rt-sign-content-frame', $classes, true)) {
                $contentFrames[] = $element;
            }
        }

        $image = $images[0] ?? null;
        $layer = $layers[0] ?? null;
        $stage = $stages[0] ?? null;
        $carrier = $carriers[0] ?? null;
        $trainFrame = $trainFrames[0] ?? null;
        $trainSlot = $trainSlots[0] ?? null;
        $contentFrame = $contentFrames[0] ?? null;
        $legacyDirectLayer = $allowLegacyDirectLayer && count($stages) === 0;
        $allowLegacyStructure = $allowLegacyDirectLayer
            || $allowLegacyContentFirst
            || $allowLegacyPercentHeight
            || $allowLegacyAbsoluteLayer
            || $allowLegacyExpandedFlowLayer;
        $usesFixedPixelStructure = count($trainFrames) === 1
            && count($trainSlots) === 1
            && count($contentFrames) === 1
            && $trainFrame instanceof DOMElement
            && $trainSlot instanceof DOMElement
            && $contentFrame instanceof DOMElement;
        $usesLegacyStructure = $allowLegacyStructure
            && $trainFrames === []
            && $trainSlots === []
            && $contentFrames === [];
        if (count($carriers) !== 1
            || count($images) !== 1
            || count($layers) !== 1
            || (! $legacyDirectLayer && count($stages) !== 1)
            || ! $image instanceof DOMElement
            || ! $layer instanceof DOMElement
            || (! $legacyDirectLayer && ! $stage instanceof DOMElement)
            || ! $carrier instanceof DOMElement
            || ! $image->hasAttribute('data-rt-train')
            || ! $layer->hasAttribute('data-rt-layer-train')
            || ! $layer->hasAttribute('data-rt-layer-align')
            || self::elementClasses($layer) !== ['rt-sign-train-layer']
            || self::elementClasses($image) !== ['rt-sign-train']
            || $image->getAttribute('src') !== '{{TRAIN_SRC}}'
            || (! $usesFixedPixelStructure && ! $usesLegacyStructure)) {
            throw new RuntimeException('Das Zugmotiv muss genau einmal im kanonischen Bild-Layer vorliegen.');
        }

        $legacyCarrierElements = [];
        if ($legacyDirectLayer) {
            foreach ($carrier->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $legacyCarrierElements[] = $child;
                }
            }
        }
        $validLegacyDirectOrder = count($legacyCarrierElements) === 2
            && (($legacyCarrierElements[0]->isSameNode($layer)
                    && strtolower($legacyCarrierElements[1]->tagName) === 'table')
                || (strtolower($legacyCarrierElements[0]->tagName) === 'table'
                    && $legacyCarrierElements[1]->isSameNode($layer)));
        $validStructure = $legacyDirectLayer
            ? $layer->parentNode?->isSameNode($carrier) && $validLegacyDirectOrder
            : $stage instanceof DOMElement
                && $layer->parentNode?->isSameNode($stage)
                && $stage->parentNode?->isSameNode($carrier)
                && self::lastElementChild($carrier)?->isSameNode($stage);
        $trainRow = $trainSlot instanceof DOMElement ? $trainSlot->parentNode : null;
        $validFixedPixelNesting = $usesFixedPixelStructure
            && $trainFrame instanceof DOMElement
            && $trainSlot instanceof DOMElement
            && $contentFrame instanceof DOMElement
            && $trainFrame->parentNode?->isSameNode($layer)
            && $trainRow instanceof DOMElement
            && strtolower($trainRow->tagName) === 'tr'
            && $trainRow->parentNode instanceof DOMElement
            && in_array(strtolower($trainRow->parentNode->tagName), ['table', 'tbody'], true)
            && ($trainRow->parentNode->isSameNode($trainFrame)
                || $trainRow->parentNode->parentNode?->isSameNode($trainFrame))
            && $trainSlot->parentNode?->isSameNode($trainRow)
            && $image->parentNode?->isSameNode($trainSlot)
            && $contentFrame->parentNode?->isSameNode($stage);
        $validLegacyNesting = $usesLegacyStructure && $image->parentNode?->isSameNode($layer);
        if ((! $validFixedPixelNesting && ! $validLegacyNesting) || ! $validStructure) {
            throw new RuntimeException('Der Zug-Layer muss in der sicheren Buehne des Signatur-Carriers liegen.');
        }

        if ($usesFixedPixelStructure) {
            self::assertCanonicalPixelFrames($trainFrame, $trainSlot, $contentFrame);
        }

        self::assertExactElementAttributeNames($layer, [
            'class',
            'data-rt-layer-train',
            'data-rt-layer-align',
            'data-rt-layer-size',
            'data-rt-layer-mobile',
            'style',
        ], 'Zug-Layer');
        self::assertExactElementAttributeNames($image, [
            'class',
            'data-rt-train',
            'src',
            'width',
            'alt',
            'style',
        ], 'Zugbild');
        if ($layer->getAttribute('data-rt-layer-train') !== ''
            || $image->getAttribute('data-rt-train') !== ''
            || $image->getAttribute('alt') !== '') {
            throw new RuntimeException('Der Zug-Layer besitzt fremde oder ungueltige Bildattribute.');
        }

        $sourceLayers = [];
        $sourceImages = [];
        $sourceStages = [];
        $sourceTrainFrames = [];
        $sourceTrainSlots = [];
        $sourceContentFrames = [];
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] === 'div' && self::sourceTagHasClass($tag, 'rt-sign-stage')) {
                $sourceStages[] = $tag;
            }
            if ($tag['name'] === 'div'
                && (self::sourceTagHasClass($tag, 'rt-sign-train-layer')
                    || isset($tag['attributes']['data-rt-layer-train']))) {
                $sourceLayers[] = $tag;
            }
            if ($tag['name'] === 'img'
                && (self::sourceTagHasClass($tag, 'rt-sign-train')
                    || isset($tag['attributes']['data-rt-train']))) {
                $sourceImages[] = $tag;
            }
            if ($tag['name'] === 'table' && self::sourceTagHasClass($tag, 'rt-sign-train-frame')) {
                $sourceTrainFrames[] = $tag;
            }
            if ($tag['name'] === 'td' && self::sourceTagHasClass($tag, 'rt-sign-train-slot')) {
                $sourceTrainSlots[] = $tag;
            }
            if ($tag['name'] === 'table' && self::sourceTagHasClass($tag, 'rt-sign-content-frame')) {
                $sourceContentFrames[] = $tag;
            }
        }
        if (count($sourceLayers) !== 1 || count($sourceImages) !== 1) {
            throw new RuntimeException('Der Quellvertrag des Zugbildes ist nicht eindeutig.');
        }
        self::assertExactSourceTagAttributeNames($sourceLayers[0], [
            'class',
            'data-rt-layer-train',
            'data-rt-layer-align',
            'data-rt-layer-size',
            'data-rt-layer-mobile',
            'style',
        ], 'Zug-Layer');
        self::assertExactSourceTagAttributeNames($sourceImages[0], [
            'class',
            'data-rt-train',
            'src',
            'width',
            'alt',
            'style',
        ], 'Zugbild');
        if ($usesFixedPixelStructure) {
            if (count($sourceStages) !== 1
                || count($sourceTrainFrames) !== 1
                || count($sourceTrainSlots) !== 1
                || count($sourceContentFrames) !== 1) {
                throw new RuntimeException('Der Quellvertrag der festen Zug-Buehne ist nicht eindeutig.');
            }
            self::assertExactSourceTagAttributeNames($sourceStages[0], [
                'class',
                'style',
            ], 'Signatur-Buehne');
            self::assertExactSourceTagAttributeNames($sourceTrainFrames[0], [
                'class',
                'role',
                'width',
                'height',
                'border',
                'cellspacing',
                'cellpadding',
                'style',
            ], 'Zug-Rahmen');
            self::assertExactSourceTagAttributeNames($sourceTrainSlots[0], [
                'class',
                'height',
                'valign',
                'style',
            ], 'Zug-Slot');
            self::assertExactSourceTagAttributeNames($sourceContentFrames[0], [
                'class',
                'role',
                'width',
                'height',
                'border',
                'cellspacing',
                'cellpadding',
                'style',
            ], 'Signatur-Inhaltsrahmen');
        } elseif ($sourceTrainFrames !== [] || $sourceTrainSlots !== [] || $sourceContentFrames !== []) {
            throw new RuntimeException('Eine unvollstaendige feste Zug-Buehne ist nicht zulaessig.');
        }

        if (! $legacyDirectLayer && $stage instanceof DOMElement) {
            if ($usesFixedPixelStructure) {
                self::assertExactElementAttributeNames($stage, ['class', 'style'], 'Signatur-Buehne');
                self::assertExactSimpleStyle($stage, [
                    'position' => 'relative',
                    'height' => self::STAGE_HEIGHT,
                    'max-height' => self::STAGE_HEIGHT,
                    'overflow' => 'hidden',
                ], 'Signatur-Buehne');
            } else {
                self::assertExactSimpleStyle($stage, [
                    'position' => 'relative',
                    'overflow' => 'hidden',
                ], 'alte Signatur-Buehne');
            }

            $stageElements = [];
            foreach ($stage->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $stageElements[] = $child;
                }
            }
            $usesCurrentOrder = count($stageElements) === 2
                && $stageElements[0]->isSameNode($layer)
                && strtolower($stageElements[1]->tagName) === 'table'
                && (! $usesFixedPixelStructure || $stageElements[1]->isSameNode($contentFrame));
            $usesLegacyContentFirst = $allowLegacyContentFirst
                && count($stageElements) === 2
                && strtolower($stageElements[0]->tagName) === 'table'
                && $stageElements[1]->isSameNode($layer);
            if (! $usesCurrentOrder && ! $usesLegacyContentFirst) {
                throw new RuntimeException('Die Signatur-Buehne muss Zug-Layer und Inhaltstabelle in eindeutiger Reihenfolge enthalten.');
            }
            // Der nachfolgende Tabelleninhalt gewinnt allein durch die
            // Quellreihenfolge. Weder der Zug noch diese Tabelle duerfen fuer
            // die sichere Ueberlagerung von z-index abhaengen.
        }

        $alignment = strtolower(trim($layer->getAttribute('data-rt-layer-align')));
        $sizeName = strtolower(trim($layer->getAttribute('data-rt-layer-size')));
        $mobileCrop = strtolower(trim($layer->getAttribute('data-rt-layer-mobile')));
        if (($sizeName === '') !== ($mobileCrop === '')
            || (! $legacyDirectLayer && ($sizeName === '' || $mobileCrop === ''))) {
            throw new RuntimeException('Der Zug-Layer besitzt nicht alle mail-sicheren Geometrieangaben.');
        }
        $sizeName = $sizeName === '' ? '100' : $sizeName;
        $mobileCrop = $mobileCrop === '' ? 'train' : $mobileCrop;
        $size = self::CANONICAL_LAYER_SIZE[$sizeName] ?? null;
        if (! in_array($alignment, ['left', 'center', 'right'], true)
            || ! is_array($size)
            || ! in_array($mobileCrop, self::CANONICAL_MOBILE_CROPS, true)) {
            throw new RuntimeException('Der Zug-Layer besitzt keine erlaubte horizontale Position.');
        }
        $expandedFlowHorizontal = match ($alignment) {
            'left' => ['left' => '0', 'right' => 'auto'],
            'center' => ['left' => $size['centerLeft'], 'right' => 'auto'],
            'right' => ['left' => $size['rightLeft'], 'right' => 'auto'],
        };
        $legacyHorizontal = match ($alignment) {
            'left' => ['left' => '0', 'right' => 'auto'],
            'center' => ['left' => $size['centerLeft'], 'right' => 'auto'],
            'right' => ['left' => 'auto', 'right' => '0'],
        };
        $layerMargin = self::layerMargin($alignment);
        $imageMargin = self::imageMargin($alignment, $size);
        $layerStyle = [
            'display' => 'block',
            'width' => '100%',
            'max-width' => '1815px',
            'margin' => $layerMargin,
            'overflow' => 'hidden',
            'font-size' => '0',
            'line-height' => '0',
            'text-align' => 'left',
        ];
        $imageStyle = [
            'position' => 'static',
            'left' => 'auto',
            'right' => 'auto',
            'bottom' => 'auto',
            'display' => 'inline-block',
            'width' => $size['width'],
            'max-width' => 'none',
            'height' => 'auto',
            'margin' => $imageMargin,
            'border' => '0',
            'outline' => 'none',
            'text-decoration' => 'none',
            'vertical-align' => 'top',
            'mso-hide' => 'all',
        ];
        if ($usesFixedPixelStructure) {
            self::assertExactSimpleStyle($layer, [
                'display' => 'block',
                'width' => '100%',
                'height' => self::STAGE_HEIGHT,
                'max-height' => self::STAGE_HEIGHT,
                'max-width' => '1815px',
                'margin' => $layerMargin,
                'margin-bottom' => self::TRAIN_OVERLAP,
                'overflow' => 'hidden',
                'font-size' => '0',
                'line-height' => '0',
                'text-align' => 'left',
            ], 'Zug-Layer');
            $fixedImageStyle = $imageStyle;
            $fixedImageStyle['vertical-align'] = 'bottom';
            self::assertExactSimpleStyle($image, $fixedImageStyle, 'Zugbild');
        } else {
        try {
            self::assertExactStyleWithOptionalProperties(
                $layer->getAttribute('style'),
                $layerStyle,
                ['margin-bottom'],
                'Zug-Layer',
            );
            self::assertTrainOverlap($layer->getAttribute('style'));
            self::assertExactSimpleStyle($image, $imageStyle, 'Zugbild');
        } catch (RuntimeException $exception) {
            $expandedFlowAccepted = false;
            if ($allowLegacyExpandedFlowLayer) {
                try {
                    // Exakt der zuletzt publizierte Schema-16-Vertrag. Die
                    // Kombination aus Viewport-Margins und IMG-Margins wird
                    // bewusst vollstaendig verglichen; aehnliche freie
                    // Relative-Layer duerfen nicht als Legacy durchrutschen.
                    self::assertExactStyleWithOptionalProperties($layer->getAttribute('style'), [
                        'position' => 'relative',
                        'left' => '0',
                        'right' => 'auto',
                        'top' => 'auto',
                        'bottom' => 'auto',
                        'width' => '100%',
                        'max-width' => '1815px',
                        'margin' => $layerMargin,
                        'overflow' => 'hidden',
                        'z-index' => '0',
                        'font-size' => '0',
                        'line-height' => '0',
                        'text-align' => 'left',
                    ], ['margin-bottom'], 'Zug-Layer');
                    self::assertExactSimpleStyle($image, [
                        'position' => 'static',
                        'left' => 'auto',
                        'right' => 'auto',
                        'bottom' => 'auto',
                        'display' => 'inline-block',
                        'width' => $size['width'],
                        'max-width' => 'none',
                        'height' => 'auto',
                        'margin' => $imageMargin,
                        'border' => '0',
                        'outline' => 'none',
                        'text-decoration' => 'none',
                        'vertical-align' => 'top',
                        'mso-hide' => 'all',
                    ], 'Zugbild');
                    $expandedFlowAccepted = true;
                } catch (RuntimeException) {
                    $expandedFlowAccepted = false;
                }
            }
            if ($allowLegacyExpandedFlowLayer && ! $expandedFlowAccepted) {
                try {
                    self::assertExactSimpleStyle($layer, [
                        'position' => 'relative',
                        'left' => '0',
                        'right' => 'auto',
                        'top' => 'auto',
                        'bottom' => 'auto',
                        'width' => '100%',
                        'max-width' => 'none',
                        'margin' => '0',
                        'overflow' => 'hidden',
                        'z-index' => '0',
                        'font-size' => '0',
                        'line-height' => '0',
                        'text-align' => $alignment,
                    ], 'Zug-Layer');
                    self::assertExactSimpleStyle($image, [
                        'position' => 'static',
                        'left' => 'auto',
                        'right' => 'auto',
                        'bottom' => 'auto',
                        'display' => 'inline-block',
                        'width' => $size['width'],
                        'max-width' => $size['maxWidth'],
                        'height' => 'auto',
                        'margin' => '0',
                        'border' => '0',
                        'outline' => 'none',
                        'text-decoration' => 'none',
                        'vertical-align' => 'top',
                        'mso-hide' => 'all',
                    ], 'Zugbild');
                    $expandedFlowAccepted = true;
                } catch (RuntimeException) {
                    $expandedFlowAccepted = false;
                }
            }
            if ($allowLegacyExpandedFlowLayer && ! $expandedFlowAccepted) {
                try {
                    self::assertExactSimpleStyle($layer, [
                        'position' => 'relative',
                        'left' => $expandedFlowHorizontal['left'],
                        'right' => $expandedFlowHorizontal['right'],
                        'top' => 'auto',
                        'bottom' => 'auto',
                        'width' => $size['width'],
                        'max-width' => $size['maxWidth'],
                        'margin' => '0',
                        'overflow' => 'hidden',
                        'z-index' => '0',
                        'font-size' => '0',
                        'line-height' => '0',
                        'text-align' => 'left',
                    ], 'Zug-Layer');
                    self::assertExactSimpleStyle($image, [
                        'position' => 'static',
                        'left' => 'auto',
                        'right' => 'auto',
                        'bottom' => 'auto',
                        'display' => 'block',
                        'width' => '100%',
                        'max-width' => $size['maxWidth'],
                        'height' => 'auto',
                        'margin' => '0',
                        'border' => '0',
                        'outline' => 'none',
                        'text-decoration' => 'none',
                        'mso-hide' => 'all',
                    ], 'Zugbild');
                    $expandedFlowAccepted = true;
                } catch (RuntimeException) {
                    $expandedFlowAccepted = false;
                }
            }
            if ($expandedFlowAccepted) {
                // Eine bekannte Flow-Form wird nur laufzeitlokal in den
                // aktuellen Flow-Vertrag ueberfuehrt.
            } elseif (! $allowLegacyAbsoluteLayer) {
                throw $exception;
            } else {
                $schema23Accepted = false;
                try {
                    self::assertExactSimpleStyle($layer, [
                        'position' => 'absolute',
                        'left' => '0',
                        'right' => 'auto',
                        'top' => 'auto',
                        'bottom' => '0',
                        'width' => '100%',
                        'max-width' => '1815px',
                        'margin' => '0',
                        'overflow' => 'hidden',
                        'font-size' => '0',
                        'line-height' => '0',
                        'text-align' => 'left',
                        'mso-hide' => 'all',
                    ], 'Zug-Layer');
                    self::assertExactSimpleStyle($image, $imageStyle, 'Zugbild');
                    $schema23Accepted = true;
                } catch (RuntimeException) {
                    $schema23Accepted = false;
                }
                if (! $schema23Accepted) {
                    $legacyLayerStyle = [
                        'position' => 'absolute',
                        'left' => $legacyHorizontal['left'],
                        'right' => $legacyHorizontal['right'],
                        'top' => '0',
                        'bottom' => '0',
                        'width' => $size['width'],
                        'max-width' => $size['maxWidth'],
                        'margin' => '0',
                        'overflow' => 'hidden',
                        'z-index' => '0',
                        'font-size' => '0',
                        'line-height' => '0',
                        'text-align' => 'left',
                    ];
                    if (! $legacyDirectLayer) {
                        $legacyLayerStyle['mso-hide'] = 'all';
                    }
                    try {
                        self::assertExactSimpleStyle($layer, $legacyLayerStyle, 'Zug-Layer');
                    } catch (RuntimeException) {
                        if (! $allowLegacyPercentHeight) {
                            throw $exception;
                        }
                        $legacyPercentLayerStyle = $legacyLayerStyle;
                        $legacyPercentLayerStyle['height'] = '100%';
                        self::assertExactSimpleStyle($layer, $legacyPercentLayerStyle, 'Zug-Layer');
                    }
                    $legacyImageStyle = [
                        'position' => 'absolute',
                        'left' => '0',
                        'right' => 'auto',
                        'bottom' => '0',
                        'display' => 'block',
                        'width' => '100%',
                        'max-width' => $size['maxWidth'],
                        'height' => 'auto',
                        'margin' => '0',
                        'border' => '0',
                        'outline' => 'none',
                        'text-decoration' => 'none',
                    ];
                    if (! $legacyDirectLayer) {
                        $legacyImageStyle['mso-hide'] = 'all';
                    }
                    self::assertExactSimpleStyle($image, $legacyImageStyle, 'Zugbild');
                }
            }
        }
        }
        $widthAttribute = strtolower(trim($image->getAttribute('width')));
        $legacyPixelWidth = preg_replace('/px$/', '', $size['maxWidth']) ?? '';
        if ($widthAttribute !== '720'
            && ! ($legacyDirectLayer && in_array($widthAttribute, ['100%', $legacyPixelWidth], true))) {
            throw new RuntimeException('Das Zugbild muss als mail-sicherer 720-Pixel-Fallback begrenzt sein.');
        }
    }

    /**
     * Der CSS-Grundschleier ist eine optionale Darstellungshilfe. Fehlen alle
     * vier Background-Longhands, bleibt der regulaere IMG-Zug voll gueltig.
     * Ist der Schleier vorhanden, muss er weiterhin exakt kanonisch sein;
     * eine versteckte zweite Bildquelle darf dadurch nicht zurueckkehren.
     */
    public static function assertOptionalCanonicalBaseBackground(string $html): void
    {
        $carrier = self::inspectCarrier($html);
        $styles = $carrier['attributes']['style'] ?? [];
        if (count($styles) !== 1) {
            throw new RuntimeException('Der Zug-Carrier besitzt kein eindeutiges style-Attribut.');
        }

        $style = CssSemantic::decodeHtmlEntitiesOnce((string) $styles[0]['raw']);
        $present = 0;
        foreach (['background-image', 'background-repeat', 'background-position', 'background-size'] as $property) {
            if (preg_match('/(?:^|;)\s*'.preg_quote($property, '/').'\s*:/i', $style) === 1) {
                $present++;
            }
        }
        if ($present === 0) {
            if (preg_match('/(?:^|;)\s*background\s*:/i', $style) === 1
                || str_contains($style, '{{TRAIN_SRC}}')
                || str_contains($style, '{{TRAIN_IDLE_SRC}}')) {
                throw new RuntimeException('Der optionale Signaturhintergrund darf keine Zugquelle oder Kurzform enthalten.');
            }

            return;
        }
        if ($present !== 4) {
            throw new RuntimeException('Der optionale Signaturhintergrund besitzt unvollstaendige Background-Listen.');
        }

        self::assertCanonicalBaseBackground($html);
    }

    /**
     * Prueft die einzige bildfreie Basis-Backgroundebene des gespeicherten
     * Editor-Dokuments. Raster und grosses RT-Wasserzeichen sind seit Schema 18
     * nicht mehr Bestandteil der Signatur; der Zug bleibt ein regulaeres IMG.
     */
    public static function assertCanonicalBaseBackground(string $html): void
    {
        $carrier = self::inspectCarrier($html);
        $styles = $carrier['attributes']['style'] ?? [];
        if (count($styles) !== 1) {
            throw new RuntimeException('Der Zug-Carrier besitzt kein eindeutiges style-Attribut.');
        }

        $style = CssSemantic::decodeHtmlEntitiesOnce((string) $styles[0]['raw']);
        $styleWithoutAllowedTokens = str_replace([
            '{{SIGNATURE_BG}}',
            '{{SIGNATURE_TRAIN_WASH}}',
        ], '', $style);
        if (preg_match('/[{}]/', $styleWithoutAllowedTokens) !== 0) {
            throw new RuntimeException('Der Zug-Carrier enthaelt einen fremden oder unvollstaendigen Platzhalter.');
        }

        $parsed = self::parseRuntimeBackgroundStyle(
            $style,
            allowStoredTokens: true,
        );
        self::assertRuntimeBaseBackgroundLists($parsed['lists'], expectedCount: 1);

        $images = $parsed['lists']['background-image'];
        if (! self::cssLinearGradientTargetsWash($images[0])) {
            throw new RuntimeException('Die bildfreie Basis-Ebene des Zug-Carriers ist nicht kanonisch.');
        }
    }

    /**
     * Akzeptiert ausschliesslich den exakten Schema-17-Altstand, damit bereits
     * veroeffentlichte Dokumente beim Rendern sicher auf die bildfreie
     * Schema-18-Ebene reduziert werden koennen.
     */
    public static function assertLegacyCanonicalBaseBackground(string $html): void
    {
        $carrier = self::inspectCarrier($html);
        $styles = $carrier['attributes']['style'] ?? [];
        if (count($styles) !== 1) {
            throw new RuntimeException('Der Zug-Carrier besitzt kein eindeutiges style-Attribut.');
        }

        $style = CssSemantic::decodeHtmlEntitiesOnce((string) $styles[0]['raw']);
        $styleWithoutAllowedTokens = str_replace([
            '{{SIGNATURE_BG}}',
            '{{GRUND_RASTER_SRC}}',
            '{{GRUND_MARKE_SRC}}',
            '{{SIGNATURE_TRAIN_WASH}}',
        ], '', $style);
        if (preg_match('/[{}]/', $styleWithoutAllowedTokens) !== 0) {
            throw new RuntimeException('Der Zug-Carrier enthaelt einen fremden oder unvollstaendigen Platzhalter.');
        }

        $parsed = self::parseRuntimeBackgroundStyle(
            $style,
            allowStoredTokens: true,
        );
        self::assertRuntimeBaseBackgroundLists($parsed['lists'], expectedCount: 3);

        $images = $parsed['lists']['background-image'];
        if (! self::cssUrlTargetsToken($images[0], 'GRUND_RASTER_SRC')
            || ! self::cssUrlTargetsToken($images[1], 'GRUND_MARKE_SRC')
            || ! self::cssLinearGradientTargetsWash($images[2])) {
            throw new RuntimeException('Die alten Basis-Layer des Zug-Carriers sind nicht kanonisch.');
        }
    }

    /**
     * Entfernt Raster und grosses RT-Wasserzeichen atomar aus einem exakt
     * validierten Schema-17-Carrier. Neue Schema-18-Dokumente werden
     * unveraendert zurueckgegeben. Der transparente Wash bleibt als einzige
     * bildfreie Kompatibilitaetsebene bestehen.
     */
    public static function withoutDecorativeBaseBackgrounds(string $html): string
    {
        if (self::hasCanonicalBackground($html)) {
            self::assertCanonicalBackground($html);

            return $html;
        }
        try {
            self::assertOptionalCanonicalBaseBackground($html);

            return $html;
        } catch (RuntimeException) {
            self::assertLegacyCanonicalBaseBackground($html);
        }

        $carrier = self::inspectCarrier($html);
        $styles = $carrier['attributes']['style'] ?? [];
        if (count($styles) !== 1 || $styles[0]['valueOffset'] === null) {
            throw new RuntimeException('Die dekorativen Signaturhintergruende koennen nicht eindeutig entfernt werden.');
        }

        $styleAttribute = $styles[0];
        $parsed = self::parseRuntimeBackgroundStyle(
            CssSemantic::decodeHtmlEntitiesOnce((string) $styleAttribute['raw']),
            allowStoredTokens: true,
        );
        foreach (['background-image', 'background-repeat', 'background-position', 'background-size'] as $property) {
            array_splice($parsed['lists'][$property], 0, 2);
            $declaration = $parsed['declarations'][$property];
            $parsed['segments'][$declaration['segment']] = $declaration['prefix']
                .implode(',', $parsed['lists'][$property])
                .$declaration['suffix'];
        }

        $projected = substr_replace(
            $html,
            htmlspecialchars(
                implode(';', $parsed['segments']),
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8',
            ),
            $styleAttribute['valueOffset'],
            $styleAttribute['valueLength'],
        );
        self::assertOptionalCanonicalBaseBackground($projected);

        return $projected;
    }

    /**
     * Fuehrt jeden zuvor streng validierten Bildvertrag laufzeitlokal in den
     * festen Schema-25-Pixelvertrag ueber. Alte Prozent- und Pixel-Overlaps
     * werden nur als bekannte Altform validiert, niemals uebernommen oder
     * mathematisch umgerechnet. Das Ergebnis ist immer 200px/-200px.
     */
    private static function normalizeImageToCanonicalFlow(string $html): string
    {
        $stages = [];
        $layers = [];
        $images = [];
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] === 'div' && self::sourceTagHasClass($tag, 'rt-sign-stage')) {
                $stages[] = $tag;
            }
            if ($tag['name'] === 'div' && self::sourceTagHasClass($tag, 'rt-sign-train-layer')) {
                $layers[] = $tag;
            }
            if ($tag['name'] === 'img' && self::sourceTagHasClass($tag, 'rt-sign-train')) {
                $images[] = $tag;
            }
        }
        if (count($stages) !== 1 || count($layers) !== 1 || count($images) !== 1) {
            throw new RuntimeException('Der alte Zug-Layer konnte nicht eindeutig in den Mailfluss ueberfuehrt werden.');
        }

        $alignment = strtolower(self::singleTagAttributeValue($layers[0], 'data-rt-layer-align'));
        $sizeAttributes = $layers[0]['attributes']['data-rt-layer-size'] ?? [];
        $sizeName = $sizeAttributes === []
            ? '100'
            : strtolower(self::singleTagAttributeValue($layers[0], 'data-rt-layer-size'));
        $mobileAttributes = $layers[0]['attributes']['data-rt-layer-mobile'] ?? [];
        $mobileCrop = $mobileAttributes === []
            ? 'train'
            : strtolower(self::singleTagAttributeValue($layers[0], 'data-rt-layer-mobile'));
        $size = self::CANONICAL_LAYER_SIZE[$sizeName] ?? null;
        if (! is_array($size)
            || ! in_array($alignment, ['left', 'center', 'right'], true)
            || ! in_array($mobileCrop, self::CANONICAL_MOBILE_CROPS, true)) {
            throw new RuntimeException('Der alte Zug-Layer besitzt keine normalisierbare Geometrie.');
        }

        if (preg_match('/<\/div\s*>/i', $html, $layerClose, PREG_OFFSET_CAPTURE, $layers[0]['endOffset'] + 1) !== 1) {
            throw new RuntimeException('Der alte Zug-Layer besitzt keinen eindeutigen Abschluss.');
        }
        $layerEnd = $layerClose[0][1] + strlen($layerClose[0][0]);
        $contentTables = array_values(array_filter(
            self::scanStartTags($html),
            static fn (array $tag): bool => $tag['name'] === 'table'
                && $tag['startOffset'] >= $layerEnd
                && ! self::sourceTagHasClass($tag, 'rt-sign-train-frame'),
        ));
        if (count($contentTables) < 1) {
            throw new RuntimeException('Der alte Signatur-Inhalt besitzt keinen eindeutigen Tabellenrahmen.');
        }
        $contentTable = $contentTables[0];
        $source = self::singleTagAttributeValue($images[0], 'src');
        $escapedSource = htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $replacements = [
            [
                $stages[0]['startOffset'],
                $stages[0]['endOffset'] - $stages[0]['startOffset'] + 1,
                '<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">',
            ],
            [
                $layers[0]['startOffset'],
                $layerEnd - $layers[0]['startOffset'],
                self::canonicalLayerMarkup($escapedSource, $alignment, $sizeName, $mobileCrop),
            ],
            [
                $contentTable['startOffset'],
                $contentTable['endOffset'] - $contentTable['startOffset'] + 1,
                self::canonicalContentFrameStartMarkup(),
            ],
        ];
        usort($replacements, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
        foreach ($replacements as [$offset, $length, $replacement]) {
            $html = substr_replace($html, $replacement, $offset, $length);
        }

        return $html;
    }

    /**
     * Projiziert den exakt validierten Schema-20-Zellhintergrund lokal in den
     * aktuellen IMG-Vertrag. Der gespeicherte Snapshot wird dabei nicht
     * veraendert; uebrig bleibt ausschliesslich der bildfreie Wash.
     */
    private static function projectCanonicalBackgroundToImage(string $html): string
    {
        self::assertCanonicalBackground($html);

        $carrier = self::inspectCarrier($html);
        $styles = $carrier['attributes']['style'] ?? [];
        $markers = $carrier['attributes']['data-rt-train-background'] ?? [];
        if (count($styles) !== 1
            || count($markers) !== 1
            || $styles[0]['valueOffset'] === null) {
            throw new RuntimeException('Der CSS-Zughintergrund kann nicht eindeutig in ein IMG projiziert werden.');
        }

        $parsed = self::parseRuntimeBackgroundStyle(
            CssSemantic::decodeHtmlEntitiesOnce((string) $styles[0]['raw']),
            allowStoredTokens: true,
        );
        foreach (['background-image', 'background-repeat', 'background-position', 'background-size'] as $property) {
            if (count($parsed['lists'][$property] ?? []) !== 2) {
                throw new RuntimeException('Der CSS-Zughintergrund besitzt keine eindeutigen parallelen Ebenen.');
            }
            array_shift($parsed['lists'][$property]);
            $declaration = $parsed['declarations'][$property];
            $parsed['segments'][$declaration['segment']] = $declaration['prefix']
                .implode(',', $parsed['lists'][$property])
                .$declaration['suffix'];
        }

        $replacements = [
            [
                $styles[0]['valueOffset'],
                $styles[0]['valueLength'],
                htmlspecialchars(
                    implode(';', $parsed['segments']),
                    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                    'UTF-8',
                ),
            ],
            [
                $markers[0]['attributeOffset'],
                $markers[0]['attributeLength'],
                '',
            ],
        ];
        usort($replacements, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
        foreach ($replacements as [$offset, $length, $replacement]) {
            $html = substr_replace($html, $replacement, $offset, $length);
        }

        self::assertOptionalCanonicalBaseBackground($html);
        $html = self::withoutCanonicalContentOverlap($html);
        $stages = array_values(array_filter(
            self::scanStartTags($html),
            static fn (array $tag): bool => $tag['name'] === 'div'
                && self::sourceTagHasClass($tag, 'rt-sign-stage'),
        ));
        if (count($stages) !== 1) {
            throw new RuntimeException('Der CSS-Zughintergrund besitzt keine eindeutige Signatur-Buehne.');
        }
        $html = substr_replace(
            $html,
            self::canonicalLayerMarkup('{{TRAIN_SRC}}'),
            $stages[0]['endOffset'] + 1,
            0,
        );
        $html = self::normalizeImageToCanonicalFlow($html);

        self::assertCanonicalImage($html);

        return $html;
    }

    /**
     * Ueberfuehrt alte Inhalt-vor-Zug- und Flow-Aufbauten in die verbindliche
     * Zug-vor-Inhalt-Reihenfolge. Ein vorhandener alter Ueberlappungswert wird
     * auf den Zug-Layer verschoben und beim weiteren Normalisieren bewahrt.
     */
    private static function withCanonicalTrainFirstStage(string $html): string
    {
        $stage = null;
        $layer = null;
        $contentTable = null;
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] === 'div' && self::sourceTagHasClass($tag, 'rt-sign-stage')) {
                if ($stage !== null) {
                    throw new RuntimeException('Die Signatur besitzt mehrere Zug-Buehnen.');
                }
                $stage = $tag;

                continue;
            }
            if ($stage === null || $tag['startOffset'] <= $stage['endOffset']) {
                continue;
            }
            if ($layer === null
                && $tag['name'] === 'div'
                && self::sourceTagHasClass($tag, 'rt-sign-train-layer')) {
                $layer = $tag;
            }
            if ($contentTable === null && $tag['name'] === 'table') {
                $contentTable = $tag;
            }
        }
        if ($stage === null || $layer === null || $contentTable === null) {
            throw new RuntimeException('Die Signatur-Buehne besitzt keine eindeutigen Zug- und Inhaltsbereiche.');
        }

        $contentStyles = $contentTable['attributes']['style'] ?? [];
        $layerStyles = $layer['attributes']['style'] ?? [];
        if (count($contentStyles) !== 1 || $contentStyles[0]['valueOffset'] === null
            || count($layerStyles) !== 1 || $layerStyles[0]['valueOffset'] === null) {
            throw new RuntimeException('Zug und Signatur-Inhalt besitzen keine eindeutigen style-Attribute.');
        }

        $contentStyle = CssSemantic::decodeHtmlEntitiesOnce((string) $contentStyles[0]['raw']);
        $layerStyle = CssSemantic::decodeHtmlEntitiesOnce((string) $layerStyles[0]['raw']);
        $contentOverlap = null;
        $contentStyle = preg_replace_callback(
            '/(?:^|;)\s*margin-bottom\s*:\s*([^;]*)/i',
            static function (array $match) use (&$contentOverlap): string {
                if ($contentOverlap !== null) {
                    throw new RuntimeException('Der Signatur-Inhalt besitzt mehrere Ueberlappungswerte.');
                }
                $contentOverlap = trim((string) $match[1]);

                return '';
            },
            $contentStyle,
        );
        $layerOverlap = null;
        $layerStyle = preg_replace_callback(
            '/(?:^|;)\s*margin-bottom\s*:\s*([^;]*)/i',
            static function (array $match) use (&$layerOverlap): string {
                if ($layerOverlap !== null) {
                    throw new RuntimeException('Der Zug-Layer besitzt mehrere Ueberlappungswerte.');
                }
                $layerOverlap = trim((string) $match[1]);

                return '';
            },
            $layerStyle,
        );
        if (! is_string($contentStyle) || ! is_string($layerStyle)) {
            throw new RuntimeException('Die Zug-Ueberlappung konnte nicht eindeutig gelesen werden.');
        }
        $legacyOverlap = $layerOverlap ?? $contentOverlap ?? '-7.3611%';
        if ($legacyOverlap === '') {
            throw new RuntimeException('Die Zug-Ueberlappung darf nicht leer sein.');
        }
        self::assertLegacyTrainOverlapValue($legacyOverlap);
        $layerStyle = preg_replace(
            '/((?:^|;)\s*margin\s*:[^;]*)(;|$)/i',
            '$1;margin-bottom:'.self::TRAIN_OVERLAP.'$2',
            $layerStyle,
            1,
            $marginCount,
        );
        if (! is_string($layerStyle) || $marginCount !== 1) {
            throw new RuntimeException('Der Zug-Layer besitzt keine eindeutige Margin-Geometrie.');
        }

        $replacements = [
            [
                $contentStyles[0]['valueOffset'],
                $contentStyles[0]['valueLength'],
                htmlspecialchars(trim($contentStyle, "; \t\r\n\f").';', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            ],
            [
                $layerStyles[0]['valueOffset'],
                $layerStyles[0]['valueLength'],
                htmlspecialchars(trim($layerStyle, "; \t\r\n\f").';', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            ],
        ];
        usort($replacements, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
        foreach ($replacements as [$offset, $length, $replacement]) {
            $html = substr_replace($html, $replacement, $offset, $length);
        }

        $stage = null;
        $layer = null;
        $contentTable = null;
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] === 'div' && self::sourceTagHasClass($tag, 'rt-sign-stage')) {
                $stage = $tag;
            } elseif ($tag['name'] === 'div' && self::sourceTagHasClass($tag, 'rt-sign-train-layer')) {
                $layer = $tag;
            } elseif ($stage !== null
                && $contentTable === null
                && $tag['startOffset'] > $stage['endOffset']
                && $tag['name'] === 'table') {
                $contentTable = $tag;
            }
        }
        if ($stage === null || $layer === null || $contentTable === null) {
            throw new RuntimeException('Die umgestellte Signatur-Buehne ist nicht eindeutig lesbar.');
        }
        if ($layer['startOffset'] < $contentTable['startOffset']) {
            return $html;
        }
        if (preg_match('/<\/div\s*>/i', $html, $layerClose, PREG_OFFSET_CAPTURE, $layer['endOffset'] + 1) !== 1) {
            throw new RuntimeException('Der Zug-Layer besitzt keinen eindeutigen Abschluss.');
        }
        $layerEnd = $layerClose[0][1] + strlen($layerClose[0][0]);
        $layerMarkup = substr($html, $layer['startOffset'], $layerEnd - $layer['startOffset']);
        $html = substr_replace($html, '', $layer['startOffset'], $layerEnd - $layer['startOffset']);

        return substr_replace($html, $layerMarkup, $stage['endOffset'] + 1, 0);
    }

    /** Entfernt den alten Inhaltstabellen-Overlap bei der Schema-20-Projektion. */
    private static function withoutCanonicalContentOverlap(string $html): string
    {
        $stage = null;
        $contentTable = null;
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] === 'div' && self::sourceTagHasClass($tag, 'rt-sign-stage')) {
                if ($stage !== null) {
                    throw new RuntimeException('Die Signatur besitzt mehrere Zug-Buehnen.');
                }
                $stage = $tag;

                continue;
            }
            if ($stage !== null
                && $contentTable === null
                && $tag['startOffset'] > $stage['endOffset']
                && $tag['name'] === 'table') {
                $contentTable = $tag;
            }
        }
        if ($stage === null || $contentTable === null) {
            throw new RuntimeException('Die Signatur-Buehne besitzt keinen eindeutigen Inhaltswrapper.');
        }

        $styles = $contentTable['attributes']['style'] ?? [];
        if (count($styles) !== 1 || $styles[0]['valueOffset'] === null) {
            throw new RuntimeException('Der Signatur-Inhalt besitzt kein eindeutiges style-Attribut.');
        }
        $style = CssSemantic::decodeHtmlEntitiesOnce((string) $styles[0]['raw']);
        $style = preg_replace(
            '/(?:^|;)\s*margin-bottom\s*:[^;]*/i',
            '',
            $style,
            -1,
            $overlapCount,
        );
        if (! is_string($style) || $overlapCount > 1) {
            throw new RuntimeException('Der Signatur-Inhalt besitzt keinen eindeutigen Ueberlappungswert.');
        }
        self::simpleStyleValues($style, 'Signatur-Inhalt');

        return substr_replace(
            $html,
            htmlspecialchars(trim($style, "; \t\r\n\f").';', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            $styles[0]['valueOffset'],
            $styles[0]['valueLength'],
        );
    }

    private static function canonicalLayerMarkup(
        string $source,
        string $alignment = 'left',
        string $sizeName = '100',
        string $mobileCrop = 'train',
    ): string
    {
        $size = self::CANONICAL_LAYER_SIZE[$sizeName] ?? null;
        if (! is_array($size)
            || ! in_array($alignment, ['left', 'center', 'right'], true)
            || ! in_array($mobileCrop, self::CANONICAL_MOBILE_CROPS, true)) {
            throw new RuntimeException('Der kanonische Zug-Layer besitzt keine erlaubte Geometrie.');
        }

        return '<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="'.$alignment.'" data-rt-layer-size="'.$sizeName.'" data-rt-layer-mobile="'.$mobileCrop.'" '
            .'style="display:block;width:100%;height:200px;max-height:200px;max-width:1815px;margin:'.self::layerMargin($alignment).';margin-bottom:-200px;overflow:hidden;font-size:0;line-height:0;text-align:left;">'
            .'<table class="rt-sign-train-frame" role="presentation" width="100%" height="200" border="0" cellspacing="0" cellpadding="0" style="width:100%;height:200px;border-collapse:collapse;">'
            .'<tr><td class="rt-sign-train-slot" height="200" valign="bottom" style="height:200px;padding:0;text-align:left;vertical-align:bottom;font-size:0;line-height:0;">'
            .'<img class="rt-sign-train" data-rt-train src="'.$source.'" width="720" alt="" '
            .'style="position:static;left:auto;right:auto;bottom:auto;display:inline-block;width:'.$size['width'].';max-width:none;height:auto;margin:'.self::imageMargin($alignment, $size).';border:0;outline:none;text-decoration:none;vertical-align:bottom;mso-hide:all;">'
            .'</td></tr></table>'
            .'</div>';
    }

    private static function canonicalContentFrameStartMarkup(): string
    {
        return '<table class="rt-sign-content-frame" role="presentation" width="100%" height="200" border="0" cellspacing="0" cellpadding="0" style="width:100%;height:200px;border-collapse:collapse;">';
    }

    private static function assertCanonicalPixelFrames(
        DOMElement $trainFrame,
        DOMElement $trainSlot,
        DOMElement $contentFrame,
    ): void {
        if (self::elementClasses($trainFrame) !== ['rt-sign-train-frame']
            || self::elementClasses($trainSlot) !== ['rt-sign-train-slot']
            || self::elementClasses($contentFrame) !== ['rt-sign-content-frame']) {
            throw new RuntimeException('Die feste Zug-Buehne besitzt fremde Klassen.');
        }

        self::assertExactElementAttributeNames($trainFrame, [
            'class', 'role', 'width', 'height', 'border', 'cellspacing', 'cellpadding', 'style',
        ], 'Zug-Rahmen');
        self::assertExactElementAttributeNames($trainSlot, [
            'class', 'height', 'valign', 'style',
        ], 'Zug-Slot');
        self::assertExactElementAttributeNames($contentFrame, [
            'class', 'role', 'width', 'height', 'border', 'cellspacing', 'cellpadding', 'style',
        ], 'Signatur-Inhaltsrahmen');

        foreach ([$trainFrame, $contentFrame] as $frame) {
            if (strtolower($frame->getAttribute('role')) !== 'presentation'
                || $frame->getAttribute('width') !== '100%'
                || $frame->getAttribute('height') !== self::STAGE_HEIGHT_ATTRIBUTE
                || $frame->getAttribute('border') !== '0'
                || $frame->getAttribute('cellspacing') !== '0'
                || $frame->getAttribute('cellpadding') !== '0') {
                throw new RuntimeException('Die feste Tabellenhoehe der Signatur muss 200 Pixel betragen.');
            }
        }
        if ($trainSlot->getAttribute('height') !== self::STAGE_HEIGHT_ATTRIBUTE
            || strtolower($trainSlot->getAttribute('valign')) !== 'bottom') {
            throw new RuntimeException('Der Zug-Slot muss am unteren Rand der 200-Pixel-Buehne stehen.');
        }

        self::assertExactSimpleStyle($trainFrame, [
            'width' => '100%',
            'height' => self::STAGE_HEIGHT,
            'border-collapse' => 'collapse',
        ], 'Zug-Rahmen');
        self::assertExactSimpleStyle($trainSlot, [
            'height' => self::STAGE_HEIGHT,
            'padding' => '0',
            'text-align' => 'left',
            'vertical-align' => 'bottom',
            'font-size' => '0',
            'line-height' => '0',
        ], 'Zug-Slot');
        self::assertExactSimpleStyle($contentFrame, [
            'width' => '100%',
            'height' => self::STAGE_HEIGHT,
            'border-collapse' => 'collapse',
        ], 'Signatur-Inhaltsrahmen');
    }

    private static function layerMargin(string $alignment): string
    {
        return match ($alignment) {
            'left' => '0 auto 0 0',
            'center' => '0 auto',
            'right' => '0 0 0 auto',
            default => throw new RuntimeException('Der Zug-Layer besitzt keine kanonische Ausrichtung.'),
        };
    }

    /** Liest den einzelnen alten Zug-Overlap aus einem Inline-Stil. */
    private static function trainOverlap(string $style): ?string
    {
        $values = self::simpleStyleValues($style, 'Zug-Layer');

        return isset($values['margin-bottom']) ? trim($values['margin-bottom']) : null;
    }

    /** Alte Schema-19-bis-24-Staende werden nur vor ihrer Migration erkannt. */
    private static function assertTrainOverlap(string $style): void
    {
        $overlap = self::trainOverlap($style);
        if ($overlap === null) {
            throw new RuntimeException('Der Zug-Layer benoetigt einen eindeutigen negativen Ueberlappungswert.');
        }

        self::assertLegacyTrainOverlapValue($overlap);
    }

    private static function assertLegacyTrainOverlapValue(string $overlap): void
    {
        $overlap = strtolower(trim($overlap));
        if (preg_match('/^-((?:\d+(?:\.\d+)?)|(?:\.\d+))(px|%)$/D', $overlap, $match) !== 1) {
            throw new RuntimeException('Die Zug-Ueberlappung muss als negativer px- oder Prozentwert angegeben sein.');
        }

        $amount = (float) $match[1];
        $maximum = $match[2] === '%' ? 100.0 : 1000.0;
        if ($amount <= 0 || $amount > $maximum) {
            throw new RuntimeException('Die Zug-Ueberlappung liegt ausserhalb des mail-sicheren Bereichs.');
        }
    }

    /** @param array{width:string,maxWidth:string,centerLeft:string,rightLeft:string} $size */
    private static function imageMargin(string $alignment, array $size): string
    {
        $offset = match ($alignment) {
            'left' => '0',
            'center' => $size['centerLeft'],
            'right' => $size['rightLeft'],
            default => throw new RuntimeException('Das Zugbild besitzt keine kanonische Ausrichtung.'),
        };

        return $offset === '0' ? '0' : '0 0 0 '.$offset;
    }

    private static function canonicalStageMarkup(string $content, string $layer): string
    {
        return '<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">'
            .$layer.$content
            .'</div>';
    }

    /**
     * Hebt den zuvor streng als Schema-12-Topologie validierten direkten
     * Bild-Layer zur Laufzeit in denselben Block-Kontext wie Schema 13. So
     * greift der Fix auch vor einem expliziten Import; der gespeicherte
     * veroeffentlichte Snapshot wird dabei nicht veraendert.
     */
    private static function wrapLegacyDirectCarrierInStage(string $html): string
    {
        $marker = '<!-- RT_SIGNATURE_MAIN_END -->';
        if (substr_count($html, $marker) !== 1) {
            throw new RuntimeException('Der bestehende Zug-Layer besitzt keinen eindeutigen Hauptanker.');
        }

        $markerOffset = strpos($html, $marker);
        $beforeMarker = substr($html, 0, $markerOffset);
        if (preg_match('/<\/td>[ \t\r\n\f]*<\/tr>[ \t\r\n\f]*$/i', $beforeMarker, $match, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('Der bestehende Zug-Layer kann nicht sicher in eine Buehne gehoben werden.');
        }

        $carrierCloseOffset = $match[0][1];
        $carrier = self::inspectCarrier($html);
        $contentOffset = $carrier['tagEnd'] + 1;
        if ($contentOffset <= 0 || $contentOffset > $carrierCloseOffset) {
            throw new RuntimeException('Der bestehende Zug-Carrier besitzt keinen sicheren Inhaltsbereich.');
        }

        $content = substr($html, $contentOffset, $carrierCloseOffset - $contentOffset);

        return substr_replace(
            $html,
            '<div class="rt-sign-stage" style="position:relative;height:200px;max-height:200px;overflow:hidden;">'.$content.'</div>',
            $contentOffset,
            $carrierCloseOffset - $contentOffset,
        );
    }

    /** @param array<string, string> $expected */
    private static function assertExactSimpleStyle(DOMElement $element, array $expected, string $label): void
    {
        self::assertExactStyleValue($element->getAttribute('style'), $expected, $label);
    }

    /**
     * @param  array{name:string,attributes:array<string,list<array<string,mixed>>>}  $tag
     * @param  array<string, string>  $expected
     */
    private static function assertExactSourceTagStyle(array $tag, array $expected, string $label): void
    {
        self::assertExactStyleValue(self::singleTagAttributeValue($tag, 'style'), $expected, $label);
    }

    /** @param array<string, string> $expected */
    private static function assertExactStyleValue(string $style, array $expected, string $label): void
    {
        $actual = self::simpleStyleValues($style, $label);

        if (count($actual) !== count($expected)) {
            throw new RuntimeException("Der {$label} muss seine mail-sichere Geometrie behalten.");
        }
        foreach ($expected as $property => $value) {
            if (($actual[$property] ?? null) !== $value) {
                throw new RuntimeException("Der {$label} muss seine mail-sichere Geometrie behalten.");
            }
        }
    }

    /**
     * @param  array<string, string>  $expected
     * @param  list<string>  $optional
     */
    private static function assertExactStyleWithOptionalProperties(
        string $style,
        array $expected,
        array $optional,
        string $label,
    ): void {
        foreach ($optional as $property) {
            $style = preg_replace(
                '/(?:^|;)\s*'.preg_quote($property, '/').'\s*:[^;]*/i',
                '',
                $style,
                -1,
                $count,
            );
            if (! is_string($style) || $count > 1) {
                throw new RuntimeException("Der {$label}-Stil ist nicht eindeutig.");
            }
        }

        self::assertExactStyleValue(trim($style, "; \t\r\n\f"), $expected, $label);
    }

    /** @return array<string, string> */
    private static function simpleStyleValues(string $style, string $label): array
    {
        $actual = [];
        foreach (explode(';', $style) as $segment) {
            if (trim($segment) === '') {
                continue;
            }
            if (substr_count($segment, ':') !== 1) {
                throw new RuntimeException("Der {$label}-Stil ist nicht eindeutig lesbar.");
            }
            [$property, $value] = array_map('trim', explode(':', $segment, 2));
            $property = strtolower($property);
            $value = strtolower(preg_replace('/[ \t\r\n\f]+/', ' ', $value) ?? $value);
            if ($property === '' || isset($actual[$property]) || str_contains($value, '!important')) {
                throw new RuntimeException("Der {$label}-Stil ist nicht eindeutig.");
            }
            $actual[$property] = $value;
        }

        return $actual;
    }

    private static function lastElementChild(DOMElement $element): ?DOMElement
    {
        for ($child = $element->lastChild; $child !== null; $child = $child->previousSibling) {
            if ($child instanceof DOMElement) {
                return $child;
            }
        }

        return null;
    }

    private static function isDescendantOf(DOMElement $element, DOMElement $ancestor): bool
    {
        for ($parent = $element->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement && $parent->isSameNode($ancestor)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function elementClasses(DOMElement $element): array
    {
        return preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @param list<string> $expected */
    private static function assertExactElementAttributeNames(
        DOMElement $element,
        array $expected,
        string $label,
    ): void {
        $actual = [];
        foreach ($element->attributes as $attribute) {
            $actual[] = strtolower($attribute->nodeName);
        }
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException("Der {$label} besitzt fremde oder fehlende Attribute.");
        }
    }

    /**
     * @param  array{name:string,attributes:array<string,list<array<string,mixed>>>}  $tag
     * @param  list<string>  $expected
     */
    private static function assertExactSourceTagAttributeNames(
        array $tag,
        array $expected,
        string $label,
    ): void {
        $actual = array_map('strtolower', array_keys($tag['attributes']));
        sort($actual);
        sort($expected);
        $duplicates = array_filter(
            $tag['attributes'],
            static fn (array $attributes): bool => count($attributes) !== 1,
        );
        if ($actual !== $expected || $duplicates !== []) {
            throw new RuntimeException("Der {$label} besitzt fremde oder fehlende Attribute.");
        }
    }

    /** @param array{name:string,attributes:array<string,list<array<string,mixed>>>} $tag */
    private static function sourceTagHasClass(array $tag, string $class): bool
    {
        $attributes = $tag['attributes']['class'] ?? [];
        if (count($attributes) > 1) {
            throw new RuntimeException('Ein Zug-Element besitzt das class-Attribut mehrfach.');
        }
        if ($attributes === []) {
            return false;
        }

        $classes = preg_split(
            '/\s+/',
            trim($attributes[0]['decoded']),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        return in_array($class, $classes, true);
    }

    /** @param array{name:string,attributes:array<string,list<array<string,mixed>>>} $tag */
    private static function singleTagAttributeValue(array $tag, string $name): string
    {
        $attributes = $tag['attributes'][strtolower($name)] ?? [];
        if (count($attributes) !== 1) {
            throw new RuntimeException('Ein Runtime-Zugelement besitzt ein erforderliches Attribut nicht eindeutig.');
        }

        return trim((string) $attributes[0]['decoded']);
    }

    private static function isAllowedMailImageSource(string $source, bool $staticOnly = false): bool
    {
        $source = trim($source);
        if ($source === ''
            || preg_match('/[\x00-\x20\x7f\\<>"\'()]/', $source) === 1) {
            return false;
        }

        if (preg_match('/^cid:[A-Za-z0-9._@+-]+$/i', $source) === 1) {
            return true;
        }

        $dataMime = $staticOnly ? 'png' : '(?:gif|png)';
        if (preg_match('/^data:image\/'.$dataMime.';base64,[A-Za-z0-9+\/=]+$/i', $source) === 1) {
            return true;
        }

        if (str_starts_with(strtolower($source), 'https://')) {
            if (preg_match('/^https:\/\/[^\s\\<>"\'()]+$/i', $source) !== 1) {
                return false;
            }
            if (! $staticOnly) {
                return true;
            }

            $path = parse_url($source, PHP_URL_PATH);

            return is_string($path) && str_ends_with(strtolower($path), '.png');
        }

        // Lokale Vorschau- und Testlaeufe besitzen nicht zwingend TLS. Nur
        // echte Loopback-Ziele duerfen deshalb ausnahmsweise HTTP verwenden;
        // externe Mailbilder bleiben weiterhin strikt HTTPS-gebunden.
        if (str_starts_with(strtolower($source), 'http://')) {
            if (preg_match(
                '/^http:\/\/(?:localhost|127\.0\.0\.1|\[::1\])(?::[1-9][0-9]{0,4})?\/[^\s\\<>"\'()]+$/i',
                $source,
            ) !== 1) {
                return false;
            }
            if (! $staticOnly) {
                return true;
            }

            $path = parse_url($source, PHP_URL_PATH);

            return is_string($path) && str_ends_with(strtolower($path), '.png');
        }

        // Ausschliesslich der servergenerierte Outlook-ZIP benutzt relative
        // Begleitdateien. Keine Schemes, absoluten Pfade oder Traversals.
        $extension = $staticOnly ? 'png' : '(?:gif|png)';

        return preg_match(
            '/^(?!.*(?:^|\/)\.\.(?:\/|$))(?:[A-Za-z0-9._-]+\/)*[A-Za-z0-9._-]+\.'.$extension.'$/i',
            $source,
        ) === 1;
    }

    /**
     * Prueft den finalen IMG-Vertrag. Zug- und Idle-GIF duerfen ausschliesslich
     * als src eines echten IMG vorkommen; Raster und grosses RT-Wasserzeichen
     * duerfen im Carrier nicht mehr vorkommen.
     */
    public static function assertRuntimeImages(
        string $html,
        ?string $expectedMainSource = null,
        ?string $expectedIdleSource = null,
        ?string $expectedMsoSource = null,
    ): void {
        if (preg_match('/\b(?:rt-sign-train-background|data-rt-train-background)\b/i', $html) === 1
            || preg_match(
                '/<v:(?:fill|image)\b[^>]*\bsrc\s*=\s*(["\'])[^"\']*(?:zug-dampf|railtime-train)[^"\']*\1/i',
                $html,
            ) === 1) {
            throw new RuntimeException('Der finale Zug darf weder CSS-GIF-Background noch VML verwenden.');
        }

        $carrier = self::inspectCarrier($html);
        $carrierStyle = CssSemantic::decodeHtmlEntitiesOnce(
            self::singleCarrierAttributeValue($carrier, 'style', raw: true),
        );
        $backgroundLonghands = 0;
        foreach (['background-image', 'background-repeat', 'background-position', 'background-size'] as $property) {
            if (preg_match('/(?:^|;)\s*'.preg_quote($property, '/').'\s*:/i', $carrierStyle) === 1) {
                $backgroundLonghands++;
            }
        }
        if ($backgroundLonghands === 0) {
            if (preg_match('/(?:^|;)\s*background\s*:/i', $carrierStyle) === 1
                || preg_match('/url\s*\(/i', $carrierStyle) === 1) {
                throw new RuntimeException('Der optionale finale Signaturhintergrund ist nicht mail-sicher.');
            }
        } else {
            if ($backgroundLonghands !== 4) {
                throw new RuntimeException('Der optionale finale Signaturhintergrund ist unvollstaendig.');
            }
            $parsed = self::parseRuntimeBackgroundStyle($carrierStyle);
            self::assertRuntimeBaseBackgroundLists($parsed['lists'], expectedCount: 1);
            $backgroundImages = $parsed['lists']['background-image'];
            if (preg_match(
                '/^[ \t\r\n\f]*linear-gradient\((.*)\)[ \t\r\n\f]*$/is',
                $backgroundImages[0],
                $gradient,
            ) !== 1) {
                throw new RuntimeException('Die einzige CSS-Basis-Ebene muss der bildfreie Grundschleier bleiben.');
            }
            $gradientStops = self::splitCssAtTopLevel($gradient[1], ',');
            if (count($gradientStops) !== 2
                || self::normalizedCssValue($gradientStops[0]) !== self::normalizedCssValue($gradientStops[1])) {
                throw new RuntimeException('Der Grundschleier des Zug-Carriers ist nicht kanonisch.');
            }
        }

        $layers = [];
        $stages = [];
        $mainImages = [];
        $idleHolders = [];
        $idleImages = [];
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] === 'div' && self::sourceTagHasClass($tag, 'rt-sign-train-layer')) {
                $layers[] = $tag;
            }
            if ($tag['name'] === 'div' && self::sourceTagHasClass($tag, 'rt-sign-stage')) {
                $stages[] = $tag;
            }
            if (self::sourceTagHasClass($tag, 'rt-train-idle-overlay')
                || isset($tag['attributes']['data-rt-train-idle-overlay'])) {
                $idleHolders[] = $tag;
            }
            if ($tag['name'] !== 'img') {
                continue;
            }
            if (self::sourceTagHasClass($tag, 'rt-sign-train')
                || isset($tag['attributes']['data-rt-train'])) {
                $mainImages[] = $tag;
            }
            if (self::sourceTagHasClass($tag, 'rt-train-idle-image')
                || isset($tag['attributes']['data-rt-train-idle-image'])) {
                $idleImages[] = $tag;
            }
        }
        if (count($layers) !== 1 || count($stages) !== 1 || count($mainImages) !== 1) {
            throw new RuntimeException('Der finale Zug muss genau einmal als IMG in seinem kanonischen Layer vorliegen.');
        }

        $mainSource = self::singleTagAttributeValue($mainImages[0], 'src');
        if (! self::isAllowedMailImageSource($mainSource)
            || ($expectedMainSource !== null && ! hash_equals(trim($expectedMainSource), $mainSource))) {
            throw new RuntimeException('Das finale Zug-IMG besitzt nicht die erwartete Bildquelle.');
        }

        if (count($idleImages) > 1) {
            throw new RuntimeException('Das finale Idle-GIF darf nur einmal als IMG vorliegen.');
        }
        if (count($idleHolders) !== count($idleImages)) {
            throw new RuntimeException('Das finale Idle-IMG benoetigt genau einen hoehenlosen Holder.');
        }
        if ($expectedIdleSource === '' && $idleImages !== []) {
            throw new RuntimeException('Die statische Signatur darf kein Idle-GIF enthalten.');
        }
        if ($expectedIdleSource !== null && $expectedIdleSource !== '') {
            if (! self::isAllowedMailImageSource(trim($expectedIdleSource))
                || count($idleImages) !== 1
                || ! hash_equals(trim($expectedIdleSource), self::singleTagAttributeValue($idleImages[0], 'src'))) {
                throw new RuntimeException('Das finale Idle-IMG besitzt nicht die erwartete Bildquelle.');
            }
        } elseif ($idleImages !== []
            && ! self::isAllowedMailImageSource(self::singleTagAttributeValue($idleImages[0], 'src'))) {
            throw new RuntimeException('Das finale Idle-IMG besitzt keine mail-sichere Bildquelle.');
        }
        $idleHolderRange = null;
        if ($idleImages !== []) {
            $idle = $idleImages[0];
            $sizeName = strtolower(self::singleTagAttributeValue($layers[0], 'data-rt-layer-size'));
            $size = self::CANONICAL_LAYER_SIZE[$sizeName] ?? null;
            if (! is_array($size)
                || ! self::sourceTagHasClass($idle, 'rt-train-idle-image')
                || count($idle['attributes']['data-rt-train-idle-image'] ?? []) !== 1
                || self::singleTagAttributeValue($idle, 'width') !== '720'
                || self::singleTagAttributeValue($idle, 'alt') !== '') {
                throw new RuntimeException('Das finale Idle-IMG besitzt nicht den kanonischen Bildvertrag.');
            }
            $alignment = strtolower(self::singleTagAttributeValue($layers[0], 'data-rt-layer-align'));
            $idleHolderRange = self::assertRuntimeIdleDom($html, $size, $alignment);
        }

        $canonical = $html;
        $replacements = [];
        if (is_array($idleHolderRange)) {
            $replacements[] = [
                $idleHolderRange['startOffset'],
                $idleHolderRange['length'],
                '',
            ];
        }
        $mainSourceAttributes = $mainImages[0]['attributes']['src'] ?? [];
        if (count($mainSourceAttributes) !== 1 || $mainSourceAttributes[0]['valueOffset'] === null) {
            throw new RuntimeException('Das finale Zug-IMG besitzt kein eindeutiges src-Attribut.');
        }
        $replacements[] = [
            $mainSourceAttributes[0]['valueOffset'],
            $mainSourceAttributes[0]['valueLength'],
            '{{TRAIN_SRC}}',
        ];
        usort($replacements, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
        foreach ($replacements as [$offset, $length, $replacement]) {
            $canonical = substr_replace($canonical, $replacement, $offset, $length);
        }
        self::assertCanonicalImage($canonical);

        $msoSources = self::msoTrainImageSources($html);
        if ($expectedMsoSource === '' && $msoSources !== []) {
            throw new RuntimeException('Die Signatur darf keinen Outlook-Zugfallback enthalten.');
        }
        if ($expectedMsoSource !== null && $expectedMsoSource !== '') {
            if (count($msoSources) !== 1
                || ! hash_equals(trim($expectedMsoSource), $msoSources[0])) {
                throw new RuntimeException('Das Outlook-Zug-IMG besitzt nicht die erwartete Bildquelle.');
            }
        } elseif (count($msoSources) > 1) {
            throw new RuntimeException('Das Outlook-Zug-IMG darf nur einmal vorliegen.');
        }
    }

    /** @return array{startOffset:int,length:int} */
    private static function assertRuntimeIdleDom(string $html, array $size, string $alignment): array
    {
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><table id="rt-runtime-train-contract"><tbody>'.$html.'</tbody></table>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $wrapper = $loaded ? $document->getElementById('rt-runtime-train-contract') : null;
        if (! $wrapper instanceof DOMElement) {
            throw new RuntimeException('Das finale Idle-IMG konnte nicht gelesen werden.');
        }

        $layers = [];
        $slots = [];
        $mainImages = [];
        $idleHolders = [];
        $idleImages = [];
        foreach ($wrapper->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            $classes = self::elementClasses($element);
            if ($element->tagName === 'div' && in_array('rt-sign-train-layer', $classes, true)) {
                $layers[] = $element;
            }
            if ($element->tagName === 'td' && in_array('rt-sign-train-slot', $classes, true)) {
                $slots[] = $element;
            }
            if ($element->hasAttribute('data-rt-train-idle-overlay')
                || in_array('rt-train-idle-overlay', $classes, true)) {
                $idleHolders[] = $element;
            }
            if ($element->tagName === 'img'
                && ($element->hasAttribute('data-rt-train')
                    || in_array('rt-sign-train', $classes, true))) {
                $mainImages[] = $element;
            }
            if ($element->tagName === 'img'
                && ($element->hasAttribute('data-rt-train-idle-image')
                    || in_array('rt-train-idle-image', $classes, true))) {
                $idleImages[] = $element;
            }
        }
        $layer = $layers[0] ?? null;
        $slot = $slots[0] ?? null;
        $main = $mainImages[0] ?? null;
        $holder = $idleHolders[0] ?? null;
        $idle = $idleImages[0] ?? null;
        if (count($layers) !== 1
            || count($mainImages) !== 1
            || count($slots) !== 1
            || count($idleHolders) !== 1
            || count($idleImages) !== 1
            || ! $layer instanceof DOMElement
            || ! $slot instanceof DOMElement
            || ! $main instanceof DOMElement
            || ! $holder instanceof DOMElement
            || ! $idle instanceof DOMElement
            || strtolower($holder->tagName) !== 'span'
            || self::elementClasses($holder) !== ['rt-train-idle-overlay']
            || ! $holder->hasAttribute('data-rt-train-idle-overlay')
            || self::elementClasses($idle) !== ['rt-train-idle-image']
            || ! $idle->hasAttribute('data-rt-train-idle-image')
            || ! self::isDescendantOf($slot, $layer)
            || ! $holder->parentNode?->isSameNode($slot)
            || ! $idle->parentNode?->isSameNode($holder)
            || ! $main->parentNode?->isSameNode($slot)) {
            throw new RuntimeException('Das finale Idle-IMG muss allein im hoehenlosen Holder des Zug-Layers liegen.');
        }

        $slotElements = [];
        foreach ($slot->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $slotElements[] = $child;
            }
        }
        $holderElements = [];
        $holderHasForeignContent = false;
        foreach ($holder->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $holderElements[] = $child;

                continue;
            }
            if ($child->nodeType !== XML_TEXT_NODE || trim((string) $child->nodeValue) !== '') {
                $holderHasForeignContent = true;
            }
        }
        if (count($slotElements) !== 2
            || ! $slotElements[0]->isSameNode($holder)
            || ! $slotElements[1]->isSameNode($main)
            || count($holderElements) !== 1
            || ! $holderElements[0]->isSameNode($idle)
            || $holderHasForeignContent) {
            throw new RuntimeException('Idle-Holder und Hauptzug besitzen nicht die kanonische Reihenfolge.');
        }

        self::assertExactSimpleStyle($holder, [
            'position' => 'absolute',
            'left' => '0',
            'right' => 'auto',
            'top' => 'auto',
            'bottom' => '0',
            'display' => 'block',
            'width' => '100%',
            'max-width' => 'none',
            'height' => '0',
            'max-height' => '0',
            'margin' => '0',
            'overflow' => 'hidden',
            'z-index' => '1',
            'font-size' => '0',
            'line-height' => '0',
            'text-align' => 'left',
            'opacity' => '0',
            'visibility' => 'hidden',
            'animation' => 'rt-train-idle-reveal 1ms step-start 13s forwards',
            'mso-hide' => 'all',
        ], 'Idle-Zugholder');
        self::assertExactSimpleStyle($idle, [
            'position' => 'absolute',
            'left' => '0',
            'right' => 'auto',
            'bottom' => '0',
            'display' => 'inline-block',
            'width' => strtolower($size['width']),
            'max-width' => 'none',
            'height' => 'auto',
            'margin' => self::imageMargin($alignment, $size),
            'border' => '0',
            'outline' => 'none',
            'text-decoration' => 'none',
            'vertical-align' => 'bottom',
            'z-index' => '1',
            'mso-hide' => 'all',
        ], 'Idle-Zugbild');

        $sourceHolders = [];
        $sourceIdleImages = [];
        $sourceMainImages = [];
        foreach (self::scanStartTags($html) as $tag) {
            if (self::sourceTagHasClass($tag, 'rt-train-idle-overlay')
                || isset($tag['attributes']['data-rt-train-idle-overlay'])) {
                $sourceHolders[] = $tag;
            }
            if ($tag['name'] === 'img'
                && (self::sourceTagHasClass($tag, 'rt-train-idle-image')
                    || isset($tag['attributes']['data-rt-train-idle-image']))) {
                $sourceIdleImages[] = $tag;
            }
            if ($tag['name'] === 'img'
                && (self::sourceTagHasClass($tag, 'rt-sign-train')
                    || isset($tag['attributes']['data-rt-train']))) {
                $sourceMainImages[] = $tag;
            }
        }
        if (count($sourceHolders) !== 1
            || count($sourceIdleImages) !== 1
            || count($sourceMainImages) !== 1) {
            throw new RuntimeException('Der Quellvertrag des Idle-Zugholders ist nicht eindeutig.');
        }
        $sourceHolder = $sourceHolders[0];
        $sourceIdle = $sourceIdleImages[0];
        $sourceMain = $sourceMainImages[0];
        self::assertExactSourceTagAttributeNames($sourceHolder, [
            'class',
            'data-rt-train-idle-overlay',
            'style',
        ], 'Idle-Zugholder');
        self::assertExactSourceTagAttributeNames($sourceIdle, [
            'class',
            'data-rt-train-idle-image',
            'src',
            'width',
            'alt',
            'style',
        ], 'Idle-Zugbild');
        if ($sourceHolder['name'] !== 'span'
            || self::singleTagAttributeValue($sourceHolder, 'class') !== 'rt-train-idle-overlay'
            || self::singleTagAttributeValue($sourceHolder, 'data-rt-train-idle-overlay') !== ''
            || ! hash_equals($holder->getAttribute('style'), self::singleTagAttributeValue($sourceHolder, 'style'))
            || self::singleTagAttributeValue($sourceIdle, 'class') !== 'rt-train-idle-image'
            || self::singleTagAttributeValue($sourceIdle, 'data-rt-train-idle-image') !== ''
            || ! hash_equals($idle->getAttribute('style'), self::singleTagAttributeValue($sourceIdle, 'style'))
            || $sourceHolder['endOffset'] >= $sourceIdle['startOffset']
            || $sourceIdle['endOffset'] >= $sourceMain['startOffset']
            || preg_match(
                '/^[ \t\r\n\f]*$/D',
                substr(
                    $html,
                    $sourceHolder['endOffset'] + 1,
                    $sourceIdle['startOffset'] - $sourceHolder['endOffset'] - 1,
                ),
            ) !== 1) {
            throw new RuntimeException('Der Quellvertrag des Idle-Zugholders ist manipuliert.');
        }

        $afterIdleOffset = $sourceIdle['endOffset'] + 1;
        if (preg_match(
            '/\A[ \t\r\n\f]*<\/span[ \t\r\n\f]*>/',
            substr($html, $afterIdleOffset),
            $closingHolder,
        ) !== 1) {
            throw new RuntimeException('Der Idle-Zugholder besitzt keinen eindeutigen Abschluss.');
        }
        $holderEndOffset = $afterIdleOffset + strlen($closingHolder[0]);
        if ($holderEndOffset > $sourceMain['startOffset']
            || preg_match(
                '/^[ \t\r\n\f]*$/D',
                substr($html, $holderEndOffset, $sourceMain['startOffset'] - $holderEndOffset),
            ) !== 1) {
            throw new RuntimeException('Das Hauptzug-IMG muss direkt nach dem hoehenlosen Idle-Holder folgen.');
        }

        return [
            'startOffset' => $sourceHolder['startOffset'],
            'length' => $holderEndOffset - $sourceHolder['startOffset'],
        ];
    }

    /** @return list<string> */
    private static function msoTrainImageSources(string $html): array
    {
        $layers = [];
        $slots = [];
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] === 'div' && self::sourceTagHasClass($tag, 'rt-sign-train-layer')) {
                $layers[] = $tag;
            }
            if ($tag['name'] === 'td' && self::sourceTagHasClass($tag, 'rt-sign-train-slot')) {
                $slots[] = $tag;
            }
        }
        if (count($layers) !== 1 || count($slots) !== 1) {
            throw new RuntimeException('Der Outlook-Zugfallback besitzt keinen eindeutigen Zug-Layer.');
        }
        $sizeName = strtolower(self::singleTagAttributeValue($layers[0], 'data-rt-layer-size'));
        $alignment = strtolower(self::singleTagAttributeValue($layers[0], 'data-rt-layer-align'));
        $size = self::CANONICAL_LAYER_SIZE[$sizeName] ?? null;
        if (! is_array($size) || ! in_array($alignment, ['left', 'center', 'right'], true)) {
            throw new RuntimeException('Der Outlook-Zugfallback besitzt keine kanonische Bildgroesse.');
        }

        preg_match_all(
            '/<!--\s*\[if\s+mso\]\s*>(.*?)<!\s*\[endif\]\s*-->/is',
            $html,
            $comments,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        $sources = [];
        foreach ($comments as $comment) {
            $content = (string) ($comment[1][0] ?? '');
            if (preg_match('/\brt-sign-train-mso\b/i', $content) !== 1) {
                continue;
            }
            if (($comment[0][1] ?? -1) !== $slots[0]['endOffset'] + 1) {
                throw new RuntimeException('Das Outlook-Zugfallback-IMG muss direkt am Anfang des Zug-Slots liegen.');
            }
            $tags = self::scanStartTags($content);
            if (count($tags) !== 1
                || $tags[0]['name'] !== 'img'
                || ! self::sourceTagHasClass($tags[0], 'rt-sign-train-mso')) {
                throw new RuntimeException('Der Outlook-Zugfallback muss genau ein IMG enthalten.');
            }
            self::assertExactSourceTagAttributeNames($tags[0], [
                'class',
                'data-rt-train-mso',
                'src',
                'width',
                'alt',
                'style',
            ], 'Outlook-Zugfallback');
            $source = self::singleTagAttributeValue($tags[0], 'src');
            if (! self::isAllowedMailImageSource($source, staticOnly: true)
                || self::singleTagAttributeValue($tags[0], 'class') !== 'rt-sign-train-mso'
                || self::singleTagAttributeValue($tags[0], 'data-rt-train-mso') !== '1'
                || self::singleTagAttributeValue($tags[0], 'width') !== '720'
                || self::singleTagAttributeValue($tags[0], 'alt') !== '') {
                throw new RuntimeException('Das Outlook-Zugfallback-IMG besitzt keine mail-sichere Quelle oder Breite.');
            }
            self::assertExactSourceTagStyle($tags[0], [
                'display' => 'inline-block',
                'width' => $size['width'],
                'max-width' => 'none',
                'height' => 'auto',
                'margin' => self::imageMargin($alignment, $size),
                'border' => '0',
                'outline' => 'none',
                'text-decoration' => 'none',
                'vertical-align' => 'bottom',
            ], 'Outlook-Zugfallback');
            $sources[] = $source;
        }

        return $sources;
    }

    /**
     * @param  array{attributes:array<string,list<array<string,mixed>>>}  $carrier
     */
    private static function singleCarrierAttributeValue(array $carrier, string $name, bool $raw = false): string
    {
        $attributes = $carrier['attributes'][strtolower($name)] ?? [];
        if (count($attributes) !== 1) {
            throw new RuntimeException('Der finale Zug-Carrier besitzt ein erforderliches Attribut nicht eindeutig.');
        }

        return (string) $attributes[0][$raw ? 'raw' : 'decoded'];
    }

    /**
     * @return array{
     *   segments:list<string>,
     *   declarations:array<string,array{segment:int,prefix:string,suffix:string}>,
     *   lists:array<string,list<string>>
     * }
     */
    private static function parseRuntimeBackgroundStyle(
        string $style,
        bool $allowStoredTokens = false,
    ): array {
        if (preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', $style) !== 0
            || str_contains($style, '/*')
            || str_contains($style, '*/')
            || preg_match('/[\[\]]/', $style) !== 0
            || (! $allowStoredTokens && preg_match('/[{}]/', $style) !== 0)) {
            throw new RuntimeException('Der finale Zug-Carrier enthaelt unzulaessige CSS-Strukturzeichen.');
        }

        $required = ['background-image', 'background-repeat', 'background-position', 'background-size'];
        $segments = self::splitCssAtTopLevel($style, ';');
        $declarations = [];
        foreach ($segments as $index => $segment) {
            if (preg_match(
                '/^([ \t\r\n\f]*)([a-z-]+)([ \t\r\n\f]*:[ \t\r\n\f]*)(.*?)([ \t\r\n\f]*)$/is',
                $segment,
                $match,
            ) !== 1) {
                continue;
            }
            $property = strtolower($match[2]);
            if ($property === 'background') {
                throw new RuntimeException('Der finale Zug-Carrier darf keine background-Kurzform enthalten.');
            }
            if (! in_array($property, $required, true)) {
                continue;
            }
            if (isset($declarations[$property]) || preg_match('/!\s*important\s*$/i', $match[4]) === 1) {
                throw new RuntimeException('Die Background-Listen des finalen Zug-Carriers sind nicht eindeutig.');
            }
            $declarations[$property] = [
                'segment' => $index,
                'prefix' => $match[1].$match[2].$match[3],
                'suffix' => $match[5],
                'value' => $match[4],
            ];
        }

        $lists = [];
        $count = null;
        foreach ($required as $property) {
            if (! isset($declarations[$property])) {
                throw new RuntimeException('Der finale Zug-Carrier besitzt keine vollstaendigen Background-Listen.');
            }
            $items = self::splitCssAtTopLevel($declarations[$property]['value'], ',');
            if ($items === [] || array_filter($items, static fn (string $item): bool => trim($item) === '') !== []) {
                throw new RuntimeException('Der finale Zug-Carrier besitzt eine leere Background-Ebene.');
            }
            $count ??= count($items);
            if (count($items) !== $count) {
                throw new RuntimeException('Die Background-Listen des finalen Zug-Carriers sind nicht parallel.');
            }
            $lists[$property] = $items;
        }

        return compact('segments', 'declarations', 'lists');
    }

    /** @param array<string,list<string>> $lists */
    private static function assertRuntimeBaseBackgroundLists(array $lists, int $expectedCount): void
    {
        if (count($lists['background-image'] ?? []) !== $expectedCount) {
            throw new RuntimeException('Der finale Zug-Carrier besitzt nicht die erwartete Background-Layerzahl.');
        }
        $expected = match ($expectedCount) {
            1 => [
                'background-repeat' => ['no-repeat'],
                'background-position' => ['center center'],
                'background-size' => ['100% 100%'],
            ],
            3 => [
                'background-repeat' => ['repeat', 'no-repeat', 'no-repeat'],
                'background-position' => ['left top', 'right center', 'center center'],
                'background-size' => ['64px 64px', 'auto 100%', '100% 100%'],
            ],
            default => throw new RuntimeException('Der finale Zug-Carrier besitzt eine unbekannte Background-Layerzahl.'),
        };
        foreach ($expected as $property => $values) {
            foreach ($values as $index => $value) {
                if (self::normalizedCssValue($lists[$property][$index] ?? '') !== $value) {
                    throw new RuntimeException('Die Basis-Layer des finalen Zug-Carriers sind nicht kanonisch.');
                }
            }
        }
    }

    private static function cssUrlSource(string $entry): string
    {
        if (preg_match(
            '/^[ \t\r\n\f]*url\([ \t\r\n\f]*(?:(["\'])(.*?)\1|([^"\'() \t\r\n\f]+))[ \t\r\n\f]*\)[ \t\r\n\f]*$/is',
            $entry,
            $match,
        ) !== 1) {
            throw new RuntimeException('Die Zugquelle ist keine eindeutige CSS-URL.');
        }

        return trim((string) (($match[1] ?? '') !== '' ? $match[2] : $match[3]));
    }

    /**
     * Entfernt nur die bekannten alten Starterabstaende direkt vor der
     * regulaeren Zugzeile. Dadurch werden bereits publizierte Signaturen und
     * Editorvorschauen sofort kompakt, waehrend individuell gesetzte
     * Innenabstaende unveraendert bleiben.
     */
    private static function compactDefaultContentPadding(string $html): string
    {
        $contentCells = [];
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] === 'td' && self::sourceTagHasClass($tag, 'rt-sign-content')) {
                $contentCells[] = $tag;
            }
        }
        if (count($contentCells) !== 1) {
            return $html;
        }

        $styles = $contentCells[0]['attributes']['style'] ?? [];
        if (count($styles) !== 1 || $styles[0]['valueOffset'] === null) {
            throw new RuntimeException('Der Signatur-Inhalt besitzt kein eindeutiges style-Attribut.');
        }
        $style = CssSemantic::decodeHtmlEntitiesOnce($styles[0]['raw']);
        $compacted = strtr($style, [
            'padding:18px 36px 20px;' => 'padding:18px 36px 0;',
            'padding:16px 28px 18px;' => 'padding:16px 28px 0;',
        ]);
        if ($compacted === $style) {
            return $html;
        }

        return substr_replace(
            $html,
            htmlspecialchars($compacted, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            $styles[0]['valueOffset'],
            $styles[0]['valueLength'],
        );
    }

    /**
     * Liefert ein echtes Attribut des per DOM bestaetigten Carrier-TD.
     * Doppelte Attribute sind immer mehrdeutig und werden fail-closed
     * abgelehnt.
     *
     * @return array{raw:string, decoded:string, quote:?string}|null
     */
    public static function carrierAttribute(string $html, string $name): ?array
    {
        $carrier = self::inspectCarrier($html);
        $attributes = $carrier['attributes'][strtolower($name)] ?? [];
        if ($attributes === []) {
            return null;
        }
        if (count($attributes) !== 1) {
            throw new RuntimeException('Der Zug-Carrier besitzt ein Attribut mehrfach.');
        }

        return [
            'raw' => $attributes[0]['raw'],
            'decoded' => $attributes[0]['decoded'],
            'quote' => $attributes[0]['quote'],
        ];
    }

    /**
     * @return array{
     *   tagStart:int,
     *   tagEnd:int,
     *   attributes:array<string,list<array{
     *     raw:string,
     *     decoded:string,
     *     quote:?string,
     *     valueOffset:?int,
     *     valueLength:int,
     *     attributeOffset:int,
     *     attributeLength:int
     *   }>>
     * }
     */
    private static function inspectCarrier(string $html): array
    {
        $wrapperId = 'rt-train-carrier-contract-'.hash('sha256', $html);
        while (str_contains($html, $wrapperId)) {
            $wrapperId .= 'x';
        }
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><table id="'.$wrapperId.'"><tbody>'.$html.'</tbody></table>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $wrapper = $loaded ? $document->getElementById($wrapperId) : null;
        if (! $wrapper instanceof DOMElement) {
            throw new RuntimeException('Die veroeffentlichte Signatur besitzt keinen lesbaren Zug-Carrier.');
        }

        $domCells = [];
        $domCarriers = [];
        foreach ($wrapper->getElementsByTagName('td') as $cell) {
            $domCells[] = $cell;
            $classes = preg_split('/\s+/', trim($cell->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (in_array('rt-sign-cell', $classes, true)) {
                $domCarriers[] = $cell;
            }
        }
        if (count($domCarriers) !== 1 || ! $domCarriers[0]->hasAttribute('style')) {
            throw new RuntimeException('Die veroeffentlichte Signatur besitzt keinen eindeutigen echten Zug-Carrier.');
        }

        $sourceCells = [];
        $sourceCarriers = [];
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] !== 'td') {
                continue;
            }
            $sourceOrdinal = count($sourceCells);
            $sourceCells[] = $tag;
            $classAttributes = $tag['attributes']['class'] ?? [];
            if (count($classAttributes) > 1) {
                throw new RuntimeException('Der Zug-Carrier besitzt das class-Attribut mehrfach.');
            }
            if ($classAttributes === []) {
                continue;
            }
            $classes = preg_split(
                '/\s+/',
                trim($classAttributes[0]['decoded']),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [];
            if (in_array('rt-sign-cell', $classes, true)) {
                $sourceCarriers[] = [
                    'ordinal' => $sourceOrdinal,
                    'tag' => $tag,
                ];
            }
        }

        if (count($sourceCarriers) !== 1 || count($sourceCells) !== count($domCells)) {
            throw new RuntimeException('Die Zug-Carrier-Quelle ist nicht eindeutig lesbar.');
        }
        $domCarrierOrdinal = null;
        foreach ($domCells as $ordinal => $cell) {
            if ($cell->isSameNode($domCarriers[0])) {
                $domCarrierOrdinal = $ordinal;

                break;
            }
        }
        $sourceCarrier = $sourceCarriers[0]['tag'];
        $sourceClass = $sourceCarrier['attributes']['class'][0]['decoded'] ?? null;
        $styleAttributes = $sourceCarrier['attributes']['style'] ?? [];
        if ($domCarrierOrdinal === null
            || $sourceCarriers[0]['ordinal'] !== $domCarrierOrdinal
            || ! is_string($sourceClass)
            || ! hash_equals($domCarriers[0]->getAttribute('class'), $sourceClass)
            || count($styleAttributes) !== 1
            || $styleAttributes[0]['valueOffset'] === null
            || $styleAttributes[0]['quote'] === null
            || ! hash_equals(
                $domCarriers[0]->getAttribute('style'),
                $styleAttributes[0]['decoded'],
            )) {
            throw new RuntimeException('Das echte style-Attribut des Zug-Carriers ist nicht eindeutig abbildbar.');
        }

        return [
            'tagStart' => $sourceCarrier['startOffset'],
            'tagEnd' => $sourceCarrier['endOffset'],
            'attributes' => $sourceCarrier['attributes'],
        ];
    }

    /**
     * Minimaler positionssicherer HTML-Starttagscanner. Er ueberspringt
     * Kommentare und komplette gequotete Attributwerte; ein darin nur als
     * Text vorkommendes `<td ... style=...>` kann deshalb nie Carrier sein.
     *
     * @return list<array{
     *   name:string,
     *   startOffset:int,
     *   endOffset:int,
     *   attributes:array<string,list<array{
     *     raw:string,
     *     decoded:string,
     *     quote:?string,
     *     valueOffset:?int,
     *     valueLength:int,
     *     attributeOffset:int,
     *     attributeLength:int
     *   }>>
     * }>
     */
    private static function scanStartTags(string $html): array
    {
        $tags = [];
        $length = strlen($html);
        for ($index = 0; $index < $length; $index++) {
            if ($html[$index] !== '<') {
                continue;
            }
            if (substr($html, $index, 4) === '<!--') {
                $commentEnd = strpos($html, '-->', $index + 4);
                if ($commentEnd === false) {
                    throw new RuntimeException('Die Signatur enthaelt einen ungeschlossenen HTML-Kommentar.');
                }
                $index = $commentEnd + 2;

                continue;
            }

            $nameStart = $index + 1;
            if (($html[$nameStart] ?? '') === '/'
                || ($html[$nameStart] ?? '') === '!'
                || ($html[$nameStart] ?? '') === '?') {
                continue;
            }
            if (preg_match('/[A-Za-z]/', $html[$nameStart] ?? '') !== 1) {
                continue;
            }

            $nameEnd = $nameStart;
            while ($nameEnd < $length
                && preg_match('/[A-Za-z0-9:-]/', $html[$nameEnd]) === 1) {
                $nameEnd++;
            }
            $tagEnd = self::findTagEnd($html, $nameEnd);
            if ($tagEnd === null) {
                throw new RuntimeException('Die Signatur enthaelt einen ungeschlossenen HTML-Starttag.');
            }

            $tags[] = [
                'name' => strtolower(substr($html, $nameStart, $nameEnd - $nameStart)),
                'startOffset' => $index,
                'endOffset' => $tagEnd,
                'attributes' => self::parseAttributes($html, $nameEnd, $tagEnd),
            ];
            $index = $tagEnd;
        }

        return $tags;
    }

    private static function findTagEnd(string $html, int $start): ?int
    {
        $quote = null;
        $length = strlen($html);
        for ($index = $start; $index < $length; $index++) {
            $character = $html[$index];
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
            if ($character === '>') {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array<string,list<array{
     *   raw:string,
     *   decoded:string,
     *   quote:?string,
     *   valueOffset:?int,
     *   valueLength:int
     * }>>
     */
    private static function parseAttributes(string $html, int $start, int $tagEnd): array
    {
        $attributes = [];
        $cursor = $start;
        while ($cursor < $tagEnd) {
            $attributeOffset = $cursor;
            while ($cursor < $tagEnd && str_contains(" \t\r\n\f", $html[$cursor])) {
                $cursor++;
            }
            if ($cursor >= $tagEnd || $html[$cursor] === '/') {
                break;
            }

            $nameStart = $cursor;
            while ($cursor < $tagEnd
                && ! str_contains(" \t\r\n\f=/>\"'<", $html[$cursor])) {
                $cursor++;
            }
            if ($cursor === $nameStart) {
                throw new RuntimeException('Der Zug-Carrier enthaelt ein unlesbares HTML-Attribut.');
            }
            $name = strtolower(substr($html, $nameStart, $cursor - $nameStart));
            while ($cursor < $tagEnd && str_contains(" \t\r\n\f", $html[$cursor])) {
                $cursor++;
            }

            $raw = '';
            $quote = null;
            $valueOffset = null;
            $valueLength = 0;
            if ($cursor < $tagEnd && $html[$cursor] === '=') {
                $cursor++;
                while ($cursor < $tagEnd && str_contains(" \t\r\n\f", $html[$cursor])) {
                    $cursor++;
                }
                if ($cursor >= $tagEnd) {
                    throw new RuntimeException('Der Zug-Carrier enthaelt ein Attribut ohne Wert.');
                }

                if ($html[$cursor] === '"' || $html[$cursor] === "'") {
                    $quote = $html[$cursor];
                    $valueOffset = ++$cursor;
                    while ($cursor < $tagEnd && $html[$cursor] !== $quote) {
                        $cursor++;
                    }
                    if ($cursor >= $tagEnd) {
                        throw new RuntimeException('Der Zug-Carrier enthaelt einen ungeschlossenen Attributwert.');
                    }
                    $valueLength = $cursor - $valueOffset;
                    $raw = substr($html, $valueOffset, $valueLength);
                    $cursor++;
                } else {
                    $valueOffset = $cursor;
                    while ($cursor < $tagEnd
                        && ! str_contains(" \t\r\n\f>", $html[$cursor])) {
                        if (str_contains("\"'<=`", $html[$cursor])) {
                            throw new RuntimeException('Der Zug-Carrier enthaelt einen unzulaessigen ungequoteten Attributwert.');
                        }
                        $cursor++;
                    }
                    $valueLength = $cursor - $valueOffset;
                    if ($valueLength === 0) {
                        throw new RuntimeException('Der Zug-Carrier enthaelt ein Attribut ohne Wert.');
                    }
                    $raw = substr($html, $valueOffset, $valueLength);
                }
            }

            $attributes[$name][] = [
                'raw' => $raw,
                'decoded' => CssSemantic::decodeHtmlEntitiesOnce($raw),
                'quote' => $quote,
                'valueOffset' => $valueOffset,
                'valueLength' => $valueLength,
                'attributeOffset' => $attributeOffset,
                'attributeLength' => $cursor - $attributeOffset,
            ];
        }

        return $attributes;
    }

    private static function normalizeStyle(string $style, bool $removeMainLayer = false): string
    {
        $controlState = preg_match(
            '/[\x{0000}-\x{0008}\x{000B}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u',
            $style,
        );
        if ($controlState !== 0) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier enthaelt unzulaessige CSS-Steuerzeichen.'
            );
        }

        $maskedStyle = preg_replace_callback(
            '/\{\{([A-Z][A-Z0-9_]*)\}\}/',
            static fn (array $match): string => in_array($match[1], self::ALLOWED_STYLE_TOKENS, true)
                ? str_repeat('_', strlen($match[0]))
                : $match[0],
            $style,
        );
        if (! is_string($maskedStyle) || preg_match('/[{}\[\]]/', $maskedStyle) !== 0) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier enthaelt unzulaessige CSS-Klammern.'
            );
        }

        // Der kanonische Carrier benoetigt keine CSS-Kommentare. Werden sie
        // vor der Semikolon-Zerlegung zugelassen, kann ein Semikolon IM
        // Kommentar den Parserzustand vom Zustand des Mailclients trennen
        // (`background/*;*/:none`). Deshalb jeden Kommentarmarker fail-closed
        // ablehnen, auch einen einzelnen beziehungsweise unbalancierten.
        if (str_contains($style, '/*') || str_contains($style, '*/')) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier enthaelt unzulaessige CSS-Kommentare.'
            );
        }
        if (str_contains($style, '\\')) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier enthaelt unzulaessige CSS-Escape-Zeichen.'
            );
        }

        $segments = self::splitCssAtTopLevel($style, ';');
        $required = [
            'background-image',
            'background-repeat',
            'background-position',
            'background-size',
        ];
        $declarations = [];

        foreach ($segments as $index => $segment) {
            if (! preg_match(
                '/^([ \t\r\n\f]*)([a-z-]+)([ \t\r\n\f]*:[ \t\r\n\f]*)(.*?)([ \t\r\n\f]*)$/is',
                $segment,
                $match,
            )) {
                continue;
            }

            $property = strtolower($match[2]);
            if ($property === 'background') {
                throw new RuntimeException(
                    'Der veroeffentlichte Zug-Carrier enthaelt eine unzulaessige background-Kurzform.'
                );
            }

            if (! in_array($property, $required, true)) {
                continue;
            }

            if (isset($declarations[$property])) {
                throw new RuntimeException(
                    "Der veroeffentlichte Zug-Carrier enthaelt {$property} mehrfach."
                );
            }

            $value = $match[4];
            $important = '';
            if (preg_match('/^(.*?)([ \t\r\n\f]*!important)$/is', $value, $importantMatch)) {
                throw new RuntimeException(
                    "Der veroeffentlichte Zug-Carrier darf {$property} nicht als !important markieren."
                );
            }

            $declarations[$property] = [
                'segment' => $index,
                'prefix' => $match[1].$match[2].$match[3],
                'value' => $value,
                'important' => $important,
                'suffix' => $match[5],
            ];
        }

        foreach ($required as $property) {
            if (! isset($declarations[$property])) {
                throw new RuntimeException(
                    "Der veroeffentlichte Zug-Carrier besitzt kein {$property}."
                );
            }
        }

        $lists = [];
        $expectedCount = null;
        foreach ($required as $property) {
            $items = self::splitCssAtTopLevel($declarations[$property]['value'], ',');
            foreach ($items as $item) {
                if (trim($item) === '') {
                    throw new RuntimeException(
                        "Der veroeffentlichte Zug-Carrier enthaelt eine leere {$property}-Ebene."
                    );
                }
            }

            $expectedCount ??= count($items);
            if (count($items) !== $expectedCount) {
                throw new RuntimeException(
                    'Die Background-Listen des veroeffentlichten Zug-Carriers sind nicht parallel.'
                );
            }

            $lists[$property] = $items;
        }

        self::assertCanonicalLayerContract($lists);

        $mainIndexes = [];
        $idleIndexes = [];
        foreach ($lists['background-image'] as $index => $image) {
            if (self::cssUrlTargetsToken($image, 'TRAIN_SRC')) {
                $mainIndexes[] = $index;
            }
            if (self::cssUrlTargetsToken($image, 'TRAIN_IDLE_SRC')) {
                $idleIndexes[] = $index;
            }
        }

        if (count($mainIndexes) !== 1) {
            throw new RuntimeException(
                'Die Background-Liste der veroeffentlichten Signatur besitzt keinen eindeutigen Zug.'
            );
        }
        if (count($idleIndexes) > 1) {
            throw new RuntimeException(
                'Die Background-Liste der veroeffentlichten Signatur besitzt mehrere Idle-Zuege.'
            );
        }

        $mainPosition = self::normalizedCssValue(
            $lists['background-position'][$mainIndexes[0]],
        );

        if ($idleIndexes !== []) {
            $idleIndex = $idleIndexes[0];
            foreach ($required as $property) {
                array_splice($lists[$property], $idleIndex, 1);
            }
        }

        $mainIndexes = [];
        foreach ($lists['background-image'] as $index => $image) {
            if (self::cssUrlTargetsToken($image, 'TRAIN_SRC')) {
                $mainIndexes[] = $index;
            }
        }
        if (count($mainIndexes) !== 1) {
            throw new RuntimeException(
                'Der Hauptzug ging bei der Normalisierung der Background-Listen verloren.'
            );
        }

        $mainIndex = $mainIndexes[0];
        if ($removeMainLayer) {
            foreach ($required as $property) {
                array_splice($lists[$property], $mainIndex, 1);
            }
        } else {
            $lists['background-repeat'][$mainIndex] = 'no-repeat';
            $lists['background-position'][$mainIndex] = $mainPosition;
            $lists['background-size'][$mainIndex] = 'auto 100%';
        }

        foreach ($required as $property) {
            $declaration = $declarations[$property];
            $segments[$declaration['segment']] = $declaration['prefix']
                .implode(',', $lists[$property])
                .$declaration['important']
                .$declaration['suffix'];
        }

        return implode(';', $segments);
    }

    /**
     * @param  array<string, list<string>>  $lists
     */
    private static function assertCanonicalLayerContract(array $lists): void
    {
        $layerCount = count($lists['background-image']);
        if (! in_array($layerCount, [4, 5], true)) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier besitzt keine kanonische Layerzahl.'
            );
        }

        $images = $lists['background-image'];
        if (! self::cssUrlTargetsToken($images[0], 'GRUND_RASTER_SRC')
            || ! self::cssUrlTargetsToken($images[1], 'GRUND_MARKE_SRC')
            || ! self::cssLinearGradientTargetsWash($images[2])) {
            throw new RuntimeException(
                'Die Basis-Layer des veroeffentlichten Zug-Carriers sind nicht kanonisch.'
            );
        }

        $mainIndex = 3;
        if ($layerCount === 5) {
            if (! self::cssUrlTargetsToken($images[3], 'TRAIN_IDLE_SRC')) {
                throw new RuntimeException(
                    'Der Legacy-Idle-Layer des veroeffentlichten Zug-Carriers ist nicht kanonisch.'
                );
            }
            $mainIndex = 4;
        }
        if (! self::cssUrlTargetsToken($images[$mainIndex], 'TRAIN_SRC')) {
            throw new RuntimeException(
                'Der Hauptzug-Layer des veroeffentlichten Zug-Carriers ist nicht kanonisch.'
            );
        }

        foreach ($lists['background-repeat'] as $index => $repeat) {
            $expected = $index === 0 ? 'repeat' : 'no-repeat';
            if (self::normalizedCssValue($repeat) !== $expected) {
                throw new RuntimeException(
                    'Die Wiederholungswerte des veroeffentlichten Zug-Carriers sind nicht kanonisch.'
                );
            }
        }

        $fixedPositions = ['left top', 'right center', 'center center'];
        foreach ($fixedPositions as $index => $expected) {
            if (self::normalizedCssValue($lists['background-position'][$index]) !== $expected) {
                throw new RuntimeException(
                    'Die Basispositionen des veroeffentlichten Zug-Carriers sind nicht kanonisch.'
                );
            }
        }
        for ($index = 3; $index < $layerCount; $index++) {
            if (! in_array(
                self::normalizedCssValue($lists['background-position'][$index]),
                self::ALLOWED_TRAIN_POSITIONS,
                true,
            )) {
                throw new RuntimeException(
                    'Die Zugpositionen des veroeffentlichten Zug-Carriers sind nicht kanonisch.'
                );
            }
        }
        if ($layerCount === 5
            && self::normalizedCssValue($lists['background-position'][3])
                !== self::normalizedCssValue($lists['background-position'][4])) {
            throw new RuntimeException(
                'Legacy-Idle-Zug und Hauptzug muessen dieselbe Position besitzen.'
            );
        }

        $expectedSizes = ['64px 64px', 'auto 100%', '100% 100%'];
        foreach ($expectedSizes as $index => $expected) {
            if (self::normalizedCssValue($lists['background-size'][$index]) !== $expected) {
                throw new RuntimeException(
                    'Die Basisgroessen des veroeffentlichten Zug-Carriers sind nicht kanonisch.'
                );
            }
        }
        for ($index = 3; $index < $layerCount; $index++) {
            if (self::normalizedCssValue($lists['background-size'][$index]) !== 'auto 100%') {
                throw new RuntimeException(
                    'Die Zuggroessen des veroeffentlichten Zug-Carriers sind nicht kanonisch.'
                );
            }
        }
    }

    private static function cssLinearGradientTargetsWash(string $entry): bool
    {
        if (! preg_match(
            '/^[ \t\r\n\f]*linear-gradient\((.*)\)[ \t\r\n\f]*$/is',
            $entry,
            $match,
        )) {
            return false;
        }

        $arguments = self::splitCssAtTopLevel($match[1], ',');

        return count($arguments) === 2
            && self::trimCssWhitespace($arguments[0]) === '{{SIGNATURE_TRAIN_WASH}}'
            && self::trimCssWhitespace($arguments[1]) === '{{SIGNATURE_TRAIN_WASH}}';
    }

    private static function normalizedCssValue(string $value): string
    {
        $normalized = preg_replace(
            '/[ \t\r\n\f]+/',
            ' ',
            self::trimCssWhitespace($value),
        );
        if (! is_string($normalized)) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier enthaelt einen unlesbaren CSS-Wert.'
            );
        }

        return strtolower($normalized);
    }

    private static function trimCssWhitespace(string $value): string
    {
        return trim($value, " \t\r\n\f");
    }

    /**
     * Trennt CSS nur ausserhalb von Funktionen und Strings. Damit bleiben
     * insbesondere Gradient-Kommas, Data-URI-Semikolons und gequotete Werte
     * unangetastet. Unbalancierte Eingaben gelten als strukturell unsicher.
     *
     * @return list<string>
     */
    private static function splitCssAtTopLevel(string $value, string $separator): array
    {
        $parts = [];
        $start = 0;
        $depth = 0;
        $quote = null;
        $escaped = false;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];

            if ($quote !== null) {
                if ($character === "\r" || $character === "\n" || $character === "\f") {
                    throw new RuntimeException(
                        'Der veroeffentlichte Zug-Carrier enthaelt einen unzulaessigen CSS-Stringumbruch.'
                    );
                }
                if ($escaped) {
                    $escaped = false;

                    continue;
                }
                if ($character === '\\') {
                    $escaped = true;

                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }
            if ($character === '(') {
                $depth++;

                continue;
            }
            if ($character === ')') {
                if ($depth === 0) {
                    throw new RuntimeException(
                        'Der veroeffentlichte Zug-Carrier enthaelt unbalanciertes CSS.'
                    );
                }
                $depth--;

                continue;
            }
            if ($character === $separator && $depth === 0) {
                $parts[] = substr($value, $start, $index - $start);
                $start = $index + 1;
            }
        }

        if ($quote !== null || $depth !== 0) {
            throw new RuntimeException(
                'Der veroeffentlichte Zug-Carrier enthaelt unbalanciertes CSS.'
            );
        }

        $parts[] = substr($value, $start);

        return $parts;
    }

    private static function cssUrlTargetsToken(string $entry, string $token): bool
    {
        if (! preg_match('/^[ \t\r\n\f]*url\((.*)\)[ \t\r\n\f]*$/is', $entry, $match)) {
            return false;
        }

        $target = self::trimCssWhitespace($match[1]);
        if (strlen($target) >= 2
            && (($target[0] === '"' && $target[strlen($target) - 1] === '"')
                || ($target[0] === "'" && $target[strlen($target) - 1] === "'"))) {
            $target = self::trimCssWhitespace(substr($target, 1, -1));
        }

        return $target === '{{'.$token.'}}';
    }
}
