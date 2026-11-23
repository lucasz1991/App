@props(['title' => null])

{{--
    Sammelliste aller Validierungsfehler. Rendert intern den Standard-Hinweis
    x-ui.feedback.alert (danger), damit Fehlerlisten app-weit gleich aussehen.
    Klassen am Aufruf (Abstaende, ops-full im Raster) landen an der Huelle.
--}}
@if ($errors->any())
    <div {{ $attributes }}>
        <x-ui.feedback.alert type="danger" :title="$title ?? __('Whoops! Something went wrong.')" :dismissible="false">
            <ul class="list-inside list-disc space-y-1">
                @foreach (array_unique($errors->all()) as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.feedback.alert>
    </div>
@endif
