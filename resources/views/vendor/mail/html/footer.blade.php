{{--
    Fusszeile jeder Systemmail: der GEMEINSAME RailTime-Signaturblock.

    Damit tragen Notifications, Mailables, die herunterladbare Vorlage und
    die herunterladbare Signatur genau dasselbe Markup — die Quelle liegt in
    resources/views/emails/parts/signature.blade.php.

    Systemmails haben keinen menschlichen Absender: MailSignature::forCompany()
    setzt Firmenname und Claim an die Stelle von Name und Funktion und zeigt
    die Firmenanschluesse. Einfahrt und Schlussbild laufen im einmaligen
    Haupt-GIF hinter den Daten; nach 13 Sekunden uebernimmt nur eine kleine
    transparente Idle-Rauchschleife im selben Layer. Classic Outlook bekommt wegen
    seiner Word-Engine stattdessen genau ein bedingtes PNG-Standbild in einer
    normalen Tabellenzeile.

    Der uebergebene $slot (Vertraulichkeitshinweis) steckt bereits im
    Rechtsblock der Signatur; er wird deshalb bewusst nicht erneut ausgegeben.
--}}
@php
    // Systemmails verlinken die Assets und bleiben dadurch klein. Der
    // validierte Editor-Carrier behaelt das echte GIF als absolute IMG-Ebene
    // hinter dem Inhalt. Ein legacy background-Attribut ist verboten, weil
    // Word es kachelt; nur Word/MSO sieht den bedingten PNG-Fallback.
    $signatur = \App\Support\MailSignature::forCompany();
@endphp
{!! $signatur->render() !!}
