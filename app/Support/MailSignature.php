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
        // Vorschau bei reduzierter Bewegung: PNG statt GIF, keine Rauchfahne.
        protected bool $staticAssets = false,
    ) {}

    public static function forUser(
        User $user,
        string $theme = 'light',
        bool $animated = false,
        ?string $playbackNonce = null,
        bool $remoteAssets = false,
        bool $staticAssets = false,
    ): self {
        return new self($user, $theme, $animated, $playbackNonce, $remoteAssets, $staticAssets);
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
        bool $staticAssets = false,
    ): self {
        return new self(null, $theme, $animated, $playbackNonce, $remoteAssets, $staticAssets);
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
        // ALS GIF: Der Schriftzug traegt einen Lichtstreifen, der einmal
        // darueberlaeuft (tools/render-marken-animation.mjs). Das ERSTE
        // Einzelbild zeigt die Marke bereits vollstaendig — nur deshalb ist
        // das in E-Mails ueberhaupt zulaessig: Outlook-Desktop zeigt von
        // einem GIF ausschliesslich dieses erste Bild.
        $logoAsset = $this->theme === 'dark' ? 'wortmarke-mail-dark.gif' : 'wortmarke-signature-light.gif';

        // Das RT-Zeichen gehoert zur VORLAGE (oben rechts), nicht zum
        // Signaturblock. Es steht trotzdem hier, weil values() die
        // gemeinsame Wertetabelle JEDES Ausgabewegs ist — auch der
        // Editorvorschau und der Seitenvorschau. Fehlte es, ersetzte
        // PageBuilderPreviewService den uebrig gebliebenen Platzhalter
        // durch einen Gedankenstrich und das Zeichen erschien als
        // kaputtes Bild.
        $markAsset = EmailTemplateBuilder::emailMarkAsset($this->theme);

        if ($this->staticAssets) {
            $logoAsset = str_replace('.gif', '.png', $logoAsset);
            $markAsset = str_replace('.gif', '.png', $markAsset);
        }

        // STANDBILDER FUER OUTLOOK-DESKTOP. Die bewegten Marken bauen sich
        // auf, ihr erstes Einzelbild ist also fast leer — und genau dieses
        // eine zeigt Outlook. Ueber einen bedingten Kommentar bekommt es
        // deshalb das fertige Bild statt einer leeren Flaeche.
        $logoStill = str_replace('.gif', '.png', $logoAsset);
        $markStill = str_replace('.gif', '.png', $markAsset);

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
                'LOGO_STILL_SRC' => EmailTemplateBuilder::mailAssetUrl($logoStill),
                'ICON_RT_SRC' => EmailTemplateBuilder::mailAssetUrl($markAsset),
                'ICON_RT_STILL_SRC' => EmailTemplateBuilder::mailAssetUrl($markStill),
                'GRUND_RASTER_SRC' => EmailTemplateBuilder::mailAssetUrl($raster),
                'GRUND_MARKE_SRC' => EmailTemplateBuilder::mailAssetUrl($marke),
                'TRAIN_SRC' => EmailTemplateBuilder::signatureTrainUrl(
                    $this->theme,
                    $this->staticAssets ? false : $this->animated,
                ),
                // Das Standbild traegt den Ersatzweg fuer Outlook-Desktop
                // (background-Attribut), siehe emails/parts/signature.blade.php.
                'TRAIN_STILL_SRC' => EmailTemplateBuilder::signatureTrainStillUrl($this->theme),
                // IMMER, nicht nur bei animierter Einfahrt: Die Ruhefahne
                // ist die Dauerbewegung des Streifens. Ob der Zug EINFAEHRT,
                // entscheidet $animated — ob er raucht, nicht.
                'TRAIN_IDLE_SRC' => $this->staticAssets
                    ? ''
                    : EmailTemplateBuilder::mailAssetUrl(
                        'zug-dampf-idle-'.($this->theme === 'dark' ? 'dark' : 'light').'.gif'
                    ),
            ]
            : [
                'LOGO_SRC' => EmailTemplateBuilder::inlineImage(
                    $logoAsset,
                    str_ends_with($logoAsset, '.gif') ? 'image/gif' : 'image/png',
                    $this->playbackNonce,
                ),
                'LOGO_STILL_SRC' => EmailTemplateBuilder::inlineImage($logoStill, 'image/png'),
                'ICON_RT_SRC' => EmailTemplateBuilder::inlineImage(
                    $markAsset,
                    str_ends_with($markAsset, '.gif') ? 'image/gif' : 'image/png',
                    $this->playbackNonce,
                ),
                'ICON_RT_STILL_SRC' => EmailTemplateBuilder::inlineImage($markStill, 'image/png'),
                'GRUND_RASTER_SRC' => EmailTemplateBuilder::inlineImage($raster, 'image/png'),
                'GRUND_MARKE_SRC' => EmailTemplateBuilder::inlineImage($marke, 'image/png'),
                'TRAIN_SRC' => EmailTemplateBuilder::signatureTrainAsset(
                    $this->theme,
                    $this->staticAssets ? false : $this->animated,
                    $this->playbackNonce,
                ),
                // Ohne verlinkte Adresse gibt es keinen Outlook-Ersatzweg:
                // das background-Attribut kann keine data:-URI laden.
                'TRAIN_STILL_SRC' => '',
                'TRAIN_IDLE_SRC' => $this->staticAssets
                    ? ''
                    : EmailTemplateBuilder::inlineImage(
                        'zug-dampf-idle-'.($this->theme === 'dark' ? 'dark' : 'light').'.gif',
                        'image/gif',
                        $this->playbackNonce,
                    ),
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
        $companyPhone = trim((string) $company['FIRMEN_TELEFON']);
        $emergencyPhone = trim((string) $company['NOTFALLNUMMER']);
        $companyPhoneHref = EmailTemplateBuilder::telHref($companyPhone);
        $emergencyPhoneHref = EmailTemplateBuilder::telHref($emergencyPhone);

        // In den offiziellen Firmendaten koennen Zentrale und 24/7-Nummer
        // identisch sein. In einer automatischen Nachricht ist das dann
        // keine zweite Kontaktmoeglichkeit, sondern dieselbe Nummer mit
        // einem zweiten Symbol. Nur echte, abweichende Nummern bleiben als
        // zusaetzliche Mobil-/Notfallzeile sichtbar.
        if ($companyPhoneHref !== '' && $companyPhoneHref === $emergencyPhoneHref) {
            $emergencyPhone = '';
            $emergencyPhoneHref = '';
        }

        return [
            'VORNAME_NACHNAME' => '',
            'POSITION' => __('app.mail_signature_company_role'),
            // Die Durchwahl der Firma ist der Festnetzanschluss, die
            // "Mobil"-Zeile die 24/7-Rufbereitschaft.
            'DURCHWAHL' => $companyPhone,
            'DURCHWAHL_TEL' => $companyPhoneHref,
            'MOBIL' => $emergencyPhone,
            'MOBIL_TEL' => $emergencyPhoneHref,
            'E_MAIL' => $company['FIRMEN_EMAIL'],
            'FIRMEN_TELEFON_TEL' => $companyPhoneHref,
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

        // Vor der MailDocument-Migration bleibt der bestehende Bootstrapweg
        // verwendbar. In einer migrierten Installation erzwingt
        // runtimeDocument dagegen eine echte Veröffentlichung.
        $published = EmailTemplateBuilder::runtimeDocument(
            MailDocumentKind::Signature,
            requirePublished: $this->remoteAssets,
        );
        if ($published === null) {
            $html = View::make('emails.parts.signature', array_merge([
                'values' => $values,
            ], $layout))->render();

            return trim(EmailTemplateBuilder::stripEmptyContactRows(
                $html,
                $this->contactRowValues($values),
            ));
        }

        // DER OUTLOOK-EXPORT BEKOMMT DEN ZUG ALS REGULAERES BILD.
        //
        // Er braucht eine lokale Zugdatei im installierbaren Paket. Eine
        // versendete Systemmail bleibt dagegen beim oberen Hintergrund-
        // Carrier; eine zusaetzliche Bildzeile wuerde in modernen Clients
        // denselben Zug sichtbar verdoppeln.
        //
        // Das ist keine Einschraenkung, sondern strukturell notwendig: Das
        // veroeffentlichte Dokument ist EIN gespeicherter Markup-Stand. Es
        // kann nicht gleichzeitig die Bildzeile (Systemmail, Outlook) und
        // die Hintergrundebene (Downloads) tragen — welche Fassung noetig
        // ist, entscheidet sich erst beim Rendern. Wuerde die Systemmail den
        // gespeicherten Stand verwenden, verschwaende der Zug dort wieder,
        // genau wie vor der Umstellung auf die Bildzeile.
        //
        // Folge fuer den Editor: Eine Freigabe wirkt auf Downloads und
        // Systemmails. Nur die strukturell besondere Outlook-Bildzeile wird
        // bei Bedarf am stabilen Marker ergaenzt.
        $zugAlsBild = trim((string) ($layout['outlookTrainSrc'] ?? '')) !== '';
        if ($published !== null) {
            $html = $this->applyPublishedLayout($published, $layout);
            if ($zugAlsBild) {
                $html = $this->projectPublishedTrainAsImage($html, $layout);
            }
            // FRUEHER stand hier ein Rueckfall auf den Firmennamen, wenn
            // keine Person sendet — er bildete eine gleichlautende Bedingung
            // der Blade-Quelle nach. Diese Bedingung ist entfallen: Die
            // Marke steht bereits als Wortmarke in der rechten Spalte, der
            // Firmenname darunter war eine Doppelung. Ohne diese Streichung
            // erschien er in jeder VEROEFFENTLICHTEN Fassung weiter, obwohl
            // die Blade-Quelle ihn laengst nicht mehr setzte.
            $escapedValues = array_map(
                static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
                $values,
            );
            $tokens = [];

            foreach ($escapedValues as $key => $value) {
                $tokens['{{'.$key.'}}'] = $value;
            }

            return trim(EmailTemplateBuilder::stripEmptyContactRows(
                strtr($html, $tokens),
                $this->contactRowValues($values),
            ));
        }

        throw new \RuntimeException('Die veröffentlichte Signatur konnte nicht gerendert werden.');
    }

    /**
     * Sichtbarkeitswerte fuer die markergebundenen Kontaktzeilen.
     *
     * Der kanonische Signaturentwurf wird mit Platzhaltern gespeichert. Beim
     * Seed-Zeitpunkt ist {{VORNAME_NACHNAME}} technisch nicht leer; deshalb
     * enthaelt er auch die Firmen-Telefonzeile der persoenlichen Fassung.
     * Automatische Nachrichten zeigen den Anschluss bereits links als
     * DURCHWAHL. Die rechte Wiederholung wird hier beim finalen Rendern ueber
     * den vorhandenen COMPANY_PHONE-Marker entfernt.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function contactRowValues(array $values): array
    {
        if ($this->user === null) {
            $values['FIRMEN_TELEFON'] = '';
        }

        return $values;
    }

    /**
     * Der Outlook-Export braucht den Zug als regulaeres lokales Bild. Der
     * kanonische veroeffentlichte Signaturstand enthaelt ihn als Hintergrund;
     * der feste Marker projiziert ihn ausschliesslich fuer das installierbare
     * Classic-Outlook-Paket. Versendete Systemmails bleiben bei genau einem
     * oberen Hintergrund-Carrier.
     *
     * @param  array<string, string>  $layout
     */
    private function projectPublishedTrainAsImage(string $html, array $layout): string
    {
        $marker = '<!-- RT_SIGNATURE_MAIN_END -->';
        if (substr_count($html, $marker) !== 1) {
            throw new \RuntimeException(
                'Die veröffentlichte Signatur besitzt keinen eindeutigen Bildzeilen-Anker.'
            );
        }

        $source = htmlspecialchars((string) $layout['outlookTrainSrc'], ENT_QUOTES, 'UTF-8');
        $fallback = htmlspecialchars(
            (string) ($layout['outlookTrainFallbackSrc'] ?? ''),
            ENT_QUOTES,
            'UTF-8',
        );
        $padding = htmlspecialchars(
            (string) ($layout['outlookTrainPadding'] ?? '6px 0 14px'),
            ENT_QUOTES,
            'UTF-8',
        );
        $animated = '<img data-rt-outlook-train src="'.$source.'" width="620" '
            .'alt="Dampflok-Güterzug" style="display:block;width:70%;max-width:620px;'
            .'height:auto;margin:0;border:0;outline:none;opacity:.7;">';
        $images = $animated;

        if ($fallback !== '') {
            $images = '<!--[if !mso]><!-->'.$animated.'<!--<![endif]-->'
                .'<!--[if mso]><img data-rt-outlook-train-still src="'.$fallback.'" width="620" '
                .'alt="Dampflok-Güterzug" style="display:block;width:70%;max-width:620px;'
                .'height:auto;margin:0;border:0;outline:none;"><![endif]-->';
        }

        $row = '<tr><td align="left" style="padding:'.$padding
            .';text-align:left;font-size:0;line-height:0;">'.$images.'</td></tr>';

        return str_replace($marker, $marker.$row, $html);
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
            ['LOGO_SRC', 'LOGO_STILL_SRC', 'TRAIN_SRC', 'TRAIN_IDLE_SRC', 'ICON_RT_SRC', 'ICON_RT_STILL_SRC', 'GRUND_RASTER_SRC', 'GRUND_MARKE_SRC'],
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
