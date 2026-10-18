<div
    x-data="communicationModule"
    wire:ignore.self
    data-communication-host
    data-communication-open="false"
    data-communication-tab="chats"
    data-communication-call="false"
    wire:poll.60s="refreshUnread"
    x-on:rt-floating-surface-opened.window="if ($event.detail?.surface === 'assist') close(false)"
>
    <span hidden data-app-badge-count="{{ $unreadCount }}"></span>
    <x-ui.floating.launcher class="rt-comm-launcher" x-ref="launcher" x-show="!open" x-cloak
        x-on:click="activeCall ? resumeCall() : show('mini')" aria-controls="railtime-communication" ::aria-expanded="open.toString()"
        ::aria-label="activeCall ? 'Laufenden Anruf öffnen' : 'Chats öffnen'" title="Chats und Anrufe öffnen">
        <i class="far" :class="activeCall ? 'fa-phone' : 'fa-comments'" aria-hidden="true"></i>
        <span x-show="activeCall" class="rt-comm-call-dot" aria-hidden="true"></span>
        @if($unreadCount > 0)<span class="rt-comm-badge">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif
    </x-ui.floating.launcher>

    <x-ui.floating.window id="railtime-communication" x-ref="panel" class="rt-communication" x-cloak wire:ignore.self
        ::data-mode="mode" ::data-corner="corner"
        ::inert="!open" ::aria-hidden="(!open).toString()" label="Chats, Anrufe und Meetings" role="region"
        layout-action="syncVisibility(); queueLayout();">
        <x-slot:header class="rt-comm-head">
            <div class="rt-comm-tabs" role="tablist" aria-label="Bereiche" x-on:keydown="tabKey($event)">
                @foreach(['chats' => ['fa-comments', 'Chats'], 'calls' => ['fa-phone', 'Anrufe'], 'meetings' => ['fa-video', 'Meetings']] as $key => [$icon, $label])
                    <button type="button" id="communication-tab-{{ $key }}" role="tab" data-comm-tab="{{ $key }}"
                        :aria-selected="(tab === '{{ $key }}').toString()" :tabindex="tab === '{{ $key }}' ? 0 : -1"
                        aria-label="{{ $label }}" title="{{ $label }}" aria-controls="communication-{{ $key }}"
                        x-on:click="selectTab('{{ $key }}')">
                        <i class="far {{ $icon }}" aria-hidden="true"></i><span>{{ $label }}</span>
                    </button>
                @endforeach
            </div>
            <span class="rt-comm-drag-handle" aria-hidden="true"></span>
            <div class="rt-comm-window-actions">
                <button type="button" data-comm-resize x-show="wide" x-cloak x-on:click="resizeWindow()" :title="mode === 'fullscreen' ? 'Mini-Modus öffnen' : 'Vollbild öffnen'"
                    :aria-label="mode === 'fullscreen' ? 'Mini-Modus öffnen' : 'Vollbild öffnen'">
                    <i class="far" :class="mode === 'fullscreen' ? 'fa-compress-alt' : 'fa-expand-alt'" aria-hidden="true"></i>
                </button>
                <button type="button" x-on:click="close()" aria-label="Modul schließen" title="Schließen – ein laufender Anruf läuft weiter">
                    <i class="far fa-times" aria-hidden="true"></i>
                </button>
            </div>
        </x-slot:header>
        <button type="button" class="rt-comm-resume" x-show="activeCall && !(tab === 'calls' && showCall)" x-cloak x-on:click="resumeCall()">
            <span class="rt-comm-call-dot"></span><span>Laufender Anruf</span><span class="ml-auto">Zum Anruf <i class="far fa-arrow-right" aria-hidden="true"></i></span>
        </button>
        <div class="rt-comm-content">
            <div id="communication-chats" role="tabpanel" aria-labelledby="communication-tab-chats" class="rt-comm-pane" x-show="tab === 'chats'" :inert="tab !== 'chats'">
                <livewire:chat-box :embedded="true" :key="'communication-chat'" />
            </div>
            <div id="communication-calls" role="tabpanel" aria-labelledby="communication-tab-calls" class="rt-comm-pane" x-show="tab === 'calls'" :inert="tab !== 'calls'">
                @if($callsAvailable && (auth()->user()->isAdmin() || auth()->user()->hasRbacPermission('calls.join')))
                <div class="rt-comm-pane" x-show="!showCall && !showHistory" :inert="showCall || showHistory"><livewire:calls.call-history :embedded="true" :key="'communication-history'" /></div>
                <div class="rt-comm-pane" x-show="showHistory && !showCall" :inert="!showHistory || showCall">
                    @if($historyRoomUuid)
                        <livewire:calls.call-details :room="\App\Models\Room::where('uuid', $historyRoomUuid)->firstOrFail()" :embedded="true" :key="'communication-detail-'.$historyRoomUuid" />
                    @endif
                </div>
                <div class="rt-comm-pane rt-comm-live-call" x-show="showCall" :inert="!showCall">
                    @if($activeRoomUuid)
                        <livewire:calls.call-window :room="\App\Models\Room::where('uuid', $activeRoomUuid)->firstOrFail()" :embedded="true" :key="'communication-room-'.$activeRoomUuid" />
                    @endif
                </div>
                @else
                    <p class="p-6 text-sm text-rt-muted">{{ $callsAvailable ? 'Anrufe sind für dein Konto nicht freigeschaltet.' : 'Anrufe sind momentan nicht verfügbar.' }}</p>
                @endif
            </div>
            <div id="communication-meetings" role="tabpanel" aria-labelledby="communication-tab-meetings" class="rt-comm-pane" x-show="tab === 'meetings'" :inert="tab !== 'meetings'">
                @if($callsAvailable && (auth()->user()->isAdmin() || auth()->user()->hasRbacPermission('calls.join')))
                <livewire:calls.meetings :embedded="true" :key="'communication-meetings'" />
                @else
                    <p class="p-6 text-sm text-rt-muted">{{ $callsAvailable ? 'Meetings sind für dein Konto nicht freigeschaltet.' : 'Meetings sind momentan nicht verfügbar.' }}</p>
                @endif
            </div>
        </div>
    </x-ui.floating.window>
</div>
