<?php

declare(strict_types=1);

namespace App\Support\Mail;

use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Opt-in V26 geometry. The real train IMG precedes the readable content.
 *
 * A negative pixel margin overlaps two matching minimum-height areas. This
 * is a client-dependent enhancement, not a guarantee that Outlook retains
 * margins. The content is never clipped when its intrinsic height grows.
 */
final class SignatureImgOverlap
{
    public const VERSION = 'v26';

    public const MAX_IMAGE_WIDTH = 1815;

    public const SOURCE_WIDTH = 2016;

    public const SOURCE_HEIGHT = 171;

    public const BREAKPOINTS = ['desktop' => null, 'tablet' => 860, 'mobile' => 480];

    public const SIZES = [100, 150, 200];

    private const PROPERTIES = ['height', 'size', 'offset'];

    private const COMPACT_HEIGHTS = ['desktop' => 154, 'tablet' => 216, 'mobile' => 212];

    /** @return array<string, array{height:int,size:int,offset:int}> */
    public static function defaults(): array
    {
        return [
            'desktop' => ['height' => 196, 'size' => 100, 'offset' => 0],
            'tablet' => ['height' => 304, 'size' => 150, 'offset' => 25],
            'mobile' => ['height' => 296, 'size' => 200, 'offset' => 55],
        ];
    }

    /** Values belong to the shared server profile, not a second JS geometry. */
    public static function editorSettings(): array
    {
        return [
            'version' => self::VERSION,
            'breakpoints' => self::BREAKPOINTS,
            'defaults' => self::defaults(),
            'compactHeights' => self::COMPACT_HEIGHTS,
            'sizes' => self::SIZES,
            'heightMin' => 80,
            'desktopHeightMin' => 154,
            'heightMax' => 600,
            'offsetMin' => 0,
            'offsetMax' => 100,
            'maxImageWidth' => self::MAX_IMAGE_WIDTH,
            'sourceWidth' => self::SOURCE_WIDTH,
            'sourceHeight' => self::SOURCE_HEIGHT,
            'layoutCss' => self::layoutCss('{scope}'),
            'styleTemplates' => [
                'stage' => 'display:block;width:100%;overflow:visible;',
                'layer' => str_replace('999', '{height}', self::layerStyle(999)),
                'frame' => str_replace('999', '{height}', self::frameStyle(999)),
                'slot' => str_replace('999', '{height}', self::slotStyle(999)),
                'image' => str_replace(['888', '-777%'], ['{size}', '{left}'], self::imageStyle(['size' => 888, 'offset' => 777])),
            ],
        ];
    }

    public static function applies(string $html): bool
    {
        return SignatureArtifactVersion::detect('signature', $html) === self::VERSION;
    }

    /** @return list<string> */
    public static function settingAttributes(): array
    {
        $attributes = [];
        foreach (array_keys(self::BREAKPOINTS) as $breakpoint) {
            foreach (self::PROPERTIES as $property) {
                $attributes[] = self::attribute($breakpoint, $property);
            }
        }

        return $attributes;
    }

    /** @return array<string, array{height:int,size:int,offset:int}> */
    public static function settings(string $html): array
    {
        $stage = self::elements($html)['stage'];
        $settings = [];
        foreach (array_keys(self::BREAKPOINTS) as $breakpoint) {
            foreach (self::PROPERTIES as $property) {
                $value = $stage->getAttribute(self::attribute($breakpoint, $property));
                if (preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $value) !== 1) {
                    throw new RuntimeException('Die V26-Geometrie benoetigt eindeutige ganze Zahlen.');
                }
                $settings[$breakpoint][$property] = (int) $value;
            }
        }

        return self::validatedSettings($settings);
    }

    /** The stored nine values remain stable across rendering and CSS parity. */
    public static function runtimeSettings(string $html): array
    {
        $settings = self::settings($html);

        return self::hasCompactDensity($html) ? self::compactSettings($settings) : $settings;
    }

    private static function compactSettings(array $settings): array
    {
        foreach (self::COMPACT_HEIGHTS as $breakpoint => $height) {
            $settings[$breakpoint]['height'] = min($settings[$breakpoint]['height'], $height);
        }

        return $settings;
    }

    public static function stageStart(?array $settings = null): string
    {
        $settings = self::validatedSettings($settings ?? self::defaults());
        $attributes = '';
        foreach ($settings as $breakpoint => $profile) {
            foreach ($profile as $property => $value) {
                $attributes .= ' '.self::attribute($breakpoint, $property).'="'.$value.'"';
            }
        }

        return '<div class="rt-sign-stage"'.$attributes.' style="display:block;width:100%;overflow:visible;">';
    }

    public static function layerMarkup(string $source = '{{TRAIN_SRC}}', ?array $settings = null): string
    {
        $settings = self::validatedSettings($settings ?? self::defaults());
        self::assertSource($source, allowToken: true);
        $profile = $settings['desktop'];
        $height = $profile['height'];

        return '<div class="rt-sign-train-layer" data-rt-layer-train style="'.self::layerStyle($height).'">'
            .'<table class="rt-sign-train-frame" role="presentation" width="100%" height="'.$height.'" border="0" cellspacing="0" cellpadding="0" style="'.self::frameStyle($height).'">'
            .'<tr><td class="rt-sign-train-slot" height="'.$height.'" valign="bottom" style="'.self::slotStyle($height).'">'
            .'<img class="rt-sign-train" data-rt-train src="'.self::escape($source).'" width="720" alt="" style="'.self::imageStyle($profile).'mso-hide:all;">'
            .'</td></tr></table></div>';
    }

    public static function contentFrameStart(?array $settings = null): string
    {
        $settings = self::validatedSettings($settings ?? self::defaults());
        $height = $settings['desktop']['height'];

        return '<table class="rt-sign-content-frame" role="presentation" width="100%" height="'.$height.'" border="0" cellspacing="0" cellpadding="0" style="'.self::frameStyle($height).'">';
    }

    public static function assertValid(string $html): void
    {
        if (substr_count($html, '{{TRAIN_SRC}}') !== 1 || str_contains($html, '{{TRAIN_IDLE_SRC}}')) {
            throw new RuntimeException('V26 benoetigt genau ein echtes Zugbild ohne Idle-Overlay.');
        }
        self::inspect($html, sourceDocument: true);
    }

    public static function assertRuntime(string $html, ?string $expectedSource = null, ?string $expectedMsoSource = null): void
    {
        $elements = self::inspect($html);
        $source = $elements['image']->getAttribute('src');
        self::assertSource($source);
        if ($expectedSource !== null && ! hash_equals($expectedSource, $source)) {
            throw new RuntimeException('Das V26-Zugbild verwendet nicht das erwartete Medium.');
        }
        $fallbacks = self::msoSources($html);
        if (count($fallbacks) > 1 || ($expectedMsoSource !== null && $fallbacks !== [$expectedMsoSource])) {
            throw new RuntimeException('Der V26-Outlook-Fallback ist nicht eindeutig.');
        }
    }

    public static function render(string $html, string $source): string
    {
        self::assertValid($html);
        self::assertSource($source);
        $html = str_replace('{{TRAIN_SRC}}', self::escape($source), $html);
        self::assertRuntime($html, $source);

        return $html;
    }

    /** Apply only to the rendered copy, after empty contact rows were removed. */
    public static function applyRuntimeDensity(string $html, bool $compact): string
    {
        self::assertRuntime($html);
        $html = preg_replace_callback('/<tr\b[^>]*\bdata-rt-artifact-version="v26"[^>]*>/i', static function (array $match) use ($compact): string {
            $tag = preg_replace('/\s+data-rt-signature-density\s*=\s*(["\']).*?\1/i', '', $match[0]) ?? $match[0];
            if (preg_match('/\bclass="([^"]*)"/', $tag, $class)) {
                $classes = preg_split('/\s+/', trim($class[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $classes = array_values(array_diff($classes, ['rt-sign-density-compact']));
                if ($compact) {
                    $classes[] = 'rt-sign-density-compact';
                }
                $tag = $classes === []
                    ? preg_replace('/\s+class="[^"]*"/', '', $tag) ?? $tag
                    : self::setAttribute($tag, 'class', implode(' ', $classes));
            } elseif ($compact) {
                $tag = self::setAttribute($tag, 'class', 'rt-sign-density-compact');
            }

            return $compact ? substr($tag, 0, -1).' data-rt-signature-density="compact">' : $tag;
        }, $html, 1, $count);
        if (! is_string($html) || $count !== 1) {
            throw new RuntimeException('Die V26-Laufzeitdichte besitzt keine eindeutige Signaturzeile.');
        }
        $height = self::runtimeSettings($html)['desktop']['height'];
        $styles = [
            'rt-sign-train-layer' => self::layerStyle($height),
            'rt-sign-train-frame' => self::frameStyle($height),
            'rt-sign-train-slot' => self::slotStyle($height),
            'rt-sign-content-frame' => self::frameStyle($height),
        ];
        $html = preg_replace_callback('/<(?:div|table|td)\b[^>]*>/i', static function (array $match) use ($height, $styles): string {
            if (preg_match('/\bclass="([^"]+)"/', $match[0], $class) !== 1 || ! isset($styles[$class[1]])) {
                return $match[0];
            }
            $tag = self::setAttribute($match[0], 'style', $styles[$class[1]]);

            return $class[1] === 'rt-sign-train-layer' ? $tag : self::setAttribute($tag, 'height', (string) $height);
        }, $html) ?? $html;
        self::assertRuntime($html);

        return $html;
    }

    public static function withMsoFallback(string $html, string $source): string
    {
        self::assertRuntime($html);
        self::assertSource($source);
        if (self::msoSources($html) !== []) {
            throw new RuntimeException('Der V26-Outlook-Fallback ist bereits vorhanden.');
        }
        $profile = self::settings($html)['desktop'];
        $fallback = '<!--[if mso]><img class="rt-sign-train-mso" data-rt-train-mso="1" src="'.self::escape($source).'" width="720" height="61" alt="" style="'.self::imageStyle($profile).'"><![endif]-->';
        $html = preg_replace_callback('/(<td\b[^>]*class="rt-sign-train-slot"[^>]*>)/i', static fn (array $match): string => $match[1].$fallback, $html, 1, $count);
        if (! is_string($html) || $count !== 1) {
            throw new RuntimeException('Der V26-Outlook-Fallback besitzt keinen eindeutigen Slot.');
        }
        self::assertRuntime($html, expectedMsoSource: $source);

        return $html;
    }

    /** @return list<string> */
    public static function msoSources(string $html): array
    {
        preg_match_all('/<!--\s*\[if\s+mso\]\s*>(.*?)<!\s*\[endif\]\s*-->/is', $html, $comments);
        $sources = [];
        foreach ($comments[1] as $comment) {
            if (stripos($comment, 'rt-sign-train-mso') === false) {
                continue;
            }
            if (preg_match('/^\s*<img\s+class="rt-sign-train-mso"\s+data-rt-train-mso="1"\s+src="([^"]+)"\s+width="720"\s+height="61"\s+alt=""\s+style="([^"]*)"\s*>\s*$/D', $comment, $match) !== 1) {
                throw new RuntimeException('Der V26-Outlook-Fallback besitzt fremdes Markup.');
            }
            $source = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertSource($source);
            if (self::styleDeclarations(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                !== self::styleDeclarations(self::imageStyle(self::settings($html)['desktop']))) {
                throw new RuntimeException('Der V26-Outlook-Fallback besitzt abweichende Bildgeometrie.');
            }
            $sources[] = $source;
        }

        return $sources;
    }

    /**
     * Prefix may be a server-created class selector, e.g. .rts-<hash>. It must
     * not be supplied by imported free CSS. No data selector is then needed
     * by the Outlook document, where the platform can discard data attrs.
     */
    public static function css(string $html, string $prefix = 'tr[data-rt-artifact-version="v26"]'): string
    {
        if ($prefix !== 'tr[data-rt-artifact-version="v26"]'
            && preg_match('/^\.[a-zA-Z_][a-zA-Z0-9_-]{0,100}$/D', $prefix) !== 1) {
            throw new RuntimeException('Der V26-CSS-Bereich ist nicht sicher gebunden.');
        }
        $settings = self::settings($html);
        $css = self::layoutCss($prefix);
        $compactPrefix = $prefix === 'tr[data-rt-artifact-version="v26"]'
            ? $prefix.'.rt-sign-density-compact'
            : $prefix.' .rt-sign-density-compact';

        return $css.self::profileCss($settings, $prefix).self::profileCss(self::compactSettings($settings), $compactPrefix, compact: true);
    }

    /** The same scoped contact layout is exposed to the editor as a template. */
    private static function layoutCss(string $prefix): string
    {
        return $prefix.' .rt-sign-stage{display:block!important;width:100%!important;overflow:visible!important;}'
            .$prefix.' .rt-sign-content-frame{border-collapse:collapse!important;}'
            .$prefix.' .rt-sign-content{padding:14px 40px 15px!important;}'
            .$prefix.' .rt-sign-heading-person,'.$prefix.' .rt-sign-heading-logo{vertical-align:top!important;}'
            .$prefix.' .rt-person-kopf{margin-top:0!important;padding-top:0!important;}'
            .$prefix.' .rt-sign-logo{text-align:right!important;}'
            .$prefix.' img.rt-logo{width:200px!important;height:34px!important;margin-left:auto!important;margin-right:0!important;}'
            .'@media only screen and (max-width:860px){'
            .$prefix.' .rt-sign-content{padding:18px 24px 15px!important;}'
            .$prefix.' .rt-sign-layout,'.$prefix.' .rt-sign-layout>tbody,'.$prefix.' .rt-sign-top-row,'.$prefix.' .rt-sign-company-row{display:block!important;width:100%!important;}'
            .$prefix.' .rt-sign-layout>tbody>tr:first-child,'.$prefix.' .rt-sign-heading-table>tbody>tr{display:table!important;width:100%!important;}'
            .$prefix.' .rt-sign-heading-person{display:table-row-group!important;width:100%!important;padding:0!important;text-align:left!important;}'
            .$prefix.' .rt-sign-heading-logo{display:table-header-group!important;width:100%!important;padding:0!important;text-align:left!important;}'
            .$prefix.' .rt-sign-heading-person .rt-person-kopf{padding-top:14px!important;}'
            .$prefix.' .rt-sign-logo{text-align:left!important;}'
            .$prefix.' img.rt-logo{width:150px!important;height:25.5px!important;margin-left:0!important;margin-right:auto!important;}'
            .$prefix.' .rt-sign-identity,'.$prefix.' .rt-sign-company{display:block!important;box-sizing:border-box!important;width:100%!important;border-left:0!important;text-align:left!important;}'
            .$prefix.' .rt-sign-identity{padding:12px 0 0!important;}'
            .$prefix.' .rt-sign-company{padding:10px 0 0!important;}'
            .$prefix.' .rt-company-contact{float:none!important;display:table!important;width:100%!important;margin-left:0!important;margin-right:auto!important;}'
            .$prefix.' .rt-company-contact td.rt-company-contact-text{text-align:left!important;}'
            .'}@media only screen and (max-width:480px){'
            .$prefix.' .rt-sign-content{padding:18px 20px 15px!important;}'
            .$prefix.' .rt-sign-logo{padding:0 0 12px!important;}'
            .$prefix.' img.rt-logo{width:138px!important;height:23.46px!important;}'
            .'}';
    }

    private static function profileCss(array $settings, string $prefix, bool $compact = false): string
    {
        $css = '';
        foreach (self::BREAKPOINTS as $breakpoint => $maxWidth) {
            $profile = $settings[$breakpoint];
            if ($compact) {
                $height = $profile['height'];
                $rule = $prefix.' .rt-sign-train-layer{height:'.$height.'px!important;max-height:'.$height.'px!important;margin-bottom:-'.$height.'px!important;}'
                    .$prefix.' .rt-sign-train-frame,'.$prefix.' .rt-sign-content-frame,'.$prefix.' .rt-sign-train-slot{height:'.$height.'px!important;}';
                $css .= $maxWidth === null ? $rule : '@media only screen and (max-width:'.$maxWidth.'px){'.$rule.'}';

                continue;
            }
            $rule = $prefix.' .rt-sign-train-layer{'.self::important(self::layerStyle($profile['height'])).'}'
                .$prefix.' .rt-sign-train-frame,'.$prefix.' .rt-sign-content-frame{'.self::important(self::frameStyle($profile['height'])).'}'
                .$prefix.' .rt-sign-train-slot{'.self::important(self::slotStyle($profile['height'])).'}'
                .$prefix.' .rt-sign-train,'.$prefix.' .rt-sign-train-mso{'.self::important(self::imageStyle($profile)).'}';
            $css .= $maxWidth === null ? $rule : '@media only screen and (max-width:'.$maxWidth.'px){'.$rule.'}';
        }

        return $css;
    }

    /** @return array<string, array{height:int,size:int,offset:int}> */
    private static function validatedSettings(array $settings): array
    {
        if (array_keys($settings) !== array_keys(self::BREAKPOINTS)) {
            throw new RuntimeException('Die V26-Geometrie besitzt unbekannte oder fehlende Breakpoints.');
        }
        foreach ($settings as $breakpoint => $profile) {
            if (! is_array($profile) || array_keys($profile) !== self::PROPERTIES
                || ! is_int($profile['height']) || $profile['height'] < 80 || $profile['height'] > 600
                || ! in_array($profile['size'], self::SIZES, true)
                || ! is_int($profile['offset']) || $profile['offset'] < 0 || $profile['offset'] > 100) {
                throw new RuntimeException('Die V26-Geometrie liegt ausserhalb der erlaubten Grenzen.');
            }
            $availableWidth = self::BREAKPOINTS[$breakpoint] === null
                ? self::MAX_IMAGE_WIDTH
                : min(self::MAX_IMAGE_WIDTH, self::BREAKPOINTS[$breakpoint] * $profile['size'] / 100);
            $imageHeight = (int) ceil($availableWidth * self::SOURCE_HEIGHT / self::SOURCE_WIDTH);
            if ($profile['height'] < $imageHeight) {
                throw new RuntimeException('Die V26-'.$breakpoint.'-Hoehe wuerde den Fahrrauch abschneiden.');
            }
        }

        return $settings;
    }

    private static function attribute(string $breakpoint, string $property): string
    {
        return 'data-rt-v26-'.$property.'-'.$breakpoint;
    }

    private static function layerStyle(int $height): string
    {
        return 'display:block;width:100%;height:'.$height.'px;max-height:'.$height.'px;margin:0;margin-bottom:-'.$height.'px;overflow:hidden;font-size:0;line-height:0;text-align:left;';
    }

    private static function frameStyle(int $height): string
    {
        return 'width:100%;height:'.$height.'px;border-collapse:collapse;';
    }

    private static function slotStyle(int $height): string
    {
        return 'height:'.$height.'px;padding:0;text-align:left;vertical-align:bottom;font-size:0;line-height:0;';
    }

    private static function imageStyle(array $profile): string
    {
        return 'display:block;width:'.$profile['size'].'%;max-width:'.self::MAX_IMAGE_WIDTH.'px;height:auto;margin:0;margin-left:'.($profile['offset'] === 0 ? '0' : '-'.$profile['offset'].'%').';border:0;outline:none;text-decoration:none;vertical-align:bottom;';
    }

    private static function important(string $style): string
    {
        return str_replace(';', '!important;', $style);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function hasCompactDensity(string $html): bool
    {
        return preg_match('/<tr\b(?=[^>]*\bdata-rt-artifact-version="v26")(?=[^>]*\bdata-rt-signature-density="compact")[^>]*>/i', $html) === 1;
    }

    private static function setAttribute(string $tag, string $name, string $value): string
    {
        $attribute = $name.'="'.self::escape($value).'"';
        $pattern = '/\b'.preg_quote($name, '/').'\s*=\s*(["\']).*?\1/i';
        if (preg_match($pattern, $tag) === 1) {
            return preg_replace_callback($pattern, static fn (): string => $attribute, $tag, 1) ?? $tag;
        }

        return substr($tag, 0, -1).' '.$attribute.'>';
    }

    private static function assertSource(string $source, bool $allowToken = false): void
    {
        if ($allowToken && $source === '{{TRAIN_SRC}}') {
            return;
        }
        if ($source === '' || str_contains($source, '{{') || preg_match('/[\s\x00-\x1f\x7f\'"<>\\\\]/', $source) === 1) {
            throw new RuntimeException('Das V26-Zugbild besitzt keine sichere Bildquelle.');
        }
        (new EmailHtmlSanitizer)->assertClean('<img src="'.self::escape($source).'" alt="">');
    }

    /** @return array<string, DOMElement> */
    private static function elements(string $html): array
    {
        if (! self::applies($html)) {
            throw new RuntimeException('Der IMG-Ueberlappungsvertrag ist ausschliesslich fuer V26 bestimmt.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $source = preg_match('/<(?:html|body)\b/i', $html) === 1 ? $html : '<table><tbody>'.$html.'</tbody></table>';
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$source, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('Die V26-Struktur konnte nicht gelesen werden.');
        }
        $groups = ['stage' => [], 'layer' => [], 'frame' => [], 'slot' => [], 'image' => [], 'content' => []];
        $classes = ['stage' => 'rt-sign-stage', 'layer' => 'rt-sign-train-layer', 'frame' => 'rt-sign-train-frame', 'slot' => 'rt-sign-train-slot', 'image' => 'rt-sign-train', 'content' => 'rt-sign-content-frame'];
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            $elementClasses = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
            foreach ($classes as $group => $class) {
                if (in_array($class, $elementClasses, true)) {
                    $groups[$group][] = $element;
                }
            }
        }
        $result = [];
        foreach ($groups as $key => $group) {
            if (count($group) !== 1) {
                throw new RuntimeException('Die V26-Struktur besitzt keinen eindeutigen '.$key.'-Knoten.');
            }
            $result[$key] = $group[0];
        }

        return $result;
    }

    /** @return array<string, DOMElement> */
    private static function inspect(string $html, bool $sourceDocument = false): array
    {
        $elements = self::elements($html);
        $settings = self::settings($html);
        $profile = $settings['desktop'];
        $stage = $elements['stage'];
        $stageChildren = self::children($stage);
        $layerChildren = self::children($elements['layer']);
        $frameChildren = self::children($elements['frame']);
        $frameRows = count($frameChildren) === 1 && $frameChildren[0]->tagName === 'tbody'
            ? self::children($frameChildren[0]) : $frameChildren;
        $frameCells = count($frameRows) === 1 ? self::children($frameRows[0]) : [];
        $slotChildren = self::children($elements['slot']);
        if ($stage->tagName !== 'div' || count($stageChildren) !== 2
            || ! $stageChildren[0]->isSameNode($elements['layer'])
            || ! $stageChildren[1]->isSameNode($elements['content'])
            || $elements['layer']->tagName !== 'div'
            || count($layerChildren) !== 1 || ! $layerChildren[0]->isSameNode($elements['frame'])
            || $elements['frame']->tagName !== 'table'
            || ! $elements['frame']->parentNode?->isSameNode($elements['layer'])
            || count($frameRows) !== 1 || $frameRows[0]->tagName !== 'tr'
            || count($frameCells) !== 1 || ! $frameCells[0]->isSameNode($elements['slot'])
            || $elements['slot']->tagName !== 'td'
            || count($slotChildren) !== 1 || ! $slotChildren[0]->isSameNode($elements['image'])
            || $elements['image']->tagName !== 'img'
            || ! $elements['image']->parentNode?->isSameNode($elements['slot'])
            || $elements['content']->tagName !== 'table') {
            throw new RuntimeException('In V26 muss das echte Zugbild vor dem Inhaltsrahmen stehen.');
        }
        foreach ($stage->attributes as $attribute) {
            if (str_starts_with($attribute->name, 'data-rt-v26-') && ! in_array($attribute->name, self::settingAttributes(), true)) {
                throw new RuntimeException('Die V26-Geometrie besitzt ein unbekanntes Attribut.');
            }
        }
        // All geometry values are shared with markup and CSS generation. This
        // does not replace the general document sanitizer/token contract.
        if ($sourceDocument) {
            if (preg_match('/\bdata-rt-signature-density\s*=/i', $html) === 1 || str_contains($html, 'rt-sign-density-compact')) {
                throw new RuntimeException('Die V26-Dichte wird ausschliesslich im aktuellen Versandkontext bestimmt.');
            }
            if (self::msoSources($html) !== []) {
                throw new RuntimeException('Der V26-Outlook-Fallback wird ausschliesslich serverseitig erzeugt.');
            }
            self::assertStyle($stage, 'display:block;width:100%;overflow:visible;');
            self::assertStyle($elements['layer'], self::layerStyle($profile['height']));
            self::assertStyle($elements['frame'], self::frameStyle($profile['height']));
            self::assertStyle($elements['slot'], self::slotStyle($profile['height']));
            self::assertStyle($elements['content'], self::frameStyle($profile['height']));
            self::assertStyle($elements['image'], self::imageStyle($profile).'mso-hide:all;');
            if ($elements['image']->getAttribute('src') !== '{{TRAIN_SRC}}'
                || $elements['image']->hasAttribute('height')
                || $elements['image']->getAttribute('width') !== '720') {
                throw new RuntimeException('Das V26-Zugbild muss proportional und an TRAIN_SRC gebunden bleiben.');
            }
            foreach (['frame', 'slot', 'content'] as $key) {
                if ($elements[$key]->getAttribute('height') !== (string) $profile['height']) {
                    throw new RuntimeException('Die V26-Pixelhoehen muessen dem Desktopprofil entsprechen.');
                }
            }
        }
        $signature = $stage->parentNode;
        if (! $signature instanceof DOMElement) {
            throw new RuntimeException('Der V26-Inhaltsbereich fehlt.');
        }
        foreach ([$signature, ...iterator_to_array($signature->getElementsByTagName('*'))] as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }
            if ($element->hasAttribute('background')
                || preg_match('/(?:^|;)\s*background(?:-image)?\s*:[^;]*(?:url|image-set|gradient)\s*\(/i', $element->getAttribute('style')) === 1
                || ($element->tagName !== 'img' && str_contains($element->getAttribute('style'), '{{TRAIN_SRC}}'))) {
                throw new RuntimeException('V26 bindet Bilder ausschliesslich als echte IMG-Elemente ein.');
            }
        }

        return $elements;
    }

    /** @return list<DOMElement> */
    private static function children(DOMElement $element): array
    {
        return array_values(array_filter(iterator_to_array($element->childNodes), static fn ($child): bool => $child instanceof DOMElement));
    }

    private static function assertStyle(DOMElement $element, string $expected): void
    {
        if (self::styleDeclarations($element->getAttribute('style')) !== self::styleDeclarations($expected)) {
            throw new RuntimeException('Die V26-Geometrie besitzt vom Profil abweichende '.$element->getAttribute('class').'-Stile.');
        }
    }

    /** Geometry contains no CSS functions; reject ambiguous duplicate keys. */
    private static function styleDeclarations(string $style): array
    {
        if (str_contains($style, '/*') || str_contains($style, '*/') || str_contains($style, '\\')) {
            throw new RuntimeException('Die V26-Geometrie besitzt mehrdeutige CSS-Deklarationen.');
        }
        $styles = [];
        foreach (explode(';', $style) as $declaration) {
            if (trim($declaration) === '') {
                continue;
            }
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                throw new RuntimeException('Die V26-Geometrie besitzt unvollstaendige CSS-Deklarationen.');
            }
            $property = strtolower(trim($parts[0]));
            if (isset($styles[$property])) {
                throw new RuntimeException('Die V26-Geometrie besitzt doppelte CSS-Deklarationen.');
            }
            $styles[$property] = strtolower((string) preg_replace('/\s+/', '', preg_replace('/\s*!important\s*$/i', '', trim($parts[1])) ?? ''));
        }
        ksort($styles);

        return $styles;
    }
}
