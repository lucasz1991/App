<div class="rt-message-inbox" data-message-inbox-scroll wire:loading.class="cursor-wait" x-data="{}">
    <x-ui.page :title="__('app.messages')" :show-back="false" :title-in-topbar="true">
        <x-slot:actions>
            <div class="rt-message-inbox__actions">
                <x-ui.dropdown.anchor-dropdown width="56" dropdown-id="inbox-status" layer-group="inbox-controls" content-label="Nachrichten filtern">
                    <x-slot:trigger>
                        <button type="button" class="rt-message-inbox__control {{ $status !== 'all' ? 'is-active' : '' }}" aria-haspopup="menu" aria-label="Nachrichten filtern: {{ $status === 'unread' ? 'Ungelesen' : ($status === 'read' ? 'Gelesen' : 'Alle Nachrichten') }}" title="Nachrichten filtern">
                            <i class="far fa-sliders-h" aria-hidden="true"></i>
                            @if($status !== 'all') <span class="rt-message-inbox__filter-dot" aria-hidden="true"></span> @endif
                        </button>
                    </x-slot:trigger>
                    <x-slot:content>
                        @foreach(['all' => 'Alle Nachrichten', 'unread' => 'Ungelesen', 'read' => 'Gelesen'] as $value => $label)
                            <x-dropdown-link href="#" role="menuitemradio" :aria-checked="$status === $value ? 'true' : 'false'" wire:click.prevent="$set('status', '{{ $value }}')" x-on:click="close()">
                                <i class="far {{ $value === 'unread' ? 'fa-envelope' : ($value === 'read' ? 'fa-envelope-open' : 'fa-inbox') }}" aria-hidden="true"></i>
                                <span class="flex-1">{{ $label }}</span>
                                @if($status === $value) <i class="far fa-check text-rt-red" aria-hidden="true"></i> @endif
                            </x-dropdown-link>
                        @endforeach
                        @if(trim($search) !== '' || $status !== 'all')
                            <x-dropdown-link href="#" wire:click.prevent="resetFilters" x-on:click="close()">
                                <i class="far fa-undo" aria-hidden="true"></i> Suche und Filter zurücksetzen
                            </x-dropdown-link>
                        @endif
                    </x-slot:content>
                </x-ui.dropdown.anchor-dropdown>
                <button type="button" class="rt-message-inbox__control rt-message-inbox__read-all" wire:click="markAllAsRead" wire:loading.attr="disabled" @disabled($unreadCount === 0)
                    aria-label="Alle {{ $unreadCount }} ungelesenen Nachrichten in meinem gesamten Posteingang als gelesen markieren" title="Gesamten eigenen Posteingang als gelesen markieren">
                    <i class="far fa-check-double" aria-hidden="true"></i><span>Alle als gelesen</span>
                </button>
            </div>
        </x-slot:actions>

        <template x-teleport="[data-page-header-search]" wire:key="inbox-header-controls">
            <div class="rt-message-inbox__selection" aria-label="Nachrichten auswählen und suchen">
                <x-tables.bulk-actions :count="$selectedCount" select-all="togglePageSelection"
                    :select-all-title="$pageSelected ? 'Auswahl dieser Seite aufheben' : 'Alle Nachrichten auf dieser Seite auswählen'" width="72">
                    <div class="rt-message-inbox__menu-label">{{ $selectedCount }} ausgewählt</div>
                    <x-dropdown-link href="#" wire:click.prevent="markSelectedAsRead" x-on:click="close()">
                        <i class="far fa-envelope-open" aria-hidden="true"></i> Als gelesen markieren
                    </x-dropdown-link>
                    <x-dropdown-link href="#" wire:click.prevent="markSelectedAsUnread" x-on:click="close()">
                        <i class="far fa-envelope" aria-hidden="true"></i> Als ungelesen markieren
                    </x-dropdown-link>
                    <x-dropdown-link href="#" tone="danger" confirm-method="deleteSelectedMessages" confirm-title="Ausgewählte Nachrichten löschen?"
                        :confirm-message="$selectedCount.' ausgewählte Nachrichten und ihre Anhänge werden endgültig gelöscht. Diese Aktion kann nicht rückgängig gemacht werden.'" confirm-label="Endgültig löschen">
                        <i class="far fa-trash-alt" aria-hidden="true"></i> Auswahl löschen
                    </x-dropdown-link>
                    <x-dropdown-link href="#" wire:click.prevent="clearSelection" x-on:click="close()">
                        <i class="far fa-times" aria-hidden="true"></i> Auswahl aufheben
                    </x-dropdown-link>
                </x-tables.bulk-actions>
                <x-tables.search-field context="page-topbar" id="inbox-search" placeholder="Absender, Betreff oder Nachricht suchen"
                    :results-count="$messages->total()" wire:model.live.debounce.400ms="search" />
            </div>
        </template>
        <template x-teleport="[data-page-header-metrics]" wire:key="inbox-header-summary">
            <p class="rt-message-inbox__summary" role="status" aria-live="polite">
                <span>{{ $totalCount }} Nachrichten</span><span class="rt-message-inbox__unread-count"><span aria-hidden="true" class="rt-message-inbox__status-dot"></span>{{ $unreadCount }} ungelesen</span>
            </p>
        </template>

        <div class="rt-message-inbox__results">
            <x-tables.table class="rt-message-inbox__table" :flush-top="true" :scroll-body="true" :mobile-header="true"
                table-key="message-inbox" data-table-pagination-key="message-inbox" label="Mein Posteingang"
                :columns="[
                    ['label' => 'Von', 'key' => 'sender', 'width' => '25%', 'hideOn' => 'none'],
                    ['label' => 'Nachricht', 'key' => 'message', 'width' => '57%', 'hideOn' => 'none'],
                    ['label' => 'Datum', 'key' => 'date', 'width' => '18%', 'hideOn' => 'md'],
                ]"
                :empty="trim($search) !== '' ? 'Keine Nachrichten für diese Suche gefunden.' : ($status === 'unread' ? 'Alles gelesen – keine ungelesenen Nachrichten.' : ($status === 'read' ? 'Noch keine gelesenen Nachrichten.' : 'Dein Posteingang ist leer. Neue Nachrichten erscheinen hier.'))"
                :items="$messages" :selected-items="$selectedMessages" selection-action="toggleMessageSelection" detail-action="openMessageDetail"
                row-view="components.tables.rows.messages.row" actions-view="components.tables.rows.messages.actions" />
            <div class="rt-message-inbox__footer">
                <x-tables.pagination :paginator="$messages" table-key="message-inbox" label="Nachrichtenseiten" />
            </div>
        </div>
    </x-ui.page>
</div>
