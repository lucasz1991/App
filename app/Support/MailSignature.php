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
        // Bilder verlinken statt einbetten. Siehe values().
        protected bool $remoteAssets = false,
    ) {}

    public static function forUser(
        User $user,
        string $theme = 'light',
        bool $animated = false,
        ?string $playbackNonce = null,
        bool $remoteAssets = false,
    ): self {
        return new self($user, $theme, $animated, $playbackNonce, $remoteAssets);
    }

    /**
     * Firmenweite Signatur — der Weg jeder VERSENDETEN Systemmail.
     *
     * Vorgaben bewusst anders als bei forUser(): der Zug faehrt ein
     * (animated) und alle Bilder werden VERLINKT (remoteAssets).
     *
     * Das Verlinken ist der Grund, warum die Signatur ueberhaupt ankommt.
     * Als data:-URI erschien sie bei vielen Empfaengern nicht: Outlook-
     * Desktop kennt weder data:-URIs in <img> noch CSS-Hintergrundbilder,
     * Gmail entfernt data:-URIs in Hintergrundangaben und kappt Nachrichten
     * ab 102 kB — die eingebetteten Bilder allein waren 104,6 kB.
     */
    public static function forCompany(
        string $theme = 'light',
        bool $animated = true,
        ?string $playbackNonce = null,
        bool $remoteAssets = true,
    ): self {
        return new self(null, $theme, $animated, $playbackNonce, $remoteAssets);
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

        // NUR DER SCHRIFTZUG, ohne das RT-Zeichen davor: die Markenspalte
        // der Signatur zeigt die Marke einmal. Das Zeichen steht allein
        // oben rechts in der E-Mail-Vorlage.
        $logoAsset = $this->theme === 'dark' ? 'wortmarke-mail-dark.png' : 'wortmarke-signature-light.png';

        // Das RT-Zeichen gehoert zur VORLAGE (oben rechts), nicht zum
        // Signaturblock. Es steht trotzdem hier, weil values() die
        // gemeinsame Wertetabelle JEDES Ausgabewegs ist — auch der
        // Editorvorschau und der Seitenvorschau. Fehlte es, ersetzte
        // PageBuilderPreviewService den uebrig gebliebenen Platzhalter
        // durch einen Gedankenstrich und das Zeichen erschien als
        // kaputtes Bild.
        $markAsset = EmailTemplateBuilder::emailMarkAsset($this->theme);

        // Hintergrundgrafik des Streifens, Bildsprache vom Notfallbanner der
        // Website: ein feines Raster (gekachelt) und ein grosses
        // RT-Wasserzeichen mit rotem Schimmer (einmal, rechts).
        $variante = $this->theme === 'dark' ? 'dark' : 'light';
        $raster = "signatur-raster-{$variante}.png";
        $marke = "signatur-marke-{$variante}.png";

        // ZWEI BETRIEBSARTEN, und die Unterscheidung ist wesentlich:
        //
        //   verlinkt   — fuer VERSENDETE Mails. Nur so erscheinen die Bilder
        //                in Outlook-Desktop und Gmail, und das Mail bleibt
        //                unter Gmails 102-kB-Schnitt.
        //   eingebettet — fuer HERUNTERLADBARE Signaturen und Vorlagen. Die
        //                muessen eigenstaendig sein, auch ohne Verbindung zu
        //                diesem Server.
        $bilder = $this->remoteAssets
            ? [
                'LOGO_SRC' => EmailTemplateBuilder::mailAssetUrl($logoAsset),
                'ICON_RT_SRC' => EmailTemplateBuilder::mailAssetUrl($markAsset),
                'GRUND_RASTER_SRC' => EmailTemplateBuilder::mailAssetUrl($raster),
                'GRUND_MARKE_SRC' => EmailTemplateBuilder::mailAssetUrl($marke),
                'TRAIN_SRC' => EmailTemplateBuilder::signatureTrainUrl($this->theme, $this->animated),
                // Das Standbild traegt den Ersatzweg fuer Outlook-Desktop
                // (background-Attribut), siehe emails/parts/signature.blade.php.
                'TRAIN_STILL_SRC' => EmailTemplateBuilder::signatureTrainStillUrl($this->theme),
                'TRAIN_IDLE_SRC' => $this->animated
                    ? EmailTemplateBuilder::mailAssetUrl(
                        'zug-dampf-idle-'.($this->theme === 'dark' ? 'dark' : 'light').'.gif'
                    )
                    : '',
            ]
            : [
                'LOGO_SRC' => EmailTemplateBuilder::inlineImage($logoAsset, 'image/png'),
                'ICON_RT_SRC' => EmailTemplateBuilder::inlineImage($markAsset, 'image/png'),
                'GRUND_RASTER_SRC' => EmailTemplateBuilder::inlineImage($raster, 'image/png'),
                'GRUND_MARKE_SRC' => EmailTemplateBuilder::inlineImage($marke, 'image/png'),
                'TRAIN_SRC' => EmailTemplateBuilder::signatureTrainAsset(
                    $this->theme,
                    $this->animated,
                    $this->playbackNonce,
                ),
                // Ohne verlinkte Adresse gibt es keinen Outlook-Ersatzweg:
                // das background-Attribut kann keine data:-URI laden.
                'TRAIN_STILL_SRC' => '',
                'TRAIN_IDLE_SRC' => $this->animated
                    ? EmailTemplateBuilder::signatureTrainIdleAsset($this->theme)
                    : '',
            ];

        $symbole = $this->remoteAssets
            ? EmailTemplateBuilder::contactIconUrls()
            : EmailTemplateBuilder::contactIconSources(true);

        return array_merge($company, $person, $theme, $bilder, $symbole, $overrides);
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

        // WER DEN ZUG ALS BILD BRAUCHT, BEKOMMT IMMER DIE BLADE-QUELLE.
        //
        // Das sind zwei Wege: der Outlook-Export (lokale Zugdatei im Paket)
        // UND jede versendete Systemmail (verlinkte Adresse, weil Outlook-
        // Desktop und Gmail keine CSS-Hintergrundbilder darstellen — siehe
        // vendor/mail/html/footer.blade.php).
        //
        // Das ist keine Einschraenkung, sondern strukturell notwendig: Das
        // veroeffentlichte Dokument ist EIN gespeicherter Markup-Stand. Es
        // kann nicht gleichzeitig die Bildzeile (Systemmail, Outlook) und
        // die Hintergrundebene (Downloads) tragen — welche Fassung noetig
        // ist, entscheidet sich erst beim Rendern. Wuerde die Systemmail den
        // gespeicherten Stand verwenden, verschwaende der Zug dort wieder,
        // genau wie vor der Umstellung auf die Bildzeile.
        //
        // Folge fuer den Editor: Eine Freigabe wirkt auf die DOWNLOADS. Die
        // Systemmails folgen der Blade-Quelle, also dem ausgelieferten Code.
        // Deshalb bindet layout.blade.php auch bewusst KEIN freigegebenes
        // CSS ein — sonst laege neues CSS auf altem Markup.
        $zugAlsBild = trim((string) ($layout['outlookTrainSrc'] ?? '')) !== '';
        $published = $zugAlsBild
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
            ['LOGO_SRC', 'TRAIN_SRC', 'TRAIN_IDLE_SRC', 'ICON_RT_SRC', 'GRUND_RASTER_SRC', 'GRUND_MARKE_SRC'],
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
            $replacements['padding:18px 36px 20px;'] = 'padding:'.$layout['padding'].';';
        }

        if (array_key_exists('topRule', $layout)) {
            $replacements['border-top:5px solid #e4002b;'] = $layout['topRule'];
        }

        if (array_key_exists('legalPadding', $layout)) {
            $replacements['padding:14px 36px;'] = 'padding:'.$layout['legalPadding'].';';
        }

        return $replacements === [] ? $html : strtr($html, $replacements);
    }
}
