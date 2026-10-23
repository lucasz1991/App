    <x-ui.forms.select id="compact-status" variant="compact" value="review" action="setStatus" :counts="['review' => 2]" icon="far fa-filter" aria-label="Status">
        <option value="all">Alle</option><option value="review">Zu prüfen</option>
    </x-ui.forms.select>
    <x-ui.forms.select id="icon-status" variant="icon" value="all" icon="far fa-sliders-h" aria-label="Status filtern"><option value="all">Alle</option></x-ui.forms.select>