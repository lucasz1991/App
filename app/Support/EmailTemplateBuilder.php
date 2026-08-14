<?php

namespace App\Support;

use App\Enums\MailDocumentKind;
use App\Models\User;
use App\Support\Mail\PublishedMailDocumentSnapshotStore;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

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
     * Aus Font Awesome 6 (fa-solid-900) gerendert: Markenrot #e4002b auf
     * transparentem Grund, 44 px Kantenlaenge fuer scharfe Darstellung bei
     * 22 px Anzeigegroesse. Neu erzeugen mit scripts/build-contact-icons.py.
     *
     * @var array<string, string>
     */
    private const CONTACT_ICON_PNG = [
        'location' => 'iVBORw0KGgoAAAANSUhEUgAAACwAAAAsCAYAAAAehFoBAAAHQklEQVR42s2YTWhc1xXHf+fep9FIoy/jb1ux5OBUeKSagoo3xRm7hbbQD28yWRSX4I1bumwbWpeCY2jaXZcmlEA3oRCULpoEDN7IsikBU4Xi2CNkTGON7NpxRqkVSSPNzLv3dPHeWIo0M56R7KZ3Nbx35p7/O/d//+dD2OJSkMtkLMBxJpyAtvL+f7bOgVGydv3zPP0dnzHa+xmjvXn6OzZ+YNaeA7NZv7K5qGatMOYAHjCyW9BvKxx3MOLRvUBXbLpokPsWbghcVuTSHm58sn6PZwZYI3sR8P/i8EAK83PgRx3YHQJUUEIUH9sbIEBoQ1CgiCsI/GUJ/8fnmZrRyERboYm0AjY21rsM/6wNfpfCblvAE+JjbooAEn8YMRAFVQUJMLYbwxLuPxX4bT83L8T2NAtaWgE7TsYOUfhTD/Z0DDQEsdL8PgrqAkzQjeFz3J+n2XHmBBOuWdDSHA3OCVw29ym800dwco5KS0DrAd9OW/CI8G972fESHPdw/on0aOK2Zo1w3s9SeHMb9uQclYogwWbBxlESQYI5KpVt2JOzFN4UznvIPhGPaUYNZjj80+3YV+YIK4K0PWFPr+AUHDy+f3WAS9scYWU79pVZ0j8RxlwtqWyKEtUbfJvh/hTcNEiqgkq9yGoMrh0xidikjFJCfezI1KNHG6KKLoaY4X4+uldVohYjnBUBTcKverDdFbyvD1ZdCmM6MaaE5hfwVxfwV0tovhNjUhijqKtHjwred2N7wP864nBWWopwrAp6i0M7O0ncspjeEKUWYEVdL4Et4q953GtK58R+JosA9xjtFIoZg32tA3P0c0IniK0V5QDB4eeF8IX93CpUMTQV4Wru7yD5nR6CvrBOdBVcD9Yu4t8u4I/tY+rifiaLCkbB7GeyuI+piwX8sWX82z1YG3N7Q5RDvO8h6PMkvrsWQ4sqod+SOplIUdeJsUu4yWm2nxohV1YyQRwZL9HlEyUTjJArT7H91BJushNjqUGPqh+DfrNllTjOhIs20eEKKorUsBMRQPFnTzARKplAmAjXflwEInp3gomwgvwm+o/UOC0xkS+G12J4IuAqdz5mIKnI7pi7G6SrHTFL+PwS4ZUouUw0KGQmnIIcoDSxiJttjwLg11+mEEVhz8cMJAVUa9CwASX6kkCXXy16vnBJEggCd77C7RJ1LsjaSEc2t0uC3ElExdCGutlHtl2x79YTh6CbzmbPatUFnEQriqxIHe0sR8c3eItD7dQ5vnVlqSqH2hUdLNeRyIjflJJopWXAe7i+LFAIon3XH7cpoT6FOZAieDE68kyDlJqxApqnPdOFfS7Ofut9axDRrLCH68tNAxbQcTJBLEsfJhCVOL2u21+jktD8YTxWiKqsrY1sVT3GyQRt6O+j/6jWoJ9PIKrwoYCPMWiTsrZLY/B/DVHxNT9MbBHvUtjRIT59Sxltq8paNXFUZe0G6cQQc2+lsKNFvKNGtvNgQlQCeGcthmaLH1HgDgPtllSuAzO4gqrUlMFqanbXBD0X0nFlbWoOWH5RMec7MUfn66dmn0RkGX/HsZQeZKYkj181x2G9TMYeZGbFoBc6o2D5OiWinSd0CczRAHtRWcnNMnxlluErykouwF5MIHXBxu58J0YMeuEgMytxWtZWy0sBKDDUtYK9mcT014vyFstLn0RkBX83iRvewfQiDdol01jss2Yn0wuC/rI9inKj5GAETBn1C3i3gHdl1FefN6hXNIERgV/sZHoh6nAaJqHmZhB50u/1EXz/EaEzdY+2teVR10dgHxG+d4DcD5uZVTTThBpA73P4gMFcB+kKowCYreKNNF4Xi/gjB5nKN+o0mm5Cow2yZh9TMyX01a6oe/Bbn8mp74o6lFefZ2ompoJ/ioOUx9R4v4/ge1uhRpUK84TvP0fuB62MrVqZ/BhAH5LeDfJPkF3lBqrRYB+fQMSjDw36tV3kPmmGCi3MJVapMQZmN7kHK+iZBCKmSSfrHPoEImX0zG5yD8YiFfFbrtZqrZfBKZlggNy7C7g3+rCBr9MN16NCLzZYwL0xQO5dJRO8XKPHe6rjVgUZI2uOMZ1Uwsl27FAxalLNk6gQjQHctBCMXmVoJcuYb3XAHWxizBRXadeX7jJy2qNXLeCiJrLe2EAtqEedx5zu5/qSMmQ3M403m5uNjTklE/Rz44Mi7vVebM1OeA1k14u1Rdzr/dz4ICo5Wx9mb3oCv1prZA08lHsU/p7CHF3EbShwFHVdWLuEv7afHd+AXcomqLBlwFWpE/D/Jp0OMP9waCKMnsnqRAdvkXIR//VBcjltURWeCiXWSt04mWAfudwyerZ7AzXUdWPtMnp2kFyu2sl86Z1sdUQ6S/rSPEc0T7qSJ12Z54jOkr601marS54O4NUCSTAftWO6AUr4BcV/dW+Thc0zp8QXs2BUIJXRUyGaD9H8Mv7H+5iaGWuysPkSqBGd2DgDyXEGktSYGv0/gja1fj+t9V83I22R1AXIsQAAAABJRU5ErkJggg==',
        'phone' => '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',
        'mobile' => 'iVBORw0KGgoAAAANSUhEUgAAACwAAAAsCAYAAAAehFoBAAADAUlEQVR42u2Zv09TURTHP+e+19JGIGAAYxATjJFIZBISt+4yEAcm/gD/CRIX/wBH3cXFARlgJSQODmwioDEhImhEIk0stPDeu8fh9RHkR2lfWwLJu8lNl577/dzTe06T74Eql4LMk3Np8Jon5ypItd+XWgW+cLelm2vZPNAREzKK/c1u8R5f92uJlWoyK6DrPMqm+PvcgSce2qEgGuPCZVEV0BSSD2Dao23yNh+KkVa9wAaQ7wzO3CQ9+gefANDK51YBLTjAdVx+cjDbx/IY4UVsbGBl3BHeBj+4/7iN1Gwe3wNxUohpiZfcw7WP4qEWNOjATeXxRvtYmYs0z4o7p4i2BMAiw6Z8cxcxHvaXD8v1ACsMupgbPhoYsIIMA3ORZkzg6HAxgBHEyyLs4jzt5eNMPcCbDI1l4V0BsUCqrHHuMrUK2fAd7SgY5WEq/KxlhzECOzbGRU2c7AQEblgcd6xATTuKCQhi9XQTs8braxF1nGG4YisBToAT4AQ4AU6AE+AEOAFOgBPgBPiSAQvWhDbVlkSWVbU7ihGsuTBgH1MM/bEFP/LJqt9hjI8pxtF2Yxh5ODDyjaEVh4wJKNVkL0QxBjsizQZW1NnDIvDCIXim7Ipzwm0UBYKyE2nCxB49I4qRzj0sijpNzbAeBppOTrhCShqhlZChQMABYE4xDv2Y/mdsR91D9Vj2NYMxJXR7D28qLA6ZSCNdJawVRI49LeEigY8KKmgaIxbd8pBcP59WATZ58FLRhTSm20NjQzahD2vQihEfpvpZWl0jl1kjl+llaXUffdOKEdDgSvxxmIb4cDU+CUEtFa18cQpYdWFig4FXt1j4DLDB0EAKO1HAKkilbmDLGvUC92iYJV20oaHt66FF/P86wGoG0wPu+w0GX1sQsBOCdO2Xi05PDlxUEGvBVXTxqGZDhzJ6ptl9WlvTU9taJF7rUKaaJ6ECdp328e3y2MuvMPYqobZY/nkFNQomOHbFo2OvbbxpaJ8UsNrAwaJE/xlNHCwK0LgCvSyj238pMGor9Jn8ngAAAABJRU5ErkJggg==',
        'email' => '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',
        'web' => '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',
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
     *     format: 'eml'|'html'|'txt'|'zip',
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
            'signatur-outlook-dunkel' => [
                'label' => 'app.email_template_signature_outlook_dark',
                'hint' => 'app.email_template_signature_outlook_hint',
                'extension' => 'zip',
                'category' => 'signature',
                'theme' => 'dark',
                'format' => 'zip',
                'previewable' => false,
            ],
            'signatur-outlook-hell' => [
                'label' => 'app.email_template_signature_outlook_light',
                'hint' => 'app.email_template_signature_outlook_hint',
                'extension' => 'zip',
                'category' => 'signature',
                'theme' => 'light',
                'format' => 'zip',
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
                'content' => $this->buildSignature('signature-dark-master.html', 'wortmarke-signature-dark.gif'),
            ],
            'signatur-hell' => [
                'filename' => "RailTime-Signatur-hell-{$slug}.html",
                'mime' => 'text/html; charset=UTF-8',
                'content' => $this->buildSignature('signature-light-master.html', 'wortmarke-signature-light.gif'),
            ],
            'signatur-outlook-dunkel' => [
                'filename' => "RailTime-Outlook-Signatur-dunkel-{$slug}.zip",
                'mime' => 'application/zip',
                'content' => $this->buildOutlookPackage('dark', $slug),
            ],
            'signatur-outlook-hell' => [
                'filename' => "RailTime-Outlook-Signatur-hell-{$slug}.zip",
                'mime' => 'application/zip',
                'content' => $this->buildOutlookPackage('light', $slug),
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
     * Baut die geschuetzte HTML-Vorschau mit einer technisch eindeutigen,
     * einmalig abspielenden Dampflok-Animation.
     *
     * @return array{filename: string, mime: string, content: string}
     */
    public function buildPreview(string $template, string $playbackNonce): array
    {
        $file = $this->build($template);
        $theme = match ($template) {
            'vorlage-html' => 'light',
            'vorlage-dunkel-html' => 'dark',
            default => abort(404),
        };

        $file['content'] = $this->buildEmailHtml(
            inlineImages: true,
            theme: $theme,
            animatedSignature: true,
            playbackNonce: $playbackNonce,
        );

        return $file;
    }

    /**
     * Bewegungsarme Vorschau: alle animierten Marken werden durch ihre
     * Standbilder ersetzt und die endlose Rauchfahne wird ausgelassen.
     * Download-Dateien bleiben davon vollständig unberührt.
     *
     * @return array{filename: string, mime: string, content: string}
     */
    public function buildStaticPreview(string $template): array
    {
        $file = $this->build($template);
        $theme = match ($template) {
            'vorlage-html' => 'light',
            'vorlage-dunkel-html' => 'dark',
            default => abort(404),
        };

        $file['content'] = $this->buildEmailHtml(
            inlineImages: true,
            theme: $theme,
            staticAnimations: true,
        );

        return $file;
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
            'DURCHWAHL_TEL' => self::telHref($phone),
            'MOBIL' => $mobile,
            'MOBIL_TEL' => self::telHref($mobile),
            'E_MAIL' => $this->user->email,
            // Firmenanschrift zeigt bewusst den Festnetzanschluss, nicht die
            // Notfall-Mobilnummer. Ohne gepflegten Anschluss entfaellt die Zeile.
            'FIRMEN_TELEFON_TEL' => self::telHref($companyValues['FIRMEN_TELEFON']),
            'FIRMEN_WEBSITE_HREF' => self::webHref($website),
            'FIRMEN_WEBSITE_LABEL' => self::webLabel($website),
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

    public static function telHref(?string $number): string
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

    public static function webHref(?string $website): string
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

    public static function webLabel(?string $website): string
    {
        $href = self::webHref($website);

        if ($href === '') {
            return '';
        }

        return rtrim((string) preg_replace('~^https?://(?:www\.)?~i', '', $href), '/');
    }

    public static function masterPath(string $file): string
    {
        return resource_path('mail-templates/'.$file);
    }

    /**
     * Veroeffentlichte Fassung eines Maildokuments — oder null.
     *
     * null ist der Normalfall und heisst: es gilt die heutige Blade-/Master-
     * Quelle. Dasselbe gilt, wenn die Tabelle noch fehlt (Migration nicht
     * eingespielt, Minimalschema in Pruefungen) oder die Datenbank nicht
     * antwortet — an einem noch nicht eingerichteten Editor darf ein
     * Download nicht scheitern.
     */
    public static function publishedDocument(MailDocumentKind $kind): ?string
    {
        $snapshot = self::publishedDocumentSnapshot($kind);

        if ($snapshot === null) {
            return null;
        }

        return $kind === MailDocumentKind::Template
            ? self::embedPublishedCss($snapshot['html'], $snapshot['css'], $kind)
            : $snapshot['html'];
    }

    /**
     * HTML und CSS werden in EINER Abfrage gelesen. Andernfalls koennte ein
     * paralleler Freigabevorgang HTML aus Version A mit CSS aus Version B
     * kombinieren.
     *
     * @return array{html: string, css: string}|null
     */
    public static function publishedDocumentSnapshot(MailDocumentKind $kind): ?array
    {
        return app(PublishedMailDocumentSnapshotStore::class)->snapshot($kind);
    }

    public static function publishedDocumentCss(MailDocumentKind $kind): string
    {
        return self::publishedDocumentSnapshot($kind)['css'] ?? '';
    }

    /**
     * Auslieferbarer Datenbankstand für produktive Ausgabewege.
     *
     * Vor der Migration darf die ausgelieferte Masterdatei einspringen. Ist
     * die Tabelle vorhanden, aber der veröffentlichte Stand fehlt, brechen
     * wir bewusst ab: Eine Systemmail darf dann weder einen Entwurf noch
     * heimlich eine andere Codefassung versenden.
     */
    public static function runtimeDocument(
        MailDocumentKind $kind,
        bool $requirePublished = false,
    ): ?string {
        $published = self::publishedDocument($kind);
        if ($published !== null) {
            return $published;
        }

        try {
            $tableExists = Schema::hasTable('mail_documents');
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Der veröffentlichte Maildokument-Stand konnte nicht geprüft werden.',
                0,
                $exception,
            );
        }

        if (! $tableExists || ! $requirePublished) {
            return null;
        }

        throw new RuntimeException(
            "Das veröffentlichte Maildokument \"{$kind->label()}\" fehlt. Bitte den MailDocumentSeeder ausführen."
        );
    }

    /**
     * Verbindet die veröffentlichte Nachrichtenschale mit ausschließlich
     * bereits durch Laravels Mail-/Markdown-Komponenten gerendertem HTML.
     * Der Htmlable-Vertrag verhindert, dass Aufrufer versehentlich freien
     * Klartext als ungeprüftes HTML in den Anwendungsslot einsetzen.
     */
    public static function buildSystemMailHtml(Htmlable $applicationContent): string
    {
        $html = self::runtimeDocument(MailDocumentKind::Template, requirePublished: true)
            ?? (string) file_get_contents(self::masterPath('email-master.html'));
        $slot = '__RT_APPLICATION_CONTENT_'.Str::random(32).'__';
        $count = 0;
        $html = preg_replace(
            '/<!--\s*RT_APPLICATION_CONTENT_START\s*-->.*?<!--\s*RT_APPLICATION_CONTENT_END\s*-->/s',
            $slot,
            $html,
            1,
            $count,
        ) ?? $html;

        if ($count !== 1 || substr_count($html, $slot) !== 1) {
            throw new RuntimeException(
                'Die veröffentlichte E-Mail-Vorlage besitzt keinen eindeutigen Anwendungsslot.'
            );
        }

        $signature = MailSignature::forCompany(
            theme: 'light',
            animated: true,
            remoteAssets: true,
        );
        $values = $signature->values();
        $values['RESPONSIVE_CSS'] = self::responsiveCss($values['SIGNATURE_BORDER'] ?? null);
        // Moderne Clients behalten den Zug im oberen CSS-Carrier. Classic
        // Outlook erhaelt serverseitig genau ein bedingtes regulaeres GIF;
        // das fruehere background-Attribut kachelte Word ueber die Zelle.
        $values['SIGNATURE_BLOCK'] = $signature->render();
        $values['APPLICATION_CONTENT'] = '';

        foreach ($values as $key => $value) {
            $html = str_replace('{{'.$key.'}}', $value, $html);
        }

        $trusted = $applicationContent->toHtml();
        $applicationRow = '<tr><td class="rt-pad" bgcolor="'.$values['SURFACE_BG'].'" '
            .'style="padding:0 36px 42px;background:'.$values['SURFACE_BG'].';'
            .'font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:27px;'
            .'color:'.$values['TEXT_SECONDARY'].';text-align:left;">'
            .$trusted
            .'</td></tr>';
        $html = str_replace($slot, $applicationRow, $html);

        return self::embedPublishedCss(
            $html,
            $signature->publishedCss(),
            MailDocumentKind::Signature,
        );
    }

    private static function embedPublishedCss(string $html, string $css, MailDocumentKind $kind): string
    {
        if ($css === '' || stripos($css, '</style') !== false) {
            return $html;
        }

        $style = '<style data-rt-mail-document-css="'.$kind->value.'">'.$css.'</style>';

        if (preg_match('/<\/head\s*>/i', $html) === 1) {
            return (string) preg_replace('/<\/head\s*>/i', $style.'</head>', $html, 1);
        }

        // Nie einen style-Block in ein <tr>-Fragment legen: innerhalb der
        // umgebenden Tabelle waere er strukturell ungueltig. Die drei Wrapper
        // binden Signatur-CSS stattdessen in ihrem echten <head> ein.
        return $html;
    }

    /**
     * Der Signaturblock: veroeffentlichte Fassung, sonst die Blade-Quelle.
     *
     * MailSignature besitzt den eigentlichen Vertrag: regulaere Downloads
     * uebernehmen die Publikation mit ihren kompakten Starterabstaenden,
     * waehrend nur der strukturell besondere Outlook-Export auf Blade
     * zurueckfaellt.
     *
     * @param  array<string, string>  $layout
     * @param  array<string, string>  $overrides
     */
    protected function signatureBlock(
        MailSignature $signature,
        array $layout = [],
        array $overrides = [],
    ): string {
        return $signature->render($layout, $overrides);
    }

    /**
     * Umbruchregeln fuer Vorlage UND Signatur aus einer einzigen Quelle.
     *
     * Vorher stand derselbe Media-Query-Block viermal im Projekt und war
     * bereits auseinandergelaufen (Vorlage 680 px, Signatur 620 px) — in
     * einer Mail, die beides enthaelt, sprang das Layout dadurch an zwei
     * verschiedenen Breiten.
     */
    public static function responsiveCss(?string $border = null): string
    {
        return trim(view('emails.parts.responsive-css', [
            'border' => $border ?: '#e6e8ec',
        ])->render());
    }

    /**
     * Setzt Platzhalter ein und loest dabei {{RESPONSIVE_CSS}} auf.
     *
     * ACHTUNG BEI DER REIHENFOLGE: Die Umbruchregeln brauchen die Farbe der
     * Trennlinie, und aufgeloest werden sie im ERSTEN Durchgang, der auf
     * {{RESPONSIVE_CSS}} trifft. Wer mehrfach substituiert, muss
     * SIGNATURE_BORDER also schon im ersten Aufruf mitgeben — sonst greift
     * still die Ersatzfarbe und die gestapelte Trennlinie steht im dunklen
     * Motiv hellgrau auf fast schwarzem Grund.
     */
    protected function substitute(string $template, array $values): string
    {
        $values['RESPONSIVE_CSS'] ??= static::responsiveCss($values['SIGNATURE_BORDER'] ?? null);

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
    public static function stripEmptyContactRows(string $html, array $values): string
    {
        foreach ([
            'PHONE' => 'DURCHWAHL',
            'MOBILE' => 'MOBIL',
            'WEBSITE' => 'FIRMEN_WEBSITE_HREF',
            'COMPANY_PHONE' => 'FIRMEN_TELEFON',
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
            '/<!-- RT_(?:PHONE|MOBILE|WEBSITE|COMPANY_PHONE)_(?:START|END) -->/',
            '',
            $html
        ) ?? $html;
    }

    public static function inlineImage(string $asset, string $mime, ?string $playbackNonce = null): string
    {
        $binary = file_get_contents(self::masterPath('assets/'.$asset));
        if ($binary === false) {
            throw new RuntimeException("Mailasset konnte nicht gelesen werden: {$asset}");
        }

        if ($mime === 'image/gif' && $playbackNonce !== null) {
            $binary = self::withGifPlaybackNonce($binary, $playbackNonce);
        }

        return "data:{$mime};base64,".base64_encode($binary);
    }

    /**
     * Verändert ausschließlich die technische GIF-Identität. Bilddaten,
     * Timing, Disposal und Loop-Vertrag bleiben bytegleich; Browser laden
     * Logo, RT-Zeichen, Zug und Rauchfahne dadurch bei Replay neu.
     */
    private static function withGifPlaybackNonce(string $binary, string $playbackNonce): string
    {
        $comment = 'RailTime-Playback:'.substr($playbackNonce, 0, 120);
        $trailer = strrpos($binary, "\x3b");

        if ($trailer === false || strlen($comment) > 255) {
            return $binary;
        }

        return substr($binary, 0, $trailer)
            ."\x21\xfe".chr(strlen($comment)).$comment."\x00"
            .substr($binary, $trailer);
    }

    /**
     * Absolute Adresse eines Signaturbildes unter public/mail-assets/.
     *
     * WARUM VERLINKT STATT EINGEBETTET — nur fuer VERSENDETE Mails:
     *
     * Als data:-URI erscheinen die Bilder bei vielen Empfaengern gar nicht.
     * Outlook-Desktop rendert mit der Word-Engine und kennt weder data:-URIs
     * in <img> noch CSS-Hintergrundbilder; Gmail entfernt data:-URIs in
     * Hintergrundangaben und kappt Nachrichten ab 102 kB — die eingebetteten
     * Bilder allein waren 104,6 kB, die Signatur stand also hinter dem
     * Schnitt.
     *
     * Verlinkt sind es normale Bilder, und das Mail schrumpft von rund
     * 105 kB auf wenige Kilobyte.
     *
     * Die HERUNTERLADBAREN Signaturen und Vorlagen behalten ihre data:-URIs:
     * die muessen eigenstaendig funktionieren, auch ohne Verbindung zu
     * diesem Server.
     */
    public static function mailAssetUrl(string $asset): string
    {
        // FINGERABDRUCK AN DIE ADRESSE. Ohne ihn zeigen Browser und
        // Mailclients nach einer Neuauslieferung weiter das ALTE Bild: Der
        // Dateiname bleibt gleich, und darauf allein stuetzt sich ihr
        // Zwischenspeicher. Genau das war der Grund, warum ein neu
        // gerechneter Zug in der Vorschau noch der alte war, obwohl die
        // Datei auf der Platte laengst neu war — es half auch kein
        // erneutes Seeden, weil die Dokumente nie das Problem waren.
        //
        // Der Wert ist die Aenderungszeit der Datei: er wechselt genau
        // dann, wenn sich das Bild wirklich geaendert hat, und bleibt
        // sonst stabil (wichtig, damit ein unveraendertes Bild nicht bei
        // jedem Aufruf neu geladen wird).
        $pfad = public_path('mail-assets/'.$asset);
        $stand = is_file($pfad) ? filemtime($pfad) : null;

        return URL::asset('mail-assets/'.$asset).($stand !== null ? '?v='.$stand : '');
    }

    /**
     * Zug als verlinkte Adresse. $animated waehlt das GIF, sonst das
     * Standbild.
     */
    public static function signatureTrainUrl(string $theme, bool $animated = false): string
    {
        $variant = $theme === 'dark' ? 'dark' : 'light';

        return self::mailAssetUrl("zug-dampf-{$variant}.".($animated ? 'gif' : 'png'));
    }

    /**
     * Standbild des Zuges fuer Outlook-Desktop.
     *
     * Outlook zeigt von einem animierten GIF nur das ERSTE Einzelbild — und
     * das ist bei der Einfahrt fast leer, weil der Zug dort noch am linken
     * Rand steht. Fuer den Ersatzweg ueber das background-Attribut wird
     * deshalb bewusst das Standbild in Ruhestellung verlinkt.
     */
    public static function signatureTrainStillUrl(string $theme): string
    {
        return self::signatureTrainUrl($theme, false);
    }

    /**
     * @return array<string, string>
     */
    public static function contactIconSources(bool $inlineImages): array
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
     * Kontaktsymbole als verlinkte Adressen — fuer versendete Mails.
     * Die Dateien liegen unter public/mail-assets/, erzeugt aus denselben
     * Konstanten wie die eingebettete Fassung.
     *
     * @return array<string, string>
     */
    public static function contactIconUrls(): array
    {
        $sources = [];

        foreach (array_keys(self::CONTACT_ICON_PNG) as $name) {
            $sources['ICON_'.strtoupper($name).'_SRC'] = self::mailAssetUrl("contact-{$name}.png");
        }

        return $sources;
    }

    /**
     * @return array<string, string>
     */
    public static function emailThemeValues(string $theme): array
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
                // Signatur sitzt eine Tonstufe ueber der Nachricht, die
                // Pflichtangaben eine Stufe darunter — dezente Staffelung
                // statt harter Kanten.
                'SIGNATURE_BG' => '#0c1017',
                // Kompatibilitaet fuer bereits publizierte Signaturen, die
                // noch eine Wash-Ebene enthalten. Die Zugassets tragen ihre
                // endgueltigen 30 Prozent Alpha selbst; ein weiterer Schleier
                // wuerde sie erneut auf rund 21 Prozent reduzieren.
                'SIGNATURE_TRAIN_WASH' => 'rgba(12,16,23,0)',
                'SIGNATURE_LEGAL_BG' => '#080b10',
                'SIGNATURE_TEXT_PRIMARY' => '#ffffff',
                'SIGNATURE_CONTACT_TEXT' => '#b9c1ca',
                'SIGNATURE_META_TEXT' => '#8e98a5',
                'SIGNATURE_TEXT_MUTED' => '#77818d',
                'SIGNATURE_LEGAL_TEXT' => '#77818d',
                'SIGNATURE_ACCENT' => '#ff5570',
                'SIGNATURE_BORDER' => '#313944',
                'SIGNATURE_RULE' => '#252c35',
            ],
            default => [
                'THEME' => 'light',
                'THEME_LABEL' => 'Hell',
                'COLOR_SCHEME' => 'light',
                // KEIN BEIGE. Die helle Fassung steht auf Weiss, die dunkle
                // auf dem dunklen Grund darunter — der warme Ton von frueher
                // (#f4f2ed) ist ersatzlos entfallen.
                'PAGE_BG' => '#eef1f4',
                'SURFACE_BG' => '#ffffff',
                'CARD_BG' => '#ffffff',
                'SOFT_BG' => '#f4f5f7',
                'TEXT_PRIMARY' => '#111820',
                'TEXT_SECONDARY' => '#3f4852',
                'TEXT_MUTED' => '#89939e',
                'BORDER' => '#dfe3e6',
                // Siehe dunkle Palette: Signatur heller Ton, Pflichtangaben
                // eine Stufe kraeftiger.
                // WEISS, nicht Beige. Der Zug traegt seinen Grund selbst;
                // er wurde dafuer auf Weiss umgesetzt (tools/zug-auf-weiss.mjs),
                // sonst stuende hier ein beiger Block auf weissem Grund.
                'SIGNATURE_BG' => '#ffffff',
                // Transparenter Kompatibilitaetswert fuer alte publizierte
                // Vier-Ebenen-Signaturen. Die endgueltigen 30 Prozent Alpha
                // sind bereits in Main-, Idle-, PNG- und Outlook-Asset
                // gebacken und duerfen hier nicht nochmals reduziert werden.
                'SIGNATURE_TRAIN_WASH' => 'rgba(255,255,255,0)',
                'SIGNATURE_LEGAL_BG' => '#f4f5f7',
                'SIGNATURE_TEXT_PRIMARY' => '#111820',
                'SIGNATURE_CONTACT_TEXT' => '#5c6671',
                'SIGNATURE_META_TEXT' => '#66717c',
                'SIGNATURE_TEXT_MUTED' => '#7b858f',
                'SIGNATURE_LEGAL_TEXT' => '#8a939d',
                'SIGNATURE_ACCENT' => '#e4002b',
                'SIGNATURE_BORDER' => '#dfe3e6',
                'SIGNATURE_RULE' => '#dfe3e6',
            ],
        };
    }

    /**
     * Das RT-Zeichen allein, fuer die rechte obere Ecke der Vorlage. Auf
     * dunklem Grund traegt der Balken des T einen hellen Ton — anthrazit
     * waere dort unsichtbar.
     */
    public static function emailMarkAsset(string $theme): string
    {
        return $theme === 'dark' ? 'icon-rt-dark.gif' : 'icon-rt-light.gif';
    }

    /** Der Schriftzug OHNE das RT-Zeichen — siehe MailSignature::values(). */
    protected function emailLogoAsset(string $theme): string
    {
        return $theme === 'dark'
            ? 'wortmarke-mail-dark.gif'
            : 'wortmarke-signature-light.gif';
    }

    protected function buildEmailHtml(
        bool $inlineImages,
        string $theme = 'light',
        bool $animatedSignature = false,
        ?string $playbackNonce = null,
        bool $staticAnimations = false,
    ): string {
        $values = $this->profileValues();
        // In einer migrierten Installation ist ausschließlich die
        // veröffentlichte Datenbankfassung auslieferbar. Nur vor Anlegen der
        // Tabelle greift der Bootstrap-Fallback auf die Masterdatei.
        $html = self::runtimeDocument(MailDocumentKind::Template)
            ?? (string) file_get_contents(self::masterPath('email-master.html'));
        // Motivfarben ZUERST: der erste Durchgang loest {{RESPONSIVE_CSS}}
        // auf und braucht dafuer SIGNATURE_BORDER (siehe substitute()).
        $html = $this->substitute($html, self::emailThemeValues($theme));
        $html = $this->substitute($html, $this->escapeForHtml($values));
        $html = $this->substitute($html, ['APPLICATION_CONTENT' => '']);

        // Das RT-Zeichen oben rechts. Es steht bewusst NUR hier: die
        // Signatur darunter traegt den Schriftzug, zusammen ergeben beide
        // die Marke einmal — nicht zweimal.
        $markAsset = self::emailMarkAsset($theme);
        if ($staticAnimations) {
            $markAsset = str_replace('.gif', '.png', $markAsset);
        }
        $markMime = str_ends_with($markAsset, '.gif') ? 'image/gif' : 'image/png';

        $html = $this->substitute($html, [
            'ICON_RT_SRC' => $inlineImages
                ? self::inlineImage($markAsset, $markMime, $playbackNonce)
                : 'cid:railtime-mark',
            // Standbild fuer Outlook-Desktop, siehe email-master.html.
            'ICON_RT_STILL_SRC' => $inlineImages
                ? self::inlineImage(str_replace('.gif', '.png', self::emailMarkAsset($theme)), 'image/png')
                : 'cid:railtime-mark-still',
        ]);

        // Signatur und Pflichtangaben kommen aus der gemeinsamen Quelle
        // (MailSignature) — dieselbe, die auch unter jeder Laravel-Mail steht.
        $signature = MailSignature::forUser(
            $this->user,
            $theme,
            animated: $animatedSignature,
            playbackNonce: $playbackNonce,
            staticAssets: $staticAnimations,
        );
        $logoAsset = $this->emailLogoAsset($theme);
        if ($staticAnimations) {
            $logoAsset = str_replace('.gif', '.png', $logoAsset);
        }
        $logoMime = str_ends_with($logoAsset, '.gif') ? 'image/gif' : 'image/png';
        $signatureOverrides = array_merge(
            [
                'LOGO_SRC' => $inlineImages
                    ? self::inlineImage($logoAsset, $logoMime, $playbackNonce)
                    : 'cid:railtime-logo',
            ],
            self::contactIconSources($inlineImages),
        );
        $html = $this->substitute($html, [
            'SIGNATURE_BLOCK' => $this->signatureBlock($signature, overrides: $signatureOverrides),
        ]);

        return self::embedPublishedCss(
            $html,
            $signature->publishedCss($signatureOverrides),
            MailDocumentKind::Signature,
        );
    }

    /**
     * Getoente Zug-Silhouette (Website-Motiv) als Data-URI.
     *
     * Die eigenstaendigen Signaturen bekommen eine einmalig abspielende
     * Fassung. Ein optionaler Playback-Nonce wird als unsichtbarer GIF-Kommentar
     * eingebettet, damit eine Vorschau technisch eine neue Bildidentitaet
     * erhaelt, ohne sich visuell zu veraendern.
     *
     * Die E-Mail-Vorlagen behalten das Standbild — sie haengen an jeder
     * gesendeten Nachricht, dort waegt Dateigroesse schwerer als der Effekt.
     */
    public static function signatureTrainAsset(
        string $theme,
        bool $animated = false,
        ?string $playbackNonce = null,
    ): string {
        $variant = $theme === 'dark' ? 'dark' : 'light';
        $extension = $animated ? 'gif' : 'png';
        $mime = $animated ? 'image/gif' : 'image/png';
        $binary = file_get_contents(self::masterPath("assets/zug-dampf-{$variant}.{$extension}"));

        if ($animated && $playbackNonce !== null) {
            $binary = self::withGifPlaybackNonce($binary, $playbackNonce);
        }

        return "data:{$mime};base64,".base64_encode($binary);
    }

    /** Nahtlos loopender Standrauch; wird nur unter die Einfahrt gelegt. */
    public static function signatureTrainIdleAsset(string $theme): string
    {
        $variant = $theme === 'dark' ? 'dark' : 'light';

        return self::inlineImage("zug-dampf-idle-{$variant}.gif", 'image/gif');
    }

    protected function buildSignature(string $master, string $logo): string
    {
        $theme = str_contains($master, 'dark') ? 'dark' : 'light';
        $html = file_get_contents(self::masterPath($master));
        // Motivfarben ZUERST, siehe substitute(): sonst bekommen die
        // Umbruchregeln die Ersatzfarbe statt der Motivfarbe.
        $html = $this->substitute($html, self::emailThemeValues($theme));
        $html = $this->substitute($html, $this->escapeForHtml($this->profileValues()));

        // Dieselbe Quelle wie Vorlage und Systemmail — nur enger gesetzt und
        // ohne Akzentlinie, weil die Signaturdatei ihre eigene traegt.
        // Hier faehrt der Zug ein (animierte Fassung).
        $signature = MailSignature::forUser($this->user, $theme, animated: true);
        $signatureOverrides = ['LOGO_SRC' => self::inlineImage($logo, 'image/gif')];
        $html = $this->substitute($html, [
            'SIGNATURE_BLOCK' => $this->signatureBlock(
                $signature,
                layout: [
                    'padding' => '16px 28px 18px',
                    'topRule' => '',
                    'legalPadding' => '11px 28px',
                ],
                overrides: $signatureOverrides,
            ),
        ]);

        return self::embedPublishedCss(
            $html,
            $signature->publishedCss($signatureOverrides),
            MailDocumentKind::Signature,
        );
    }

    /**
     * Outlook-kompatible Signatur als installierbares ZIP-Paket.
     *
     * Klassisches Outlook liest lokale .htm/.rtf/.txt-Signaturen aus dem
     * Signatures-Ordner. Neues Outlook besitzt keinen entsprechenden
     * Dateiimport; dafuer dient dieselbe .htm-Datei als Kopiervorlage mit
     * regulaeren lokalen <img>-Elementen und einem manuellen Bild-Fallback.
     */
    protected function buildOutlookPackage(string $theme, string $slug): string
    {
        $variant = $theme === 'dark' ? 'dunkel' : 'hell';
        $signatureName = "RailTime-Signatur-{$variant}-{$slug}";
        $assetFolder = "{$signatureName}_files";
        $html = $this->buildOutlookSignatureHtml($theme, $assetFolder);
        $plain = $this->buildPlainSignature();
        $rtf = $this->buildOutlookRtf($plain);
        $readme = $this->buildOutlookReadme($signatureName, $assetFolder);
        $installer = $this->buildOutlookInstaller($signatureName);
        $installerScript = $this->buildOutlookInstallerScript($signatureName);

        $tempPath = tempnam(sys_get_temp_dir(), 'railtime-outlook-');
        if ($tempPath === false) {
            throw new RuntimeException('Temporäre Outlook-Exportdatei konnte nicht angelegt werden.');
        }

        $zip = new ZipArchive;
        $zipOpen = false;

        try {
            $opened = $zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($opened !== true) {
                throw new RuntimeException('Outlook-Export konnte nicht als ZIP geöffnet werden.');
            }
            $zipOpen = true;

            $files = [
                "{$signatureName}.htm" => $html,
                "{$signatureName}.txt" => $plain,
                "{$signatureName}.rtf" => $rtf,
                'README-Outlook.html' => $readme,
                'Outlook-klassisch-installieren.cmd' => $installer,
                'RailTime-Outlook-Installer.ps1' => $installerScript,
                "{$assetFolder}/zug-dampf.gif" => file_get_contents(
                    self::masterPath('assets/zug-dampf-outlook-'.($theme === 'dark' ? 'dark' : 'light').'.gif')
                ),
                "{$assetFolder}/logo.gif" => file_get_contents(
                    self::masterPath('assets/'.$this->emailLogoAsset($theme))
                ),
            ];

            foreach (self::CONTACT_ICON_PNG as $name => $base64) {
                $files["{$assetFolder}/contact-{$name}.png"] = base64_decode($base64, true) ?: '';
            }

            $files['RailTime-Paketmanifest.json'] = $this->buildOutlookPackageManifest($signatureName, $files);

            foreach ($files as $path => $content) {
                if (! is_string($content) || ! $zip->addFromString($path, $content)) {
                    throw new RuntimeException("Outlook-Exportdatei konnte nicht hinzugefügt werden: {$path}");
                }
            }

            $closed = $zip->close();
            $zipOpen = false;
            if (! $closed) {
                throw new RuntimeException('Outlook-Export konnte nicht abgeschlossen werden.');
            }

            $binary = file_get_contents($tempPath);
            if ($binary === false) {
                throw new RuntimeException('Outlook-Export konnte nicht gelesen werden.');
            }

            return $binary;
        } finally {
            if ($zipOpen) {
                $zip->close();
            }
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    protected function buildOutlookSignatureHtml(string $theme, string $assetFolder): string
    {
        $master = $theme === 'dark' ? 'signature-dark-master.html' : 'signature-light-master.html';
        $html = file_get_contents(self::masterPath($master));
        $html = $this->substitute($html, $this->escapeForHtml($this->profileValues()));

        $iconSources = [];
        foreach (array_keys(self::CONTACT_ICON_PNG) as $name) {
            $iconSources['ICON_'.strtoupper($name).'_SRC'] = "{$assetFolder}/contact-{$name}.png";
        }

        return $this->substitute($html, [
            'SIGNATURE_BLOCK' => $this->signatureBlock(
                MailSignature::forUser($this->user, $theme),
                layout: [
                    'padding' => '16px 28px 0',
                    'topRule' => '',
                    'legalPadding' => '11px 28px',
                    'outlookTrainSrc' => "{$assetFolder}/zug-dampf.gif",
                ],
                overrides: array_merge([
                    'LOGO_SRC' => "{$assetFolder}/logo.gif",
                    'TRAIN_SRC' => '',
                    'TRAIN_IDLE_SRC' => '',
                ], $iconSources),
            ),
        ]);
    }

    protected function buildOutlookInstaller(string $signatureName): string
    {
        $installer = <<<CMD
@echo off
setlocal EnableExtensions
title RailTime Outlook-Einrichtung
color 0F

set "SIGNATURE_NAME={$signatureName}"
set "INSTALLER_SCRIPT=%~dp0RailTime-Outlook-Installer.ps1"
set "POWERSHELL_EXE=%SystemRoot%\System32\WindowsPowerShell\\v1.0\powershell.exe"

echo.
echo ================================================================
echo   RAILTIME OUTLOOK-EINRICHTUNG
echo ================================================================
echo [INFO] Signatur: %SIGNATURE_NAME%
echo [INFO] Paketordner: %~dp0
echo [INFO] Benutzer: %USERNAME%
echo [INFO] Classic Outlook: automatische lokale Einrichtung
echo [INFO] Neues Outlook: gefuehrte manuelle Einrichtung laut README
echo [INFO] Protokoll: %TEMP%\RailTime-Outlook-Signatur-Installation.log
echo.
echo [PRUEFUNG] Das vollstaendig entpackte Paket wird kontrolliert ...

if not exist "%INSTALLER_SCRIPT%" (
  echo.
  echo [FEHLER] Das ZIP wurde nicht vollstaendig entpackt. RailTime-Outlook-Installer.ps1 fehlt.
  echo Bitte das ZIP per Rechtsklick vollstaendig extrahieren und erneut starten.
  echo Es wurden keine Outlook-Einstellungen geaendert.
  if not defined RAILTIME_INSTALLER_TEST_MODE pause
  exit /b 11
)

if not exist "%~dp0RailTime-Paketmanifest.json" (
  echo.
  echo [FEHLER] Das Paketmanifest fehlt. Bitte das ZIP erneut herunterladen und vollstaendig entpacken.
  echo Es wurden keine Outlook-Einstellungen geaendert.
  if not defined RAILTIME_INSTALLER_TEST_MODE pause
  exit /b 11
)

if not exist "%POWERSHELL_EXE%" set "POWERSHELL_EXE=powershell.exe"

echo [INFO] PowerShell: %POWERSHELL_EXE%
echo [INFO] Die grafische Pruefung wird jetzt gestartet.
echo.

"%POWERSHELL_EXE%" -NoLogo -NoProfile -STA -ExecutionPolicy Bypass -File "%INSTALLER_SCRIPT%" %*
set "INSTALLER_EXIT=%ERRORLEVEL%"

if "%INSTALLER_EXIT%"=="0" (
  echo.
  echo ================================================================
  echo [ERFOLG] Die Classic-Outlook-Einrichtung wurde verifiziert.
  echo [INFO] Ziel: %APPDATA%\Microsoft\Signatures
  echo [INFO] Neue Nachrichten und Antworten wurden dem erkannten
  echo        RailTime-Konto zugeordnet.
  echo [NAECHSTER SCHRITT] Classic Outlook starten und eine Testmail oeffnen.
  echo [NEUES OUTLOOK] README-Outlook.html oeffnen und die Signatur dort
  echo                 unter Einstellungen - Konten - Signaturen einfuegen.
  echo [PROTOKOLL] %TEMP%\RailTime-Outlook-Signatur-Installation.log
  echo ================================================================
) else (
  echo.
  echo ================================================================
  echo [FEHLER] Die RailTime Outlook-Einrichtung wurde nicht abgeschlossen ^(Code %INSTALLER_EXIT%^).
  echo [INFO] Es wurde kein ungepruefter Erfolg gemeldet.
  echo [HILFE] Details stehen in der grafischen Meldung, in README-Outlook.html
  echo         und unter %TEMP%\RailTime-Outlook-Signatur-Installation.log.
  echo ================================================================
)

if not defined RAILTIME_INSTALLER_TEST_MODE pause

exit /b %INSTALLER_EXIT%
CMD;

        $installer = str_replace(["\r\n", "\r"], "\n", $installer);

        return str_replace("\n", "\r\n", $installer)."\r\n";
    }

    protected function buildOutlookInstallerScript(string $signatureName): string
    {
        $path = self::masterPath('outlook-installer.ps1');
        $script = file_get_contents($path);

        if ($script === false || ! str_contains($script, '__RAILTIME_SIGNATURE_NAME__')) {
            throw new RuntimeException('Die grafische Outlook-Installationsroutine konnte nicht geladen werden.');
        }

        $script = str_replace('__RAILTIME_SIGNATURE_NAME__', $signatureName, $script);
        $script = str_replace(["\r\n", "\r"], "\n", $script);

        // Windows PowerShell 5.1 erkennt UTF-8 mit Umlauten nur zuverlässig
        // über die BOM. Der Quelltext bleibt im Repository normales UTF-8.
        return "\xEF\xBB\xBF".$script."\n";
    }

    protected function buildOutlookReadme(string $signatureName, string $assetFolder): string
    {
        $name = htmlspecialchars($signatureName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $folder = htmlspecialchars($assetFolder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><title>RailTime Signatur in Outlook einrichten</title></head>
<body style="max-width:760px;margin:40px auto;padding:0 20px;font-family:Arial,Helvetica,sans-serif;color:#111820;line-height:1.55;">
  <h1 style="font-size:26px;">RailTime-Signatur in Outlook einrichten</h1>
  <p>Das Paket enthält eine Outlook-kompatible Signatur mit einem normalen, einmalig abspielenden GIF, eine geprüfte Windows-Einrichtung und ein SHA-256-Paketmanifest. Bitte das ZIP zuerst vollständig entpacken. Ein Start direkt aus der ZIP-Ansicht kann die Begleitdateien nicht installieren.</p>
  <h2 style="font-size:19px;">Klassisches Outlook für Windows</h2>
  <ol>
    <li>Das heruntergeladene ZIP mit <strong>Rechtsklick → Alle extrahieren</strong> vollständig entpacken.</li>
    <li>Offene Entwürfe speichern und im entpackten Ordner <strong>Outlook-klassisch-installieren.cmd</strong> doppelt anklicken.</li>
    <li>Die grafische Einrichtung prüft Paketmanifest, Dateigrößen und SHA-256-Hashes, zeigt erkanntes Profil, Konten und Zielordner an und schließt anschließend ausschließlich Classic Outlook. Falls Classic Outlook nicht regulär reagiert, fragt sie vor einem erzwungenen Schließen ausdrücklich nach.</li>
    <li>„{$name}“ wird automatisch für neue Nachrichten sowie Antworten/Weiterleitungen dem <strong>ersten Konto mit einer Adresse, die exakt auf @rail-time.de endet</strong>, zugeordnet. Ohne passendes Konto erscheint ein Fehler und es wird nichts geändert.</li>
    <li>Nach dem Erfolg Classic Outlook wieder öffnen, eine neue Testmail erstellen und kontrollieren, ob Signatur, Bilder, Links und Animation sichtbar sind.</li>
  </ol>
  <p><strong>Lokaler Classic-Modus:</strong> Damit die lokale Installation zuverlässig bleibt, aktiviert die Einrichtung den von Microsoft dokumentierten Classic-Signaturmodus. Dadurch werden Roaming-Signaturen im klassischen Outlook für diesen Windows-Benutzer deaktiviert. Vorherige Zuordnungswerte werden lokal gesichert.</p>
  <p>Erfolg oder Fehler erscheinen direkt in der Oberfläche. Das vollständige Protokoll liegt unter <strong>%TEMP%\RailTime-Outlook-Signatur-Installation.log</strong>.</p>
  <h2 style="font-size:19px;">Neues Outlook oder Outlook im Web</h2>
  <p><strong>Wichtig:</strong> Das neue Outlook speichert Signaturen konto- beziehungsweise cloudgebunden und liest den lokalen Classic-Outlook-Ordner nicht ein. Eine lokale Windows-Installationsroutine kann diese Signatur daher nicht direkt im neuen Outlook registrieren. Die Windows-Einrichtung lässt das neue Outlook deshalb geöffnet und verändert dort nichts.</p>
  <ol>
    <li><strong>{$name}.htm</strong> in Edge oder Chrome öffnen.</li>
    <li>Mit <strong>Strg+A</strong> alles markieren und mit <strong>Strg+C</strong> kopieren.</li>
    <li>Im neuen Outlook <strong>Einstellungen → Konten → Signaturen</strong> öffnen, das richtige RailTime-Konto auswählen, eine neue Signatur anlegen und einfügen.</li>
    <li>Falls Outlook das Zugbild nicht übernimmt: über „Bild einfügen“ die Datei <strong>{$folder}/zug-dampf.gif</strong> unter der Signatur einsetzen.</li>
    <li>Die Signatur für neue Nachrichten und Antworten/Weiterleitungen auswählen, speichern und anschließend eine Testmail erstellen.</li>
  </ol>
  <p><strong>E-Mail-Vorlagen sind davon getrennt:</strong> Das neue Outlook kann lokale OFT-Dateien bei einem qualifizierenden Microsoft-365-Abonnement unter <strong>Einstellungen → E-Mail → Vorlagen → Hinzufügen → OFT hinzufügen</strong> importieren. Dieses Signaturpaket enthält bewusst keine OFT-Datei und behauptet keinen automatischen Import. Die Mailvorlage wird in der RailTime-App separat heruntergeladen.</p>
  <p>Microsoft-Anleitungen: <a href="https://support.microsoft.com/en-us/outlook/mail/how-to-add-and-change-an-email-signature-in-outlook">Signaturen in Outlook</a> und <a href="https://support.microsoft.com/en-au/office/download-add-and-share-templates-as-oft-files-in-outlook-a410e7b9-8bb0-437e-828d-a9954ff2ac2c">OFT-Vorlagen im neuen Outlook</a>.</p>
  <p><strong>Animationshinweis:</strong> Das Outlook-GIF spielt die Einfahrt, den Rauch-Ausklang und einen vollständigen Standrauch-Zyklus einmal ab. Danach bleibt der Zug sichtbar stehen. Ein endloser Idle-Teil in derselben GIF-Datei würde immer auch die Einfahrt wiederholen.</p>
</body>
</html>
HTML;
    }

    /**
     * @param  array<string, string>  $files
     */
    protected function buildOutlookPackageManifest(string $signatureName, array $files): string
    {
        $manifestFiles = [];
        foreach ($files as $path => $content) {
            if (! is_string($content)) {
                throw new RuntimeException("Outlook-Exportdatei ist ungültig: {$path}");
            }

            $normalizedPath = str_replace('\\', '/', $path);
            $hash = hash('sha256', $content);
            $manifestFiles[] = [
                'path' => $normalizedPath,
                'bytes' => strlen($content),
                'sha256' => $hash,
            ];
        }

        usort($manifestFiles, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));
        $fingerprintMaterial = array_map(
            static fn (array $file): string => "{$file['path']}:{$file['sha256']}",
            $manifestFiles,
        );

        return json_encode([
            'schema' => 1,
            'signatureName' => $signatureName,
            'packageFingerprint' => hash('sha256', implode("\n", $fingerprintMaterial)),
            'files' => $manifestFiles,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    protected function buildOutlookRtf(string $plain): string
    {
        $plain = str_replace(["\r\n", "\r"], "\n", $plain);
        $escaped = '';

        foreach (preg_split('//u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            if ($character === "\n") {
                $escaped .= "\\par\n";

                continue;
            }

            if (in_array($character, ['\\', '{', '}'], true)) {
                $escaped .= '\\'.$character;

                continue;
            }

            $codepoint = mb_ord($character, 'UTF-8');
            if ($codepoint >= 32 && $codepoint <= 126) {
                $escaped .= $character;

                continue;
            }

            if ($codepoint > 32767) {
                $codepoint -= 65536;
            }

            $escaped .= "\\u{$codepoint}?";
        }

        return "{\\rtf1\\ansi\\deff0{\\fonttbl{\\f0 Arial;}}\\uc1\\f0\\fs20\n{$escaped}\n}";
    }

    protected function buildPlainBody(): string
    {
        $values = $this->profileValues();

        $phoneParts = array_filter([
            $values['DURCHWAHL'] !== '' ? "T {$values['DURCHWAHL']}" : null,
            $values['MOBIL'] !== '' ? "M {$values['MOBIL']}" : null,
        ]);
        $phoneLine = $phoneParts === [] ? '' : implode(' · ', $phoneParts)."\n";
        // Wie in den HTML-Fassungen: Festnetzanschluss statt Notfall-Mobilnummer.
        $companyPhoneLine = $values['FIRMEN_TELEFON'] !== ''
            ? "Telefon (Zentrale): {$values['FIRMEN_TELEFON']}\n"
            : '';

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
{$companyPhoneLine}
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
        // Wie in den HTML-Fassungen: Festnetzanschluss statt Notfall-Mobilnummer.
        $companyPhoneLine = $values['FIRMEN_TELEFON'] !== ''
            ? "Telefon (Zentrale): {$values['FIRMEN_TELEFON']}\n"
            : '';

        return <<<TEXT
{$values['VORNAME_NACHNAME']}
{$values['POSITION']}

{$values['FIRMENNAME']}
{$values['FIRMENSTRASSE']}
{$values['FIRMEN_PLZ_ORT']}

{$contactLines}E {$values['E_MAIL']}
{$companyPhoneLine}Zentrale E-Mail: {$values['FIRMEN_EMAIL']}

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

        $logoAsset = $this->emailLogoAsset($theme);
        $markAsset = self::emailMarkAsset($theme);
        $inlineImages = [
            'railtime-logo' => [
                'filename' => $logoAsset,
                'content' => file_get_contents(self::masterPath('assets/'.$logoAsset)),
            ],
            'railtime-mark' => [
                'filename' => $markAsset,
                'content' => file_get_contents(self::masterPath('assets/'.$markAsset)),
            ],
            'railtime-mark-still' => [
                'filename' => str_replace('.gif', '.png', $markAsset),
                'content' => file_get_contents(self::masterPath('assets/'.str_replace('.gif', '.png', $markAsset))),
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
                'Content-Type: '.(str_ends_with($image['filename'], '.gif') ? 'image/gif' : 'image/png').'; name="'.$image['filename'].'"',
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
