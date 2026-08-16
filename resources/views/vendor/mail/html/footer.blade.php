{{--
    Fusszeile jeder Systemmail: der GEMEINSAME RailTime-Signaturblock.

    Damit tragen Notifications, Mailables, die herunterladbare Vorlage und
    die herunterladbare Signatur genau dasselbe Markup — die Quelle liegt in
    resources/views/emails/parts/signature.blade.php.

    Systemmails haben keinen menschlichen Absender: MailSignature::forCompany()
    setzt Firmenname und Claim an die Stelle von Name und Funktion und zeigt
    die Firmenanschluesse. Einfahrt und nachgelagerter Rauch laufen im
    oberen Signatur-Carrier; eine zusaetzliche Zugzeile gibt es nicht.

    Der uebergebene $slot (Vertraulichkeitshinweis) steckt bereits im
    Rechtsblock der Signatur; er wird deshalb bewusst nicht erneut ausgegeben.
--}}
@php
    // Systemmails verlinken die Assets und bleiben dadurch klein. Der
    // validierte CSS-Hauptlayer wird beim Rendern durch genau ein regulaeres,
    // hoehenneutrales Zug-IMG ersetzt. Ein legacy background-Attribut ist
    // verboten, weil Word es kachelt.
    $signatur = \App\Support\MailSignature::forCompany();
@endphp
{!! $signatur->render() !!}
