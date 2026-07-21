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
    public function __construct(protected User $user) {}

    /**
     * Verfuegbare Downloads: key => [label-/hint-Sprachschluessel, Endung].
     *
     * @return array<string, array{label: string, hint: string, extension: string}>
     */
    public static function available(): array
    {
        return [
            'vorlage-eml' => [
                'label' => 'app.email_template_mail_eml',
                'hint' => 'app.email_template_mail_eml_hint',
                'extension' => 'eml',
            ],
            'vorlage-html' => [
                'label' => 'app.email_template_mail_html',
                'hint' => 'app.email_template_mail_html_hint',
                'extension' => 'html',
            ],
            'signatur-dunkel' => [
                'label' => 'app.email_template_signature_dark',
                'hint' => 'app.email_template_signature_dark_hint',
                'extension' => 'html',
            ],
            'signatur-hell' => [
                'label' => 'app.email_template_signature_light',
                'hint' => 'app.email_template_signature_light_hint',
                'extension' => 'html',
            ],
            'signatur-text' => [
                'label' => 'app.email_template_signature_text',
                'hint' => 'app.email_template_signature_text_hint',
                'extension' => 'txt',
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
                'filename' => "RailTime-E-Mailvorlage-{$slug}.eml",
                'mime' => 'message/rfc822',
                'content' => $this->buildEml(),
            ],
            'vorlage-html' => [
                'filename' => "RailTime-E-Mailvorlage-{$slug}.html",
                'mime' => 'text/html; charset=UTF-8',
                'content' => $this->buildEmailHtml(inlineImages: true),
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

        return array_merge(CompanyData::templateValues(), [
            'VORNAME_NACHNAME' => $this->user->name,
            'POSITION' => $profile?->position ?: $this->fallbackPosition(),
            'DURCHWAHL' => (string) ($profile?->phone ?? ''),
            'DURCHWAHL_TEL' => $this->telHref($profile?->phone),
            'MOBIL' => (string) ($profile?->mobile ?? ''),
            'MOBIL_TEL' => $this->telHref($profile?->mobile),
            'E_MAIL' => $this->user->email,
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

    /**
     * Entfernt die Telefon- bzw. Mobil-Zeile aus den HTML-Mastern,
     * wenn der Benutzer die jeweilige Nummer nicht gepflegt hat.
     */
    protected function stripEmptyContactRows(string $html, array $values): string
    {
        if ($values['DURCHWAHL'] === '') {
            $html = preg_replace('/^[ \t]*T&nbsp;.*?\{\{DURCHWAHL\}\}<\/a><br>[ \t]*\r?\n/m', '', $html);
        }

        if ($values['MOBIL'] === '') {
            $html = preg_replace('/^[ \t]*M&nbsp;.*?\{\{MOBIL\}\}<\/a><br>[ \t]*\r?\n/m', '', $html);
        }

        return $html;
    }

    protected function inlineImage(string $asset, string $mime): string
    {
        return "data:{$mime};base64,".base64_encode(file_get_contents($this->masterPath('assets/'.$asset)));
    }

    protected function buildEmailHtml(bool $inlineImages): string
    {
        $values = $this->profileValues();
        $html = file_get_contents($this->masterPath('email-master.html'));
        $html = $this->stripEmptyContactRows($html, $values);
        $html = $this->substitute($html, $this->escapeForHtml($values));

        return $this->substitute($html, $inlineImages
            ? [
                'LOGO_SRC' => $this->inlineImage('logo-mail-dark.png', 'image/png'),
                'HERO_SRC' => $this->inlineImage('hero-railtime.jpg', 'image/jpeg'),
            ]
            : [
                'LOGO_SRC' => 'cid:railtime-logo',
                'HERO_SRC' => 'cid:railtime-hero',
            ]);
    }

    protected function buildSignature(string $master, string $logo): string
    {
        $values = $this->profileValues();
        $html = file_get_contents($this->masterPath($master));
        $html = $this->stripEmptyContactRows($html, $values);
        $html = $this->substitute($html, $this->escapeForHtml($values));

        return $this->substitute($html, [
            'LOGO_SRC' => $this->inlineImage($logo, 'image/png'),
        ]);
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

    /**
     * Importierbare MIME-Mail (Text- und HTML-Teil, Logo/Hero als CID-Bilder).
     * "X-Unsent: 1" laesst Outlook die Datei direkt als Entwurf oeffnen.
     */
    protected function buildEml(): string
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

        $plain = chunk_split(base64_encode($this->buildPlainBody()), 76, "\r\n");
        $html = chunk_split(base64_encode($this->buildEmailHtml(inlineImages: false)), 76, "\r\n");
        $logo = chunk_split(base64_encode(file_get_contents($this->masterPath('assets/logo-mail-dark.png'))), 76, "\r\n");
        $hero = chunk_split(base64_encode(file_get_contents($this->masterPath('assets/hero-railtime.jpg'))), 76, "\r\n");

        $lines = [
            'MIME-Version: 1.0',
            "Subject: {{BETREFF}} | {$values['FIRMENNAME']}",
            "From: {$fromName} <{$values['E_MAIL']}>",
            'To: {{EMPFAENGER_E_MAIL}}',
            'X-Unsent: 1',
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
            "--{$relBoundary}",
            'Content-Type: image/png; name="logo-mail-dark.png"',
            'Content-Transfer-Encoding: base64',
            'Content-ID: <railtime-logo>',
            'Content-Disposition: inline; filename="logo-mail-dark.png"',
            '',
            rtrim($logo, "\r\n"),
            "--{$relBoundary}",
            'Content-Type: image/jpeg; name="hero-railtime.jpg"',
            'Content-Transfer-Encoding: base64',
            'Content-ID: <railtime-hero>',
            'Content-Disposition: inline; filename="hero-railtime.jpg"',
            '',
            rtrim($hero, "\r\n"),
            "--{$relBoundary}--",
            "--{$altBoundary}--",
            '',
        ];

        return implode("\r\n", $lines);
    }
}
