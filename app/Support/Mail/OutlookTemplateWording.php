<?php

declare(strict_types=1);

namespace App\Support\Mail;

use DOMDocument;
use DOMElement;
use DOMText;
use RuntimeException;

/** Explicit text-only template opt-in; never rewrites arbitrary greetings. */
final class OutlookTemplateWording
{
    public const GREETING_TOKEN = '{{OUTLOOK_ANREDE}}';

    public const FAREWELL_TOKEN = '{{OUTLOOK_GRUSS}}';

    public const DEFAULT_GREETING = 'Guten Tag,';

    public const DEFAULT_FAREWELL = 'Mit freundlichen Grüßen,';

    public const WRAP_DECLARATIONS = 'overflow-wrap:anywhere;word-break:break-word;';

    public static function wrappingCss(string $scope): string
    {
        if (preg_match('/\Artt[0-9a-f]{12}\z/', $scope) !== 1) {
            throw new RuntimeException('Der persönliche Vorlagen-Scope ist ungültig.');
        }

        return '.'.$scope.' .design-salutation,.'.$scope.' .design-greeting{'.self::WRAP_DECLARATIONS.'}';
    }

    public static function wrapOutlookParagraphs(string $html): string
    {
        $result = preg_replace_callback('~<p class="(design-salutation|design-greeting)" style="([^"]*)">~',
            static fn (array $match): string => '<p class="'.$match[1].'" style="'.rtrim($match[2], '; ').';'.self::WRAP_DECLARATIONS.'">', $html, -1, $count);
        if ($count !== 2 || ! is_string($result)) {
            throw new RuntimeException('Die persönliche Vorlage besitzt keine eindeutigen Outlook-Textabsätze.');
        }

        return $result;
    }

    public static function requested(string $html): bool
    {
        return str_contains($html, self::GREETING_TOKEN) || str_contains($html, self::FAREWELL_TOKEN);
    }

    /** Resolve once, after all ordinary substitutions, so text cannot become markup/tokens. */
    public static function render(string $html, ?array $preferences = null, string $employeeName = ''): string
    {
        if (! self::requested($html)) {
            return $html;
        }
        if (substr_count($html, self::GREETING_TOKEN) !== 1 || substr_count($html, self::FAREWELL_TOKEN) !== 1) {
            throw new RuntimeException('Die persönliche Anrede und der Gruß benötigen genau ein vollständiges Platzhalterpaar.');
        }
        $visible = preg_replace('~<!--.*?-->|<(style|script|textarea|title)\b[^>]*>.*?</\1\s*>~is', '', $html);
        if (! is_string($visible) || substr_count($visible, self::GREETING_TOKEN) !== 1 || substr_count($visible, self::FAREWELL_TOKEN) !== 1) {
            throw new RuntimeException('Persönliche Textplatzhalter sind nicht in Kommentaren oder Metadaten zulässig.');
        }
        foreach (['design-salutation' => self::GREETING_TOKEN, 'design-greeting' => self::FAREWELL_TOKEN] as $role => $token) {
            if (preg_match('~<p\b((?:[^"\'<>]|"[^"]*"|\'[^\']*\')*)>\s*'.preg_quote($token, '~').'\s*</p\s*>~i', $html, $paragraph) !== 1
                || preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~is', $paragraph[1], $class) !== 1
                || ! in_array($role, preg_split('/\s+/', trim($class[2])) ?: [], true)) {
                throw new RuntimeException('Persönliche Platzhalter dürfen ausschließlich den Text ihrer Anrede-/Grußabsätze belegen.');
            }
        }
        if (strpos($html, self::GREETING_TOKEN) >= strpos($html, self::FAREWELL_TOKEN)) {
            throw new RuntimeException('Die persönliche Anrede muss vor dem Gruß stehen.');
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $before = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }
        $roles = [];
        $paragraphTokens = [self::GREETING_TOKEN => 0, self::FAREWELL_TOKEN => 0];
        foreach ($loaded ? $document->getElementsByTagName('p') : [] as $paragraph) {
            if (array_key_exists(trim($paragraph->textContent), $paragraphTokens)) {
                $paragraphTokens[trim($paragraph->textContent)]++;
            }
            $classes = preg_split('/\s+/', trim($paragraph->getAttribute('class'))) ?: [];
            foreach (['design-salutation' => self::GREETING_TOKEN, 'design-greeting' => self::FAREWELL_TOKEN] as $role => $token) {
                if (in_array($role, $classes, true)) {
                    if (isset($roles[$role]) || $paragraph->childNodes->length !== 1
                        || ! $paragraph->firstChild instanceof DOMText || trim($paragraph->textContent) !== $token) {
                        throw new RuntimeException('Persönliche Textplatzhalter sind nur in ihren eindeutigen Anrede-/Grußabsätzen zulässig.');
                    }
                    $roles[$role] = $paragraph;
                }
            }
        }
        $greeting = $roles['design-salutation'] ?? null;
        $farewell = $roles['design-greeting'] ?? null;
        if (! $greeting instanceof DOMElement || ! $farewell instanceof DOMElement || $greeting->isSameNode($farewell)
            || $paragraphTokens[self::GREETING_TOKEN] !== 1 || $paragraphTokens[self::FAREWELL_TOKEN] !== 1
            || ! $greeting->parentNode instanceof DOMElement || strtolower($greeting->parentNode->tagName) !== 'td'
            || ! $greeting->parentNode->isSameNode($farewell->parentNode)) {
            throw new RuntimeException('Die persönliche Anrede und der Gruß gehören in dieselbe eindeutige Inhaltszelle.');
        }
        if ($preferences !== null && (! is_string($preferences['greeting'] ?? null) || ! is_string($preferences['farewell'] ?? null))) {
            throw new RuntimeException('Die persönlichen Anrede-/Grußwerte sind ungültig.');
        }
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return strtr($html, [
            self::GREETING_TOKEN => $escape($preferences['greeting'] ?? self::DEFAULT_GREETING),
            self::FAREWELL_TOKEN => $escape($preferences['farewell'] ?? self::DEFAULT_FAREWELL)
                .($employeeName !== '' ? '<br>'.$escape($employeeName) : ''),
        ]);
    }
}
