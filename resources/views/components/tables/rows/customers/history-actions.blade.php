@if($item->href)<x-tables.row-open-action :href="$item->href" :navigate="false" :label="'Vorgang öffnen: '.$item->label" title="Vorgang öffnen" />@endif
