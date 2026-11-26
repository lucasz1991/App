<script src="{{ URL::asset('build/libs/@popperjs/core/umd/popper.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/feather-icons/feather.min.js') }}"></script>
@if(\App\Support\Ui\LegacyAssets::enabled())
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
@endif
{{-- Sidebar: Untermenues steuert resources/js/sidebar-accordion.js, gescrollt
     wird nativ in .rt-sidebar-scroll (frueher MetisMenu und SimpleBar). --}}
{{-- Das Ton-Repertoire (window.RTSound) liegt im Vite-Bundle: resources/js/
     rt-sounds.js wird in app.js direkt nach dem Alpine-Watchdog importiert;
     den Versionsstempel liefert der Build-Hash. --}}
{{-- Die Meldungen liegen seit der Umstellung auf SweetAlert2 im Vite-Bundle
     (resources/js/rt-alerts.js) und brauchen hier kein eigenes Skript mehr. --}}
@yield('scripts')
