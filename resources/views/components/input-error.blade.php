@props(['for'])

{{-- Alias fuer Jetstream- und Paket-Views; Standard ist x-ui.forms.input-error. --}}
<x-ui.forms.input-error :for="$for" {{ $attributes }} />
