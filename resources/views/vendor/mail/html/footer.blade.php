{{--
    Fusszeile jeder Systemmail: der GEMEINSAME RailTime-Signaturblock.

    Damit tragen Notifications, Mailables, die herunterladbare Vorlage und
    die herunterladbare Signatur genau dasselbe Markup — die Quelle liegt in
    resources/views/emails/parts/signature.blade.php.

    Systemmails haben keinen menschlichen Absender: MailSignature::forCompany()
    setzt Firmenname und Claim an die Stelle von Name und Funktion und zeigt
    die Firmenanschluesse. Der Zug steht hier still (Standbild) — eine
    Systemmail soll nicht bei jedem Oeffnen eine Animation starten.

    Der uebergebene $slot (Vertraulichkeitshinweis) steckt bereits im
    Rechtsblock der Signatur; er wird deshalb bewusst nicht erneut ausgegeben.
--}}
@php
    // Der Zug wird als REGULAERES, VERLINKTES Bild ausgeliefert statt als
    // eingebetteter CSS-Hintergrund.
    //
    // Vorher stand er als data:-URI in einem background-image. Damit erschien
    // er bei vielen Empfaengern nie: Outlook-Desktop rendert mit der Word-
    // Engine und kennt weder data:-URIs noch CSS-Hintergrundbilder, Gmail
    // entfernt data:-URIs in Hintergrundangaben. Zusaetzlich kappt Gmail
    // Nachrichten ab 102 kB — die eingebetteten Bilder allein waren 104,6 kB,
    // die Signatur stand also hinter dem Schnitt.
    $signatur = \App\Support\MailSignature::forCompany();
    $werte = $signatur->values();
@endphp
{!! $signatur->render([
    'outlookTrainSrc' => $werte['TRAIN_SRC'],
    'outlookTrainFallbackSrc' => $werte['TRAIN_STILL_SRC'],
]) !!}
