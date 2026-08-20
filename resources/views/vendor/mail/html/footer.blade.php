{{--
    Fusszeile jeder Systemmail: der GEMEINSAME RailTime-Signaturblock.

    Damit tragen Notifications, Mailables, die herunterladbare Vorlage und
    die herunterladbare Signatur genau dasselbe Markup — die Quelle liegt in
    resources/views/emails/parts/signature.blade.php.

    Systemmails haben keinen menschlichen Absender: MailSignature::forCompany()
    setzt Firmenname und Claim an die Stelle von Name und Funktion und zeigt
    die Firmenanschluesse. Einfahrt und Schlussbild laufen im einmaligen
    Haupt-GIF als absolut positioniertes IMG hinter den Daten; nach
    13 Sekunden uebernimmt ein zweites transparentes Idle-IMG die
    Rauchschleife. Classic Outlook bekommt ein bedingtes PNG-IMG innerhalb
    derselben Stage. Keine GIF-Datei wird per CSS geladen.

    Der uebergebene $slot (Vertraulichkeitshinweis) steckt bereits im
    Rechtsblock der Signatur; er wird deshalb bewusst nicht erneut ausgegeben.
--}}
@php
    // Systemmails verlinken die Assets und bleiben dadurch klein. Der
    // validierte Editor-Carrier behaelt auch in der fertigen Mail seine
    // absoluten IMG-Layer. Nur Word/MSO sieht das bedingte PNG-IMG; eine
    // separate Zugzeile wird nicht erzeugt.
    $signatur = \App\Support\MailSignature::forCompany();
@endphp
{!! $signatur->render() !!}
