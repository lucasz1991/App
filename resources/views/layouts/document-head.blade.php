{{--
    Gemeinsamer Dokumentkopf aller HTML-Huellen (master, guest,
    customer-portal, Kundenportal-Anmeldung, Fehler- und Wartungsseite).

    Aufruf: @include('layouts.document-head', ['documentHead' => [...]])
    Die Optionen stehen bewusst in EINEM Array mit eigenem Namen, weil
    @include alle Variablen der aufrufenden View erbt ($title, $description
    usw. sind dort oft schon belegt).

      viewport     Inhalt des Viewport-Metas (Standard: App-Viewport mit
                   gesperrtem Zoom; Kundenportal/Terminal geben ihren eigenen an)
      description  Meta-Description (null = keine)
      robots       z. B. 'noindex, nofollow' (null = keins)
      referrer     Referrer-Policy (null = keine)
      csrf         CSRF-Meta ausgeben. Fehlerseiten setzen false: sie duerfen
                   Session und Datenbank nicht anfassen (SESSION_DRIVER=database).
      pwa          true: layouts.pwa-head (Icons + PWA-, Ton- und Sitzungs-Metas,
                   liest den angemeldeten Benutzer). false: nur layouts.icon-head
                   (reine Favicon-Kette ohne Auth/Session/DB).
      themeScript  FOUC-Schutz: Dunkelmodus (.dark) vor dem ersten Paint setzen.
                   Die eigentliche Verwaltung uebernimmt der Alpine-Persist-Store
                   ($store.theme), der auch wire:navigate-Seitenwechsel uebersteht.
      fontAwesome  Font-Awesome-Stylesheet laden (FA Pro 5.15). Das App-Layout
                   bringt es ueber layouts.head-css mit und setzt false.

    Favicons kommen ausschliesslich aus layouts.icon-head (echte Favicon-
    Dateien), nie als rt-logo.svg: das transparente Logo verschwindet auf
    dunkler Browserleiste und konkurriert mit der zentralen Icon-Kette.
--}}
@php
    $documentHeadOptions = array_merge([
        'viewport' => 'width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content',
        'description' => null,
        'robots' => null,
        'referrer' => null,
        'csrf' => true,
        'pwa' => true,
        'themeScript' => true,
        'fontAwesome' => false,
    ], $documentHead ?? []);
@endphp
<meta charset="utf-8">
<meta name="viewport" content="{{ $documentHeadOptions['viewport'] }}">
@if (filled($documentHeadOptions['description']))
<meta name="description" content="{{ $documentHeadOptions['description'] }}">
@endif
@if (filled($documentHeadOptions['robots']))
<meta name="robots" content="{{ $documentHeadOptions['robots'] }}">
@endif
@if (filled($documentHeadOptions['referrer']))
<meta name="referrer" content="{{ $documentHeadOptions['referrer'] }}">
@endif
@if ($documentHeadOptions['csrf'])
<meta name="csrf-token" content="{{ csrf_token() }}">
@endif
@if ($documentHeadOptions['pwa'])
@include('layouts.pwa-head')
@else
@include('layouts.icon-head')
@endif
@if ($documentHeadOptions['fontAwesome'])
<link rel="stylesheet" href="{{ asset('adminresources/fontawesome6/css/all.min.css') }}">
@endif
@if ($documentHeadOptions['themeScript'])
<script>if (localStorage.getItem('rt-theme') === 'true') document.documentElement.classList.add('dark');</script>
@endif
