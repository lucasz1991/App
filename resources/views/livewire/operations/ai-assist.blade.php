@php
    $panelId = 'rt-ai-assist-'.$this->getId();
    $allActions = collect($actions['page'])->concat($actions['global'])->values();
    // Antworttexte: erst escapen, dann **Hervorhebung** und Zeilenumbrüche erlauben.
    $format = fn (?string $text) => preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', e((string) $text));
    $tabs = array_filter(['chat' => 'Chat', 'intake' => $intakesReady ? 'AI-Annahme' : null, 'actions' => 'Aktionen', 'activity' => 'Aktivitäten']);
    $period = \Carbon\CarbonImmutable::parse($from)->format('d.m.').' – '.\Carbon\CarbonImmutable::parse($until)->format('d.m.');
    $chips = [['Seite', $pageLabel]];
    if (in_array($page, ['shifts', 'calendar', 'planning'], true)) {
        $chips[] = ['Zeitraum', $period];
    }
    if ($intakesReady) {
        $chips[] = ['AI-Eingänge', $reviewCount === 1 ? '1 prüfen' : $reviewCount.' prüfen'];
    }
    $canPlan = auth()->user()->can('operations.manage');
    $suggestions = array_values(array_filter([
        $canPlan ? 'Welche Schichten sind diese Woche noch offen?' : null,
        $canPlan ? 'Wer kann heute einen offenen Dienst übernehmen?' : null,
        auth()->user()->can('operations.inquiries.manage') ? 'Fasse die neuen Anfragen zusammen.' : null,
    ]));
    $modeLabel = 'Disposition · '.($enabled ? ($mode === 'assisted' ? 'Assistiert' : 'Automatisch') : 'AI-Eingang aus');
@endphp
<div class="rt-ai-assist" x-data="rtAiAssist" :data-open="open ? 'true' : 'false'" data-open="false"
    data-hints="{{ json_encode($hints, JSON_UNESCAPED_UNICODE) }}" data-actions="{{ json_encode($allActions, JSON_UNESCAPED_UNICODE) }}"
    x-on:keydown.window="shortcut($event)" x-on:keydown.escape="escape($event)" data-ai-assist>
    <div x-data="railtimeAssistantCloud()" :data-state="orbState" data-state="idle">
        <div class="rt-ai-assist__stage">
            <div class="rt-ai-assist__bubble" role="status" aria-live="polite" x-show="bubble && !open && prefs.proactive && hints.length > 0" style="display: none">
                <span x-text="hints[0]?.text"></span>
                <div class="rt-ai-assist__bubble-actions">
                    <button type="button" data-primary="true" x-on:click="bubbleRun(hints[0].run)" x-text="hints[0]?.label"></button>
                    <button type="button" x-on:click="bubble = false">Später</button>
                </div>
            </div>
            <button class="rt-ai-assist__launcher" type="button" x-ref="launcher" x-on:click="toggle()" x-on:mouseenter="hover()"
                :aria-expanded="open ? 'true' : 'false'" aria-expanded="false" aria-controls="{{ $panelId }}"
                aria-label="AI-Assist öffnen{{ $reviewCount ? ' · '.$reviewCount.' AI-Eingänge zur Prüfung' : '' }}" title="AI-Assist (Strg J)">
                <span class="rt-ai-assist__halo" aria-hidden="true"></span>
                <span class="rt-ai-assist__orb" data-assistant-cloud-slot="launcher" aria-hidden="true"><span class="rt-assistant-cloud__fallback"></span></span>
                @if($reviewCount > 0)
                    <span class="rt-ai-assist__presence" aria-hidden="true">{{ $reviewCount > 9 ? '9+' : $reviewCount }}</span>
                @endif
                <span class="rt-ai-assist__label" aria-hidden="true">AI-Assist</span>
            </button>
        </div>

        <section class="rt-ai-assist__panel" id="{{ $panelId }}" role="dialog" aria-modal="false" aria-labelledby="{{ $panelId }}-title" x-show="open" style="display: none">
            <header class="rt-ai-assist__head">
                <span class="rt-ai-assist__avatar"><span class="rt-ai-assist__orb rt-ai-assist__orb--head" data-assistant-cloud-slot="header" aria-hidden="true"><span class="rt-assistant-cloud__fallback"></span></span></span>
                <div class="rt-ai-assist__identity">
                    <span class="rt-ai-assist__mode">{{ $modeLabel }}</span>
                    <h2 class="rt-ai-assist__title" id="{{ $panelId }}-title">AI-Assist</h2>
                </div>
                <div class="rt-ai-assist__head-actions">
                    <button class="rt-ai-assist__icon-btn" type="button" :aria-expanded="settingsOpen ? 'true' : 'false'" aria-expanded="false" aria-label="Assistenz-Einstellungen" title="Einstellungen"
                        x-on:click="settingsOpen = !settingsOpen; @if($canConfigure) if (settingsOpen) $wire.openSettings(); @endif"><i class="far fa-cog" aria-hidden="true"></i></button>
                    <button class="rt-ai-assist__icon-btn" type="button" aria-label="Neues Gespräch" title="Neues Gespräch" wire:click="resetConversation" @disabled($messages === [])><i class="far fa-edit" aria-hidden="true"></i></button>
                    <button class="rt-ai-assist__icon-btn" type="button" aria-label="AI-Assist schließen" title="Schließen (Esc)" x-on:click="toggle(false)"><i class="far fa-times" aria-hidden="true"></i></button>
                </div>

                <div class="rt-ai-assist__settings" role="dialog" aria-label="Assistenz-Einstellungen" x-show="settingsOpen" style="display: none" x-on:click.outside="settingsOpen = false">
                    <div class="rt-ai-assist__settings-head">
                        <div><span class="rt-ai-assist__kicker">AI-Assist</span><strong>Assistenz-Einstellungen</strong></div>
                        <button class="rt-ai-assist__icon-btn" type="button" aria-label="Einstellungen schließen" x-on:click="settingsOpen = false"><i class="far fa-times" aria-hidden="true"></i></button>
                    </div>
                    <div class="rt-ai-assist__status">
                        <span class="rt-ai-assist__status-dot" data-on="{{ $enabled ? 'true' : 'false' }}"></span>
                        <div>
                            <strong>{{ $enabled ? 'AI-Eingang aktiv' : 'AI-Eingang pausiert' }}</strong>
                            <small>
                                Datenmodell {{ $status['configured'] ? 'eingerichtet' : 'fehlt' }} · Bildverständnis {{ $status['image_configured'] ? 'eingerichtet' : 'fehlt' }}
                                · Postfach {{ $status['mailbox_configured'] ? 'verbunden' : 'nicht verbunden' }} · Versand {{ $status['smtp_configured'] ? 'eingerichtet' : 'fehlt' }}
                            </small>
                        </div>
                    </div>

                    <p class="rt-ai-assist__group">Persönlich auf diesem Gerät</p>
                    <div class="rt-ai-assist__list">
                        <label class="rt-ai-assist__setting">
                            <span class="rt-ai-assist__setting-copy"><strong>Seitenkontext zeigen</strong><small>Seite, Zeitraum und Schnellaktionen über dem Chat</small></span>
                            <input type="checkbox" :checked="prefs.context" x-on:change="setPref('context', $event.target.checked)"><span class="rt-ai-assist__switch" aria-hidden="true"></span>
                        </label>
                        <label class="rt-ai-assist__setting">
                            <span class="rt-ai-assist__setting-copy"><strong>Proaktive Hinweise</strong><small>Offene Dienste und Freigaben am Orb melden</small></span>
                            <input type="checkbox" :checked="prefs.proactive" x-on:change="setPref('proactive', $event.target.checked)"><span class="rt-ai-assist__switch" aria-hidden="true"></span>
                        </label>
                    </div>

                    <p class="rt-ai-assist__group">AI-Eingang</p>
                    @if($canConfigure)
                        <div class="rt-ai-assist__list">
                            <label class="rt-ai-assist__setting"><span class="rt-ai-assist__setting-copy"><strong>Automatisierung</strong><small>Assistiert: jede Änderung erst nach Freigabe</small></span>
                                <select wire:model="config.automation_mode"><option value="assisted">Assistiert</option><option value="automatic">Automatisch</option></select></label>
                            <label class="rt-ai-assist__setting"><span class="rt-ai-assist__setting-copy"><strong>Rückfragen an Kunden</strong><small>Höchstens so viele Runden je Eingang</small></span>
                                <select wire:model="config.max_rounds"><option value="1">1 Runde</option><option value="2">2 Runden</option></select></label>
                            <label class="rt-ai-assist__setting"><span class="rt-ai-assist__setting-copy"><strong>Antwortfrist</strong><small>Danach als Kundenantwort ausstehend markieren</small></span>
                                <select wire:model="config.reply_timeout_hours"><option value="24">24 Stunden</option><option value="48">48 Stunden</option><option value="72">72 Stunden</option></select></label>
                            <label class="rt-ai-assist__setting"><span class="rt-ai-assist__setting-copy"><strong>AI-Aufrufe je Stunde</strong><small>Obergrenze für automatische Auswertungen</small></span>
                                <select wire:model="config.max_ai_calls_per_hour"><option value="50">50</option><option value="100">100</option><option value="250">250</option></select></label>
                        </div>
                        @error('config')<p class="rt-ai-assist__error" role="alert">{{ $message }}</p>@enderror
                        <div class="rt-ai-assist__settings-foot">
                            <a href="{{ route('admin.settings', ['tab' => 'ai-disposition', 'section' => 'configuration']) }}">Postfach &amp; Verbindung einrichten</a>
                            <button type="button" class="rt-ai-assist__btn" data-primary="true" wire:click="saveSettings" wire:loading.attr="disabled" wire:target="saveSettings">Speichern</button>
                        </div>
                    @else
                        <div class="rt-ai-assist__list">
                            <div class="rt-ai-assist__setting"><span class="rt-ai-assist__setting-copy"><strong>Automatisierung</strong><small>Ändern nur durch die Systemverwaltung</small></span><b>{{ $mode === 'assisted' ? 'Assistiert' : 'Automatisch' }}</b></div>
                            <div class="rt-ai-assist__setting"><span class="rt-ai-assist__setting-copy"><strong>Rückfragen an Kunden</strong><small>Höchstens je Eingang</small></span><b>{{ $values['max_rounds'] }} {{ (int) $values['max_rounds'] === 1 ? 'Runde' : 'Runden' }}</b></div>
                            <div class="rt-ai-assist__setting"><span class="rt-ai-assist__setting-copy"><strong>Antwortfrist</strong><small>Kundenantwort</small></span><b>{{ $values['reply_timeout_hours'] }} Stunden</b></div>
                        </div>
                    @endif
                    <p class="rt-ai-assist__privacy"><i class="far fa-info-circle" aria-hidden="true"></i><span>Antworten sind Auswertungen der Planungsdaten in RailTime; Live- und Personaldaten verlassen den Server dafür nicht. Einteilungen geschehen erst nach deiner Bestätigung und lassen sich zurücknehmen.@if($supervisor) Verantwortlich: {{ $supervisor }}.@endif</span></p>
                </div>
            </header>

            <details class="rt-ai-assist__context rt-ai-assist__context--compact" x-show="prefs.context">
                <summary><i class="far fa-eye" aria-hidden="true"></i><span>{{ $pageLabel }}</span><small>{{ $reviewCount }} zur Prüfung</small><i class="far fa-chevron-down" aria-hidden="true"></i></summary>
                <div class="rt-ai-assist__chips">
                    @foreach($chips as [$label, $value])
                        <span class="rt-ai-assist__chip"><small>{{ $label }}</small><strong>{{ $value }}</strong></span>
                    @endforeach
                </div>
                @if($actions['page'] !== [])
                    <div class="rt-ai-assist__quickbar">
                        @foreach(array_slice($actions['page'], 0, 2) as $action)
                            <button type="button" class="rt-ai-assist__quick" x-on:click="run(@js($action['key']))" :disabled="busy"><span>{{ $action['title'] }}</span><i class="far fa-arrow-right" aria-hidden="true"></i></button>
                        @endforeach
                    </div>
                @endif
            </details>

            <nav class="rt-ai-assist__tabs" role="tablist" aria-label="AI-Assist">
                @foreach($tabs as $key => $label)
                    <button type="button" role="tab" id="{{ $panelId }}-tab-{{ $key }}" aria-controls="{{ $panelId }}-body" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')">
                        {{ $label }}@if($key === 'intake' && $reviewCount > 0)<span class="rt-ai-assist__count">{{ $reviewCount }}</span>@endif
                    </button>
                @endforeach
            </nav>

            <div class="rt-ai-assist__body" id="{{ $panelId }}-body" role="tabpanel" aria-labelledby="{{ $panelId }}-tab-{{ $tab }}">
                @if($tab === 'chat')
                    <div class="rt-ai-assist__messages" x-ref="messages" x-on:scroll.passive="handleMessagesScroll()" aria-live="polite">
                        @if($messages === [])
                            <div class="rt-ai-assist__empty" x-show="!busy">
                                <span class="rt-ai-assist__orb rt-ai-assist__orb--empty" data-assistant-cloud-slot="empty" aria-hidden="true"><span class="rt-assistant-cloud__fallback"></span></span>
                                <strong>Was steht als Nächstes an?</strong>
                                <span>AI-Annahme, Anfragen und Schichtplanung an einem Ort. Ich bereite Vorschläge vor, du gibst Änderungen frei.</span>
                                @if($overview && $overview['available'])
                                    <div class="rt-assistant-operations__overview">
                                        @foreach(['review' => 'Zur Prüfung', 'busy' => 'In Analyse', 'waiting' => 'Warten auf Antwort'] as $key => $label)
                                            <button type="button" wire:click="setTab('intake')"><strong>{{ $overview['counts'][$key] }}</strong><span>{{ $label }}</span></button>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="rt-ai-assist__suggest">
                                    @foreach($suggestions as $question)
                                        <button type="button" x-on:click="ask(@js($question))"><i class="far fa-comment-alt" aria-hidden="true"></i>{{ $question }}</button>
                                    @endforeach
                                </div>
                                @if(!empty($overview['recent']))
                                    <div class="rt-assistant-operations__recent">
                                        <strong>Letzte Aktivitäten</strong>
                                        <ol class="rt-ai-assist__activity">
                                            @foreach(array_slice($overview['recent'], 0, 3) as $item)
                                                <li><button type="button" class="rt-ai-assist__activity-item" wire:click="setTab('activity')"><span class="rt-ai-assist__activity-icon" data-tone="{{ $item['tone'] }}"><i class="far {{ $item['icon'] }}" aria-hidden="true"></i></span><span class="rt-ai-assist__activity-text"><strong>{{ $item['title'] }}</strong><small>{{ $item['detail'] }}</small></span><span class="rt-ai-assist__badge" data-tone="{{ $item['tone'] }}">{{ $item['status'] }}</span></button></li>
                                            @endforeach
                                        </ol>
                                    </div>
                                @endif
                            </div>
                        @endif
                        @foreach($messages as $message)
                            @if(($message['role'] ?? '') === 'user')
                                <div class="rt-ai-assist__row rt-ai-assist__row--user" wire:key="ai-assist-message-{{ $message['id'] }}"><div class="rt-ai-assist__msg">{{ $message['text'] }}</div></div>
                            @else
                                @php
                                    $card = $message['card'] ?? null;
                                    $state = $card['state'] ?? null;
                                @endphp
                                <div class="rt-ai-assist__row" wire:key="ai-assist-message-{{ $message['id'] }}">
                                    <span class="rt-ai-assist__orb rt-ai-assist__orb--msg" data-assistant-cloud-slot="message" data-state="idle" aria-hidden="true"><span class="rt-assistant-cloud__fallback"></span></span>
                                    <div class="rt-ai-assist__stack">
                                        <div class="rt-ai-assist__msg">{!! $format($message['lead'] ?? '') !!}</div>
                                        @if($card)
                                            <div class="rt-ai-assist__card" data-state="{{ $state ?? 'open' }}">
                                                <div class="rt-ai-assist__card-head">
                                                    <i class="far {{ $card['icon'] ?? 'fa-sparkles' }}" aria-hidden="true"></i><strong>{{ $card['title'] }}</strong>
                                                    @if($state === 'done')<span class="rt-ai-assist__badge" data-tone="ok">Übernommen</span>
                                                    @elseif($state === 'dismissed')<span class="rt-ai-assist__badge">Verworfen</span>
                                                    @elseif($state === 'undone')<span class="rt-ai-assist__badge">Zurückgenommen</span>
                                                    @endif
                                                </div>
                                                @if(! empty($card['rows']))
                                                    <div>
                                                        @foreach($card['rows'] as $row)
                                                            <div class="rt-ai-assist__card-row">
                                                                <span>{{ $row['label'] }}</span><i class="far fa-arrow-right" aria-hidden="true"></i><b>{{ $row['value'] }}</b>
                                                                @if(($row['small'] ?? '') !== '')<small>{{ $row['small'] }}</small>@endif
                                                                @if(! empty($row['why']))
                                                                    <details class="rt-ai-assist__why"><summary>Warum?</summary><ul>
                                                                        @foreach($row['why'] as $reason)<li><i class="far fa-check" aria-hidden="true"></i>{{ $reason }}</li>@endforeach
                                                                    </ul></details>
                                                                @endif
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                @endif
                                                @if(($card['note'] ?? '') !== '')<p class="rt-ai-assist__card-note">{!! $format($card['note']) !!}</p>@endif
                                                @foreach(($card['failed'] ?? []) as $failure)
                                                    <p class="rt-ai-assist__card-failed" role="alert">Nicht übernommen – {{ $failure }}</p>
                                                @endforeach
                                                @if($state === 'done')
                                                    <div class="rt-ai-assist__card-buttons rt-ai-assist__card-buttons--done">
                                                        <span><i class="far fa-check-circle" aria-hidden="true"></i>Übernommen · {{ $card['done_at'] ?? '' }}</span>
                                                        @if(! empty($card['undo']))
                                                            <button type="button" class="rt-ai-assist__btn" x-on:click="call('act', {{ $message['id'] }}, 'undo')" :disabled="busy"><i class="far fa-undo" aria-hidden="true"></i>Rückgängig</button>
                                                        @endif
                                                    </div>
                                                @elseif(! $state && ! empty($card['buttons']))
                                                    <div class="rt-ai-assist__card-buttons">
                                                        @foreach($card['buttons'] as $button)
                                                            @if(isset($button['href']))
                                                                <a class="rt-ai-assist__btn" href="{{ $button['href'] }}" data-primary="{{ ! empty($button['primary']) ? 'true' : 'false' }}">{{ $button['label'] }}</a>
                                                            @else
                                                                <button type="button" class="rt-ai-assist__btn" data-primary="{{ ! empty($button['primary']) ? 'true' : 'false' }}" x-on:click="call('act', {{ $message['id'] }}, @js($button['act']))" :disabled="busy">{{ $button['label'] }}</button>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endif
                                        <span class="rt-ai-assist__meta">AI-Assist · {{ $message['time'] }}</span>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                        <div class="rt-ai-assist__row" x-show="busy" style="display: none" role="status">
                            <span class="rt-ai-assist__orb rt-ai-assist__orb--msg" data-assistant-cloud-slot="message" data-state="thinking" aria-hidden="true"><span class="rt-assistant-cloud__fallback"></span></span>
                            <div class="rt-ai-assist__msg rt-ai-assist__msg--typing"><span>AI-Assist wertet aus</span><i></i><i></i><i></i></div>
                        </div>
                    </div>
                    <button type="button" class="rt-ai-assist__new-output" x-show="unseenOutput" style="display:none" x-on:click="jumpToLatest()">Neue Antworten <i class="far fa-arrow-down" aria-hidden="true"></i></button>
                @elseif($tab === 'intake')
                    <div class="rt-ai-assist__scroll">
                        <div class="rt-ai-assist__intake-head">
                            <p>Eingänge aus Postfach, Sprache und Dateien – prüfen, zuordnen und freigeben.</p>
                            <a class="rt-ai-assist__btn" data-primary="true" href="{{ \App\Support\Operations\OperationsPages::url('cases', ['view' => 'inbox', 'section' => 'ai-intake']) }}"><i class="far fa-plus" aria-hidden="true"></i>Eingang erfassen</a>
                        </div>
                        <div class="rt-ai-assist__tools">
                            <label class="rt-ai-assist__search"><i class="far fa-search" aria-hidden="true"></i><span class="sr-only">AI-Eingang suchen</span>
                                <input type="search" placeholder="AI-Eingang suchen" wire:model.live.debounce.300ms="intakeSearch" maxlength="100"></label>
                            <label class="sr-only" for="{{ $panelId }}-intake-status">Bearbeitungsstand</label>
                            <select id="{{ $panelId }}-intake-status" wire:model.live="intakeStatus">
                                <option value="all">Alle Zustände</option>
                                @foreach($labels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                        </div>
                        @if($intakes->isEmpty())
                            <p class="rt-ai-assist__note">Noch keine AI-Eingänge für diese Auswahl.</p>
                        @else
                            <ol class="rt-ai-assist__activity">
                                @foreach($intakes as $intake)
                                    @php
                                        $tone = $service->tone($intake->status);
                                    @endphp
                                    <li wire:key="ai-assist-intake-{{ $intake->id }}">
                                        <a class="rt-ai-assist__activity-item" href="{{ $service->intakeUrl($intake) }}">
                                            <span class="rt-ai-assist__activity-icon" data-tone="{{ $tone }}"><i class="far {{ $intake->status === 'completed' ? 'fa-check' : 'fa-inbox' }}" aria-hidden="true"></i></span>
                                            <span class="rt-ai-assist__activity-text"><strong>{{ $intake->title ?: 'Eingang #'.$intake->id }}</strong><small>{{ $intake->customer?->company_name ?? 'Bitte prüfen und zuordnen' }}</small></span>
                                            <span class="rt-ai-assist__activity-side"><span>{{ \Carbon\CarbonImmutable::parse($intake->updated_at)->setTimezone(config('operations.display_timezone', 'Europe/Berlin'))->format('d.m. H:i') }}</span><span class="rt-ai-assist__badge" data-tone="{{ $tone }}">{{ $labels[$intake->status] ?? $intake->status }}</span></span>
                                        </a>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>
                @elseif($tab === 'actions')
                    <div class="rt-ai-assist__scroll">
                        @foreach(['page' => 'Für '.$pageLabel, 'global' => 'Überall'] as $group => $heading)
                            @if($actions[$group] !== [])
                                <p class="rt-ai-assist__kicker rt-ai-assist__kicker--block">{{ $heading }}</p>
                                <div class="rt-ai-assist__actions">
                                    @foreach($actions[$group] as $action)
                                        <button type="button" class="rt-ai-assist__action" x-on:click="run(@js($action['key']))" :disabled="busy">
                                            <i class="far {{ $action['icon'] }}" aria-hidden="true"></i><span><strong>{{ $action['title'] }}</strong><small>{{ $action['detail'] }}</small></span><i class="far fa-arrow-right" aria-hidden="true"></i>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    </div>
                @else
                    <div class="rt-ai-assist__scroll">
                        <div class="rt-ai-assist__filter" role="group" aria-label="Aktivitäten filtern">
                            @foreach(['all' => 'Alle', 'intake' => 'Eingänge', 'planning' => 'Planung'] as $key => $label)
                                <button type="button" aria-pressed="{{ $activityFilter === $key ? 'true' : 'false' }}" wire:click="$set('activityFilter', '{{ $key }}')">{{ $label }}</button>
                            @endforeach
                        </div>
                        @if($activity->isEmpty())
                            <p class="rt-ai-assist__note">Noch keine Aktivitäten. Übernommene Vorschläge und AI-Eingänge erscheinen hier.</p>
                        @else
                            @php
                                $zone = config('operations.display_timezone', 'Europe/Berlin');
                                $today = \Carbon\CarbonImmutable::now($zone)->toDateString();
                                $yesterday = \Carbon\CarbonImmutable::now($zone)->subDay()->toDateString();
                                $group = null;
                            @endphp
                            <ol class="rt-ai-assist__activity">
                                @foreach($activity as $item)
                                    @php
                                        $local = $item['at']->setTimezone($zone);
                                        $day = $local->toDateString() === $today ? 'Heute' : ($local->toDateString() === $yesterday ? 'Gestern' : $local->format('d.m.Y'));
                                    @endphp
                                    @if($day !== $group)
                                        @php
                                            $group = $day;
                                        @endphp
                                        <li class="rt-ai-assist__activity-group">{{ $day }}</li>
                                    @endif
                                    <li>
                                        @if($item['href'])<a class="rt-ai-assist__activity-item" href="{{ $item['href'] }}">@else<div class="rt-ai-assist__activity-item">@endif
                                            <span class="rt-ai-assist__activity-icon" data-tone="{{ $item['tone'] }}"><i class="far {{ $item['icon'] }}" aria-hidden="true"></i></span>
                                            <span class="rt-ai-assist__activity-text"><strong>{{ $item['title'] }}</strong><small>{{ $item['detail'] }}</small></span>
                                            <span class="rt-ai-assist__activity-side"><span>{{ $local->format('H:i') }}</span><span class="rt-ai-assist__badge" data-tone="{{ $item['tone'] }}">{{ $item['status'] }}</span></span>
                                        @if($item['href'])</a>@else</div>@endif
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>
                @endif
            </div>

            @if($tab === 'chat')
                <form class="rt-ai-assist__composer" x-on:submit.prevent="submit()">
                    <p class="rt-ai-assist__error" x-show="callError" style="display:none" role="alert" x-text="callError"></p>
                    <div class="rt-ai-assist__slash" role="listbox" aria-label="Aktionen" x-show="slash" style="display: none">
                        <template x-for="(action, index) in slashList" :key="action.key">
                            <button type="button" role="option" class="rt-ai-assist__slash-item" :aria-selected="index === slashIndex ? 'true' : 'false'" x-on:click="run(action.key)">
                                <i class="far" :class="action.icon" aria-hidden="true"></i><span x-text="action.title"></span>
                            </button>
                        </template>
                        <p class="rt-ai-assist__slash-empty" x-show="slashList.length === 0">Keine passende Aktion.</p>
                    </div>
                    <div class="rt-ai-assist__shell">
                        <textarea x-ref="input" rows="1" maxlength="500" placeholder="Frage stellen oder / für Aktionen" aria-label="Nachricht an AI-Assist"
                            x-model="text" x-on:input="typed()" x-on:keydown="keydown($event)" :disabled="busy"></textarea>
                        <button class="rt-ai-assist__send" type="submit" aria-label="Senden" :disabled="busy || text.trim() === ''"><i class="far fa-paper-plane" aria-hidden="true"></i></button>
                    </div>
                    <p class="rt-ai-assist__hint">Vorschläge wirken erst nach deiner Freigabe · <kbd>/</kbd> Aktionen · <kbd>Strg</kbd> <kbd>J</kbd> öffnen</p>
                </form>
            @endif
        </section>
    </div>
</div>
