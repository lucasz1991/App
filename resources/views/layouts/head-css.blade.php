{{-- Ob die Legacy-Schicht laedt, entscheidet allein App\Support\Ui\LegacyAssets
     (dieselbe Abfrage nutzt layouts.vendor-scripts).
     Die fruehere build/css/tailwind.min.css ist entfernt; ihre noch greifenden
     Regeln stehen in resources/css/legacy-allowlist.css. Der Link muss hier,
     VOR den Vite-Bundles aus layouts.master, stehen (alte Kaskadenstelle) und
     bleibt wie die alte Datei ein schlichter Link ohne Navigations-Tracking.
     Icons: nur Font Awesome 5 Pro (adminresources/fontawesome6/css/all.min.css);
     das fruehere Sammel-Stylesheet (Boxicons/MDI/Dripicons/FA6 Free) ist entfernt. --}}
@if(\App\Support\Ui\LegacyAssets::enabled())
<link rel="stylesheet" href="{{ \Illuminate\Support\Facades\Vite::asset('resources/css/legacy-allowlist.css') }}" />
<link rel="stylesheet" href="{{ URL::asset('build/libs/apexcharts/apexcharts.css') }}" />
        <link rel="stylesheet" href="https://unpkg.com/trix@2.0.8/dist/trix.css">
        <script src="https://unpkg.com/trix@2.0.8/dist/trix.umd.min.js" defer></script>
<script src="https://unpkg.com/dropzone@6.0.0-beta.1/dist/dropzone-min.js"></script>
    <link href="https://unpkg.com/dropzone@6.0.0-beta.1/dist/dropzone.css" rel="stylesheet" type="text/css" />
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js" defer></script>
@endif
        
   <link rel="stylesheet" href="{{ asset('adminresources/fontawesome6/css/all.min.css') }}">
