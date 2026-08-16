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
        // Playback-URLs. Der echte Runtime-IMG-Weg verbessert auch das erneute
        // Abspielen beim Oeffnen; ob dieselbe Mail wirklich neu startet, bleibt
        // jedoch eine Entscheidung des jeweiligen Mailclient-/Proxy-Caches.
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
                // Das Standbild bleibt dem expliziten Outlook-Paketexport
                // vorbehalten. Versendete Systemmails projizieren TRAIN_SRC
                // genau einmal als unbedingtes Runtime-IMG; der moderne CSS-
                // Hauptlayer wird dabei entfernt, damit nie zwei Zuege stehen.
                'TRAIN_STILL_SRC' => EmailTemplateBuilder::signatureTrainStillUrl($this->theme),
                // Die Rauchschleife wird erst nach dem Ende der einmaligen
                // 13-s-Einfahrt eingeblendet. Statische Fassungen und
                // Signaturen ohne Einfahrt laden sie deshalb gar nicht.
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
        $runtimeTrainImage = $this->usesRuntimeTrainImage($values, $layout);

        // Vor der MailDocument-Migration bleibt der bestehende Bootstrapweg
        // verwendbar. In einer migrierten Installation erzwingt
        // runtimeDocument dagegen eine echte Veröffentlichung.
        $published = EmailTemplateBuilder::runtimeDocument(
            MailDocumentKind::Signature,
            requirePublished: $this->remoteAssets,
        );
        if ($published === null) {
            $viewValues = $values;
            if ($runtimeTrainImage) {
                // Dieselbe tokenisierte Zwischenform wie ein publiziertes
                // Dokument verwenden, damit auch der Bootstrap-Fallback den
                // fail-closed Carrier-Parser durchlaeuft.
                $viewValues['TRAIN_SRC'] = '{{TRAIN_SRC}}';
            }
            $html = View::make('emails.parts.signature', array_merge([
                'values' => $viewValues,
            ], $layout))->render();

            if ($runtimeTrainImage) {
                $html = $this->projectPublishedTrainAsRuntimeImage(
                    $this->normalizePublishedTrainCarrier($html),
                );
                $html = str_replace(
                    '{{TRAIN_SRC}}',
                    htmlspecialchars($values['TRAIN_SRC'], ENT_QUOTES, 'UTF-8'),
                    $html,
                );
            }

            $html = trim(EmailTemplateBuilder::stripEmptyContactRows(
                $html,
                $this->contactRowValues($values),
            ));

            return $this->finalizeTrainRendering($html, $values, $layout);
        }

        // JEDER VERSANDWEG BEKOMMT DEN ZUG ALS REGULAERES BILD.
        //
        // Der gespeicherte Entwurf behaelt den geschuetzten Background-Token
        // fuer Editor und Downloads. Erst nach seiner vollstaendigen Runtime-
        // Validierung wird dieser eine Layer aus allen vier gekoppelten CSS-
        // Listen geloest und als genau ein echtes IMG in denselben oberen
        // Carrier projiziert. Deshalb startet der Zug beim Oeffnen wie die
        // Logo-/Icon-GIFs neu und bleibt auch in Reply-/Forward-Zitaten als
        // normales Bild erhalten.
        //
        // Das installierbare Outlook-Paket nutzt weiterhin seine lokale
        // Bildquelle. Systemmails nutzen die remote URL mit einer pro Mail
        // stabilen Playback-ID; es existiert niemals gleichzeitig noch ein
        // moderner Hauptzug-CSS-Layer.
        $zugAlsBild = trim((string) ($layout['outlookTrainSrc'] ?? '')) !== '';
        if ($published !== null) {
            SignatureDocumentContract::assertValid(
                $published,
                allowLegacyTrainStill: true,
            );

            $html = $this->applyPublishedLayout($published, $layout);
            if ($zugAlsBild) {
                $html = $this->projectPublishedTrainAsImage($html, $layout);
            } else {
                $html = $this->normalizePublishedTrainCarrier($html);
                if ($runtimeTrainImage) {
                    $html = $this->projectPublishedTrainAsRuntimeImage($html);
                }
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

            $html = strtr($html, $tokens);
            // Alte publizierte Staende koennen bis zum erneuten Seedern noch
            // den inzwischen entfernten Classic-Outlook-Fallback tragen.
            // Leere Attribute verschwinden sofort; ein befuelltes Attribut
            // wird im zentralen Train-Finalizer gezielt am Carrier entfernt.
            $html = preg_replace('/\s+background=(["\'])\s*\1/i', '', $html) ?? $html;

            $html = trim(EmailTemplateBuilder::stripEmptyContactRows(
                $html,
                $this->contactRowValues($values),
            ));

            return $this->finalizeTrainRendering($html, $values, $layout);
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
     *
     * @param  array<string, string>  $values
     * @param  array<string, string>  $layout
     */
    private function finalizeTrainRendering(string $html, array $values, array $layout): string
    {
        $html = $this->removeLegacyTrainBackground($html);

        return $this->injectDelayedIdleOverlay($html, $values, $layout);
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
    private function usesRuntimeTrainImage(array $values, array $layout): bool
    {
        return $this->remoteAssets
            && trim((string) ($layout['outlookTrainSrc'] ?? '')) === ''
            && trim((string) ($values['TRAIN_SRC'] ?? '')) !== '';
    }

    /**
     * Projiziert den bereits validierten Hauptzug in genau EIN regulaeres
     * Bild innerhalb des oberen Carriers.
     *
     * Das IMG liegt absolut hinter dem Inhalt und steckt in einem explizit
     * null Pixel hohen, ueberlaufenden Layer. So reservieren auch Clients mit
     * lueckenhafter Positionierungsunterstuetzung keine zweite Bildhoehe; bei
     * CSS-Verlust bleibt dasselbe regulaere IMG erhalten, aber niemals als
     * zusaetzliche Signaturzeile. Bis 1815 px skaliert die Leinwand fluide,
     * darueber bleibt sie linksbuendig auf ihrer echten Maximalbreite. Damit
     * endet die Lok bis 1815 px bei 75 Prozent und auf breiteren Ansichten
     * wird das Zugheck nicht abgeschnitten.
     */
    private function projectPublishedTrainAsRuntimeImage(string $html): string
    {
        if (str_contains($html, 'data-rt-train-main-image')
            || str_contains($html, 'data-rt-train-main-layer')) {
            throw new \RuntimeException('Die veroeffentlichte Signatur enthaelt bereits einen Runtime-Zug.');
        }

        $html = SignatureTrainCarrier::withoutMainLayer($html);
        $image = '<img class="rt-train-main-image" data-rt-train-main-image '
            .'src="{{TRAIN_SRC}}" width="100%" alt="Dampflok-G&uuml;terzug" '
            .'style="display:block;position:absolute;left:0;right:auto;bottom:0;z-index:0;'
            .'width:100%;max-width:1815px;height:auto;margin:0;'
            .'border:0;outline:none;text-decoration:none;opacity:1;visibility:visible;">';
        $layer = '<span class="rt-train-main-layer" data-rt-train-main-layer '
            .'style="display:block;width:100%;height:0;max-height:0;overflow:visible;'
            .'font-size:0;line-height:0;mso-line-height-rule:exactly;">'.$image.'</span>';
        $replacements = 0;
        $rendered = preg_replace_callback(
            '/<td\b[^>]*class=(["\'])[^"\']*\brt-sign-cell\b[^"\']*\1[^>]*>/i',
            static fn (array $match): string => $match[0].$layer,
            $html,
            1,
            $replacements,
        );

        if (! is_string($rendered)
            || $replacements !== 1
            || substr_count($rendered, 'data-rt-train-main-layer') !== 1
            || substr_count($rendered, 'data-rt-train-main-image') !== 1
            || substr_count($rendered, '{{TRAIN_SRC}}') !== 1) {
            throw new \RuntimeException('Der Runtime-Zug konnte nicht eindeutig in den oberen Carrier projiziert werden.');
        }

        return $rendered;
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
     * Haengt die reine Rauchschleife in DENSELBEN oberen Zug-Carrier.
     *
     * Das gespeicherte Maildokument bleibt frei von Animation und absoluter
     * Positionierung. Erst der serverkontrollierte Renderweg setzt dieses
     * feste Markup ein; die ebenfalls serverkontrollierten Umbruchregeln
     * blenden es nach exakt 13 Sekunden ein. Ohne Keyframe-Unterstuetzung
     * bleibt das Overlay unsichtbar und layoutneutral. Runtime-Hauptzug und
     * Smoke-IMG teilen exakt dieselbe fluide Geometrie. Standalone-/Editor-
     * Fassungen behalten dagegen die zum dortigen CSS-Hauptzug passende
     * Background-Surface. Der Outlook-Paketexport behaelt seine einzelne
     * regulaere Bildzeile ohne Overlay.
     *
     * @param  array<string, string>  $values
     * @param  array<string, string>  $layout
     */
    private function injectDelayedIdleOverlay(string $html, array $values, array $layout): string
    {
        $idleSource = trim((string) ($values['TRAIN_IDLE_SRC'] ?? ''));
        $outlookExport = trim((string) ($layout['outlookTrainSrc'] ?? '')) !== '';

        if (! $this->animated
            || $this->staticAssets
            || $idleSource === ''
            || $outlookExport) {
            return $html;
        }

        if (str_contains($html, 'data-rt-train-idle-overlay')
            || str_contains($html, 'data-rt-train-idle-image')) {
            throw new \RuntimeException('Die veroeffentlichte Signatur enthaelt bereits eine unzulaessige Idle-Ebene.');
        }

        $source = htmlspecialchars($idleSource, ENT_QUOTES, 'UTF-8');
        $idleSurface = str_contains($html, 'data-rt-train-main-image')
            ? '<img class="rt-train-idle-surface rt-train-idle-image" data-rt-train-idle-image '
                .'src="'.$source.'" width="100%" alt="" '
                .'style="display:block;position:absolute;left:0;right:auto;bottom:0;z-index:1;'
                .'width:100%;max-width:1815px;height:auto;max-height:none;'
                .'margin:0;border:0;outline:none;text-decoration:none;opacity:1;visibility:visible;">'
            : '<span class="rt-train-idle-surface" style="display:block;width:1px;height:1px;'
                .'max-width:1px;max-height:1px;background-image:url('.$source.');background-repeat:no-repeat;'
                .'background-position:75% bottom;background-size:auto 100%;"></span>';
        $overlay = '<span class="rt-train-idle-overlay" data-rt-train-idle-overlay '
            .'style="display:block;width:100%;height:0;max-height:0;overflow:hidden;'
            .'opacity:0;visibility:hidden;animation:rt-train-idle-reveal 1ms step-start 13s forwards;'
            .'font-size:0;line-height:0;mso-hide:all;">'
            .$idleSurface.'</span>';
        $replacements = 0;
        $rendered = preg_replace_callback(
            '/<td\b[^>]*class=(["\'])[^"\']*\brt-sign-cell\b[^"\']*\1[^>]*>/i',
            static fn (array $match): string => $match[0].$overlay,
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
     * Der Outlook-Export braucht den Zug als regulaeres lokales Bild. Der
     * kanonische veroeffentlichte Signaturstand enthaelt ihn als Hintergrund;
     * der feste Marker projiziert ihn ausschliesslich fuer das installierbare
     * Classic-Outlook-Paket in eine eigene Bildzeile. Versendete Systemmails
     * laufen nicht durch diesen Paketpfad, sondern erhalten ihr einzelnes
     * Runtime-IMG direkt im oberen Carrier.
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
        $animated = '<img data-rt-outlook-train src="'.$source.'" width="100%" '
            .'alt="Dampflok-Güterzug" style="display:block;width:100%;'
            .'height:auto;margin:0;border:0;outline:none;">';
        $images = $animated;

        if ($fallback !== '') {
            $images = '<!--[if !mso]><!-->'.$animated.'<!--<![endif]-->'
                .'<!--[if mso]><img data-rt-outlook-train-still src="'.$fallback.'" width="100%" '
                .'alt="Dampflok-Güterzug" style="display:block;width:100%;'
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
