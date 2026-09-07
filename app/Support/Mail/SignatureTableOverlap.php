<?php

declare(strict_types=1);

namespace App\Support\Mail;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/** Opt-in V27: a bottom-aligned IMG and readable contacts share one table row. */
final class SignatureTableOverlap
{
    public const VERSION = 'v27';

    public static function applies(string $html): bool
    {
        return SignatureArtifactVersion::detect('signature', $html) === self::VERSION;
    }

    /** The cropped canvas preserves the V19 visible geometry and full smoke height. */
    public static function profiles(): array
    {
        return [
            'desktop' => ['max' => null, 'anchor' => '6031.746032', 'image' => '100'],
            'tablet' => ['max' => 860, 'anchor' => '6547.619048', 'image' => '138.181818'],
            'mobile' => ['max' => 480, 'anchor' => '6563.492063', 'image' => '183.796856'],
        ];
    }

    public static function frame(string $content): string
    {
        return '<table class="rt-sign-content-frame" role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;table-layout:fixed;border-collapse:collapse;">'
            .'<tr><td class="rt-v27-image-cell" width="1%" valign="bottom" style="width:1%;padding:0;font-size:0;line-height:0;vertical-align:bottom;">'
            .'<table class="rt-v27-anchor" role="presentation" width="6031.746032%" border="0" cellspacing="0" cellpadding="0" style="width:6031.746032%;table-layout:fixed;border-collapse:collapse;">'
            .'<tr><td class="rt-v27-image-slot" dir="rtl" align="right" valign="bottom" style="direction:rtl;padding:0;text-align:right;vertical-align:bottom;font-size:0;line-height:0;">'
            .'<img class="rt-sign-train" data-rt-train src="{{TRAIN_SRC}}" width="1216" alt="" style="display:inline-block;width:100%;max-width:100%;height:auto;border:0;vertical-align:bottom;">'
            .'</td></tr></table></td>'.$content.'</tr></table>';
    }

    /** Preserve the existing contact contract; replace only the explicitly requested carrier. */
    public static function fromV26(string $html): string
    {
        SignatureDocumentContract::assertValid($html);
        if (! SignatureImgOverlap::applies($html)) {
            throw new RuntimeException('V27 benoetigt eine explizit gewaehlte V26-Quelle.');
        }
        [$dom, $xpath] = self::document($html);
        $stage = $xpath->query('//div[@class="rt-sign-stage"]')->item(0);
        $content = $xpath->query('//td[contains(concat(" ",normalize-space(@class)," ")," rt-sign-content ")]')->item(0);
        if (! $stage instanceof DOMElement || ! $content instanceof DOMElement) {
            throw new RuntimeException('Die vollstaendige Kontaktquelle fehlt.');
        }
        $content->removeAttribute('height');
        $content->setAttribute('valign', 'top');
        $content->setAttribute('style', preg_replace('/(?:^|;)\s*(?:height|min-height|max-height)\s*:[^;]*/i', '', $content->getAttribute('style')).';vertical-align:top;');
        $stageHtml = $dom->saveHTML($stage);
        $replacement = '<div class="rt-sign-stage" style="display:block;width:100%;overflow:visible;">'.self::frame($dom->saveHTML($content)).'</div>';
        $source = '';
        foreach ($dom->getElementById('rt-v27-root')->firstElementChild->childNodes as $node) {
            $source .= $dom->saveHTML($node);
        }
        $result = str_replace($stageHtml, $replacement, $source);
        $result = str_replace('data-rt-artifact-version="v26"', 'data-rt-artifact-version="v27"', $result);
        $result = trim(str_ireplace(['%7B', '%7D'], ['{', '}'], $result));
        self::assertValid($result);

        return $result;
    }

    public static function assertValid(string $html): void
    {
        self::assertRuntime($html, '{{TRAIN_SRC}}');
        if (substr_count($html, '{{TRAIN_SRC}}') !== 1 || str_contains($html, '{{TRAIN_IDLE_SRC}}')) {
            throw new RuntimeException('V27 benoetigt genau ein gebundenes IMG ohne Idle-Kopie.');
        }
    }

    public static function assertRuntime(string $html, ?string $source = null): void
    {
        if (! self::applies($html)) {
            throw new RuntimeException('Der Tabellen-Ueberlappungsvertrag gilt nur fuer V27.');
        }
        [, $xpath] = self::document($html);
        $topRows = $xpath->query('//*[@id="rt-v27-root"]/tbody/tr');
        if ($topRows->length !== 2 || $topRows->item(0)->getAttribute('data-rt-artifact-version') !== self::VERSION) {
            throw new RuntimeException('V27 benoetigt genau zwei oberste Tabellenzeilen.');
        }
        $nodes = [];
        foreach (['rt-sign-cell', 'rt-sign-stage', 'rt-sign-content-frame', 'rt-v27-image-cell', 'rt-v27-anchor', 'rt-v27-image-slot', 'rt-sign-train', 'rt-sign-content'] as $class) {
            $matches = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]');
            if ($matches->length !== 1) {
                throw new RuntimeException('V27 besitzt keinen eindeutigen '.$class.'-Knoten.');
            }
            $nodes[$class] = $matches->item(0);
        }
        $frame = $nodes['rt-sign-content-frame'];
        $content = $nodes['rt-sign-content'];
        $imageCell = $nodes['rt-v27-image-cell'];
        $image = $nodes['rt-sign-train'];
        if (! $nodes['rt-sign-cell']->parentNode->isSameNode($topRows->item(0))
            || $frame->tagName !== 'table' || $content->tagName !== 'td' || $image->tagName !== 'img'
            || ! $imageCell->parentNode->isSameNode($content->parentNode)
            || ! $xpath->query('.//tr', $frame)->item(0)?->isSameNode($content->parentNode)
            || ! $imageCell->nextElementSibling?->isSameNode($content)
            || $imageCell->parentNode->childElementCount !== 2
            || ! $frame->parentNode->isSameNode($nodes['rt-sign-stage'])
            || ! $nodes['rt-sign-stage']->parentNode->isSameNode($nodes['rt-sign-cell'])
            || ! $nodes['rt-v27-anchor']->parentNode->isSameNode($imageCell)
            || $nodes['rt-v27-anchor']->tagName !== 'table'
            || $xpath->query('.//td', $nodes['rt-v27-anchor'])->length !== 1
            || ! $xpath->query('.//td', $nodes['rt-v27-anchor'])->item(0)?->isSameNode($nodes['rt-v27-image-slot'])
            || ! $image->parentNode->isSameNode($nodes['rt-v27-image-slot'])
            || $nodes['rt-v27-image-slot']->getAttribute('dir') !== 'rtl'
            || $imageCell->getAttribute('width') !== '1%'
            || ($source !== null && $image->getAttribute('src') !== $source)
            || $image->hasAttribute('height')) {
            throw new RuntimeException('V27 muss IMG und lesbare Kontakte in derselben Tabellenzeile halten.');
        }
        if ($source === '{{TRAIN_SRC}}') {
            foreach (['rt-sign-content-frame' => ['width' => '100%', 'table-layout' => 'fixed'], 'rt-v27-anchor' => ['width' => '6031.746032%', 'table-layout' => 'fixed'], 'rt-sign-train' => ['width' => '100%', 'height' => 'auto', 'display' => 'inline-block']] as $class => $properties) {
                $styles = [];
                foreach (explode(';', $nodes[$class]->getAttribute('style')) as $declaration) {
                    $parts = explode(':', $declaration, 2);
                    if (count($parts) === 2) {
                        $styles[strtolower(trim($parts[0]))] = strtolower(trim(str_replace('!important', '', $parts[1])));
                    }
                }
                foreach ($properties as $property => $value) {
                    if (($styles[$property] ?? null) !== $value) {
                        throw new RuntimeException('V27 besitzt eine veraenderte IMG-Tabellengeometrie: '.$class.'.'.$property);
                    }
                }
            }
        }
        foreach ($xpath->query('//*[@style or @background]') as $element) {
            $style = $element->getAttribute('style');
            if ($element->hasAttribute('background') || preg_match('/(?:url|gradient)\s*\(|(?:^|;)\s*(?:position|z-index|overflow|margin(?:-[a-z]+)?)\s*:[^;]*(?:absolute|hidden|-[0-9])/i', $style)) {
                throw new RuntimeException('V27 erlaubt keine Bildhintergruende, Clipping- oder Minusmargin-Ersatzebenen.');
            }
        }
    }

    public static function render(string $html, string $source): string
    {
        self::assertValid($html);
        $result = str_replace('{{TRAIN_SRC}}', htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
        self::assertRuntime($result, $source);

        return $result;
    }

    public static function css(): string
    {
        $scope = 'tr[data-rt-artifact-version="v27"]';
        $css = str_replace('{scope}', $scope, SignatureImgOverlap::editorSettings()['layoutCss']);
        $css .= $scope.' .rt-sign-content-frame{width:100%!important;table-layout:fixed!important;height:auto!important;}'
            .$scope.' .rt-v27-image-cell{width:1%!important;padding:0!important;font-size:0!important;line-height:0!important;vertical-align:bottom!important;}'
            .$scope.' .rt-v27-image-slot{direction:rtl!important;text-align:right!important;vertical-align:bottom!important;font-size:0!important;line-height:0!important;padding:0!important;}'
            .$scope.' .rt-sign-train{display:inline-block!important;height:auto!important;border:0!important;vertical-align:bottom!important;margin:0!important;}'
            .$scope.' .rt-sign-content{vertical-align:top!important;background-color:transparent!important;height:auto!important;}'
            .$scope.' .rt-contact-text,'.$scope.' .rt-company-contact-text{overflow-wrap:anywhere!important;word-break:normal!important;}';
        foreach (self::profiles() as $profile) {
            $rule = $scope.' .rt-v27-anchor{width:'.$profile['anchor'].'%!important;table-layout:fixed!important;border-collapse:collapse!important;}'
                .$scope.' .rt-sign-train{width:'.$profile['image'].'%!important;max-width:'.$profile['image'].'%!important;}';
            $css .= $profile['max'] === null ? $rule : '@media only screen and (max-width:'.$profile['max'].'px){'.$rule.'}';
        }

        return $css;
    }

    private static function document(string $html): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><table id="rt-v27-root"><tbody>'.$html.'</tbody></table>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return [$dom, new DOMXPath($dom)];
    }
}
