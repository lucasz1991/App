<div data-mail-delivery-controls="{{ $documentId }}" x-data="{ deliveryDialog: $wire.entangle('open').live }" class="min-w-0">
    <div class="flex flex-wrap gap-1" aria-label="Verwendung von {{ $document->name }}">
        <span class="rt-mail-library__status {{ $document->isActive() ? 'rt-mail-library__status--default' : '' }}">Systemmail: {{ $document->isActive() ? 'Standard' : 'Nein' }}</span>
        <span class="rt-mail-library__status {{ $document->outlook_default ? 'rt-mail-library__status--default' : '' }}">Outlook: {{ $document->outlook_default ? 'Standard' : 'Nein' }}</span>
        @if ($isTemplate)
            <span class="rt-mail-library__status {{ $document->outlook_released ? 'rt-mail-library__status--released' : '' }}">Add-in: {{ $document->outlook_released ? 'Verfügbar' : 'Ausgeblendet' }}</span>
        @else
            <span class="rt-mail-library__status">{{ $document->outlook_default ? 'Automatische Mitarbeitersignatur' : 'Nicht im Add-in verwendet' }}</span>
        @endif
        <span class="rt-mail-library__status {{ $document->hasUnpublishedChanges() ? 'rt-mail-library__status--draft' : 'rt-mail-library__status--released' }}">{{ $document->hasUnpublishedChanges() ? ($document->published_at ? 'Neuer Entwurf' : 'Entwurf') : 'Stand veröffentlicht' }}</span>
    </div>
    <x-ui.dropdown.anchor-dropdown align="right" width="72" dropdown-id="delivery-{{ $this->getId() }}" layer-group="mail-delivery" content-label="Verwendung von {{ $document->name }} ändern" content-classes="p-1.5 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
        <x-slot:trigger><button type="button" class="rt-mail-library__button rt-mail-library__button--subtle mt-2" aria-label="Verwendung von {{ $document->name }} ändern"><i class="far fa-sliders-h" aria-hidden="true"></i>Verwendung ändern<i class="far fa-chevron-down" aria-hidden="true"></i></button></x-slot:trigger>
        <x-slot:content>
            @if (! $document->isPublished())
                <p class="p-3 text-xs">Zuerst einen Stand veröffentlichen. Danach können die Verwendungsbereiche getrennt zugeordnet werden.</p>
            @else
                @foreach ($actions as $action => [$label, $explanation])
                    @if (($action === 'system' && ! $document->isActive()) || ($action === 'outlook' && ! $document->outlook_default && (! $isTemplate || $document->outlook_released)) || ($action === 'outlook-off' && $isTemplate && $document->outlook_default) || ($action === 'offer' && $isTemplate && ! $document->outlook_released) || ($action === 'hide' && $isTemplate && $document->outlook_released && ! $document->outlook_default))
                        <button type="button" role="menuitem" class="rt-mail-library-menu__item" wire:click="prepare('{{ $action }}', '{{ $document->content_hash }}', '{{ $token }}')" x-on:click="close()">{{ $label }}</button>
                    @endif
                @endforeach
            @endif
        </x-slot:content>
    </x-ui.dropdown.anchor-dropdown>
    @if ($notice)<p class="mt-2 text-xs" role="status">{{ $notice }}</p>@endif
    <x-ui.state-modal :id="'delivery-confirm-'.$this->getId()" state="deliveryDialog" :title="$actions[$pending['action'] ?? 'system'][0]" icon="far fa-sliders-h" max-width="2xl">
        <div class="space-y-3">
            <p class="font-semibold">{{ $document->name }}</p>
            <p>{{ $actions[$pending['action'] ?? 'system'][1] }}</p>
            <p class="text-sm">Ungespeicherte Änderungen und neuere Entwürfe werden dabei nicht veröffentlicht. Die Outlook-Verwendung gilt nur für zugeordnete Firmenpostfächer.</p>
            @error('delivery')<p role="alert" class="text-red-700">{{ $message }}</p>@enderror
        </div>
        <x-slot:footer>
            <button type="button" class="rt-mail-library__button" x-on:click="deliveryDialog = false">Abbrechen</button>
            <button type="button" class="rt-mail-library__button rt-mail-library__button--primary" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm">Zuordnung bestätigen</button>
        </x-slot:footer>
    </x-ui.state-modal>
</div>
