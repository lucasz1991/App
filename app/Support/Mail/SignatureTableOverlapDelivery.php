<?php

declare(strict_types=1);

namespace App\Support\Mail;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/** Render-only projection: stored V27/V28/V29 contracts remain untouched. */
final class SignatureTableOverlapDelivery
{
    public const MARKER = 'bounded-img-v1';

    public const IMAGE_WIDTH = 600;

    public const IMAGE_HEIGHT = 75;

    public static function asset(string $theme, bool $animated, string $version): string
    {
        if (! in_array($theme, ['light', 'dark'], true) || ! SignatureArtifactVersion::usesTableOverlapTrain($version)) {
            throw new RuntimeException('Unbekannte begrenzte Zugmedienvariante.');
        }

        return 'zug-dampf-v27-delivery-'.$theme.(SignatureArtifactVersion::usesMirroredTrain($version) ? '-mirrored' : '').'.'.($animated ? 'gif' : 'png');
    }

    public static function source(string $source): string
    {
        return preg_replace('~(?<=/)zug-dampf-v27-(light|dark)(-mirrored)?\.(gif|png)(?=\?|$)~', 'zug-dampf-v27-delivery-$1$2.$3', $source) ?? $source;
    }

    public static function applies(string $html): bool
    {
        return str_contains($html, 'data-rt-train-delivery="'.self::MARKER.'"')
            && SignatureTableOverlap::applies($html);
    }

    public static function usesLedger(string $html): bool
    {
        return preg_match('~\bclass\s*=\s*(["\'])[^"\']*\brt-sign-ledger\b[^"\']*\1~i', $html) === 1;
    }

    public static function project(string $html, string $stillSource): string
    {
        if (! SignatureTableOverlap::applies($html)) {
            return $html;
        }
        if (self::applies($html)) {
            self::assertRuntime($html);

            return $html;
        }
        SignatureTableOverlap::assertRuntime($html);
        $stillSource = self::source($stillSource);
        if (! self::allowedSource($stillSource)) {
            throw new RuntimeException('Der begrenzte Outlook-Zug besitzt kein gueltiges Standbild.');
        }
        [$dom, $xpath] = self::document($html);
        $frame = self::one($xpath, 'rt-sign-content-frame');
        $imageCell = self::one($xpath, 'rt-v27-image-cell');
        $image = self::one($xpath, 'rt-sign-train');
        $content = self::one($xpath, 'rt-sign-content');
        $source = self::source($image->getAttribute('src'));
        if (! self::allowedSource($source)) {
            throw new RuntimeException('Der begrenzte Signaturzug besitzt keine gueltige Bildquelle.');
        }
        // A 1%-wide cell containing a 6031%-wide nested table depends on the
        // client's percentage/table algorithm. Remove that dependency entirely.
        $frame->removeAttribute('dir');
        $frame->setAttribute('data-rt-train-delivery', self::MARKER);
        $frame->setAttribute('style', 'width:100%;table-layout:fixed;border-collapse:collapse;direction:ltr;');
        $content->setAttribute('width', '100%');
        $content->setAttribute('dir', 'ltr');
        $content->setAttribute('style', rtrim($content->getAttribute('style'), ';').';width:100%;direction:ltr;padding-bottom:15px;');
        $image->setAttribute('class', 'rt-delivery-train');
        $image->setAttribute('src', $source);
        $image->removeAttribute('data-rt-train');
        $image->setAttribute('width', '100%');
        $image->removeAttribute('height');
        $mirrored = SignatureArtifactVersion::usesMirroredTrain(SignatureArtifactVersion::detect('signature', $html));
        $image->setAttribute('style', self::imageStyle($mirrored));
        $image->setAttribute('alt', '');
        $row = $dom->createElement('tr');
        $row->setAttribute('class', 'rt-delivery-train-row');
        $cell = $dom->createElement('td');
        $cell->setAttribute('class', 'rt-delivery-train-cell');
        $cell->setAttribute('width', '100%');
        $cell->setAttribute('align', $mirrored ? 'right' : 'left');
        $cell->setAttribute('valign', 'bottom');
        $cell->setAttribute('style', 'width:100%;padding:0;font-size:0;line-height:0;vertical-align:bottom;');
        $cell->appendChild($dom->createComment('[if !mso]><!'));
        $cell->appendChild($image);
        $cell->appendChild($dom->createComment('<![endif]'));
        // The original GIF's first frame is transparent. Word versions that
        // cannot animate must receive the populated PNG, independent of CSS.
        $still = '<img class="rt-delivery-train-mso" src="'.htmlspecialchars($stillSource, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" width="'.self::IMAGE_WIDTH.'" height="'.self::IMAGE_HEIGHT.'" alt="" style="'.self::imageStyle($mirrored).'">';
        $cell->appendChild($dom->createComment('[if mso]>'.$still.'<![endif]'));
        $row->appendChild($cell);
        $imageCell->parentNode->removeChild($imageCell);
        $content->parentNode->parentNode->appendChild($row);
        if (self::usesLedger($html)) {
            self::stack($dom, $xpath, self::one($xpath, 'rt-sign-ledger'), ['rt-ledger-brand', 'rt-ledger-contacts']);
            $contacts = self::one($xpath, 'rt-ledger-contacts');
            $nested = $xpath->query('./table', $contacts);
            if ($nested->length !== 1) {
                throw new RuntimeException('Die begrenzte Signaturausgabe besitzt keine eindeutigen Kontaktgruppen.');
            }
            $nested->item(0)->setAttribute('class', trim($nested->item(0)->getAttribute('class').' rt-delivery-contacts'));
            self::stack($dom, $xpath, $nested->item(0), ['rt-ledger-direct', 'rt-ledger-company']);
            foreach ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-contact ")]') as $table) {
                $table->setAttribute('width', '100%');
                $table->setAttribute('style', 'width:100%;table-layout:auto;border-collapse:collapse;direction:ltr;');
            }
            foreach ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-contact-text ")]') as $text) {
                $text->removeAttribute('width');
            }
        }
        // Give the logo proportional HTML dimensions as a CSS-free fallback,
        // including its classic Outlook copy inside the conditional comment.
        $output = '';
        foreach ($dom->getElementById('rt-delivery-root')->firstElementChild->childNodes as $child) {
            $output .= $dom->saveHTML($child);
        }
        $output = preg_replace_callback('~<img\b(?=[^>]*\bclass="[^"]*\brt-logo\b[^"]*")(?=[^>]*\bwidth="(\d+)")[^>]*>~i', static function (array $match): string {
            if (preg_match('~\sheight=~i', $match[0])) {
                return $match[0];
            }

            return substr($match[0], 0, -1).' height="'.(int) round((int) $match[1] * 68 / 400).'">';
        }, $output) ?? $output;
        self::assertRuntime($output, $source, $stillSource);

        return trim($output);
    }

    public static function assertRuntime(string $html, ?string $source = null, ?string $stillSource = null): void
    {
        if (! self::applies($html)) {
            throw new RuntimeException('Die begrenzte Zugausgabe besitzt keinen eindeutigen Vertrag.');
        }
        [, $xpath] = self::document($html);
        $frame = self::one($xpath, 'rt-sign-content-frame');
        $content = self::one($xpath, 'rt-sign-content');
        $image = self::one($xpath, 'rt-delivery-train');
        $cell = self::one($xpath, 'rt-delivery-train-cell');
        if ($xpath->query('//table[@data-rt-train-delivery="'.self::MARKER.'"]')->length !== 1
            || ! $image->parentNode->isSameNode($cell)
            || ! $cell->parentNode->previousElementSibling?->isSameNode($content->parentNode)
            || ! $content->parentNode->parentNode->isSameNode($cell->parentNode->parentNode)
            || $xpath->query('./tr|./tbody/tr', $frame)->length !== 2
            || $image->getAttribute('width') !== '100%'
            || $image->hasAttribute('height')
            || ! self::allowedSource($image->getAttribute('src'))
            || ($source !== null && ! hash_equals($source, $image->getAttribute('src')))) {
            throw new RuntimeException('Der Signaturzug muss begrenzt nach den Kontakten stehen.');
        }
        if (preg_match('~<!--\[if mso\]>(<img class="rt-delivery-train-mso"[^>]*>)<!\[endif\]-->~', $html, $match) !== 1
            || substr_count($html, 'class="rt-delivery-train-mso"') !== 1
            || ($stillSource !== null && ! str_contains($match[1], 'src="'.htmlspecialchars($stillSource, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"'))
            || str_contains($html, 'rt-v27-anchor') || str_contains($html, 'rt-v27-image-cell')) {
            throw new RuntimeException('Die begrenzte Zugausgabe besitzt keinen eindeutigen Outlook-Fallback.');
        }
        [, $stillXpath] = self::document($match[1]);
        $still = self::one($stillXpath, 'rt-delivery-train-mso');
        $style = preg_replace('/\s+|!important/i', '', strtolower($still->getAttribute('style')));
        if ($still->getAttribute('width') !== (string) self::IMAGE_WIDTH
            || $still->getAttribute('height') !== (string) self::IMAGE_HEIGHT
            || ! self::allowedSource($still->getAttribute('src'))
            || ! str_contains($style, 'max-width:'.self::IMAGE_WIDTH.'px')
            || ! str_contains($style, 'height:auto')
            || preg_match('/(?:^|;)(?:position|min-width|white-space|transform):/', $style)) {
            throw new RuntimeException('Der Outlook-Fallback muss ein gueltiges, proportional begrenztes IMG bleiben.');
        }
    }

    public static function css(string $html): string
    {
        $version = SignatureArtifactVersion::detect('signature', $html);
        if (! SignatureArtifactVersion::usesTableOverlapTrain($version)) {
            throw new RuntimeException('Unbekannte Version fuer begrenzte Zugausgabe.');
        }
        $scope = 'tr[data-rt-artifact-version="'.$version.'"]';
        $ledger = self::usesLedger($html);
        $css = $ledger
            ? (string) file_get_contents(resource_path('mail-templates/signature-v27-ledger.css'))
            : str_replace('{scope}', $scope, SignatureImgOverlap::editorSettings()['layoutCss']);

        $css .= $scope.' .rt-sign-content-frame{width:100%!important;table-layout:fixed!important;height:auto!important;direction:ltr!important;}'
            .$scope.' .rt-sign-cell .rt-sign-content{width:100%!important;vertical-align:top!important;height:auto!important;padding-bottom:15px!important;direction:ltr!important;}'
            .$scope.' .rt-delivery-train-cell{width:100%!important;padding:0!important;font-size:0!important;line-height:0!important;}'
            .$scope.' .rt-delivery-train,'.$scope.' .rt-delivery-train-mso{'.str_replace(';', '!important;', self::imageStyle(SignatureArtifactVersion::usesMirroredTrain($version))).'}';
        if (! $ledger) {
            return $css;
        }

        return $css
            .$scope.' .rt-sign-ledger .rt-ledger-brand,'.$scope.' .rt-sign-ledger .rt-ledger-contacts,'.$scope.' .rt-sign-ledger .rt-ledger-direct,'.$scope.' .rt-sign-ledger .rt-ledger-company{width:100%!important;border:0!important;padding:0!important;}'
            .$scope.' .rt-sign-ledger .rt-ledger-contacts,'.$scope.' .rt-sign-ledger .rt-ledger-company{padding-top:14px!important;}'
            .'@media only screen and (min-width:861px){'
            .$scope.' .rt-sign-ledger>tbody,'.$scope.' .rt-delivery-contacts>tbody{display:table-row!important;}'
            .$scope.' .rt-sign-ledger>tbody>tr,'.$scope.' .rt-delivery-contacts>tbody>tr{display:table-cell!important;vertical-align:top!important;}'
            .$scope.' .rt-sign-ledger>tbody>tr{width:28%!important;}'
            .$scope.' .rt-sign-ledger>tbody>tr+tr{width:72%!important;}'
            .$scope.' .rt-delivery-contacts>tbody>tr{width:38%!important;}'
            .$scope.' .rt-delivery-contacts>tbody>tr+tr{width:62%!important;}'
            .$scope.' .rt-sign-ledger>tbody>tr>td,'.$scope.' .rt-delivery-contacts>tbody>tr>td{display:block!important;box-sizing:border-box!important;}'
            .$scope.' .rt-sign-ledger .rt-ledger-brand{border-right:2px solid #e90032!important;padding-right:24px!important;}'
            .$scope.' .rt-sign-ledger .rt-ledger-contacts{padding:0 0 0 24px!important;}'
            .$scope.' .rt-sign-ledger .rt-ledger-direct{padding-right:18px!important;}'
            .$scope.' .rt-sign-ledger .rt-ledger-company{padding:0!important;}'
            .'}';
    }

    private static function imageStyle(bool $mirrored = false): string
    {
        return 'display:block;width:100%;max-width:'.self::IMAGE_WIDTH.'px;height:auto;margin:'.($mirrored ? '0 0 0 auto' : '0').';border:0;vertical-align:bottom;';
    }

    private static function allowedSource(string $source): bool
    {
        return preg_match('~\A(?:https?://[^\s<>"\']+|cid:[A-Za-z0-9._@+\-]+|data:image/(?:gif|png);base64,[A-Za-z0-9+/=]+|[^\s<>"\']+_files/[^\s<>"\']+)\z~D', $source) === 1;
    }

    /** @return array{DOMDocument, DOMXPath} */
    private static function document(string $html): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><table id="rt-delivery-root"><tbody>'.$html.'</tbody></table>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('Die begrenzte Signaturausgabe konnte nicht gelesen werden.');
        }

        return [$dom, new DOMXPath($dom)];
    }

    private static function one(DOMXPath $xpath, string $class): DOMElement
    {
        $matches = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," '.$class.' ")]');
        if ($matches->length !== 1 || ! $matches->item(0) instanceof DOMElement) {
            throw new RuntimeException('Die begrenzte Signaturausgabe besitzt keinen eindeutigen '.$class.'.');
        }

        return $matches->item(0);
    }

    private static function stack(DOMDocument $dom, DOMXPath $xpath, DOMElement $table, array $classes): void
    {
        $rows = $xpath->query('./tr|./tbody/tr', $table);
        if ($rows->length !== 1 || $xpath->query('./td', $rows->item(0))->length !== count($classes)) {
            throw new RuntimeException('Die begrenzte Signaturausgabe besitzt fremde Tabellenstrukturen.');
        }
        $row = $rows->item(0);
        $cells = iterator_to_array($xpath->query('./td', $row));
        foreach ($cells as $index => $cell) {
            if (! in_array($classes[$index], preg_split('/\s+/', $cell->getAttribute('class')), true)) {
                throw new RuntimeException('Die begrenzte Signaturausgabe besitzt fremde Kontaktgruppen.');
            }
            $next = $dom->createElement('tr');
            $next->setAttribute('class', 'rt-delivery-ledger-group');
            $row->parentNode->insertBefore($next, $row);
            $next->appendChild($cell);
            $cell->setAttribute('width', '100%');
            $cell->setAttribute('align', 'left');
            $cell->setAttribute('style', 'width:100%;padding:'.($index === 1 ? '14px 0 0' : '0').';border:0;vertical-align:top;text-align:left;');
        }
        $row->parentNode->removeChild($row);
        $table->setAttribute('style', 'width:100%;table-layout:auto;border-collapse:collapse;');
    }
}
