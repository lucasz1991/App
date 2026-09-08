<div data-mail-delivery-controls="{{ $documentId }}" data-presentation="{{ $presentation }}" x-data="{ deliveryDialog: $wire.entangle('open').live }" class="min-w-0">
    @if ($presentation !== 'menu')
        <div class="rt-mail-usage rt-mail-usage__badges" role="group" aria-label="Verwendung von {{ $document->name }}"
            x-data="{ tooltipDismissed: false }"
            x-bind:data-tooltip-dismissed="tooltipDismissed"
            x-on:keydown.escape.stop="tooltipDismissed = true"
            x-on:focusin="tooltipDismissed = false"
            x-on:mouseover="tooltipDismissed = false">
            @foreach ($badges as $badge)
                @php
                    $action = $badge['action'];
                    $isToggle = $action !== null && $action !== 'publish';
                    $label = $badge['label'].($action ? ' – '.$actions[$action][0] : '');
                @endphp
                @if ($action)
                    <button
                        type="button"
                        class="rt-mail-usage__badge"
                        data-kind="{{ $badge['kind'] }}"
                        data-state="{{ $badge['state'] }}"
                        @if ($isToggle) data-toggle="true" role="switch" aria-checked="{{ $badge['state'] === 'active' ? 'true' : 'false' }}" @endif
                        title="{{ $label }}"
                        aria-label="{{ $label }}"
                        wire:click="{{ $action === 'publish' ? "preparePublish('{$document->content_hash}', '{$token}')" : "prepare('{$action}', '{$document->content_hash}', '{$token}')" }}"
                        wire:loading.attr="disabled"
                        wire:target="prepare,preparePublish,confirm"
                    >
                        <i class="rt-mail-usage__icon {{ $badge['icon'] }}" aria-hidden="true"></i>
                        @if ($isToggle)
                            <span class="rt-mail-usage__switch" aria-hidden="true"><span class="rt-mail-usage__thumb"></span></span>
                        @endif
                        <span class="sr-only">{{ $label }}</span>
                        <span class="rt-mail-usage__tooltip" aria-hidden="true">{{ $label }}</span>
                    </button>
                @else
                    <span class="rt-mail-usage__badge" data-kind="{{ $badge['kind'] }}" data-state="{{ $badge['state'] }}" tabindex="0" title="{{ $label }}" aria-label="{{ $label }}">
                        <i class="rt-mail-usage__icon {{ $badge['icon'] }}" aria-hidden="true"></i>
                        <span class="sr-only">{{ $label }}</span>
                        <span class="rt-mail-usage__tooltip" aria-hidden="true">{{ $label }}</span>
                    </span>
                @endif
            @endforeach
        </div>
    @endif

    @if ($presentation === 'menu')
        @include('livewire.admin.partials.mail-delivery-menu-items')
    @elseif ($presentation === 'full')
        <x-ui.dropdown.anchor-dropdown align="right" width="72" dropdown-id="delivery-{{ $this->getId() }}" layer-group="mail-delivery" content-label="Verwendung von {{ $document->name }} ändern" content-classes="p-1.5 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text">
            <x-slot:trigger><button type="button" class="rt-mail-library__button rt-mail-library__button--subtle mt-2" aria-label="Verwendung von {{ $document->name }} ändern"><i class="far fa-sliders-h" aria-hidden="true"></i>Verwendung ändern<i class="far fa-chevron-down" aria-hidden="true"></i></button></x-slot:trigger>
            <x-slot:content>
                @include('livewire.admin.partials.mail-delivery-menu-items')
            </x-slot:content>
        </x-ui.dropdown.anchor-dropdown>
    @endif

    @if ($notice)<p class="sr-only" role="status">{{ $notice }}</p>@endif
    <x-ui.state-modal :id="'delivery-confirm-'.$this->getId()" state="deliveryDialog" :title="$actions[$pending['action'] ?? 'system'][0]" icon="far fa-sliders-h" max-width="2xl" data-page-builder-subdialog>
        <div class="space-y-3">
            <p class="font-semibold">{{ $document->name }}</p>
            <p>{{ $actions[$pending['action'] ?? 'system'][1] }}</p>
            @if (($pending['action'] ?? '') === 'publish')
                <p class="text-sm">Ungespeicherte Änderungen im Editor gehören nicht zu dieser Freigabe. Bereits eingefügte E-Mails bleiben unverändert.</p>
            @else
                <p class="text-sm">Ungespeicherte Änderungen und neuere Entwürfe werden dabei nicht veröffentlicht. Die Outlook-Verwendung gilt nur für zugeordnete Firmenpostfächer.</p>
            @endif
            @error('delivery')<p role="alert" class="text-red-700">{{ $message }}</p>@enderror
        </div>
        <x-slot:footer>
            <button type="button" class="rt-mail-library__button" x-on:click="deliveryDialog = false">Abbrechen</button>
            <button type="button" class="rt-mail-library__button rt-mail-library__button--primary" wire:click="confirm" wire:loading.attr="disabled" wire:target="confirm">{{ ($pending['action'] ?? '') === 'publish' ? 'Gespeicherten Stand veröffentlichen' : 'Zuordnung bestätigen' }}</button>
        </x-slot:footer>
    </x-ui.state-modal>
</div>
