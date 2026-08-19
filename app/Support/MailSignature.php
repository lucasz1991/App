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
                'TRAIN_SRC' => $this->withRemotePlaybackNonce(
                    EmailTemplateBuilder::signatureTrainUrl(
                        $this->theme,
                        $this->staticAssets ? false : $this->animated,
                    ),
                ),
                // Nur als Legacy-Wert fuer alte Dokumente vorhanden. Neue
                // Ausgaben transportieren ausschliesslich das kombinierte GIF.
                'TRAIN_STILL_SRC' => EmailTemplateBuilder::signatureTrainStillUrl($this->theme),
                // Einfahrt, Idle-Rauch und sichtbarer Schlusszustand stecken
                // gemeinsam im Haupt-GIF. Eine zweite Rauchquelle wuerde den
                // Zug beim Antworten oder Weiterleiten wieder duplizieren.
                'TRAIN_IDLE_SRC' => '',
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
                // Nur als Legacy-Wert fuer alte Dokumente vorhanden.
                'TRAIN_STILL_SRC' => '',
                'TRAIN_IDLE_SRC' => '',
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
                $html = $this->normalizePublishedTrainCarrier($html);
                $html = $this->hydrateTrainBackground($html, $singleTrainSource);
                if ($this->remoteAssets) {
                    $html = $this->appendClassicOutlookTrainFallback($html, $singleTrainSource);
                }
            }

            $html = trim(EmailTemplateBuilder::stripEmptyContactRows(
                $html,
                $this->contactRowValues($values),
            ));

            return $this->finalizeTrainRendering($html);
        }

        // Der normale HTML-Pfad behaelt den streng normalisierten Zug-Layer
        // im Carrier: Der Zug liegt damit ohne eigene Layouthoehe hinter den
        // Kontakt- und Firmendaten. Nur explizite Outlook-/EML-/ZIP-Exporte
        // loesen ihn in eine regulaere Bildzeile auf. Versendete Remote-Mails
        // erhalten zusaetzlich einen ausschliesslich fuer Word sichtbaren
        // conditional-comment-Fallback; moderne Clients sehen weiterhin nur
        // den einen Background-Layer.
        if ($published !== null) {
            SignatureDocumentContract::assertRuntimeValid($published);

            $html = $this->applyPublishedLayout($published, $layout);
            $html = $explicitTrainSource !== ''
                ? $this->projectPublishedTrainAsImage($html, $singleTrainLayout)
                : $this->normalizePublishedTrainCarrier($html);
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

            $html = strtr($html, $tokens);
            if ($this->remoteAssets && $explicitTrainSource === '') {
                $html = $this->appendClassicOutlookTrainFallback($html, $singleTrainSource);
            }
            // Alte publizierte Staende koennen bis zum erneuten Seedern noch
            // den inzwischen entfernten Classic-Outlook-Fallback tragen.
            // Leere Attribute verschwinden sofort; ein befuelltes Attribut
            // wird im zentralen Train-Finalizer gezielt am Carrier entfernt.
            $html = preg_replace('/\s+background=(["\'])\s*\1/i', '', $html) ?? $html;

            $html = trim(EmailTemplateBuilder::stripEmptyContactRows(
                $html,
                $this->contactRowValues($values),
            ));

            return $this->finalizeTrainRendering($html);
        }

        throw new \RuntimeException('Die veröffentlichte Signatur konnte nicht gerendert werden.');
    }

    /**
     * Sichtbarkeitswerte fuer die markergebundenen Kontaktzeilen.
     *
     * Der kanonische Signaturentwurf wird mit Platzhaltern gespeichert. Beim
     * Seed-Zeitpunkt ist {{VORNAME_NACHNAME}} technisch nicht leer; deshalb
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
     * ohne auf einen Seeder-Lauf angewiesen zu sein.
     */
    private function finalizeTrainRendering(string $html): string
    {
        return $this->removeLegacyTrainBackground($html);
    }

    /**
     * Erzwingt denselben serverkontrollierten Zugvertrag, den der Editor
     * bereits beim Speichern und Veroeffentlichen validiert.
     */
    private function normalizePublishedTrainCarrier(string $html): string
    {
        return SignatureTrainCarrier::normalize($html);
    }

    /**
     * Ersetzt den einen serverkontrollierten Zug-Token erst nach der
     * Carrier-Normalisierung. So bleibt die editierte, erlaubte Position in
     * allen gekoppelten Background-Listen erhalten, waehrend freie Quellen
     * niemals in einen CSS-Kontext gelangen.
     */
    private function hydrateTrainBackground(string $html, string $source): string
    {
        if (substr_count($html, '{{TRAIN_SRC}}') !== 1 || trim($source) === '') {
            throw new \RuntimeException('Der Zug-Hintergrund besitzt keine eindeutige Bildquelle.');
        }

        return str_replace(
            '{{TRAIN_SRC}}',
            htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            $html,
        );
    }

    /**
     * Classic Outlook/Word stellt CSS-Hintergrundbilder nicht verlaesslich
     * dar. Ausschliesslich versendete Remote-Mails erhalten deshalb dieselbe
     * kombinierte Zugdatei als normale, MSO-only Tabellenzeile. Der bedingte
     * Kommentar ist fuer Browser, Webmail und New Outlook unsichtbar; dort
     * bleibt der Zug genau einmal als Background hinter dem Inhalt.
     */
    private function appendClassicOutlookTrainFallback(string $html, string $source): string
    {
        $source = trim($source);
        $marker = '<!-- RT_SIGNATURE_MAIN_END -->';
        if ($source === '' || substr_count($html, $marker) !== 1) {
            throw new \RuntimeException('Der Classic-Outlook-Zug besitzt keinen eindeutigen Anker.');
        }
        if (substr_count($html, 'data-rt-train') !== 0) {
            throw new \RuntimeException('Der Classic-Outlook-Zug ist bereits vorhanden.');
        }

        $source = htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $fallback = '<!--[if mso]><tr><td align="left" style="padding:0;text-align:left;'
            .'font-size:0;line-height:0;"><img class="rt-sign-train" data-rt-train '
            .'src="'.$source.'" width="100%" alt="" style="display:block;width:100%;'
            .'max-width:1815px;height:auto;margin:0;border:0;outline:none;'
            .'text-decoration:none;"></td></tr><![endif]-->';
        $rendered = str_replace($marker, $marker.$fallback, $html);

        if (substr_count($rendered, 'data-rt-train') !== 1) {
            throw new \RuntimeException('Der Classic-Outlook-Zug konnte nicht eindeutig eingesetzt werden.');
        }

        return $rendered;
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
        $replacements = 0;
        $rendered = preg_replace_callback(
            '/<td\b[^>]*class=(["\'])[^"\']*\brt-sign-cell\b[^"\']*\1[^>]*>/i',
            static function (array $match): string {
                return preg_replace('/\s+background=(["\'])[^"\']*\1/i', '', $match[0]) ?? $match[0];
            },
            $html,
            1,
            $replacements,
        );

        if (! is_string($rendered) || $replacements !== 1) {
            throw new \RuntimeException('Die veroeffentlichte Signatur besitzt keinen eindeutigen Zug-Carrier.');
        }

        return $rendered;
    }

    /**
     * Ausschliesslich explizite Outlook-/EML-/ZIP-Ausgaben bekommen den Zug
     * wie Logo und RT-Icon als regulaeres Bild. Der kanonische Editorstand
     * enthaelt den streng validierten Token weiterhin im Carrier; diese
     * Methode loest ihn atomar aus den parallelen CSS-Listen und setzt genau
     * eine kompakte Bildzeile vor die Pflichtdaten.
     *
     * @param  array<string, string>  $layout
     */
    private function projectPublishedTrainAsImage(string $html, array $layout): string
    {
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
