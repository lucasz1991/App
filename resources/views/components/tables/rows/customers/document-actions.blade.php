@if($item->download_url)
    <x-tables.row-open-action :href="$item->download_url" :navigate="false" icon="far fa-download" :label="'Datei öffnen: '.$item->title" title="Datei öffnen" />
@endif
