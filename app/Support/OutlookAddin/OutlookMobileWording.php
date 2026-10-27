<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use App\Support\Mail\OutlookTemplateWording;
use DOMDocument;
use DOMElement;
use DOMXPath;

/** Positive, template-only compaction for the three known wording paragraphs. */
final class OutlookMobileWording
{
    public static function project(string $html): string
    {
        if (preg_match_all('~<style data-rt-outlook-template-css="1">(.*?)</style>~s', $html, $styles, PREG_OFFSET_CAPTURE) !== 1
            || preg_match_all('~<p class="(design-salutation|design-greeting|design-spacer)" style="([^"]*)">(.*?)</p>~s', $html, $paragraphs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) !== 3
            || preg_match('/\.([a-z]*rtt[0-9a-f]{12})\b/', $styles[1][0][0], $scope) !== 1) {
            return $html;
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $before = libxml_use_internal_errors(true);
        try {
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }
        if (! $loaded) {
            return $html;
        }
        $xpath = new DOMXPath($dom);
        if ($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," rt-wc ")]')->length !== 0
            || str_contains($styles[1][0][0], '.rt-wc')) {
            return $html;
        }
        $salutation = $xpath->query('//p[@class="design-salutation"]');
        $greeting = $xpath->query('//p[@class="design-greeting"]');
        $spacer = $xpath->query('//p[@class="design-spacer"]');
        if ($salutation->length !== 1 || $greeting->length !== 1 || $spacer->length !== 1
            || trim($spacer->item(0)->textContent) !== ''
            || $xpath->query('./*[not(self::br)]', $spacer->item(0))->length !== 0
            || ! $salutation->item(0)->parentNode instanceof DOMElement
            || ! $salutation->item(0)->parentNode->isSameNode($greeting->item(0)->parentNode)) {
            return $html;
        }
        $parent = $salutation->item(0)->parentNode;
        if ($parent->tagName !== 'td' || ! in_array('rt-native-template-pad', explode(' ', $parent->getAttribute('class')), true)
            || preg_match('/(?:^|;)\s*(?:word-break|word-wrap|overflow-wrap)\s*:/i', $parent->getAttribute('style'))) {
            return $html;
        }
        $scoped = false;
        for ($owner = $parent; $owner instanceof DOMElement; $owner = $owner->parentNode) {
            $classes = explode(' ', $owner->getAttribute('class'));
            if (in_array('rt-outlook-template', $classes, true) && in_array($scope[1], $classes, true)) {
                $scoped = true;
                break;
            }
        }
        if (! $scoped) {
            return $html;
        }
        // Moving the two identical, inheritable wrap values to this cell is
        // allowed only when its visible text belongs to these three P nodes.
        foreach ($xpath->query('.//text()', $parent) as $text) {
            if (trim($text->nodeValue) === '') {
                continue;
            }
            $known = false;
            for ($ancestor = $text->parentNode; $ancestor instanceof DOMElement && ! $ancestor->isSameNode($parent); $ancestor = $ancestor->parentNode) {
                if ($ancestor->tagName === 'p') {
                    $known = in_array($ancestor->getAttribute('class'), ['design-salutation', 'design-greeting', 'design-spacer'], true);
                    break;
                }
            }
            $metadata = $text->parentNode;
            if (! $known && (! $metadata instanceof DOMElement || $metadata->tagName !== 'span' || ! $metadata->hasAttribute('hidden')
                || ! in_array($text->nodeValue, ['RT-COMPOSE-EDITABLE-START-V1', 'RT-COMPOSE-EDITABLE-END-V1'], true))) {
                return $html;
            }
        }
        // The editable TABLE may sit between the spacer and this same cell.
        for ($node = $spacer->item(0)->parentNode; $node instanceof DOMElement && ! $node->isSameNode($parent); $node = $node->parentNode) {
            if (! $node instanceof DOMElement || ! in_array($node->tagName, ['table', 'tbody', 'tr', 'td'], true)
                || preg_match('/(?:^|;)\s*(?:font[\w-]*|color|line-height|all|word-break|word-wrap|overflow-wrap)\s*:/i', $node->getAttribute('style'))) {
                return $html;
            }
        }
        if (! $node instanceof DOMElement || ! $node->isSameNode($parent)) {
            return $html;
        }
        $parentDeclarations = self::declarations($parent->getAttribute('style'), false);
        $inherited = [];
        foreach (['font-family', 'font-size', 'line-height', 'color'] as $property) {
            if (! isset($parentDeclarations[$property])) {
                return $html;
            }
            $inherited[$property] = $parentDeclarations[$property];
        }
        $inherited['overflow-wrap'] = 'anywhere';
        $inherited['word-break'] = 'break-word';
        $css = $styles[1][0][0];
        $wrapRule = OutlookTemplateWording::wrappingCss($scope[1]);
        if (substr_count($css, $wrapRule) !== 1) {
            return $html;
        }
        $css = str_replace($wrapRule, '', $css);
        $edits = [];
        $roles = [];
        foreach ($paragraphs as $paragraph) {
            $role = $paragraph[1][0];
            if (isset($roles[$role])) {
                return $html;
            }
            $roles[$role] = true;
            $declarations = self::declarations($paragraph[2][0]);
            if ($declarations === null) {
                return $html;
            }
            $selector = '.'.$scope[1].' .'.$role;
            if (preg_match_all('~'.preg_quote($selector, '~').'\{([^{}]*)\}~', $css, $rules) !== 1) {
                return $html;
            }
            $ruleDeclarations = self::declarations($rules[1][0]);
            if ($role !== 'design-spacer' && $ruleDeclarations !== null) {
                $ruleDeclarations['overflow-wrap'] = 'anywhere';
                $ruleDeclarations['word-break'] = 'break-word';
                ksort($ruleDeclarations);
            }
            if ($ruleDeclarations !== $declarations) {
                return $html;
            }
            $compact = self::compact($declarations, $inherited);
            $css = str_replace($rules[0][0], $selector.'{'.$compact.'}', $css);
            $inner = $role === 'design-spacer' ? $paragraph[3][0] : self::compactText($paragraph[3][0]);
            $edits[] = [$paragraph[0][1], strlen($paragraph[0][0]), '<p class="'.$role.'" style="'.$compact.'">'.$inner.'</p>'];
        }
        // No other rule may change inheritance on the cell/paragraph chain.
        $remaining = preg_replace('~'.preg_quote('.'.$scope[1].' .design-', '~').'(?:salutation|greeting|spacer)\{[^{}]*\}~', '', $css);
        $remaining = str_replace('.'.$scope[1].' a{color: inherit;}', '', $remaining);
        $remaining = str_replace('.'.$scope[1].' img{border: 0; display: block; height: auto; line-height: 100%; outline: none; text-decoration: none;}', '', $remaining);
        if (preg_match('/(?:^|[;{])\s*(?:font[\w-]*|color|line-height|all|word-break|word-wrap|overflow-wrap)\s*:/i', $remaining)) {
            return $html;
        }
        $parentOpening = null;
        preg_match_all('~<td\b(?:[^"\'<>]|"[^"]*"|\'[^\']*\')*>~i', $html, $cells, PREG_OFFSET_CAPTURE);
        foreach ($cells[0] as $cell) {
            if (str_contains($cell[0], 'class="'.$parent->getAttribute('class').'"')
                && str_contains($cell[0], 'style="'.$parent->getAttribute('style').'"')) {
                if ($parentOpening !== null) {
                    return $html;
                }
                $parentOpening = $cell;
            }
        }
        if ($parentOpening === null) {
            return $html;
        }
        $newParent = str_replace('class="'.$parent->getAttribute('class').'"', 'class="'.$parent->getAttribute('class').' rt-wc"', $parentOpening[0]);
        $oldStyle = $parent->getAttribute('style');
        $newStyle = rtrim(self::compactCellPadding($oldStyle), '; ').';'.OutlookTemplateWording::WRAP_DECLARATIONS;
        $newParent = str_replace('style="'.$oldStyle.'"', 'style="'.$newStyle.'"', $newParent);
        $edits[] = [$parentOpening[1], strlen($parentOpening[0]), $newParent];
        $css .= '.'.$scope[1].' .rt-wc{'.self::serialize($inherited).'}';
        $edits[] = [$styles[1][0][1], strlen($styles[1][0][0]), $css];
        usort($edits, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
        foreach ($edits as [$offset, $length, $replacement]) {
            $html = substr_replace($html, $replacement, $offset, $length);
        }

        return $html;
    }

    private static function declarations(string $style, bool $paragraph = true): ?array
    {
        $result = [];
        foreach (explode(';', $style) as $declaration) {
            if (trim($declaration) === '') {
                continue;
            }
            if (preg_match('/^\s*([a-z-]+)\s*:\s*([^;{}!]+)\s*$/i', $declaration, $part) !== 1) {
                return null;
            }
            $key = strtolower($part[1]);
            if (! $paragraph && ! in_array($key, ['font-family', 'font-size', 'line-height', 'color', 'all', 'font'], true)) {
                continue;
            }
            if (isset($result[$key]) || ! in_array($key, ['margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'font-family', 'font-size', 'line-height', 'font-weight', 'color', 'overflow-wrap', 'word-break'], true)) {
                return null;
            }
            $value = trim($part[2]);
            if ($key === 'font-family') {
                $value = preg_replace('/\s*,\s*/', ',', $value);
            } elseif ($key === 'color' && preg_match('/^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/i', $value, $rgb)) {
                if (max((int) $rgb[1], (int) $rgb[2], (int) $rgb[3]) > 255) {
                    return null;
                }
                $value = sprintf('#%02x%02x%02x', $rgb[1], $rgb[2], $rgb[3]);
            }
            $result[$key] = $value;
        }
        // The editable-slot compiler repeats exactly the existing four margins
        // as a shorthand. Accept only proven identical values, never a reset.
        if (isset($result['margin'])) {
            $values = preg_split('/\s+/', $result['margin']);
            if (count($values) < 1 || count($values) > 4) {
                return null;
            }
            $values = [$values[0], $values[1] ?? $values[0], $values[2] ?? $values[0], $values[3] ?? $values[1] ?? $values[0]];
            foreach (['top', 'right', 'bottom', 'left'] as $index => $side) {
                if (! isset($result['margin-'.$side])
                    || preg_replace('/\b0px\b/', '0', $result['margin-'.$side]) !== preg_replace('/\b0px\b/', '0', $values[$index])) {
                    return null;
                }
            }
            unset($result['margin']);
        }
        ksort($result);

        return $result;
    }

    /** Fold only the exact physical mobile left/right override into its shorthand. */
    private static function compactCellPadding(string $style): string
    {
        $parts = explode(';', $style);
        $padding = [];
        foreach ($parts as $index => $part) {
            if (preg_match('/^\s*(padding[\w-]*)\s*:\s*(.*?)\s*$/i', $part, $match)) {
                $key = strtolower($match[1]);
                if (isset($padding[$key]) || ! in_array($key, ['padding', 'padding-left', 'padding-right'], true)) {
                    return $style;
                }
                $padding[$key] = ['index' => $index, 'value' => $match[2]];
            }
        }
        if (count($padding) !== 3 || ($padding['padding-left']['value'] ?? null) !== '18px' || ($padding['padding-right']['value'] ?? null) !== '18px'
            || $padding['padding']['index'] > min($padding['padding-left']['index'], $padding['padding-right']['index'])
            || preg_match('/^(?:0|\d+(?:\.\d+)?px)(?:\s+(?:0|\d+(?:\.\d+)?px)){0,3}$/', $padding['padding']['value']) !== 1) {
            return $style;
        }
        $values = preg_split('/\s+/', $padding['padding']['value']);
        $top = $values[0] === '0px' ? '0' : $values[0];
        $bottom = $values[2] ?? $values[0];
        $bottom = $bottom === '0px' ? '0' : $bottom;
        $parts[$padding['padding']['index']] = 'padding:'.$top.' 18px'.($bottom === $top ? '' : ' '.$bottom);
        unset($parts[$padding['padding-left']['index']], $parts[$padding['padding-right']['index']]);

        return implode(';', $parts);
    }

    private static function compact(array $declarations, array $inherited): string
    {
        foreach ($inherited as $key => $value) {
            if (($declarations[$key] ?? null) === $value) {
                unset($declarations[$key]);
            }
        }
        $margins = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $key = 'margin-'.$side;
            if (! isset($declarations[$key]) || preg_match('/^(?:0|\d+(?:\.\d+)?px)$/', $declarations[$key]) !== 1) {
                return self::serialize($declarations);
            }
            $margins[] = $declarations[$key] === '0px' ? '0' : $declarations[$key];
        }
        if ($margins[1] === $margins[3]) {
            array_pop($margins);
            if ($margins[0] === $margins[2]) {
                array_pop($margins);
                if ($margins[0] === $margins[1]) {
                    array_pop($margins);
                }
            }
        }
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            unset($declarations['margin-'.$side]);
        }

        return 'margin:'.implode(' ', $margins).';'.self::serialize($declarations);
    }

    private static function serialize(array $declarations): string
    {
        $result = '';
        foreach ($declarations as $key => $value) {
            $result .= $key.':'.$value.';';
        }

        return $result;
    }

    private static function compactText(string $html): string
    {
        // Never expose any named/numeric entity prefix, including legacy
        // semicolon-less forms. All alphanumeric/# followers remain encoded.
        return preg_replace('/&amp;(?=[^A-Za-z0-9#]|$)/', '&', $html);
    }
}
