{{--
    Fusszeile jeder Systemmail: der GEMEINSAME RailTime-Signaturblock.

    Damit tragen Notifications, Mailables, die herunterladbare Vorlage und
    die herunterladbare Signatur genau dasselbe Markup — die Quelle liegt in
    resources/views/emails/parts/signature.blade.php.

    Systemmails haben keinen menschlichen Absender: MailSignature::forCompany()
    setzt Firmenname und Claim an die Stelle von Name und Funktion und zeigt
    die Firmenanschluesse. Einfahrt und Schlussbild laufen im einmaligen
    Haupt-GIF als hoehenneutrale Carrier-Background-Ebene hinter den Daten;
    nach 13 Sekunden uebernimmt nur eine kleine transparente
    Idle-Rauchschleife. Classic Outlook bekommt wegen seiner Word-Engine
    stattdessen genau ein bedingtes VML-PNG innerhalb derselben Stage.

    Der uebergebene $slot (Vertraulichkeitshinweis) steckt bereits im
    Rechtsblock der Signatur; er wird deshalb bewusst nicht erneut ausgegeben.
--}}
@php
    // Systemmails verlinken die Assets und bleiben dadurch klein. Der
    // validierte Editor-Carrier wird fuer die fertige Mail in eine vierte
    // Background-Ebene projiziert. Nur Word/MSO sieht den bedingten
    // VML-PNG-Fallback; eine separate Zugzeile wird nicht erzeugt.
    $signatur = \App\Support\MailSignature::forCompany();
@endphp
{!! $signatur->render() !!}
