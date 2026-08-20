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
    /** @var array<string, array{width:string,maxWidth:string,centerLeft:string}> */
    private const CANONICAL_LAYER_SIZE = [
        '100' => ['width' => '100%', 'maxWidth' => '1815px', 'centerLeft' => '0'],
        '125' => ['width' => '125%', 'maxWidth' => '2269px', 'centerLeft' => '-12.5%'],
        '150' => ['width' => '150%', 'maxWidth' => '2723px', 'centerLeft' => '-25%'],
        '200' => ['width' => '200%', 'maxWidth' => '3630px', 'centerLeft' => '-50%'],
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
     * Ein-GIF-Vertrag von Logo und RT-Icon. Der absolute Bild-Layer bleibt
     * innerhalb einer normalen Block-Buehne hinter dem Inhalt und erzeugt
     * keine eigene Tabellenhoehe.
     */
    public static function projectAsImage(string $html, string $source, string $padding = '0'): string
    {
        $source = trim($source);
        if ($source === '') {
            throw new RuntimeException('Die Zuganimation besitzt keine eindeutige Bildquelle.');
        }

        if (self::hasCanonicalImage($html)) {
            $legacyDirectLayer = false;
            $legacyPercentHeight = false;
            try {
                self::assertCanonicalImage($html);
            } catch (RuntimeException) {
                try {
                    self::assertCanonicalImage($html, allowLegacyPercentHeight: true);
                    $legacyPercentHeight = true;
                } catch (RuntimeException) {
                    self::assertCanonicalImage(
                        $html,
                        allowLegacyDirectLayer: true,
                        allowLegacyPercentHeight: true,
                    );
                    $legacyDirectLayer = true;
                    $legacyPercentHeight = true;
                }
            }
            if ($legacyDirectLayer) {
                $html = self::hardenLegacyDirectLayer($html);
                $html = self::wrapLegacyDirectCarrierInStage($html);
            }
            if ($legacyPercentHeight) {
                $html = self::withoutLegacyPercentHeight($html);
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

        $html = self::withoutMainLayer($html);
        $html = self::compactDefaultContentPadding($html);
        $marker = '<!-- RT_SIGNATURE_MAIN_END -->';
        if (substr_count($html, $marker) !== 1) {
            throw new RuntimeException(
                'Die veroeffentlichte Signatur besitzt keinen eindeutigen Zug-Layer-Anker.'
            );
        }

        $source = htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $layer = self::canonicalLayerMarkup($source);
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
        $stage = self::canonicalStageMarkup($content, $layer);

        return substr_replace(
            $html,
            $stage,
            $contentOffset,
            $carrierCloseOffset - $contentOffset,
        );
    }

    /**
     * Outlook-Desktop nutzt die Word-Engine und kann absolute Ebenen in
     * Tabellenzellen verwerfen. Wie bei den animierten Marken bekommt nur
     * dieser Client deshalb eine normale, auf 720 Pixel begrenzte Bildzeile.
     * Moderne Clients sehen den bedingten Kommentar nicht und behalten den
     * hoehenneutralen Layer hinter den Kontaktdaten.
     */
    public static function withMsoFallback(string $html, string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            throw new RuntimeException('Das Outlook-Standbild des Zuges besitzt keine Bildquelle.');
        }

        $marker = '<!-- RT_SIGNATURE_MAIN_END -->';
        if (substr_count($html, $marker) !== 1
            || preg_match(
                '/<!--\s*\[if\s+mso\]\s*>.*?\brt-sign-train-mso\b.*?<!\s*\[endif\]\s*-->/is',
                $html,
            ) === 1) {
            throw new RuntimeException('Der Outlook-Zugfallback kann nicht eindeutig verankert werden.');
        }

        $escapedSource = htmlspecialchars(
            $source,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        $fallback = '<!--[if mso]><tr><td class="rt-sign-train-mso" width="100%" style="padding:0;font-size:0;line-height:0;mso-line-height-rule:exactly;">'
            .'<img src="'.$escapedSource.'" width="720" alt="" '
            .'style="display:block;width:100%;max-width:720px;height:auto;margin:0;border:0;outline:none;text-decoration:none;">'
            .'</td></tr><![endif]-->';

        return str_replace($marker, $fallback."\n".$marker, $html);
    }

    /**
     * Legt die transparente Endlos-Rauchschleife direkt in denselben
     * absoluten Layer wie das Haupt-GIF. Dadurch erbt sie alle erlaubten
     * Desktop-/Mobil-Ausschnitte pixelgleich, erzeugt keine eigene Hoehe und
     * kann den Zug beim Antworten oder Weiterleiten nicht duplizieren.
     * Ohne CSS-Keyframe-Unterstuetzung bleibt sie fail-closed unsichtbar.
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

        if (preg_match('/^(?:https:\/\/[^\s"\'()]+|cid:[A-Za-z0-9._@+-]+|data:image\/gif;base64,[A-Za-z0-9+\/=]+)$/i', $source) !== 1) {
            throw new RuntimeException('Die Idle-Rauchquelle ist fuer eine E-Mail-CSS-Ebene nicht zulaessig.');
        }

        $cssSource = str_replace(['\\', "'"], ['\\\\', "\\'"], $source);
        $escapedCssSource = htmlspecialchars(
            $cssSource,
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        $overlay = '<span class="rt-train-idle-overlay" data-rt-train-idle-overlay '
            .'style="position:absolute;left:0;right:auto;top:0;bottom:0;display:block;width:100%;margin:0;overflow:hidden;'
            .'background-image:url(\''.$escapedCssSource.'\');background-repeat:no-repeat;background-position:left bottom;background-size:100% auto;'
            .'opacity:0;visibility:hidden;animation:rt-train-idle-reveal 1ms step-start 13s forwards;font-size:0;line-height:0;mso-hide:all;"></span>';

        return substr_replace($html, $overlay, $images[0]['endOffset'] + 1, 0);
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
        return str_contains($html, 'data-rt-train') || str_contains($html, 'rt-sign-train');
    }

    /**
     * Neuer Seeder-/Editorvertrag: TRAIN_SRC lebt ausschliesslich im src
     * eines einzigen normalen Bildes in einem eindeutigen absoluten Layer
     * innerhalb des Carriers.
     */
    public static function assertCanonicalImage(
        string $html,
        bool $allowLegacyDirectLayer = false,
        bool $allowLegacyPercentHeight = false,
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
        }

        $image = $images[0] ?? null;
        $layer = $layers[0] ?? null;
        $stage = $stages[0] ?? null;
        $carrier = $carriers[0] ?? null;
        $legacyDirectLayer = $allowLegacyDirectLayer && count($stages) === 0;
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
            || $image->getAttribute('src') !== '{{TRAIN_SRC}}') {
            throw new RuntimeException('Das Zugmotiv muss genau einmal im kanonischen Bild-Layer vorliegen.');
        }

        $validStructure = $legacyDirectLayer
            ? $layer->parentNode?->isSameNode($carrier)
                && self::lastElementChild($carrier)?->isSameNode($layer)
            : $stage instanceof DOMElement
                && $layer->parentNode?->isSameNode($stage)
                && $stage->parentNode?->isSameNode($carrier)
                && self::lastElementChild($stage)?->isSameNode($layer)
                && self::lastElementChild($carrier)?->isSameNode($stage);
        if (! $image->parentNode?->isSameNode($layer) || ! $validStructure) {
            throw new RuntimeException('Der Zug-Layer muss in der letzten sicheren Buehne des Signatur-Carriers liegen.');
        }

        if (! $legacyDirectLayer && $stage instanceof DOMElement) {
            self::assertExactSimpleStyle($stage, [
                'position' => 'relative',
                'overflow' => 'hidden',
            ], 'Signatur-Buehne');
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
        $horizontal = match ($alignment) {
            'left' => ['left' => '0', 'right' => 'auto'],
            'center' => ['left' => $size['centerLeft'], 'right' => 'auto'],
            'right' => ['left' => 'auto', 'right' => '0'],
        };
        $layerStyle = [
            'position' => 'absolute',
            'left' => $horizontal['left'],
            'right' => $horizontal['right'],
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
            $layerStyle['mso-hide'] = 'all';
        }
        if ($allowLegacyPercentHeight) {
            try {
                self::assertExactSimpleStyle($layer, $layerStyle, 'Zug-Layer');
            } catch (RuntimeException) {
                $legacyLayerStyle = $layerStyle;
                $legacyLayerStyle['height'] = '100%';
                self::assertExactSimpleStyle($layer, $legacyLayerStyle, 'Zug-Layer');
            }
        } else {
            self::assertExactSimpleStyle($layer, $layerStyle, 'Zug-Layer');
        }
        $imageStyle = [
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
            $imageStyle['mso-hide'] = 'all';
        }
        self::assertExactSimpleStyle($image, $imageStyle, 'Zugbild');
        $widthAttribute = strtolower(trim($image->getAttribute('width')));
        $legacyPixelWidth = preg_replace('/px$/', '', $size['maxWidth']) ?? '';
        if ($widthAttribute !== '720'
            && ! ($legacyDirectLayer && in_array($widthAttribute, ['100%', $legacyPixelWidth], true))) {
            throw new RuntimeException('Das Zugbild muss als mail-sicherer 720-Pixel-Fallback begrenzt sein.');
        }
    }

    private static function canonicalLayerMarkup(string $source): string
    {
        return '<div class="rt-sign-train-layer" data-rt-layer-train data-rt-layer-align="left" data-rt-layer-size="100" data-rt-layer-mobile="train" '
            .'style="position:absolute;left:0;right:auto;top:0;bottom:0;width:100%;max-width:1815px;margin:0;overflow:hidden;z-index:0;font-size:0;line-height:0;text-align:left;mso-hide:all;">'
            .'<img class="rt-sign-train" data-rt-train src="'.$source.'" width="720" alt="" '
            .'style="position:absolute;left:0;right:auto;bottom:0;display:block;width:100%;max-width:1815px;height:auto;margin:0;border:0;outline:none;text-decoration:none;mso-hide:all;">'
            .'</div>';
    }

    private static function canonicalStageMarkup(string $content, string $layer): string
    {
        return '<div class="rt-sign-stage" style="position:relative;overflow:hidden;">'
            .$content.$layer
            .'</div>';
    }

    private static function hardenLegacyDirectLayer(string $html): string
    {
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
            throw new RuntimeException('Der bestehende Zug-Layer konnte nicht sicher gehaertet werden.');
        }

        $layerStyles = $layers[0]['attributes']['style'] ?? [];
        $imageStyles = $images[0]['attributes']['style'] ?? [];
        $imageWidths = $images[0]['attributes']['width'] ?? [];
        if (count($layerStyles) !== 1 || count($imageStyles) !== 1 || count($imageWidths) !== 1
            || $layerStyles[0]['valueOffset'] === null
            || $imageStyles[0]['valueOffset'] === null
            || $imageWidths[0]['valueOffset'] === null) {
            throw new RuntimeException('Der bestehende Zug-Layer besitzt keine eindeutigen Bildattribute.');
        }

        $hardenStyle = static fn (array $attribute): string => htmlspecialchars(
            rtrim(CssSemantic::decodeHtmlEntitiesOnce($attribute['raw']), ';').';mso-hide:all;',
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );
        $replacements = [
            [$layerStyles[0]['valueOffset'], $layerStyles[0]['valueLength'], $hardenStyle($layerStyles[0])],
            [$imageStyles[0]['valueOffset'], $imageStyles[0]['valueLength'], $hardenStyle($imageStyles[0])],
            [$imageWidths[0]['valueOffset'], $imageWidths[0]['valueLength'], '720'],
        ];
        usort($replacements, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
        foreach ($replacements as [$offset, $length, $replacement]) {
            $html = substr_replace($html, $replacement, $offset, $length);
        }

        return $html;
    }

    /**
     * Entfernt ausschliesslich die zuvor durch den alten kanonischen Vertrag
     * validierte Prozenthoehe. Absolute Elemente mit top und bottom brauchen
     * sie nicht; Outlook kann sie sonst auf die gesamte Mailflaeche dehnen.
     */
    private static function withoutLegacyPercentHeight(string $html): string
    {
        $layers = [];
        foreach (self::scanStartTags($html) as $tag) {
            if ($tag['name'] === 'div' && self::sourceTagHasClass($tag, 'rt-sign-train-layer')) {
                $layers[] = $tag;
            }
        }
        $styles = $layers[0]['attributes']['style'] ?? [];
        if (count($layers) !== 1 || count($styles) !== 1 || $styles[0]['valueOffset'] === null) {
            throw new RuntimeException('Die alte Zug-Layer-Hoehe konnte nicht eindeutig normalisiert werden.');
        }

        $segments = [];
        $removed = 0;
        foreach (explode(';', CssSemantic::decodeHtmlEntitiesOnce($styles[0]['raw'])) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $segment, 2));
            if (strtolower($property) === 'height') {
                if (strtolower($value) !== '100%') {
                    throw new RuntimeException('Die alte Zug-Layer-Hoehe ist nicht kanonisch.');
                }
                $removed++;

                continue;
            }
            $segments[] = $property.':'.$value;
        }
        if ($removed !== 1) {
            throw new RuntimeException('Die alte Zug-Layer-Hoehe ist nicht eindeutig.');
        }

        $replacement = htmlspecialchars(
            implode(';', $segments).';',
            ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
            'UTF-8',
        );

        return substr_replace(
            $html,
            $replacement,
            $styles[0]['valueOffset'],
            $styles[0]['valueLength'],
        );
    }

    /**
     * Hebt den zuvor streng als Schema-12-Topologie validierten direkten
     * Bild-Layer zur Laufzeit in denselben Block-Kontext wie Schema 13. So
     * greift der Fix auch vor dem autoritativen Seeder-Lauf; der gespeicherte
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
            '<div class="rt-sign-stage" style="position:relative;overflow:hidden;">'.$content.'</div>',
            $contentOffset,
            $carrierCloseOffset - $contentOffset,
        );
    }

    /** @param array<string, string> $expected */
    private static function assertExactSimpleStyle(DOMElement $element, array $expected, string $label): void
    {
        $actual = [];
        foreach (explode(';', $element->getAttribute('style')) as $segment) {
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

        if (count($actual) !== count($expected)) {
            throw new RuntimeException("Der {$label} muss seine mail-sichere absolute Position behalten.");
        }
        foreach ($expected as $property => $value) {
            if (($actual[$property] ?? null) !== $value) {
                throw new RuntimeException("Der {$label} muss seine mail-sichere absolute Position behalten.");
            }
        }
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

    /** @return list<string> */
    private static function elementClasses(DOMElement $element): array
    {
        return preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
