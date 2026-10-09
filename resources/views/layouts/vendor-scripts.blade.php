@php($usesNativePlanning = request()->routeIs('operations.page') && in_array(request()->route('page'), ['cases', 'shifts'], true))
<script src="{{ URL::asset('build/libs/@popperjs/core/umd/popper.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/feather-icons/feather.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/metismenujs/metismenujs.min.js') }}"></script>
<script src="{{ URL::asset('build/libs/simplebar/simplebar.min.js') }}"></script>
@if(!($usesNativePlanning ?? false))
<script src="{{ URL::asset('build/libs/apexcharts/apexcharts.min.js') }}"></script>
@endif
{{-- Eigene Skripte MIT Versionsstempel: ohne ihn haelt der Browser alte
     Kopien fest — z. B. ein rt-sounds.js ohne die waehlbaren Signaturen und
     ohne preview(), wodurch neue Toene schlicht nie ankommen. --}}
<script src="{{ URL::asset('js/rt-sounds.js') }}?v={{ filemtime(public_path('js/rt-sounds.js')) }}"></script>
{{-- Die Meldungen liegen seit der Umstellung auf SweetAlert2 im Vite-Bundle
     (resources/js/rt-alerts.js) und brauchen hier kein eigenes Skript mehr. --}}
@yield('scripts')
