<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\View;

/**
 * Der RailTime-Signaturblock — eine Quelle fuer alle Ausgabewege.
 *
 * Dasselbe Markup (resources/views/emails/parts/signature.blade.php) steht
 * damit in den herunterladbaren Signaturen, in der herunterladbaren
 * E-Mail-Vorlage UND unter jeder Laravel-Mail beziehungsweise -Notification.
 *
 *   MailSignature::forUser($user)->render()      persoenliche Signatur
 *   MailSignature::forCompany()->render()        firmenweit (Systemmails)
 *
 * Systemmails haben keinen Absender aus Fleisch und Blut. Ohne Person
 * ruecken Firmenname und Claim an die Stelle von Name und Funktion, die
 * Kontaktzeilen zeigen die Firmenanschluesse — die Gestalt bleibt gleich.
 */
class MailSignature
{
    protected function __construct(
        protected ?User $user,
        protected string $theme,
        protected bool $animated,
        protected ?string $playbackNonce = null,
    ) {}

    public static function forUser(
        User $user,
        string $theme = 'light',
        bool $animated = false,
        ?string $playbackNonce = null,
    ): self {
        return new self($user, $theme, $animated, $playbackNonce);
    }

    public static function forCompany(
        string $theme = 'light',
        bool $animated = false,
        ?string $playbackNonce = null,
    ): self {
        return new self(null, $theme, $animated, $playbackNonce);
    }

    /**
     * Werte fuer die Blade-Vorlage — bewusst ROH: das Blade-Teil escaped
     * selbst. Vorher escapen wuerde zu doppelt maskierten Zeichen fuehren.
     *
     * @param  array<string, string>  $overrides  z. B. cid:-Bildquellen
     * @return array<string, string>
     */
    public function values(array $overrides = []): array
    {
        $company = CompanyData::templateValues();
        $theme = EmailTemplateBuilder::emailThemeValues($this->theme);

        $person = $this->user !== null
            ? (new EmailTemplateBuilder($this->user))->profileValues()
            : $this->companyAsSender($company);

        return array_merge($company, $person, $theme, [
            'LOGO_SRC' => EmailTemplateBuilder::inlineImage(
                $this->theme === 'dark' ? 'logo-mail-dark.png' : 'logo-signature-light.png',
                'image/png'
            ),
            'TRAIN_SRC' => EmailTemplateBuilder::signatureTrainAsset(
                $this->theme,
                $this->animated,
                $this->playbackNonce,
            ),
        ], EmailTemplateBuilder::contactIconSources(true), $overrides);
    }

    /**
     * Firmenweiter Absender: Der Firmenname steht an der Stelle des Namens,
     * der Claim an der der Funktion. VORNAME_NACHNAME bleibt leer — daran
     * erkennt die Vorlage den unpersoenlichen Fall.
     *
     * @param  array<string, string>  $company
     * @return array<string, string>
     */
    protected function companyAsSender(array $company): array
    {
        return [
            'VORNAME_NACHNAME' => '',
            'POSITION' => __('app.mail_signature_company_role'),
            // Die Durchwahl der Firma ist der Festnetzanschluss, die
            // "Mobil"-Zeile die 24/7-Rufbereitschaft.
            'DURCHWAHL' => $company['FIRMEN_TELEFON'],
            'DURCHWAHL_TEL' => EmailTemplateBuilder::telHref($company['FIRMEN_TELEFON']),
            'MOBIL' => $company['NOTFALLNUMMER'],
            'MOBIL_TEL' => EmailTemplateBuilder::telHref($company['NOTFALLNUMMER']),
            'E_MAIL' => $company['FIRMEN_EMAIL'],
            'FIRMEN_TELEFON_TEL' => EmailTemplateBuilder::telHref($company['FIRMEN_TELEFON']),
            'FIRMEN_WEBSITE_HREF' => EmailTemplateBuilder::webHref($company['FIRMEN_WEBSITE']),
            'FIRMEN_WEBSITE_LABEL' => EmailTemplateBuilder::webLabel($company['FIRMEN_WEBSITE']),
        ];
    }

    /**
     * Fertiges HTML: Signaturzeile plus Pflichtangaben. Der Aufrufer stellt
     * die umgebende <table>.
     *
     * @param  array<string, string>  $layout     padding / topRule / legalPadding
     * @param  array<string, string>  $overrides  z. B. cid:-Bildquellen
     */
    public function render(array $layout = [], array $overrides = []): string
    {
        $values = $this->values($overrides);

        $html = View::make('emails.parts.signature', array_merge([
            'values' => $values,
        ], $layout))->render();

        // Leere Kontaktzeilen fallen samt ihrer Marker heraus.
        return trim(EmailTemplateBuilder::stripEmptyContactRows($html, $values));
    }
}
