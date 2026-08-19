{{--
    Fusszeile jeder Systemmail: der GEMEINSAME RailTime-Signaturblock.

    Damit tragen Notifications, Mailables, die herunterladbare Vorlage und
    die herunterladbare Signatur genau dasselbe Markup — die Quelle liegt in
    resources/views/emails/parts/signature.blade.php.

    Systemmails haben keinen menschlichen Absender: MailSignature::forCompany()
    setzt Firmenname und Claim an die Stelle von Name und Funktion und zeigt
    die Firmenanschluesse. Einfahrt, Rauchphase und Schlussbild laufen als
    ein kombiniertes GIF hinter den Daten. Nur Classic Outlook bekommt
    dieselbe Datei als normales IMG im geschuetzten Carrier-Layer.

    Der uebergebene $slot (Vertraulichkeitshinweis) steckt bereits im
    Rechtsblock der Signatur; er wird deshalb bewusst nicht erneut ausgegeben.
--}}
@php
    // Systemmails verlinken die Assets und bleiben dadurch klein. Der
    // validierte Editor-Carrier behaelt den Zug als CSS-Layer hinter dem
    // Inhalt. Ein legacy background-Attribut ist verboten, weil Word es
    // kachelt; ein zweites Idle-/Standbild wird nicht ausgegeben.
    $signatur = \App\Support\MailSignature::forCompany();
@endphp
{!! $signatur->render() !!}
