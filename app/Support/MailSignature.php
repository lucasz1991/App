<?php

namespace App\Support;

use App\Enums\MailDocumentKind;
use App\Models\User;
use App\Support\Mail\CssSemantic;
use App\Support\Mail\SignatureDocumentContract;
use App\Support\Mail\SignatureTrainCarrier;
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
        // Ein einmal abgespieltes, remote verlinktes GIF darf nicht die
        // Bildidentitaet einer anderen Systemmail teilen. Der Nonce entsteht
        // genau einmal pro Signaturinstanz: innerhalb EINER Mail bleiben alle
        // Verweise identisch, zwei unabhaengige Mails erhalten getrennte
        // Playback-URLs. Ob dieselbe bereits geladene Mail beim erneuten Oeffnen
        // wirklich neu startet, bleibt eine Entscheidung des jeweiligen
        // Mailclient-/Proxy-Caches.
        if ($animated && $remoteAssets && ! $staticAssets && $playbackNonce === null) {
            $playbackNonce = bin2hex(random_bytes(18));
        }

        return new self(null, $theme, $animated, $playbackNonce, $remoteAssets, $staticAssets);
    }

    /**
     * Werte fuer die Blade-Vorlage — bewusst ROH: das Blade-Teil escaped
     * selbst. Vorher escapen wuerde zu doppelt maskierten Zeichen fuehren.
     *
     * @param  array<string, string>  $overrides  z. B. cid:-Bildquellen
     * @param  array<string, string>|null  $companyProfile  bereits geladene Roh-Firmendaten
     * @return array<string, string>
     */
    public function values(array $overrides = [], ?array $companyProfile = null): array
    {
        $company = CompanyData::templateValues($companyProfile);
        $theme = EmailTemplateBuilder::emailThemeValues($this->theme);

        $person = $this->user !== null
            ? (new EmailTemplateBuilder($this->user))->profileValues()
            : $this->companyAsSender($company);

        // NUR DER SCHRIFTZUG, ohne das RT-Zeichen davor: die Markenspalte
        // der Signatur zeigt die Marke einmal. Das Zeichen steht allein
        // oben rechts in der E-Mail-Vorlage.
        // ALS GIF: Der Schriftzug baut sich einmal auf
        // (tools/render-marken-animation.mjs). Classic Outlook bekommt ueber
        // den bedingten IMG-Zweig das zugehoerige PNG-Standbild, weil bei
        // deaktivierter GIF-Wiedergabe nur das leere Aufbau-Startbild bliebe.
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
                'TRAIN_SRC' => $this->withRemotePlaybackNonce(
                    EmailTemplateBuilder::signatureTrainUrl(
                        $this->theme,
                        $this->staticAssets ? false : $this->animated,
                    ),
                ),
                // Statische, weiter validierte Referenz. Das aktuelle Schema injiziert
                // sie als MSO-Zugbild im selben mail-sicheren Flow-Layer.
                'TRAIN_STILL_SRC' => EmailTemplateBuilder::signatureTrainStillUrl($this->theme),
                // Nur der Rauch loopt. Die einmalige Einfahrt bleibt im
                // Haupt-GIF und wird nie erneut abgespielt; die transparente
                // Idle-Datei erscheint erst nach dessen 13-s-Timeline.
                'TRAIN_IDLE_SRC' => ($this->staticAssets || ! $this->animated)
                    ? ''
                    : $this->withRemotePlaybackNonce(
                        EmailTemplateBuilder::mailAssetUrl(
                            'zug-dampf-idle-'.($this->theme === 'dark' ? 'dark' : 'light').'.gif'
                        )
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
                'TRAIN_SRC' => EmailTemplateBuilder::signatureTrainAsset(
                    $this->theme,
                    $this->staticAssets ? false : $this->animated,
                    $this->playbackNonce,
                ),
                // Statische, lokal paketierbare Referenz. Sie bleibt Teil des
                // Wertevertrags und wird als Classic-Outlook-Standbild im
                // selben Flow-Layer injiziert.
                'TRAIN_STILL_SRC' => EmailTemplateBuilder::signatureTrainAsset(
                    $this->theme,
                    animated: false,
                ),
                'TRAIN_IDLE_SRC' => ($this->staticAssets || ! $this->animated)
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
     * Ergaenzt die pro Mail stabile Playback-Identitaet getrennt von der
     * dauerhaften Asset-Version (?v=...). Der Wert ist zufaellig bzw. vom
     * Aufrufer explizit gesetzt und enthaelt keinerlei Benutzerinformation.
     */
    private function withRemotePlaybackNonce(string $url): string
    {
        if (! $this->remoteAssets
            || ! $this->animated
            || $this->staticAssets
            || $this->playbackNonce === null) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        // Auch ein explizit gesetzter Nonce darf keine fachliche ID oder
        // sonstige Aufruferdaten in einer oeffentlichen Bild-URL verraten.
        // Der feste Digest bleibt deterministisch, kurz und URL-sicher.
        $playbackId = substr(hash('sha256', $this->playbackNonce), 0, 32);

        return $url.$separator.'p='.$playbackId;
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
        $tokenizedTrainCarrier = $this->usesTokenizedTrainCarrier($values, $layout);
        $explicitTrainSource = trim((string) ($layout['outlookTrainSrc'] ?? ''));
        $singleTrainSource = $explicitTrainSource !== ''
            ? $explicitTrainSource
            : trim((string) ($values['TRAIN_SRC'] ?? ''));
        $singleTrainLayout = array_merge($layout, [
            'outlookTrainSrc' => $singleTrainSource,
            'outlookTrainPadding' => (string) ($layout['outlookTrainPadding'] ?? '0'),
        ]);
        $outlookFallbackSource = trim((string) (
            $layout['outlookTrainFallbackSrc']
                ?? $values['TRAIN_STILL_SRC']
                ?? ''
        ));

        // Vor der MailDocument-Migration bleibt der bestehende Bootstrapweg
        // verwendbar. In einer migrierten Installation erzwingt
        // runtimeDocument dagegen eine echte Veröffentlichung.
        $published = EmailTemplateBuilder::runtimeDocument(
            MailDocumentKind::Signature,
            requirePublished: $this->remoteAssets,
        );
        if ($published === null) {
            $viewValues = $values;
            if ($tokenizedTrainCarrier) {
                // Dieselbe tokenisierte Zwischenform wie ein publiziertes
                // Dokument verwenden, damit auch der Bootstrap-Fallback den
                // fail-closed Carrier-Parser durchlaeuft.
                $viewValues['TRAIN_SRC'] = '{{TRAIN_SRC}}';
            }
            $html = View::make('emails.parts.signature', array_merge([
                'values' => $viewValues,
            ], $layout))->render();

            if ($tokenizedTrainCarrier) {
                $html = $this->projectPublishedTrainAsImage($html, $singleTrainLayout);
                $html = str_replace(
                    '{{TRAIN_SRC}}',
                    htmlspecialchars($singleTrainSource, ENT_QUOTES, 'UTF-8'),
                    $html,
                );
            }

            $html = trim(EmailTemplateBuilder::stripEmptyContactRows(
                $html,
                $this->contactRowValues($values),
            ));

            return $this->finalizeTrainRendering(
                $html,
                $outlookFallbackSource,
                (string) ($values['TRAIN_IDLE_SRC'] ?? ''),
            );
        }

        return $this->renderValidatedDocument(
            $published,
            $values,
            $layout,
            $singleTrainLayout,
            $outlookFallbackSource,
        );
    }

    /**
     * Rendert einen bereits geladenen Entwurf durch exakt dieselbe
     * Signaturpipeline wie eine Systemmail. Die Adminvorschau darf dadurch
     * den aktuellen Arbeitsstand zeigen, ohne Absender-, Kontakt- oder
     * Zuglogik ein zweites Mal nachzubauen.
     *
     * @param  array<string, string>  $layout
     * @param  array<string, string>  $overrides
     */
    public function renderDocument(string $documentHtml, array $layout = [], array $overrides = []): string
    {
        $values = $this->values($overrides);
        $explicitTrainSource = trim((string) ($layout['outlookTrainSrc'] ?? ''));
        $singleTrainSource = $explicitTrainSource !== ''
            ? $explicitTrainSource
            : trim((string) ($values['TRAIN_SRC'] ?? ''));
        $singleTrainLayout = array_merge($layout, [
            'outlookTrainSrc' => $singleTrainSource,
            'outlookTrainPadding' => (string) ($layout['outlookTrainPadding'] ?? '0'),
        ]);
        $outlookFallbackSource = trim((string) (
            $layout['outlookTrainFallbackSrc']
                ?? $values['TRAIN_STILL_SRC']
                ?? ''
        ));

        return $this->renderValidatedDocument(
            $documentHtml,
            $values,
            $layout,
            $singleTrainLayout,
            $outlookFallbackSource,
        );
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, string>  $layout
     * @param  array<string, string>  $singleTrainLayout
     */
    private function renderValidatedDocument(
        string $documentHtml,
        array $values,
        array $layout,
        array $singleTrainLayout,
        string $outlookFallbackSource,
    ): string {
        SignatureDocumentContract::assertRuntimeValid($documentHtml);

        $html = $this->applyPublishedLayout($documentHtml, $layout);
        $html = $this->projectPublishedTrainAsImage($html, $singleTrainLayout);
        // Bereits veroeffentlichte Altstaende verlieren Raster und grosses
        // RT-Wasserzeichen vor der Tokenersetzung. Die bildfreie Fassung gilt
        // dadurch sofort, auch ohne einen spaeteren Initialisierungsjob.
        $html = SignatureTrainCarrier::withoutDecorativeBaseBackgrounds($html);
        $escapedValues = array_map(
            static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
            $values,
        );
        $tokens = [];

        foreach ($escapedValues as $key => $value) {
            $tokens['{{'.$key.'}}'] = $value;
        }

        $html = strtr($html, $tokens);
        $html = preg_replace('/\s+background=(["\'])\s*\1/i', '', $html) ?? $html;
        $html = trim(EmailTemplateBuilder::stripEmptyContactRows(
            $html,
            $this->contactRowValues($values),
        ));

        return $this->finalizeTrainRendering(
            $html,
            $outlookFallbackSource,
            (string) ($values['TRAIN_IDLE_SRC'] ?? ''),
        );
    }

    /**
     * Sichtbarkeitswerte fuer die markergebundenen Kontaktzeilen.
     *
     * Der kanonische Signaturentwurf wird mit Platzhaltern gespeichert. Beim
     * gespeicherten Quellstand ist {{VORNAME_NACHNAME}} technisch nicht leer;
     * deshalb
     * enthaelt er auch die Firmen-Telefonzeile der persoenlichen Fassung.
     * Automatische Nachrichten zeigen Anschluss und Firmenmail bereits links
     * als Absenderkontakte. Die rechten Wiederholungen werden hier beim
     * finalen Rendern ueber COMPANY_PHONE/COMPANY_EMAIL entfernt.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function contactRowValues(array $values): array
    {
        if ($this->user === null) {
            $values['FIRMEN_TELEFON'] = '';
            // Automatische Nachrichten zeigen dieselbe Firmenadresse bereits
            // links als Absenderkontakt. Die rechte Firmenkopie wird ueber
            // ihren stabilen Marker entfernt, statt zweimal sichtbar zu sein.
            $values['FIRMEN_EMAIL'] = '';
        }

        return $values;
    }

    /**
     * Finalisiert die clientgetrennten Zugpfade erst nach dem autoritativen
     * Dokument-Render. Dadurch werden auch bereits publizierte Signaturen mit
     * dem fehlerhaften legacy background-Attribut sofort sicher ausgeliefert,
     * ohne auf eine erneute Veröffentlichung angewiesen zu sein.
     */
    private function finalizeTrainRendering(
        string $html,
        string $outlookFallbackSource,
        string $idleSource,
    ): string {
        $html = $this->removeLegacyTrainBackground($html);
        if (SignatureTrainCarrier::hasCanonicalBackground($html)) {
            return $html;
        }
        if ($this->animated && ! $this->staticAssets && trim($idleSource) !== '') {
            $html = SignatureTrainCarrier::withIdleOverlay($html, $idleSource);
        }

        // Hauptzug und Idle-Rauch bleiben wie Logo und RT-Zeichen echte IMG.
        // Das Hauptbild liegt mailclient-sicher im normalen Fluss direkt vor
        // der Legal-Zeile; nur der Idle-Holder bleibt hoehenneutral darueber.
        return SignatureTrainCarrier::withMsoFallback($html, $outlookFallbackSource);
    }

    /**
     * Erzwingt denselben serverkontrollierten Zugvertrag, den der Editor
     * bereits beim Speichern und Veroeffentlichen validiert.
     */
    private function normalizePublishedTrainCarrier(string $html): string
    {
        return SignatureTrainCarrier::normalize($html);
    }

    /** @param array<string, string> $values @param array<string, string> $layout */
    private function usesTokenizedTrainCarrier(array $values, array $layout): bool
    {
        return trim((string) ($layout['outlookTrainSrc'] ?? '')) === ''
            && trim((string) ($values['TRAIN_SRC'] ?? '')) !== '';
    }

    /**
     * Word interpretiert das HTML-Attribut background als wiederholbare
     * Zelltextur und ignoriert dabei die modernen no-repeat-/size-Regeln.
     * Entfernt wird es ausschliesslich am eindeutigen Signatur-Carrier;
     * Raster und Grundfarben anderer Zellen bleiben unberuehrt.
     */
    private function removeLegacyTrainBackground(string $html): string
    {
        return SignatureTrainCarrier::withoutLegacyBackgroundAttribute($html);
    }

    /**
     * Die gespeicherte/editierbare Fassung bekommt den Zug wie Logo und
     * RT-Icon als regulaeres Bild in einer sicheren Stage. Alte Background-
     * und absolute Direkt-Layer werden atomar in denselben Flowvertrag
     * ueberfuehrt. finalizeTrainRendering ergaenzt nur Idle- und Outlook-IMG.
     *
     * @param  array<string, string>  $layout
     */
    private function projectPublishedTrainAsImage(string $html, array $layout): string
    {
        if (SignatureTrainCarrier::hasCanonicalBackground($html)) {
            return $html;
        }

        $rawSource = trim((string) ($layout['outlookTrainSrc'] ?? ''));
        $padding = (string) ($layout['outlookTrainPadding'] ?? '0');

        return SignatureTrainCarrier::projectAsImage(
            $html,
            $rawSource,
            $padding,
        );
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

        if (CssSemantic::containsForbiddenAnimationOrProtectedSelector($css)) {
            throw new \RuntimeException(CssSemantic::PROTECTED_EDITABLE_CSS_MESSAGE);
        }

        if (CssSemantic::containsImportant($css)
            || CssSemantic::containsReservedRuntimeToken($css)
            || stripos($css, '</style') !== false) {
            throw new \RuntimeException(
                'Das veroeffentlichte Signatur-CSS verletzt den geschuetzten Runtime-Vertrag.'
            );
        }

        $values = $this->values($overrides);
        $safeKeys = array_unique(array_merge(
            array_keys(EmailTemplateBuilder::emailThemeValues($this->theme)),
            ['LOGO_SRC', 'LOGO_STILL_SRC', 'TRAIN_SRC', 'TRAIN_IDLE_SRC', 'ICON_RT_SRC', 'ICON_RT_STILL_SRC'],
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
            $targetPadding = 'padding:'.$layout['padding'].';';
            // Schema-7-Publikationen koennen noch den frueheren unteren
            // 20-px-Starterabstand enthalten. Beide exakten Starterwerte
            // werden abgebildet; individuell editierte Abstaende bleiben
            // unangetastet.
            $replacements['padding:18px 36px 20px;'] = $targetPadding;
            $replacements['padding:18px 36px 0;'] = $targetPadding;
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
