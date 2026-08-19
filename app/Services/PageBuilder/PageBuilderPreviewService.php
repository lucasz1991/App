<?php

namespace App\Services\PageBuilder;

use App\Enums\MailDocumentKind;
use App\Enums\MarketingCreativeFormat;
use App\Models\MailDocument;
use App\Models\MarketingCreative;
use App\Models\User;
use App\Services\Marketing\MarketingHtmlSanitizer;
use App\Services\Marketing\MarketingRenderAssetHydrator;
use App\Support\EmailTemplateBuilder;
use App\Support\Mail\EmailHtmlSanitizer;
use App\Support\MailSignature;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Read-only preview pipeline shared by the Marketing and Mail PageBuilders.
 *
 * Nothing in this service publishes or updates a document. Every image is
 * embedded as a data URI so the preview response can run under a no-network
 * Content-Security-Policy.
 */
final class PageBuilderPreviewService
{
    private const TRANSPARENT_PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M/wHwAF/gL+QZkWAAAAAElFTkSuQmCC';

    public function __construct(
        private readonly MarketingHtmlSanitizer $marketingSanitizer,
        private readonly MarketingRenderAssetHydrator $marketingAssets,
        private readonly EmailHtmlSanitizer $mailSanitizer,
    ) {}

    /** @return array{html: string, width: int, height: int} */
    public function marketing(MarketingCreative $creative, MarketingCreativeFormat $format): array
    {
        $variant = $creative->variants()
            ->where('format', $format->value)
            ->first();

        if ($variant === null) {
            throw (new ModelNotFoundException)->setModel(get_class($creative), [$format->value]);
        }

        $html = $this->marketingSanitizer->html((string) $variant->html);
        $css = $this->marketingSanitizer->css((string) $variant->css);
        $hydrated = $this->marketingAssets->hydrate($html, $css);
        $hydrated['html'] = $this->neutralizeNavigation($hydrated['html']);
        $dimensions = $format->dimensions();

        $document = $this->document(
            title: $creative->title.' · '.ucfirst($format->value),
            content: '<div id="rt-preview-artboard" data-page-builder-preview-artboard>'.$hydrated['html'].'</div>',
            css: $this->marketingDocumentCss($dimensions['width'], $dimensions['height'])."\n".$hydrated['css'],
            theme: 'light',
            bodyAttributes: sprintf(
                'data-preview-kind="marketing" data-preview-format="%s" data-preview-width="%d" data-preview-height="%d"',
                $format->value,
                $dimensions['width'],
                $dimensions['height'],
            ),
        );

        return [
            'html' => $document,
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
        ];
    }

    /** @return array{html: string, width: int, height: int} */
    public function mail(
        MailDocument $document,
        User $user,
        string $theme,
        bool $animated = false,
        ?string $playbackNonce = null,
    ): array {
        $theme = $theme === 'dark' ? 'dark' : 'light';
        $signatureDocument = $document->kind === MailDocumentKind::Signature
            ? $document
            : MailDocument::query()->where('kind', MailDocumentKind::Signature->value)->first();

        // Vorschau und Editor zeigen den tatsaechlichen Absenderkontext der
        // Systemnachrichten. Zuvor wurde hier das Profil des angemeldeten
        // Administrators eingesetzt, waehrend der Versand forCompany()
        // verwendet. Dadurch sah derselbe veroeffentlichte Signaturblock
        // in der Vorschau personengebunden und in der Mail automatisiert aus.
        $signatureRenderer = MailSignature::forCompany(
            $theme,
            animated: $animated,
            playbackNonce: $playbackNonce,
            remoteAssets: false,
            staticAssets: ! $animated,
        );
        $values = $signatureRenderer->values();
        if ($animated) {
            $values = $this->uniquePreviewGifValues(
                $values,
                $playbackNonce ?? bin2hex(random_bytes(12)),
            );
        }
        $values = array_merge($values, $this->sampleMailValues($user));
        $signature = $signatureDocument === null
            ? ''
            : $signatureRenderer->renderDocument(
                (string) $signatureDocument->html,
                overrides: $values,
            );

        $html = $this->renderTokenHtml((string) $document->html, $values, [
            'SIGNATURE_BLOCK' => $signature,
            'RESPONSIVE_CSS' => EmailTemplateBuilder::responsiveCss($values['SIGNATURE_BORDER'] ?? null),
            // Die Adminvorschau zeigt das im Entwurf vorhandene Muster. Der
            // Slot selbst wird erst beim produktiven Versand durch echten
            // Anwendungsinhalt ersetzt und darf hier nicht doppelt erscheinen.
            'APPLICATION_CONTENT' => '',
        ]);

        $css = $this->mailCss((string) $document->css, $values);
        if ($document->kind === MailDocumentKind::Template && $signatureDocument !== null) {
            $css .= "\n".$this->mailCss((string) $signatureDocument->css, $values);
        }

        if ($document->kind === MailDocumentKind::Signature) {
            $html = $this->document(
                title: 'RailTime Signatur · '.ucfirst($theme),
                content: '<table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0"><tbody>'.$html.'</tbody></table>',
                css: $this->mailPreviewCss($theme)."\n".$css,
                theme: $theme,
                bodyAttributes: 'data-preview-kind="mail" data-preview-document="signature" data-preview-theme="'.$theme.'" data-preview-animation="'.($animated ? 'animated' : 'static').'"',
            );
        } else {
            $html = $this->embedStyle($html, $css, 'template');
            $html = $this->ensureDocumentMetadata($html, 'template', $theme, $animated);
        }

        // XML namespace identifiers are not fetched by browsers, but keeping
        // an http(s) literal would weaken the preview's auditable no-network
        // contract. The email-specific urn: namespaces remain untouched.
        $html = preg_replace(
            '/\s+xmlns(?:\:[a-z0-9_-]+)?\s*=\s*(["\'])https?:\/\/[^"\']+\1/iu',
            '',
            $html,
        ) ?? $html;

        return [
            'html' => $html,
            'width' => 1920,
            'height' => $document->kind === MailDocumentKind::Signature ? 360 : 820,
        ];
    }

    /** @param array{width: int, height: int}|array<string, int> $dimensions */
    private function marketingDocumentCss(int $width, int $height): string
    {
        return sprintf(<<<'CSS'
html,body{margin:0!important;padding:0!important;width:%1$dpx!important;min-width:%1$dpx!important;max-width:%1$dpx!important;height:%2$dpx!important;min-height:%2$dpx!important;max-height:%2$dpx!important;overflow:hidden!important;background:#fff}
#rt-preview-artboard{position:relative;width:%1$dpx;height:%2$dpx;overflow:hidden;isolation:isolate}
CSS, $width, $height);
    }

    /** @param array<string, string> $values @param array<string, string> $rawTokens */
    private function renderTokenHtml(string $html, array $values, array $rawTokens = []): string
    {
        $html = $this->mailSanitizer->clean($html)->html;
        $tokens = [];

        foreach ($values as $key => $value) {
            $tokens['{{'.$key.'}}'] = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        foreach ($rawTokens as $key => $value) {
            $tokens['{{'.$key.'}}'] = $value;
        }

        $html = strtr($html, $tokens);
        $html = EmailTemplateBuilder::stripEmptyContactRows($html, $values);
        $html = $this->neutralizeNavigation($html);

        return preg_replace('/\{\{[A-Z0-9_]+\}\}/', '—', $html) ?? $html;
    }

    /** @param array<string, string> $values */
    private function mailCss(string $css, array $values): string
    {
        if ($css === '') {
            return '';
        }

        $safeTokens = [];
        foreach (array_merge(
            array_keys(EmailTemplateBuilder::emailThemeValues($values['THEME'] ?? 'light')),
            ['LOGO_SRC', 'LOGO_STILL_SRC', 'TRAIN_SRC', 'TRAIN_IDLE_SRC', 'ICON_RT_SRC', 'ICON_RT_STILL_SRC', 'GRUND_RASTER_SRC', 'GRUND_MARKE_SRC'],
            array_keys(EmailTemplateBuilder::contactIconSources(true)),
        ) as $key) {
            if (array_key_exists($key, $values)) {
                $safeTokens['{{'.$key.'}}'] = (string) $values[$key];
            }
        }

        $css = strtr($css, $safeTokens);
        $css = preg_replace('/<\/style/iu', '', $css) ?? '';
        $css = preg_replace('/@(?:import|charset|namespace)\b[^;]*(?:;|$)/iu', '', $css) ?? '';
        $css = preg_replace('/expression\s*\([^)]*\)/iu', '', $css) ?? '';
        $css = preg_replace_callback('/url\s*\(\s*(["\']?)(.*?)\1\s*\)/iu', static function (array $match): string {
            $url = trim(html_entity_decode((string) ($match[2] ?? ''), ENT_QUOTES | ENT_HTML5));

            return str_starts_with(strtolower($url), 'data:image/') ? $match[0] : 'none';
        }, $css) ?? '';

        return trim($css);
    }

    /** @return array<string, string> */
    private function sampleMailValues(User $user): array
    {
        return [
            'ANREDE' => 'Guten Tag',
            'KURZE_EINLEITUNG' => 'vielen Dank für Ihre Nachricht und Ihr Interesse an RailTime.',
            'NACHRICHT' => 'Diese sichere Vorschau zeigt den aktuellen Arbeitsstand der Nachrichtenschale mit echten RailTime-Markenelementen und Ihren hinterlegten Profildaten.',
            'EINSATZORT' => 'Deutschlandweit',
            'ZEITRAUM' => 'Nach Vereinbarung',
            'LEISTUNG' => 'Bahnbetriebliche Dienstleistungen',
            'ANSPRECHPARTNER' => $user->name,
            'CTA_TEXT' => 'RailTime kontaktieren',
            'CTA_URL' => '#',
        ];
    }

    /** @param array<string, string> $values @return array<string, string> */
    private function uniquePreviewGifValues(array $values, string $nonce): array
    {
        foreach (['LOGO_SRC', 'ICON_RT_SRC', 'TRAIN_SRC'] as $key) {
            $source = (string) ($values[$key] ?? '');
            if (! str_starts_with($source, 'data:image/gif;base64,')) {
                continue;
            }

            $binary = base64_decode(substr($source, strlen('data:image/gif;base64,')), true);
            $trailer = is_string($binary) ? strrpos($binary, "\x3b") : false;
            if ($trailer === false) {
                continue;
            }

            $comment = 'RailTime-Preview:'.substr($nonce.'-'.$key, 0, 120);
            $binary = substr($binary, 0, $trailer)
                ."\x21\xfe".chr(strlen($comment)).$comment."\x00"
                .substr($binary, $trailer);
            $values[$key] = 'data:image/gif;base64,'.base64_encode($binary);
        }

        return $values;
    }

    private function neutralizeNavigation(string $html): string
    {
        return preg_replace_callback(
            '/\s(href|action|formaction)\s*=\s*(["\'])(.*?)\2/iu',
            static fn (array $match): string => ' '.strtolower($match[1]).'="#"',
            $html,
        ) ?? $html;
    }

    private function mailPreviewCss(string $theme): string
    {
        $background = $theme === 'dark' ? '#070a0e' : '#e7eaed';

        return "html,body{margin:0;min-width:1920px;width:1920px;min-height:360px;background:{$background};font-family:Arial,Helvetica,sans-serif}body{padding:28px;box-sizing:border-box}table{border-collapse:collapse}";
    }

    private function embedStyle(string $html, string $css, string $kind): string
    {
        if (trim($css) === '') {
            return $html;
        }

        $style = '<style data-page-builder-preview-css="'.$kind.'">'.$css.'</style>';

        return preg_match('/<\/head\s*>/i', $html) === 1
            ? (string) preg_replace('/<\/head\s*>/i', $style.'</head>', $html, 1)
            : $this->document('RailTime Mailvorschau', $html, $css, 'light', 'data-preview-kind="mail"');
    }

    private function ensureDocumentMetadata(string $html, string $kind, string $theme, bool $animated): string
    {
        $attributes = ' data-preview-kind="mail" data-preview-document="'.$kind.'" data-preview-theme="'.$theme.'" data-preview-animation="'.($animated ? 'animated' : 'static').'"';

        if (preg_match('/<body\b/i', $html) === 1) {
            return (string) preg_replace('/<body\b/i', '<body'.$attributes, $html, 1);
        }

        return $this->document('RailTime Mailvorschau', $html, '', $theme, trim($attributes));
    }

    private function document(
        string $title,
        string $content,
        string $css,
        string $theme,
        string $bodyAttributes,
    ): string {
        $title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $colorScheme = $theme === 'dark' ? 'dark' : 'light';

        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="color-scheme" content="'.$colorScheme.'"><title>'.$title.'</title>'
            .'<style>'.$css.'</style></head><body '.$bodyAttributes.'>'.$content.'</body></html>';
    }
}
