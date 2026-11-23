@props(['seats', 'summary', 'previewId' => null, 'timezone' => 'Europe/Berlin'])
@php
    $staffingLabel = $summary['reserved'].' von '.$summary['required'].' Einsatzplätzen eingeplant, '.$summary['confirmed'].' bestätigt, '.max(0, (int) $summary['open']).' offen';
@endphp
{{-- Dünne Hülle über x-operations.service-cell-preview: Vorschau-Dropdown gibt es nur einmal. --}}
<x-operations.service-cell-preview
    label="Besetzung"
    :preview-id="$previewId"
    preview-class="rt-service-staffing-preview"
    :trigger-label="$staffingLabel.' · Besetzung vergrößern'"
    close-label="Vergrößerte Besetzung schließen"
    :content-wrapper="false"
    data-service-staffing
>
    <x-slot:trigger>
        <x-operations.service-staffing-content :seats="$seats" :summary="$summary" />
    </x-slot:trigger>
    <x-slot:content>
        <x-operations.service-staffing-content :seats="$seats" :summary="$summary" :timezone="$timezone" :expanded="true" />
    </x-slot:content>
</x-operations.service-cell-preview>
