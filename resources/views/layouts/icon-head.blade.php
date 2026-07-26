{{--
    Vollstaendige Icon-Kette — bewusst OHNE Auth-, Session- oder DB-Zugriff,
    damit auch Fehlerseiten (inkl. 500 bei ausgefallener Datenbank, denn
    SESSION_DRIVER=database) dieses Partial sicher einbinden koennen.

    Je Anwendungsfall das passende Icon:
      * Tab-Leiste: favicon-16/32.png
      * Tab-UEBERSICHT, Verlauf, Lesezeichen, "Meistbesucht": favicon.ico
        (jetzt mehrgroessig 16/32/48) bzw. das skalierbare SVG. Vorher war nur
        ein 192px-PNG deklariert und die .ico enthielt eine einzige 192px-
        Grafik — genau deshalb blieben diese Flaechen leer.
      * Angepinnte Safari-Tabs: mask-icon.svg (monochrom, Safari faerbt selbst)
      * iOS/iPadOS Home-Bildschirm: apple-touch-icon (iOS nutzt die
        Manifest-Icons NICHT)
      * Android/Chrome-Installation und Splash: Manifest + pwa-192/512
      * Windows-Kachel: msapplication-*
--}}

{{-- Klassische Favicons. favicon.ico bleibt bewusst am Ursprung liegen: den
     Pfad fragen Browser, Crawler und Betriebssystem-Shells fest ab. --}}
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="16x16 32x32 48x48">
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('icons/favicon-16.png') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/favicon-32.png') }}">
<link rel="icon" type="image/png" sizes="48x48" href="{{ asset('icons/favicon-48.png') }}">

{{-- Skalierbar (Chrome, Firefox, Edge bevorzugen dies fuer beliebige Groessen) --}}
<link rel="icon" type="image/svg+xml" href="{{ asset('icons/favicon.svg') }}">

{{-- Angepinnter Safari-Tab --}}
<link rel="mask-icon" href="{{ asset('icons/mask-icon.svg') }}" color="#e4002b">

{{-- iOS/iPadOS. Mehrere Groessen, damit iOS nicht selbst umrechnen muss, plus
     die Variante ohne sizes und die precomposed-Form fuer aeltere Versionen.
     Zusaetzlich liegen apple-touch-icon.png und -precomposed.png am Ursprung:
     genau diese Pfade fragt iOS ab, wenn eine Seite keinen Link mitbringt. --}}
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/apple-touch-icon-180.png') }}">
<link rel="apple-touch-icon" sizes="167x167" href="{{ asset('icons/apple-touch-icon-167.png') }}">
<link rel="apple-touch-icon" sizes="152x152" href="{{ asset('icons/apple-touch-icon-152.png') }}">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon-180.png') }}">
<link rel="apple-touch-icon-precomposed" href="{{ asset('apple-touch-icon-precomposed.png') }}">

{{-- Android/Chrome-Installation --}}
<link rel="icon" type="image/png" sizes="192x192" href="{{ route('pwa.icon', ['icon' => 'pwa-192.png']) }}">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">

{{-- Windows-Kachel --}}
<meta name="msapplication-TileColor" content="#e4002b">
<meta name="msapplication-TileImage" content="{{ route('pwa.icon', ['icon' => 'pwa-192.png']) }}">

<meta name="theme-color" content="#f3f6fa" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0b1120" media="(prefers-color-scheme: dark)">
