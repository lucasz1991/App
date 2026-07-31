<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Baut personalisierte E-Mail-Vorlagen und Signaturen aus den
 * RailTime-Master-Templates (resources/mail-templates, Design "d1").
 *
 * Name, Funktion und Kontaktdaten des Benutzers werden fest eingesetzt;
 * inhaltliche Platzhalter ({{BETREFF}}, {{NACHRICHT}}, …) sowie die
 * zentralen Firmendaten werden aus den Administrationseinstellungen bezogen.
 */
class EmailTemplateBuilder
{
    /**
     * Kleine, mailclient-taugliche PNG-Icons. Standalone-HTML verwendet sie
     * als Data-URI, EML-Dateien als CID-Anhang. So bleiben die Kontaktdaten
     * ohne Webfonts, externe Requests oder SVG-Support verständlich.
     *
     * @var array<string, string>
     */
    private const CONTACT_ICON_PNG = [
        'phone' => 'iVBORw0KGgoAAAANSUhEUgAAABYAAAAWCAYAAADEtGw7AAAAnElEQVR42r2V0RGAIAiGkXOL2iD3n6Q2yDnsqTslUEyUt37145c8AJgUjgo3HOkPaIerYKEFlDuLFlCOgVZQCveazVs6P1p0odCjCyDWWAvldPrttdDckZRMBX4P0yvmWi0B9ly/J6o15txK63SvH3UmJcdZvQJr9R2p9TrHVi9knWP6l1tPrtnorTrc2/BRmgCjUwRr42VkNE2LB+gZOyfJkRL1AAAAAElFTkSuQmCC',
        'mobile' => 'iVBORw0KGgoAAAANSUhEUgAAABYAAAAWCAYAAADEtGw7AAAAeUlEQVR42mNgoBFgRBd4wqD9nxyDZBiuopjFRA1Dsellooah2MxgJGSo9P8rOA16yqiDM1iY8LkAn6GE5JlolSpYiFGEzcuEfEMzF48aPGrwYDWYUKYgOechG0is4UzYCmlCpRc+eZhZjNQq6NEdyISveqGkaqIZAACVwSb0g/w2/wAAAABJRU5ErkJggg==',
        'email' => 'iVBORw0KGgoAAAANSUhEUgAAABYAAAAWCAYAAADEtGw7AAAAnUlEQVR42rWV2w3AIAhFkbCF3aDdfxK7QTuH/bKxBCn44MuYcHJ56AVYFIFfXLDnHtAG54eFM6BSLs6ASgycBeVwXDU8ki5jTm7QHQ5oDq8XKuWRV4lVBHqVWCtDi9KY0wuqz1pFZGlDDbW0yLxuHPIHde1xgVmg7gdihao97t3npmKPKi2PRktWFfNPeiQKC1sOMOoiqNnLiDUtiwe2SD1EB3bXVQAAAABJRU5ErkJggg==',
        'web' => 'iVBORw0KGgoAAAANSUhEUgAAABYAAAAWCAYAAADEtGw7AAAAtElEQVR42rWV2xGEIAxFkwxdaAdL/5VoB1IH++WOi3lcFDLjz3U4hDyJJhm3wkGf+gS00v7HkhFQ7ayMgGoMRqBL3W5a4eyGJfUC23/WBYJCC+ffh1wuEdTy6KprcPFCUTjTUrcb3NJdcBQ7y/PWa5nVeQlJnpUgr2oS8kQtLFe9O3lvTNBk9DaKRIfO0kL0EBwVP9JE7hBCwqFBV9qZkbHZM93Ogc+jBn27RcRbL29W0zT7AoV0ZxDP41WLAAAAAElFTkSuQmCC',
    ];

    public function __construct(protected User $user) {}

    /**
     * Verfuegbare Downloads samt stabiler Gruppierungsmetadaten fuer die UI.
     *
     * @return array<string, array{
     *     label: string,
     *     hint: string,
     *     extension: string,
     *     category: 'mail'|'signature',
     *     theme: 'light'|'dark'|'neutral',
     *     format: 'eml'|'html'|'txt',
     *     previewable: bool
     * }>
     */
    public static function available(): array
    {
        return [
            'vorlage-eml' => [
                'label' => 'app.email_template_mail_eml',
                'hint' => 'app.email_template_mail_eml_hint',
                'extension' => 'eml',
                'category' => 'mail',
                'theme' => 'light',
                'format' => 'eml',
                'previewable' => false,
            ],
            'vorlage-html' => [
                'label' => 'app.email_template_mail_html',
                'hint' => 'app.email_template_mail_html_hint',
                'extension' => 'html',
                'category' => 'mail',
                'theme' => 'light',
                'format' => 'html',
                'previewable' => true,
            ],
            'vorlage-dunkel-eml' => [
                'label' => 'app.email_template_mail_eml',
                'hint' => 'app.email_template_mail_eml_hint',
                'extension' => 'eml',
                'category' => 'mail',
                'theme' => 'dark',
                'format' => 'eml',
                'previewable' => false,
            ],
            'vorlage-dunkel-html' => [
                'label' => 'app.email_template_mail_html',
                'hint' => 'app.email_template_mail_html_hint',
                'extension' => 'html',
                'category' => 'mail',
                'theme' => 'dark',
                'format' => 'html',
                'previewable' => true,
            ],
            'signatur-dunkel' => [
                'label' => 'app.email_template_signature_dark',
                'hint' => 'app.email_template_signature_dark_hint',
                'extension' => 'html',
                'category' => 'signature',
                'theme' => 'dark',
                'format' => 'html',
                'previewable' => false,
            ],
            'signatur-hell' => [
                'label' => 'app.email_template_signature_light',
                'hint' => 'app.email_template_signature_light_hint',
                'extension' => 'html',
                'category' => 'signature',
                'theme' => 'light',
                'format' => 'html',
                'previewable' => false,
            ],
            'signatur-text' => [
                'label' => 'app.email_template_signature_text',
                'hint' => 'app.email_template_signature_text_hint',
                'extension' => 'txt',
                'category' => 'signature',
                'theme' => 'neutral',
                'format' => 'txt',
                'previewable' => false,
            ],
        ];
    }

    /**
     * @return array{filename: string, mime: string, content: string}
     */
    public function build(string $template): array
    {
        $slug = Str::slug($this->user->name) ?: 'mitarbeiter';

        return match ($template) {
            'vorlage-eml' => [
                'filename' => "RailTime-E-Mailvorlage-hell-{$slug}.eml",
                'mime' => 'message/rfc822',
                'content' => $this->buildEml('light'),
            ],
            'vorlage-html' => [
                'filename' => "RailTime-E-Mailvorlage-hell-{$slug}.html",
                'mime' => 'text/html; charset=UTF-8',
                'content' => $this->buildEmailHtml(inlineImages: true, theme: 'light'),
            ],
            'vorlage-dunkel-eml' => [
                'filename' => "RailTime-E-Mailvorlage-dunkel-{$slug}.eml",
                'mime' => 'message/rfc822',
                'content' => $this->buildEml('dark'),
            ],
            'vorlage-dunkel-html' => [
                'filename' => "RailTime-E-Mailvorlage-dunkel-{$slug}.html",
                'mime' => 'text/html; charset=UTF-8',
                'content' => $this->buildEmailHtml(inlineImages: true, theme: 'dark'),
            ],
            'signatur-dunkel' => [
                'filename' => "RailTime-Signatur-dunkel-{$slug}.html",
                'mime' => 'text/html; charset=UTF-8',
                'content' => $this->buildSignature('signature-dark-master.html', 'logo-signature-dark.png'),
            ],
            'signatur-hell' => [
                'filename' => "RailTime-Signatur-hell-{$slug}.html",
                'mime' => 'text/html; charset=UTF-8',
                'content' => $this->buildSignature('signature-light-master.html', 'logo-signature-light.png'),
            ],
            'signatur-text' => [
                'filename' => "RailTime-Signatur-{$slug}.txt",
                'mime' => 'text/plain; charset=UTF-8',
                'content' => $this->buildPlainSignature(),
            ],
            default => abort(404),
        };
    }

    /**
     * Personalisierungswerte des Benutzers (fuer Vorlagen und Vorschau).
     *
     * @return array<string, string>
     */
    public function profileValues(): array
    {
        $profile = $this->user->profile;
        $companyValues = CompanyData::templateValues();
        $website = $companyValues['FIRMEN_WEBSITE'];
        $phone = trim((string) ($profile?->phone ?? ''));
        $mobile = trim((string) ($profile?->mobile ?? ''));

        return array_merge($companyValues, [
            'VORNAME_NACHNAME' => $this->user->name,
            'POSITION' => $profile?->position ?: $this->fallbackPosition(),
            'DURCHWAHL' => $phone,
            'DURCHWAHL_TEL' => $this->telHref($phone),
            'MOBIL' => $mobile,
            'MOBIL_TEL' => $this->telHref($mobile),
            'E_MAIL' => $this->user->email,
            'FIRMEN_WEBSITE_HREF' => $this->webHref($website),
            'FIRMEN_WEBSITE_LABEL' => $this->webLabel($website),
        ]);
    }

    /**
     * Ohne gepflegte Funktion: Name des (nicht persoenlichen) Teams,
     * ansonsten neutral der Firmenname.
     */
    protected function fallbackPosition(): string
    {
        $teamName = $this->user->teams()
            ->where('personal_team', false)
            ->orderBy('name')
            ->value('teams.name');

        return $teamName ?: CompanyData::all()['name'];
    }

    protected function telHref(?string $number): string
    {
        // "(0)" ist die eingeklammerte Trunk-Null ("+49 (0) 4171 …") und
        // gehoert nicht in die internationale Wahlnummer.
        $number = str_replace('(0)', '', (string) $number);
        $digits = preg_replace('/[^\d+]/', '', $number) ?? '';

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return '+49'.substr($digits, 1);
        }

        return $digits;
    }

    protected function webHref(?string $website): string
    {
        $website = trim((string) $website);

        if ($website === '') {
            return '';
        }

        if (! preg_match('~^https?://~i', $website)) {
            $website = 'https://'.ltrim($website, '/');
        }

        return filter_var($website, FILTER_VALIDATE_URL) ? $website : '';
    }

    protected function webLabel(?string $website): string
    {
        $href = $this->webHref($website);

        if ($href === '') {
            return '';
        }

        return rtrim((string) preg_replace('~^https?://(?:www\.)?~i', '', $href), '/');
    }

    protected function masterPath(string $file): string
    {
        return resource_path('mail-templates/'.$file);
    }

    protected function substitute(string $template, array $values): string
    {
        foreach ($values as $key => $value) {
            $template = str_replace('{{'.$key.'}}', $value, $template);
        }

        return $template;
    }

    /**
     * Profilwerte fuer HTML-Kontexte escapen — Werte wie der Team-Name
     * (POSITION-Fallback) stammen nicht zwingend vom Benutzer selbst.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    protected function escapeForHtml(array $values): array
    {
        return array_map(
            fn (string $value) => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            $values
        );
    }

    /** Entfernt vollstaendige optionale Kontaktzeilen ueber stabile Marker. */
    protected function stripEmptyContactRows(string $html, array $values): string
    {
        foreach ([
            'PHONE' => 'DURCHWAHL',
            'MOBILE' => 'MOBIL',
            'WEBSITE' => 'FIRMEN_WEBSITE_HREF',
        ] as $marker => $valueKey) {
            if (($values[$valueKey] ?? '') !== '') {
                continue;
            }

            $html = preg_replace(
                '/\s*<!-- RT_'.$marker.'_START -->.*?<!-- RT_'.$marker.'_END -->\s*/s',
                '',
                $html
            ) ?? $html;
        }

        return preg_replace(
            '/<!-- RT_(?:PHONE|MOBILE|WEBSITE)_(?:START|END) -->/',
            '',
            $html
        ) ?? $html;
    }

    protected function inlineImage(string $asset, string $mime): string
    {
        return "data:{$mime};base64,".base64_encode(file_get_contents($this->masterPath('assets/'.$asset)));
    }

    /**
     * @return array<string, string>
     */
    protected function contactIconSources(bool $inlineImages): array
    {
        $sources = [];

        foreach (self::CONTACT_ICON_PNG as $name => $base64) {
            $sources['ICON_'.strtoupper($name).'_SRC'] = $inlineImages
                ? 'data:image/png;base64,'.$base64
                : 'cid:railtime-icon-'.$name;
        }

        return $sources;
    }

    /**
     * @return array<string, string>
     */
    protected function emailThemeValues(string $theme): array
    {
        return match ($theme) {
            'dark' => [
                'THEME' => 'dark',
                'THEME_LABEL' => 'Dunkel',
                'COLOR_SCHEME' => 'dark',
                'PAGE_BG' => '#070a0e',
                'SURFACE_BG' => '#111820',
                'CARD_BG' => '#18212b',
                'SOFT_BG' => '#202a35',
                'TEXT_PRIMARY' => '#f7f8fa',
                'TEXT_SECONDARY' => '#c3ccd6',
                'TEXT_MUTED' => '#8f9baa',
                'BORDER' => '#303944',
            ],
            default => [
                'THEME' => 'light',
                'THEME_LABEL' => 'Hell',
                'COLOR_SCHEME' => 'light',
                'PAGE_BG' => '#e7eaed',
                'SURFACE_BG' => '#f4f2ed',
                'CARD_BG' => '#ffffff',
                'SOFT_BG' => '#eef1f3',
                'TEXT_PRIMARY' => '#111820',
                'TEXT_SECONDARY' => '#3f4852',
                'TEXT_MUTED' => '#89939e',
                'BORDER' => '#dfe3e6',
            ],
        };
    }

    protected function buildEmailHtml(bool $inlineImages, string $theme = 'light'): string
    {
        $values = $this->profileValues();
        $html = file_get_contents($this->masterPath('email-master.html'));
        $html = $this->stripEmptyContactRows($html, $values);
        $html = $this->substitute($html, $this->escapeForHtml($values));
        $html = $this->substitute($html, $this->emailThemeValues($theme));

        return $this->substitute($html, array_merge(
            ['LOGO_SRC' => $inlineImages
                ? $this->inlineImage('logo-mail-dark.png', 'image/png')
                : 'cid:railtime-logo'],
            $this->contactIconSources($inlineImages)
        ));
    }

    protected function buildSignature(string $master, string $logo): string
    {
        $values = $this->profileValues();
        $html = file_get_contents($this->masterPath($master));
        $html = $this->stripEmptyContactRows($html, $values);
        $html = $this->substitute($html, $this->escapeForHtml($values));

        return $this->substitute($html, array_merge(
            ['LOGO_SRC' => $this->inlineImage($logo, 'image/png')],
            $this->contactIconSources(true)
        ));
    }

    protected function buildPlainBody(): string
    {
        $values = $this->profileValues();

        $phoneParts = array_filter([
            $values['DURCHWAHL'] !== '' ? "T {$values['DURCHWAHL']}" : null,
            $values['MOBIL'] !== '' ? "M {$values['MOBIL']}" : null,
        ]);
        $phoneLine = $phoneParts === [] ? '' : implode(' · ', $phoneParts)."\n";

        return <<<TEXT
{{ANREDE}},

{{KURZE_EINLEITUNG}}

{{NACHRICHT}}

EINSATZDATEN / OPTIONAL
Einsatzort: {{EINSATZORT}}
Zeitraum: {{ZEITRAUM}}
Leistung: {{LEISTUNG}}
Ansprechpartner: {{ANSPRECHPARTNER}}

{{CTA_TEXT}}: {{CTA_URL}}

Freundliche Grüße
{$values['VORNAME_NACHNAME']}
{$values['POSITION']}

{$values['FIRMENNAME']}
{$values['FIRMENSTRASSE']} · {$values['FIRMEN_PLZ_ORT']}
{$phoneLine}E {$values['E_MAIL']}
Notfalldienst 24/7: {$values['NOTFALLNUMMER']}

Geschäftsführung: {$values['GESCHAEFTSFUEHRUNG']}
Registergericht: {$values['REGISTERGERICHT']} · HRB {$values['HRB']}
USt-IdNr.: {$values['UST_ID']} · Steuernummer: {$values['STEUERNUMMER']}
TEXT;
    }

    protected function buildPlainSignature(): string
    {
        $values = $this->profileValues();

        $contactLines = implode('', array_filter([
            $values['DURCHWAHL'] !== '' ? "T {$values['DURCHWAHL']}\n" : null,
            $values['MOBIL'] !== '' ? "M {$values['MOBIL']}\n" : null,
        ]));

        return <<<TEXT
{$values['VORNAME_NACHNAME']}
{$values['POSITION']}

{$values['FIRMENNAME']}
{$values['FIRMENSTRASSE']}
{$values['FIRMEN_PLZ_ORT']}

{$contactLines}E {$values['E_MAIL']}
Notfalldienst 24/7: {$values['NOTFALLNUMMER']}
Zentrale E-Mail: {$values['FIRMEN_EMAIL']}

Geschäftsführung: {$values['GESCHAEFTSFUEHRUNG']}
Registergericht: {$values['REGISTERGERICHT']} · HRB {$values['HRB']}
USt-IdNr.: {$values['UST_ID']} · Steuernummer: {$values['STEUERNUMMER']}
TEXT;
    }

    protected function mimeHeaderValue(string $value): string
    {
        $value = trim(preg_replace('/[\r\n]+/', ' ', $value) ?? '');

        return preg_match('/[^\x20-\x7E]/', $value)
            ? '=?UTF-8?B?'.base64_encode($value).'?='
            : $value;
    }

    /**
     * Importierbare MIME-Mail (Text- und HTML-Teil, Logo/Hero als CID-Bilder).
     * "X-Unsent: 1" laesst Outlook die Datei direkt als Entwurf oeffnen.
     */
    protected function buildEml(string $theme): string
    {
        $values = $this->profileValues();
        $altBoundary = '=_rt_alt_'.Str::random(24);
        $relBoundary = '=_rt_rel_'.Str::random(24);

        // Anzeigename RFC-5322-konform aufbereiten: Zeilenumbrueche duerfen
        // nie in einen Header gelangen; Nicht-ASCII wird RFC-2047-kodiert,
        // ASCII mit Sonderzeichen (Komma, Klammern, …) in DQUOTEs gesetzt.
        $fromName = trim(preg_replace('/[\r\n]+/', ' ', $values['VORNAME_NACHNAME']));
        if (! mb_check_encoding($fromName, 'ASCII')) {
            $fromName = mb_encode_mimeheader($fromName, 'UTF-8', 'B');
        } elseif (! preg_match('/^[A-Za-z0-9 ._-]+$/', $fromName)) {
            $fromName = '"'.addcslashes($fromName, '\\"').'"';
        }

        $fromEmail = trim(preg_replace('/[\r\n]+/', '', $values['E_MAIL']));
        $companyName = trim(preg_replace('/[\r\n]+/', ' ', $values['FIRMENNAME']));
        $subject = $this->mimeHeaderValue("{{BETREFF}} | {$companyName}");
        $plain = chunk_split(base64_encode($this->buildPlainBody()), 76, "\r\n");
        $html = chunk_split(base64_encode($this->buildEmailHtml(inlineImages: false, theme: $theme)), 76, "\r\n");

        $lines = [
            'MIME-Version: 1.0',
            "Subject: {$subject}",
            "From: {$fromName} <{$fromEmail}>",
            'To: {{EMPFAENGER_E_MAIL}}',
            'X-Unsent: 1',
            "X-RailTime-Theme: {$theme}",
            "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"",
            '',
            "--{$altBoundary}",
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim($plain, "\r\n"),
            "--{$altBoundary}",
            "Content-Type: multipart/related; boundary=\"{$relBoundary}\"; type=\"text/html\"",
            '',
            "--{$relBoundary}",
            'Content-Type: text/html; charset=utf-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim($html, "\r\n"),
        ];

        $inlineImages = [
            'railtime-logo' => [
                'filename' => 'logo-mail-dark.png',
                'content' => file_get_contents($this->masterPath('assets/logo-mail-dark.png')),
            ],
        ];

        foreach (self::CONTACT_ICON_PNG as $name => $base64) {
            $inlineImages['railtime-icon-'.$name] = [
                'filename' => "contact-{$name}.png",
                'content' => base64_decode($base64, true) ?: '',
            ];
        }

        foreach ($inlineImages as $contentId => $image) {
            array_push(
                $lines,
                "--{$relBoundary}",
                "Content-Type: image/png; name=\"{$image['filename']}\"",
                'Content-Transfer-Encoding: base64',
                "Content-ID: <{$contentId}>",
                "Content-Disposition: inline; filename=\"{$image['filename']}\"",
                '',
                rtrim(chunk_split(base64_encode($image['content']), 76, "\r\n"), "\r\n")
            );
        }

        array_push($lines, "--{$relBoundary}--", "--{$altBoundary}--", '');

        return implode("\r\n", $lines);
    }
}
