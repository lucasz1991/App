<?php

namespace App\Support;

use App\Enums\MailDocumentKind;
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
            'TRAIN_IDLE_SRC' => $this->animated
                ? EmailTemplateBuilder::signatureTrainIdleAsset($this->theme)
                : '',
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
     * @param  array<string, string>  $layout  padding / topRule / legalPadding
     * @param  array<string, string>  $overrides  z. B. cid:-Bildquellen
     */
    public function render(array $layout = [], array $overrides = []): string
    {
        $values = $this->values($overrides);

        // Outlook braucht die strukturelle Blade-Verzweigung mit separater
        // lokaler Zugdatei. Alle anderen Wege duerfen den veroeffentlichten
        // Signaturblock verwenden, einschliesslich Systemmail und normalem
        // Hell-/Dunkel-Download.
        $isOutlookExport = trim((string) ($layout['outlookTrainSrc'] ?? '')) !== '';
        $published = $isOutlookExport
            ? null
            : EmailTemplateBuilder::publishedDocument(MailDocumentKind::Signature);

        if ($published !== null) {
            $html = $this->applyPublishedLayout($published, $layout);
            // Die Blade-Quelle ersetzt im unpersoenlichen Systemmail-Fall
            // den leeren Namen bedingt durch den Firmennamen. Im
            // veroeffentlichten Token-HTML ist diese Blade-Bedingung bereits
            // aufgeloest; dieselbe Semantik muss deshalb hier vor der
            // Platzhalter-Ersetzung nachgebildet werden.
            if ($this->user === null && trim($values['VORNAME_NACHNAME'] ?? '') === '') {
                $values['VORNAME_NACHNAME'] = $values['FIRMENNAME'] ?? '';
            }
            $escapedValues = array_map(
                static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
                $values,
            );
            $tokens = [];

            foreach ($escapedValues as $key => $value) {
                $tokens['{{'.$key.'}}'] = $value;
            }

            return trim(EmailTemplateBuilder::stripEmptyContactRows(strtr($html, $tokens), $values));
        }

        $html = View::make('emails.parts.signature', array_merge([
            'values' => $values,
        ], $layout))->render();

        // Leere Kontaktzeilen fallen samt ihrer Marker heraus.
        return trim(EmailTemplateBuilder::stripEmptyContactRows($html, $values));
    }

    /**
     * Separat gespeicherte Builder-Regeln fuer einen gueltigen Wrapper-Head.
     * Nur serverkontrollierte Farb- und Bildwerte werden eingesetzt; freie
     * Profiltexte duerfen nie in einen CSS-Kontext gelangen.
     *
     * @param  array<string, string>  $overrides
     */
    public function publishedCss(array $overrides = []): string
    {
        $css = EmailTemplateBuilder::publishedDocumentCss(MailDocumentKind::Signature);
        if ($css === '') {
            return '';
        }

        $values = $this->values($overrides);
        $safeKeys = array_unique(array_merge(
            array_keys(EmailTemplateBuilder::emailThemeValues($this->theme)),
            ['LOGO_SRC', 'TRAIN_SRC', 'TRAIN_IDLE_SRC'],
            array_values(array_filter(
                array_keys($values),
                static fn (string $key): bool => str_starts_with($key, 'ICON_'),
            )),
        ));
        $tokens = [];

        foreach ($safeKeys as $key) {
            if (array_key_exists($key, $values)) {
                $tokens['{{'.$key.'}}'] = $values[$key];
            }
        }

        return strtr($css, $tokens);
    }

    /**
     * Das Startdokument traegt die Standardabstaende der Systemmail. Beim
     * eigenstaendigen Download gelten engere Werte. Nur die drei bekannten
     * Starterwerte werden ersetzt: hat ein Administrator sie im Editor
     * individuell gestaltet, bleiben diese Werte unangetastet.
     *
     * @param  array<string, string>  $layout
     */
    private function applyPublishedLayout(string $html, array $layout): string
    {
        $replacements = [];

        if (array_key_exists('padding', $layout)) {
            $replacements['padding:20px 38px 28px;'] = 'padding:'.$layout['padding'].';';
        }

        if (array_key_exists('topRule', $layout)) {
            $replacements['border-top:5px solid #e4002b;'] = $layout['topRule'];
        }

        if (array_key_exists('legalPadding', $layout)) {
            $replacements['padding:18px 38px;'] = 'padding:'.$layout['legalPadding'].';';
        }

        return $replacements === [] ? $html : strtr($html, $replacements);
    }
}
