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
    public const MARKER = 'bounded-img-v2';

    public const IMAGE_WIDTH = 600;

    public const FALLBACK_WIDTH = 300;

    public const FALLBACK_HEIGHT = 38;

    private const LOGO_TRAIN_FALLBACK_WIDTH = 220;

    private const LOGO_TRAIN_FALLBACK_HEIGHT = 28;

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

    public static function usesLogoTrain(string $html): bool
    {
        if (SignatureArtifactVersion::detect('signature', $html) !== 'v27') {
            return false;
        }
        preg_match_all('~<table\b[^>]*\sclass\s*=\s*(["\'])(.*?)\1[^>]*>~is', $html, $tables, PREG_SET_ORDER);
        foreach ($tables as $table) {
            $classes = preg_split('/\s+/', trim(CssSemantic::decodeHtmlEntitiesOnce($table[2])));
            if (in_array('rt-sign-ledger', $classes, true) && in_array('design-train-logo', $classes, true)) {
                return true;
            }
        }

        return false;
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
        $logoTrain = self::usesLogoTrain($html);
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
        if ($logoTrain) {
            $content->setAttribute('colspan', '2');
        }
        $contentStyle = $content->getAttribute('style');
        if ($logoTrain) {
            $contentStyle = preg_replace('/(?:^|;)\s*padding(?:-(?:top|right|bottom|left))?\s*:[^;]*(?=;|$)/i', '', $contentStyle) ?? $contentStyle;
        }
        $content->setAttribute('style', rtrim($contentStyle, ';').';width:100%;direction:ltr;'.($logoTrain ? 'padding:25px 0 0;' : 'padding-bottom:15px;'));
        $image->setAttribute('class', 'rt-delivery-train');
        $image->setAttribute('src', $source);
        $image->removeAttribute('data-rt-train');
        $image->setAttribute('width', (string) self::FALLBACK_WIDTH);
        $image->setAttribute('height', (string) self::FALLBACK_HEIGHT);
        $mirrored = SignatureArtifactVersion::usesMirroredTrain(SignatureArtifactVersion::detect('signature', $html));
        $image->setAttribute('style', self::imageStyle($mirrored, $logoTrain));
        $image->setAttribute('alt', '');
        $row = $dom->createElement('tr');
        $row->setAttribute('class', 'rt-delivery-train-row');
        $cell = $dom->createElement('td');
        $cell->setAttribute('class', 'rt-delivery-train-cell');
        $cell->setAttribute('width', $logoTrain ? '50%' : '100%');
        $cell->setAttribute('align', $mirrored ? 'right' : 'left');
        $cell->setAttribute('valign', 'bottom');
        $cell->setAttribute('style', $logoTrain
            ? 'width:50%;padding:0 40px 0 0;border-right:1px solid transparent;box-sizing:border-box;font-size:0;line-height:0;vertical-align:bottom;'
            : 'width:100%;padding:0;font-size:0;line-height:0;vertical-align:bottom;');
        $cell->appendChild($dom->createComment('[if !mso]><!'));
        $cell->appendChild($image);
        $cell->appendChild($dom->createComment('<![endif]'));
        // The original GIF's first frame is transparent. Word versions that
        // cannot animate must receive the populated PNG, independent of CSS.
        $stillWidth = $logoTrain ? self::LOGO_TRAIN_FALLBACK_WIDTH : self::FALLBACK_WIDTH;
        $stillHeight = $logoTrain ? self::LOGO_TRAIN_FALLBACK_HEIGHT : self::FALLBACK_HEIGHT;
        $still = '<img class="rt-delivery-train-mso" src="'.htmlspecialchars($stillSource, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" width="'.$stillWidth.'" height="'.$stillHeight.'" alt="" style="'.self::msoImageStyle($mirrored, $logoTrain).'">';
        $cell->appendChild($dom->createComment('[if mso]>'.$still.'<![endif]'));
        $row->appendChild($cell);
        if ($logoTrain) {
            $gap = $dom->createElement('td');
            $gap->setAttribute('class', 'rt-delivery-train-gap');
            $gap->setAttribute('width', '50%');
            $gap->setAttribute('style', 'width:50%;padding:0;font-size:0;line-height:0;');
            $row->appendChild($gap);
        }
        $imageCell->parentNode->removeChild($imageCell);
        $content->parentNode->parentNode->appendChild($row);
        if (self::usesLedger($html)) {
            self::columns($xpath, self::one($xpath, 'rt-sign-ledger'), ['rt-ledger-brand', 'rt-ledger-contacts'], $logoTrain);
            self::fluidLedgerLogo($xpath);
            $contacts = self::one($xpath, 'rt-ledger-contacts');
            $nested = $xpath->query('./table', $contacts);
            if ($nested->length !== 1) {
                throw new RuntimeException('Die begrenzte Signaturausgabe besitzt keine eindeutigen Kontaktgruppen.');
            }
            $nested->item(0)->setAttribute('class', trim($nested->item(0)->getAttribute('class').' rt-delivery-contacts'));
            if ($logoTrain) {
                self::parallelContactColumns($xpath, $nested->item(0));
                self::prepareLogoTrainHeading($dom, $xpath);
            } else {
                self::stack($dom, $xpath, $nested->item(0), ['rt-ledger-direct', 'rt-ledger-company']);
            }
        } else {
            self::stack($dom, $xpath, self::one($xpath, 'rt-sign-heading-table'), ['rt-sign-heading-person', 'rt-sign-heading-logo']);
            self::stackRow($dom, $xpath, self::one($xpath, 'rt-sign-top-row'), ['rt-sign-identity', 'rt-sign-company']);
            self::one($xpath, 'rt-sign-logo')->removeAttribute('colspan');
        }
        foreach ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-contact ")]') as $table) {
            $table->setAttribute('width', '100%');
            $table->setAttribute('style', 'width:100%;table-layout:auto;border-collapse:collapse;direction:ltr;');
        }
        foreach ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-contact-text ")]') as $text) {
            $text->removeAttribute('width');
            $text->setAttribute('style', rtrim($text->getAttribute('style'), ';').';word-break:break-all;overflow-wrap:anywhere;');
            // Word supports long-word wrapping more reliably on paragraphs.
            // Move existing nodes so links and visible text stay unchanged.
            $paragraph = $dom->createElement('p');
            $paragraph->setAttribute('class', 'rt-delivery-contact-value');
            $paragraph->setAttribute('style', 'margin:0;word-break:break-all;overflow-wrap:anywhere;');
            while ($text->firstChild !== null) {
                $paragraph->appendChild($text->firstChild);
            }
            $text->appendChild($paragraph);
        }
        // The shared legacy stylesheet makes rt-sign-layout a block on narrow
        // screens. The physical delivery table no longer needs that opt-in.
        foreach ($xpath->query('//table[contains(concat(" ",normalize-space(@class)," ")," rt-sign-layout ")]') as $table) {
            $table->setAttribute('class', trim(preg_replace('/(?:^|\s)rt-sign-layout(?=\s|$)/', '', $table->getAttribute('class'))));
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
        $logoTrain = self::usesLogoTrain($html);
        if ($xpath->query('//table[@data-rt-train-delivery="'.self::MARKER.'"]')->length !== 1
            || ! $image->parentNode->isSameNode($cell)
            || $image->getAttribute('width') !== (string) self::FALLBACK_WIDTH
            || $image->getAttribute('height') !== (string) self::FALLBACK_HEIGHT
            || ! self::allowedSource($image->getAttribute('src'))
            || ($source !== null && ! hash_equals($source, $image->getAttribute('src')))) {
            throw new RuntimeException('Der Signaturzug muss begrenzt nach den Kontakten stehen.');
        }
        if ($logoTrain) {
            self::assertLogoTrainShape($xpath, $frame, $content, $cell);
        } elseif (! $cell->parentNode->previousElementSibling?->isSameNode($content->parentNode)
            || ! $content->parentNode->parentNode->isSameNode($cell->parentNode->parentNode)
            || $xpath->query('./tr|./tbody/tr', $frame)->length !== 2) {
            throw new RuntimeException('Der Signaturzug muss begrenzt nach den Kontakten stehen.');
        }
        $cellHtml = $cell->ownerDocument->saveHTML($cell);
        if (preg_match('~<!--\[if !mso\]><!-->\s*<img\b(?=[^>]*\bclass="rt-delivery-train(?:\s[^\"]*)?")[^>]*>\s*<!--<!\[endif\]-->~', $cellHtml) !== 1
            || preg_match('~<!--\[if mso\]>(<img class="rt-delivery-train-mso"[^>]*>)<!\[endif\]-->~', $cellHtml, $match) !== 1
            || substr_count($html, 'class="rt-delivery-train-mso"') !== 1
            || ($stillSource !== null && ! str_contains($match[1], 'src="'.htmlspecialchars($stillSource, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"'))
            || str_contains($html, 'rt-v27-anchor') || str_contains($html, 'rt-v27-image-cell')) {
            throw new RuntimeException('Die begrenzte Zugausgabe besitzt keinen eindeutigen Outlook-Fallback.');
        }
        [, $stillXpath] = self::document($match[1]);
        $still = self::one($stillXpath, 'rt-delivery-train-mso');
        $style = preg_replace('/\s+|!important/i', '', strtolower($still->getAttribute('style')));
        $stillWidth = $logoTrain ? self::LOGO_TRAIN_FALLBACK_WIDTH : self::FALLBACK_WIDTH;
        $stillHeight = $logoTrain ? self::LOGO_TRAIN_FALLBACK_HEIGHT : self::FALLBACK_HEIGHT;
        if ($still->getAttribute('width') !== (string) $stillWidth
            || $still->getAttribute('height') !== (string) $stillHeight
            || ! self::allowedSource($still->getAttribute('src'))
            || ! str_contains($style, 'max-width:'.$stillWidth.'px')
            || ! str_contains($style, 'width:'.$stillWidth.'px')
            || ! str_contains($style, 'height:'.$stillHeight.'px')
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
        $logoTrain = self::usesLogoTrain($html);
        $css = $ledger
            ? $scope.' .rt-sign-ledger-content{padding:'.($logoTrain ? '25px 0 0' : '25px 40px 15px').'!important;}'
                .$scope.' .rt-sign-ledger img.rt-logo{width:100%!important;max-width:180px!important;height:auto!important;margin:0!important;}'
                .$scope.' .rt-sign-ledger .rt-contact{margin:0!important;text-align:left!important;}'
                .$scope.' .rt-address-break{display:none;}'
            : '';

        $css .= $scope.' .rt-sign-content-frame{width:100%!important;table-layout:fixed!important;height:auto!important;direction:ltr!important;}'
            .$scope.' .rt-sign-cell .rt-sign-content{width:100%!important;vertical-align:top!important;height:auto!important;padding-bottom:'.($logoTrain ? '0' : '15px').'!important;direction:ltr!important;}'
            .$scope.' .rt-delivery-train-cell{'.($logoTrain ? 'width:50%!important;padding:0 40px 0 0!important;border-right:1px solid transparent!important;box-sizing:border-box!important;' : 'width:100%!important;padding:0!important;').'font-size:0!important;line-height:0!important;}'
            .$scope.' .rt-delivery-train{'.str_replace(';', '!important;', self::imageStyle(SignatureArtifactVersion::usesMirroredTrain($version), $logoTrain)).'}'
            .$scope.' .rt-delivery-train-mso{'.str_replace(';', '!important;', self::msoImageStyle(SignatureArtifactVersion::usesMirroredTrain($version), $logoTrain)).'}';
        $css .= $scope.' .rt-delivery-group-cell{display:table-cell!important;width:'.($logoTrain ? '50%' : '100%').'!important;border:0!important;vertical-align:top!important;text-align:left!important;}'
            .$scope.' .rt-contact-text{word-break:break-all!important;overflow-wrap:anywhere!important;}'
            .$scope.' .rt-delivery-contact-value{margin:0!important;word-break:break-all!important;overflow-wrap:anywhere!important;}';
        if (! $ledger) {
            return $css;
        }

        return $css
            .$scope.' .rt-ledger-brand,'.$scope.' .rt-ledger-direct{padding:0!important;}'
            .$scope.' .rt-ledger-company{padding:'.($logoTrain ? '0' : '14px 0 0').'!important;}'
            .$scope.' .rt-delivery-wide-column{display:table-cell!important;vertical-align:top!important;box-sizing:border-box!important;text-align:left!important;font-weight:normal!important;}'
            .$scope.' .rt-ledger-brand.rt-delivery-wide-column{width:'.($logoTrain ? '50' : '32').'%!important;padding:0 '.($logoTrain ? '40' : '22').'px 0 0!important;border-right:1px solid '.($logoTrain ? 'transparent' : '#e60033').'!important;}'
            .$scope.' .rt-ledger-contacts.rt-delivery-wide-column{width:'.($logoTrain ? '50' : '68').'%!important;padding:'.($logoTrain ? '0 62px 0 0' : '0 0 0 22px').'!important;border:0!important;}'
            .($logoTrain ? $scope.' .rt-delivery-brand-heading{padding-left:40px!important;}' : '')
            .'@media only screen and (max-width:860px){'
            .$scope.' .rt-sign-ledger-content{padding:'.($logoTrain ? '23px 0 0' : '23px 22px 15px').'!important;}'
            .$scope.' .rt-sign-ledger img.rt-logo{max-width:175px!important;}'
            .$scope.' .rt-ledger-brand.rt-delivery-wide-column,'.$scope.' .rt-ledger-contacts.rt-delivery-wide-column{display:block!important;width:100%!important;'.($logoTrain ? '' : 'padding:0!important;').'border:0!important;box-sizing:border-box!important;}'
            .($logoTrain ? $scope.' .rt-ledger-brand.rt-delivery-wide-column{padding:0 22px 0 0!important;}' : '')
            .$scope.' .rt-ledger-contacts.rt-delivery-wide-column{'.($logoTrain ? 'padding:14px 22px 0' : 'padding-top:14px').'!important;}'
            .($logoTrain ? $scope.' .rt-delivery-brand-heading{padding-left:22px!important;}' : '')
            .($logoTrain ? $scope.' .rt-delivery-group-cell{display:block!important;width:100%!important;}'
                .$scope.' .rt-ledger-company{padding-top:14px!important;}'
                .$scope.' .rt-sign-content-frame{table-layout:auto!important;}'
                .$scope.' .rt-delivery-train-cell{width:100%!important;padding:0 22px 0 0!important;border:0!important;}'
                .$scope.' .rt-delivery-train-gap{display:none!important;}' : '')
            .$scope.' .rt-address-break{display:block!important;}'
            .'}';
    }

    private static function imageStyle(bool $mirrored = false, bool $logoTrain = false): string
    {
        return 'display:block;width:100%;max-width:'.($logoTrain ? '100%' : self::IMAGE_WIDTH.'px').';height:auto;margin:'.($mirrored ? '0 0 0 auto' : '0').';border:0;vertical-align:bottom;';
    }

    private static function msoImageStyle(bool $mirrored = false, bool $logoTrain = false): string
    {
        // Word cannot reliably constrain a fixed 600px image. The default
        // 300px full-row fallback fits a 320px pane; the opted-in 220px image
        // fits its half-column at about 570px without responsive head CSS.
        // Actual Word rendering still requires a received-client check.
        // Modern clients retain the fluid GIF rule within their content cell.
        $width = $logoTrain ? self::LOGO_TRAIN_FALLBACK_WIDTH : self::FALLBACK_WIDTH;
        $height = $logoTrain ? self::LOGO_TRAIN_FALLBACK_HEIGHT : self::FALLBACK_HEIGHT;

        return 'display:block;width:'.$width.'px;max-width:'.$width.'px;height:'.$height.'px;margin:'.($mirrored ? '0 0 0 auto' : '0').';border:0;vertical-align:bottom;';
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
        if ($rows->length !== 1) {
            throw new RuntimeException('Die begrenzte Signaturausgabe besitzt fremde Tabellenstrukturen.');
        }
        self::stackRow($dom, $xpath, $rows->item(0), $classes);
        $table->setAttribute('style', 'width:100%;table-layout:auto;border-collapse:collapse;');
    }

    /** Real layout cells; TH allows responsive reflow in Outlook iOS. */
    private static function columns(DOMXPath $xpath, DOMElement $table, array $classes, bool $logoTrain = false): void
    {
        $rows = $xpath->query('./tr|./tbody/tr', $table);
        if ($rows->length !== 1 || $xpath->query('./td', $rows->item(0))->length !== count($classes)) {
            throw new RuntimeException('Die zweispaltige Signatur besitzt fremde Tabellenstrukturen.');
        }
        foreach (iterator_to_array($xpath->query('./td', $rows->item(0))) as $index => $cell) {
            if (! in_array($classes[$index], preg_split('/\s+/', $cell->getAttribute('class')), true)) {
                throw new RuntimeException('Die zweispaltige Signatur besitzt fremde Kontaktgruppen.');
            }
            $column = $table->ownerDocument->createElement('th');
            foreach ($cell->attributes as $attribute) {
                $column->setAttribute($attribute->name, $attribute->value);
            }
            while ($cell->firstChild !== null) {
                $column->appendChild($cell->firstChild);
            }
            $cell->parentNode->replaceChild($column, $cell);
            $cell = $column;
            $width = $logoTrain ? '50%' : ($index === 0 ? '32%' : '68%');
            $cell->setAttribute('role', 'presentation');
            $cell->setAttribute('class', trim($cell->getAttribute('class').' rt-delivery-wide-column'));
            $cell->setAttribute('width', $width);
            $cell->setAttribute('align', 'left');
            $cell->setAttribute('valign', 'top');
            $padding = $index === 0 ? ($logoTrain ? '0 40px 0 0' : '0 22px 0 0') : ($logoTrain ? '0 62px 0 0' : '0 0 0 22px');
            $cell->setAttribute('style', 'width:'.$width.';padding:'.$padding.';border:0;'.($index === 0 ? 'border-right:1px solid '.($logoTrain ? 'transparent' : '#e60033').';' : '').'vertical-align:top;text-align:left;font-weight:normal;');
        }
        $table->setAttribute('width', '100%');
        $table->setAttribute('style', 'width:100%;table-layout:fixed;border-collapse:collapse;');
    }

    private static function parallelContactColumns(DOMXPath $xpath, DOMElement $table): void
    {
        $rows = $xpath->query('./tr|./tbody/tr', $table);
        if ($rows->length !== 1 || $xpath->query('./td', $rows->item(0))->length !== 2) {
            throw new RuntimeException('Die Logo-Zug-Kontakte benoetigen zwei eindeutige Tabellenzellen.');
        }
        foreach (['rt-ledger-direct', 'rt-ledger-company'] as $index => $class) {
            $cell = $xpath->query('./td', $rows->item(0))->item($index);
            if (! $cell->isSameNode(self::one($xpath, $class))) {
                throw new RuntimeException('Die Logo-Zug-Kontakte muessen Person vor Firma enthalten.');
            }
            $cell->setAttribute('class', trim($cell->getAttribute('class').' rt-delivery-group-cell'));
            $cell->setAttribute('width', '50%');
            $cell->setAttribute('align', 'left');
            $cell->setAttribute('style', 'width:50%;padding:0;border:0;vertical-align:top;text-align:left;');
        }
        $table->setAttribute('width', '100%');
        $table->setAttribute('style', 'width:100%;table-layout:fixed;border-collapse:collapse;');
    }

    /** Heading inset is independent from the final, edge-aligned train row. */
    private static function prepareLogoTrainHeading(DOMDocument $dom, DOMXPath $xpath): void
    {
        $brand = self::one($xpath, 'rt-ledger-brand');
        $headingTable = $dom->createElement('table');
        foreach (['role' => 'presentation', 'width' => '100%', 'border' => '0', 'cellspacing' => '0', 'cellpadding' => '0', 'style' => 'width:100%;table-layout:fixed;border-collapse:collapse;'] as $name => $value) {
            $headingTable->setAttribute($name, $value);
        }
        $headingBody = $dom->createElement('tbody');
        $headingRow = $dom->createElement('tr');
        $heading = $dom->createElement('td');
        $heading->setAttribute('class', 'rt-delivery-brand-heading');
        $heading->setAttribute('style', 'padding:0 0 0 40px;');
        while ($brand->firstChild !== null) {
            $heading->appendChild($brand->firstChild);
        }
        $headingRow->appendChild($heading);
        $headingBody->appendChild($headingRow);
        $headingTable->appendChild($headingBody);
        $brand->appendChild($headingTable);
        // Only the known empty authoring reserve becomes unnecessary here.
        // Other authored spacers, legal content and hotline padding survive.
        foreach (['rt-sign-ledger', 'rt-sign-stage'] as $class) {
            $spacer = self::one($xpath, $class)->nextElementSibling;
            if ($spacer instanceof DOMElement && strtolower($spacer->tagName) === 'div'
                && $spacer->childElementCount === 0 && trim(str_replace("\u{00a0}", '', $spacer->textContent)) === ''
                && preg_replace('/\s+/', '', strtolower($spacer->getAttribute('style'))) === 'height:25px;line-height:25px;font-size:0;') {
                $spacer->parentNode->removeChild($spacer);
            }
        }
    }

    private static function assertLogoTrainShape(DOMXPath $xpath, DOMElement $frame, DOMElement $content, DOMElement $cell): void
    {
        $ledger = self::one($xpath, 'rt-sign-ledger');
        $optIn = self::one($xpath, 'design-train-logo');
        $brand = self::one($xpath, 'rt-ledger-brand');
        $contacts = self::one($xpath, 'rt-ledger-contacts');
        $heading = self::one($xpath, 'rt-delivery-brand-heading');
        $headingTable = $xpath->query('ancestor::table[1]', $heading)->item(0);
        $row = self::one($xpath, 'rt-delivery-train-row');
        $frameRows = $xpath->query('./tr|./tbody/tr', $frame);
        $mobile = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-mobile-ledger ")]')->length === 1
            && $xpath->query('//style[@data-rt-outlook-mobile-css="1"]')->length === 1;
        $ledgerRows = $xpath->query('./tr|./tbody/tr', $ledger);
        $gap = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-delivery-train-gap ")]');
        if (! $optIn->isSameNode($ledger) || $ledger->tagName !== 'table'
            || ! $ledger->parentNode->isSameNode($content)
            || $frameRows->length !== 2 || ! $content->parentNode->isSameNode($frameRows->item(0))
            || ! $frameRows->item(1)->isSameNode($row)
            || $xpath->query('./td|./th', $frameRows->item(0))->length !== 1
            || ($mobile ? $content->hasAttribute('colspan') : $content->getAttribute('colspan') !== '2')
            || $brand->childElementCount !== 1 || $heading->tagName !== 'td'
            || ! $headingTable instanceof DOMElement || ! $headingTable->parentNode->isSameNode($brand)
            || ! $brand->firstElementChild?->isSameNode($headingTable)
            || $xpath->query('./tr|./tbody/tr', $headingTable)->length !== 1
            || $xpath->query('./td', $heading->parentNode)->length !== 1
            || ! $cell->parentNode->isSameNode($row) || $xpath->query('./td|./th', $row)->length !== ($mobile ? 1 : 2)
            || ! $row->firstElementChild?->isSameNode($cell)
            || $cell->getAttribute('width') !== ($mobile ? '100%' : '50%')
            || $gap->length !== ($mobile ? 0 : 1)
            || $ledgerRows->length !== ($mobile ? 2 : 1)) {
            throw new RuntimeException('Der Logo-Zug muss in der letzten echten Tabellenzeile unter allen Daten stehen.');
        }
        if (! $mobile) {
            $empty = $gap->item(0);
            if ($empty->tagName !== 'td' || ! $empty->parentNode->isSameNode($row)
                || ! $cell->nextElementSibling?->isSameNode($empty) || $empty->getAttribute('width') !== '50%'
                || $empty->childElementCount !== 0 || trim(str_replace("\u{00a0}", '', $empty->textContent)) !== '') {
                throw new RuntimeException('Die rechte Zugspalte muss als leere halbe Tabellenzelle reserviert bleiben.');
            }
        }
        foreach ([$brand, $contacts] as $index => $column) {
            $expectedRow = $ledgerRows->item($mobile ? $index : 0);
            if ($column->tagName !== ($mobile ? 'td' : 'th')
                || $column->getAttribute('width') !== ($mobile ? '100%' : '50%')
                || ! $column->parentNode->isSameNode($expectedRow)
                || $xpath->query('./td|./th', $expectedRow)->length !== ($mobile ? 1 : 2)
                || ! $xpath->query('./td|./th', $expectedRow)->item($mobile ? 0 : $index)?->isSameNode($column)) {
                throw new RuntimeException('Der Logo-Zug benoetigt eindeutige Marken- und Kontaktspalten.');
            }
        }
        $contactTable = self::one($xpath, 'rt-delivery-contacts');
        $contactRows = $xpath->query('./tr|./tbody/tr', $contactTable);
        if (! $contactTable->parentNode->isSameNode($contacts) || $contactRows->length !== ($mobile ? 2 : 1)) {
            throw new RuntimeException('Die Logo-Zug-Kontakte besitzen keine eindeutige responsive Datenzeile.');
        }
        foreach (['rt-ledger-direct', 'rt-ledger-company'] as $index => $class) {
            $group = self::one($xpath, $class);
            $expectedRow = $contactRows->item($mobile ? $index : 0);
            if ($group->tagName !== 'td' || $group->getAttribute('width') !== ($mobile ? '100%' : '50%')
                || ! $group->parentNode->isSameNode($expectedRow)
                || $xpath->query('./td', $expectedRow)->length !== ($mobile ? 1 : 2)
                || ! $xpath->query('./td', $expectedRow)->item($mobile ? 0 : $index)?->isSameNode($group)) {
                throw new RuntimeException('Die Logo-Zug-Kontakte muessen Person vor Firma behalten.');
            }
        }
    }

    /** Outlook's editor pane can be narrower than the media-query viewport. */
    private static function fluidLedgerLogo(DOMXPath $xpath): void
    {
        $brand = self::one($xpath, 'rt-ledger-brand');
        $logo = self::one($xpath, 'rt-logo');
        $ancestor = $logo->parentNode;
        $insideBrand = false;
        while ($ancestor instanceof DOMElement) {
            if ($ancestor->isSameNode($brand)) {
                $insideBrand = true;
                break;
            }
            $ancestor = $ancestor->parentNode;
        }
        if (! $insideBrand) {
            throw new RuntimeException('Das Signaturlogo liegt ausserhalb der eindeutigen Markenspalte.');
        }
        self::fluidLogoDimensions($logo);
        // Some published designs put the logo in its own fixed 180px table.
        // Constrain that carrier too; an IMG rule alone cannot shrink it.
        $carrier = $logo->parentNode;
        while ($carrier instanceof DOMElement && ! $carrier->isSameNode($brand)) {
            if (strtolower($carrier->tagName) === 'table') {
                $rows = $xpath->query('./tr|./tbody/tr', $carrier);
                if ($rows->length !== 1 || $xpath->query('./td', $rows->item(0))->length !== 1
                    || $xpath->query('.//img', $carrier)->length !== 1) {
                    throw new RuntimeException('Der Signaturlogo-Traeger besitzt fremde Tabellenstrukturen.');
                }
                $carrier->setAttribute('width', '100%');
                $carrier->removeAttribute('height');
                self::fluidLogoDimensions($carrier);
                $cell = $xpath->query('./td', $rows->item(0))->item(0);
                $cell->setAttribute('width', '100%');
                $cell->removeAttribute('height');
                $cell->setAttribute('style', self::withoutLogoDimensions($cell->getAttribute('style')).'width:100%;height:auto;');
                break;
            }
            $carrier = $carrier->parentNode;
        }
    }

    private static function fluidLogoDimensions(DOMElement $element): void
    {
        $element->setAttribute('style', self::withoutLogoDimensions($element->getAttribute('style')).'width:100%;max-width:180px;height:auto;');
    }

    private static function withoutLogoDimensions(string $style): string
    {
        return trim(preg_replace('/(?:^|;)\s*(?:width|min-width|max-width|height|min-height|max-height)\s*:[^;]*(?=;|$)/i', '', $style) ?? $style, ';').';';
    }

    private static function stackRow(DOMDocument $dom, DOMXPath $xpath, DOMElement $row, array $classes): void
    {
        if ($xpath->query('./td', $row)->length !== count($classes)) {
            throw new RuntimeException('Die begrenzte Signaturausgabe besitzt fremde Tabellenstrukturen.');
        }
        $cells = iterator_to_array($xpath->query('./td', $row));
        foreach ($cells as $index => $cell) {
            if (! in_array($classes[$index], preg_split('/\s+/', $cell->getAttribute('class')), true)) {
                throw new RuntimeException('Die begrenzte Signaturausgabe besitzt fremde Kontaktgruppen.');
            }
            $next = $dom->createElement('tr');
            $next->setAttribute('class', 'rt-delivery-ledger-group');
            $row->parentNode->insertBefore($next, $row);
            $next->appendChild($cell);
            $cell->setAttribute('class', trim($cell->getAttribute('class').' rt-delivery-group-cell'));
            $cell->setAttribute('width', '100%');
            $cell->setAttribute('align', 'left');
            $cell->setAttribute('style', 'width:100%;padding:'.($index === 1 ? '14px 0 0' : '0').';border:0;vertical-align:top;text-align:left;');
        }
        $row->parentNode->removeChild($row);
    }
}
