@if($item->download_url)
    <x-ui.buttons.button-basic :href="$item->download_url" data-no-navigate size="sm" mode="link" :aria-label="'Datei öffnen: '.$item->title" title="Datei öffnen"><i class="far fa-download" aria-hidden="true"></i></x-ui.buttons.button-basic>
@endif
