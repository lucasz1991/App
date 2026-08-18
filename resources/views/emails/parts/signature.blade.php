{{--
    EINZIGE Quelle des RailTime-Signaturblocks.

    Genutzt von:
      - den herunterladbaren Signaturen (signature-*-master.html)
      - der herunterladbaren E-Mail-Vorlage (email-master.html)
      - jeder Laravel-Mail und -Notification (vendor/mail/html/footer.blade.php)

    Der kanonische Editorstand besitzt zwei Tabellenzeilen: Signaturblock und
    Pflichtangaben. Beim Ausliefern wird der dort streng gebundene Zug-Layer
    entfernt und genau eine normale GIF-Bildzeile dazwischen eingesetzt. Der
    Aufrufer stellt die umgebende <table> — dadurch passt derselbe Block in die
    schmale Signaturdatei wie in die breite Nachrichtenschale.

    AUFBAU: zwei gleich breite Spalten an einer Mittelachse. Links die
    Person, rechts die Firma. Die Firmenkontakte existieren genau einmal:
    Der gemeinsame Tabellenblock sitzt im Breitlayout rechts und wird auf
    schmalen Ansichten linksbuendig dargestellt. Damit koennen Mailclients
    beim Antworten oder Weiterleiten niemals eine versteckte zweite Fassung
    zusaetzlich sichtbar machen.

    Die Markenspalte zeigt bewusst NUR den Schriftzug: das RT-Zeichen davor
    doppelte die Marke auf engem Raum, und der Claim darunter kostete eine
    Zeile, ohne etwas zu sagen. Auch die Wortmarke existiert nur einmal im
    DOM; mobil wird dieselbe Firmenspalte unter die Person gesetzt.

    Alle Werte kommen bereits HTML-escaped aus App\Support\MailSignature.
    Optionale Zeilen (Durchwahl, Mobil, Website, Firmentelefon) tragen die
    RT_*_START/END-Marker, damit der EmailTemplateBuilder sie fuer leere
    Werte vollstaendig entfernen kann.

    @param array  $values   Werte- und Farbtabelle (siehe MailSignature)
    @param string $padding  Innenabstand der Signaturzelle
    @param string $topRule  Akzentlinie oben (in der Signaturdatei leer)
--}}
@php
    // Kein unterer Leerraum vor der regulaeren Zugzeile: Das GIF besitzt
    // selbst den benoetigten Rauch-/Himmelsbereich. Zusaetzliches Padding
    // vergroessert nur den sichtbaren Abstand und die Signaturhoehe.
    $padding = $padding ?? '18px 36px 0';
    $topRule = $topRule ?? 'border-top:5px solid #e4002b;';
    $legalPadding = $legalPadding ?? '14px 36px';
    $outlookTrainSrc = trim((string) ($outlookTrainSrc ?? ''));
    // Der Zug wird in jedem Ausgabeweg als genau ein regulaeres Bild
    // transportiert. Ein zweites Stand- oder Idle-Bild ist nicht noetig:
    // das Haupt-GIF enthaelt Einfahrt, Rauchphase und sichtbaren Endzustand.
    $isOutlookExport = $outlookTrainSrc !== '';
    $outlookTrainPadding = $outlookTrainPadding ?? '0';
    $hasPerson = trim((string) ($values['VORNAME_NACHNAME'] ?? '')) !== '';
    $pflichtangaben = implode(' · ', array_filter([
        ($values['GESCHAEFTSFUEHRUNG'] ?? '') !== '' ? 'Geschäftsführung: '.$values['GESCHAEFTSFUEHRUNG'] : '',
        ($values['REGISTERGERICHT'] ?? '') !== '' ? 'Registergericht: '.$values['REGISTERGERICHT'] : '',
        ($values['HRB'] ?? '') !== '' ? 'HRB '.$values['HRB'] : '',
        ($values['UST_ID'] ?? '') !== '' ? 'USt-IdNr. '.$values['UST_ID'] : '',
        ($values['STEUERNUMMER'] ?? '') !== '' ? 'Steuernummer '.$values['STEUERNUMMER'] : '',
    ]));
    /*
     * DIE EBENEN DES STREIFENS, von oben nach unten.
     *
     * In CSS steht die ZUERST genannte Ebene ganz oben:
     *
     *   1  Raster        feines technisches Netz, gekachelt, durchsichtig
     *   2  Wasserzeichen RT-Marke und roter Schimmer, EINMAL rechts
     *   3  Kompatibilitaetsebene (transparent; fuer alte DB-Dokumente)
     *   4  Zug           Einfahrt und anschliessende Idle-Rauchphase
     *   5  Grundfarbe    weiss beziehungsweise dunkel
     *
     * RASTER UND MARKE LIEGEN OBEN, nicht unten. Die Zugassets tragen die
     * endgueltigen 30 Prozent Alpha bereits selbst. Die dritte Ebene bleibt
     * nur transparent erhalten, damit alte publizierte Vier-Ebenen-Staende
     * und ihre mobilen Positionslisten kompatibel bleiben; sie darf die
     * Deckkraft nicht ein zweites Mal reduzieren.
     *
     * Der Text liegt in der Zelle und damit ueber allen Ebenen — die
     * geforderte Reihenfolge Hintergrund < Zug < Daten ergibt sich daraus
     * von selbst, ganz ohne z-index (den viele Mailclients ignorieren).
     *
     * DER ZUG FUELLT DIE HOEHE, NICHT DIE BREITE.
     *
     * Frueher war er auf 77 % der Breite gelegt und lag als flaches Band am
     * unteren Rand — unter den Daten, nicht dahinter. Mit `auto 100 %`
     * richtet er sich stattdessen an der Streifenhoehe aus und reicht damit
     * hinter die Schrift.
     *
     * Das macht ihn zugleich von selbst BREITENABHAENGIG: Auf einem
     * schmalen Schirm ist der Streifen niedriger, der Zug also kleiner und
     * weniger davon zu sehen; auf einem breiten Schirm faehrt mehr ins
     * Bild. Es braucht dafuer keine einzige zusaetzliche Umbruchregel.
     *
     * Die Zugfront liegt im Asset bei 75 Prozent seiner Breite. Mit derselben
     * horizontalen background-position bleibt sie mathematisch bei exakt
     * 75 Prozent der Carrierbreite — unabhaengig davon, wie breit das auf
     * `auto 100%` skalierte Bild gegenueber der Zelle ist.
     *
     * IM OUTLOOK-WEG entfallen Zug und Kompatibilitaetsebene: dort steht der Zug als
     * eigene Bildzeile unter dem Inhalt. Als Hintergrund UND als Bildzeile
     * stuende er zweimal im Streifen.
     */
    $zugMass = 'auto 100%';
    $ebenen = array_values(array_filter([
        ($values['GRUND_RASTER_SRC'] ?? '') !== ''
            ? "url({$values['GRUND_RASTER_SRC']})|left top|64px 64px|repeat" : '',
        ($values['GRUND_MARKE_SRC'] ?? '') !== ''
            ? "url({$values['GRUND_MARKE_SRC']})|right center|auto 100%|no-repeat" : '',
        $isOutlookExport ? '' : "linear-gradient({$values['SIGNATURE_TRAIN_WASH']},{$values['SIGNATURE_TRAIN_WASH']})|center center|100% 100%|no-repeat",
        $isOutlookExport ? '' : "url({$values['TRAIN_SRC']})|75% bottom|{$zugMass}|no-repeat",
    ]));
    $teile = array_map(static fn (string $e): array => explode('|', $e), $ebenen);
    $backgroundImage = implode(',', array_column($teile, 0));
    $backgroundPosition = implode(',', array_column($teile, 1));
    $backgroundSize = implode(',', array_column($teile, 2));
    $backgroundRepeat = implode(',', array_column($teile, 3));
@endphp
<tr>
    {{-- Kein legacy background-Attribut: Classic Outlook/Word kachelt dessen
         Bild ungeachtet von background-repeat und background-size. Der
         validierte Hauptzug bleibt deshalb genau einmal in den gekoppelten
         CSS-Listen und liegt ohne eigene Layouthoehe hinter den Inhaltsdaten. --}}
    <td class="rt-sign-cell" bgcolor="{{ $values['SIGNATURE_BG'] }}" style="padding:0;position:relative;overflow:hidden;background-color:{{ $values['SIGNATURE_BG'] }};background-image:{{ $backgroundImage }};background-repeat:{{ $backgroundRepeat }};background-position:{{ $backgroundPosition }};background-size:{{ $backgroundSize }};{{ $topRule }}">
        {{-- Der aeussere Zug-Carrier bleibt absichtlich ohne Padding. So
             endet die `bottom`-Ausrichtung des kombinierten Haupt-GIFs an der
             echten unteren Carrierkante direkt auf dem grauen Legal-Footer.
             Der mail-sichere innere Tabellenwrapper behaelt unabhaengig davon
             dieselben Inhaltsabstaende und die kompakte Signaturhoehe. --}}
        <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;position:relative;z-index:1;">
            <tr>
                <td class="rt-pad rt-sign-content" style="padding:{{ $padding }};position:relative;z-index:1;">
                    <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;position:relative;z-index:1;">
                        <tr class="rt-stack">
                {{-- Ohne Person bleibt die Namenszeile LEER: Die Marke steht
                     bereits als Wortmarke in der rechten Spalte, der
                     Firmenname darunter waere eine Doppelung. --}}
                <td class="rt-sign-identity" width="50%" valign="top" align="left" style="width:50%;padding:0 24px 0 0;position:relative;z-index:1;text-align:left;vertical-align:top;">
                    {{-- Eigener Behaelter, damit Name und Funktion gestapelt
                         NEBEN die Kontaktliste ruecken koennen statt darueber
                         (siehe responsive-css: inline-block). --}}
                    <div class="rt-person-kopf">
                    <p class="rt-sign-name" style="margin:0 0 4px;color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};font-size:23px;line-height:27px;font-weight:bold;letter-spacing:-.5px;">{{ $hasPerson ? $values['VORNAME_NACHNAME'] : '' }}</p>
                    <p style="margin:0;color:{{ $values['SIGNATURE_ACCENT'] }};font-family:Consolas,'Courier New',monospace;font-size:10px;line-height:16px;font-weight:bold;letter-spacing:1.2px;text-transform:uppercase;">{{ $values['POSITION'] }}</p>
                    </div>

                    <table class="rt-contact" role="presentation" dir="ltr" border="0" cellspacing="0" cellpadding="0" style="direction:ltr;margin-left:0;margin-right:auto;margin-top:14px;border-collapse:collapse;">
                        <tbody>
                        <!-- RT_PHONE_START -->
                        <tr>
                            <td width="22" align="center" valign="middle" class="rt-contact-icon" style="width:22px;padding:0 0 6px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values['ICON_PHONE_SRC'] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
                            <td valign="middle" class="rt-contact-text" style="padding:0 0 6px 9px;color:{{ $values['SIGNATURE_CONTACT_TEXT'] }};font-size:12px;line-height:18px;"><a href="tel:{{ $values['DURCHWAHL_TEL'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $values['DURCHWAHL'] }}</a></td>
                        </tr>
                        <!-- RT_PHONE_END -->
                        <!-- RT_MOBILE_START -->
                        <tr>
                            <td width="22" align="center" valign="middle" class="rt-contact-icon" style="width:22px;padding:0 0 6px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values['ICON_MOBILE_SRC'] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
                            <td valign="middle" class="rt-contact-text" style="padding:0 0 6px 9px;color:{{ $values['SIGNATURE_CONTACT_TEXT'] }};font-size:12px;line-height:18px;"><a href="tel:{{ $values['MOBIL_TEL'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $values['MOBIL'] }}</a></td>
                        </tr>
                        <!-- RT_MOBILE_END -->
                        <tr>
                            <td width="22" align="center" valign="middle" class="rt-contact-icon" style="width:22px;padding:0;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values['ICON_EMAIL_SRC'] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
                            <td valign="middle" class="rt-contact-text" style="padding:0 0 0 9px;color:{{ $values['SIGNATURE_CONTACT_TEXT'] }};font-size:12px;line-height:18px;"><a href="mailto:{{ $values['E_MAIL'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $values['E_MAIL'] }}</a></td>
                        </tr>
                        </tbody>
                    </table>
                </td>
                {{-- Die Trennlinie sitzt an der Firmenspalte. Beim Stapeln
                     wandert sie nach oben (siehe responsive-css). --}}
                <td class="rt-sign-logo" width="50%" valign="top" align="right" style="width:50%;padding-left:24px;border-left:1px solid {{ $values['SIGNATURE_BORDER'] }};text-align:right;vertical-align:top;">
                    {{-- OUTLOOK BEKOMMT DAS STANDBILD. Die bewegte Marke baut
                         sich Zeichen fuer Zeichen auf; ihr erstes Einzelbild
                         ist deshalb fast leer — und genau dieses eine zeigt
                         Outlook-Desktop. Ohne diese Verzweigung stuende dort
                         eine leere Flaeche statt der Marke.

                         Bewusst mso-hide plus EIN geschlossener bedingter
                         Kommentar: die aufgebrochene, zweiteilige Form laesst
                         der EmailHtmlSanitizer nicht durch. --}}
                    <img class="rt-logo" src="{{ $values['LOGO_SRC'] }}" width="210" alt="{{ $values['FIRMENNAME'] }}" style="display:block;width:210px;max-width:100%;height:auto;margin-left:auto;mso-hide:all;">
                    <!--[if mso]><img class="rt-logo" src="{{ $values['LOGO_STILL_SRC'] ?? $values['LOGO_SRC'] }}" width="210" alt="{{ $values['FIRMENNAME'] }}" style="display:block;width:210px;height:auto;margin-left:auto;"><![endif]-->
                    {{-- Im unpersoenlichen Fall stehen Firmentelefon und
                         Firmen-E-Mail bereits links an der Stelle von
                         Durchwahl und Mailadresse. Rechts blieben sie eine
                         sichtbare Doppelung. --}}
                    {{-- EIN gemeinsamer Kontaktblock fuer alle Breiten.
                         Frueher lagen hier eine rechte Desktop- und eine
                         versteckte linke Mobilkopie. Outlook und andere
                         Clients koennen beim Antworten/Weiterleiten die
                         zugehoerigen Media-Queries entfernen und dadurch
                         beide Fassungen anzeigen. Die Tabelle verwendet
                         deshalb in jeder Ansicht dieselbe mailclient-sichere
                         Reihenfolge: Symbol links, Text rechts. Lediglich die
                         Position des Blocks wird responsiv angepasst. --}}
                    <div align="right" style="text-align:right;">
                        @include('emails.parts.company-contact-table', [
                            'values' => $values,
                            'align' => 'right',
                            'ohneDoppelung' => ! $hasPerson,
                        ])
                    </div>
                </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
        @if($isOutlookExport)
            {{-- Bootstrap-/Outlook-Paketpfad: dieselbe einzelne GIF-Zeile wie
                 in versendeten und heruntergeladenen HTML-Fassungen. Die
                 Zeile beginnt ohne Zusatzabstand direkt nach den Daten. --}}
            <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse;">
                <tr>
                    <td align="left" style="padding:{{ $outlookTrainPadding }};text-align:left;font-size:0;line-height:0;">
                        <img class="rt-sign-train" data-rt-train src="{{ $outlookTrainSrc }}" width="100%" alt="" style="display:block;width:100%;max-width:1815px;height:auto;margin:0;border:0;outline:none;text-decoration:none;">
                    </td>
                </tr>
            </table>
        @endif
    </td>
</tr>
<!-- RT_SIGNATURE_MAIN_END -->
<tr>
    <td class="rt-pad" bgcolor="{{ $values['SIGNATURE_LEGAL_BG'] }}" style="padding:{{ $legalPadding }};background:{{ $values['SIGNATURE_LEGAL_BG'] }};color:{{ $values['SIGNATURE_LEGAL_TEXT'] }};font-size:9px;line-height:15px;">
        {{-- Beschriftung und Wert gehoeren zusammen: eine nicht gepflegte
             Angabe faellt samt ihrer Beschriftung weg. Vorher endete die
             Zeile in jeder Mail auf ein blankes "Steuernummer", weil die
             Vorgabe dafuer leer ist. --}}
        {{ $pflichtangaben }}<br>
        Diese E-Mail kann vertrauliche Informationen enthalten. Sollten Sie nicht der vorgesehene Empfänger sein, informieren Sie bitte den Absender und löschen Sie diese Nachricht.
    </td>
</tr>
