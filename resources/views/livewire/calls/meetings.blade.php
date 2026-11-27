<div class="rt-comm-meetings flex h-full min-h-0 flex-col overflow-hidden text-rt-text dark:text-rt-dark-text" wire:poll.visible.10s>
    @if ($pane === 'list')
        <x-ui.floating.toolbar label="Meetings suchen und filtern" search>
            <x-ui.forms.search context="floating" id="communication-meeting-search" wire:model.live.debounce.300ms="search" placeholder="Meetings suchen …" aria-label="Meetings suchen" autocomplete="off" />
            <x-slot:actions>
                <x-ui.dropdown.anchor-dropdown align="right" width="48" :offset="6" dropdown-id="communication-meeting-filter" layer-group="communication-controls" content-label="Meetings filtern">
                    <x-slot:trigger>
                        <button type="button" class="rt-floating-toolbar__button" aria-label="Meetings filtern" title="Meetings filtern" aria-haspopup="menu"><i class="far fa-sliders-h" aria-hidden="true"></i></button>
                    </x-slot:trigger>
                    <x-slot:content>
                        @foreach (['upcoming' => 'Anstehend', 'past' => 'Vergangen', 'all' => 'Alle'] as $key => $label)
                            <x-ui.dropdown.dropdown-link role="menuitemradio" aria-checked="{{ $filter === $key ? 'true' : 'false' }}" wire:click="$set('filter', '{{ $key }}')" x-on:click="close()">
                                <i class="far fa-check" style="visibility: {{ $filter === $key ? 'visible' : 'hidden' }}" aria-hidden="true"></i>
                                {{ $label }}
                            </x-ui.dropdown.dropdown-link>
                        @endforeach
                    </x-slot:content>
                </x-ui.dropdown.anchor-dropdown>
                @if ($canStart)
                    <button type="button" wire:click="showCreate('instant')" class="rt-floating-toolbar__button rt-comm-meeting-start" aria-label="Meeting jetzt starten" title="Jetzt starten"><i class="far fa-video" aria-hidden="true"></i></button>
                    <button type="button" wire:click="showCreate('plan')" @disabled(! $planningReady) class="rt-floating-toolbar__button rt-comm-meeting-plan" aria-label="Meeting planen" title="Termin planen"><i class="far fa-plus" aria-hidden="true"></i></button>
                @endif
            </x-slot:actions>
        </x-ui.floating.toolbar>
        <div class="rt-comm-meeting-list scroll-container min-h-0 flex-1 overflow-y-auto">
            @unless ($planningReady)<p role="status" class="rounded-2xl p-4 text-sm">Die Terminplanung ist noch nicht verfügbar. Sofortmeetings bleiben verfügbar.</p>@endunless
            @foreach ($live as $room)
                <article class="rt-comm-meeting-card rt-comm-meeting-card--live flex items-center" wire:key="meeting-live-{{ $room->id }}">
                    <i class="far fa-signal-stream" aria-hidden="true"></i>
                    <div class="min-w-0 flex-1">
                        @if ($room->communicationMeetingId())
                            <button type="button" class="block max-w-full truncate text-left text-sm font-bold" wire:click="openMeeting({{ $room->communicationMeetingId() }})">{{ $room->name }}</button>
                        @else
                            <p class="truncate text-sm font-bold">{{ $room->name }}</p>
                        @endif
                        <p class="text-xs">{{ data_get($room->settings, 'provisioning') ? 'Wird vorbereitet' : 'Läuft jetzt' }} · {{ $room->owner?->name }}</p>
                    </div>
                    @unless (data_get($room->settings, 'provisioning') || $room->participantFor(auth()->id())?->isRemoved())
                        <button type="button" wire:click="join({{ $room->id }})" wire:loading.attr="disabled" class="rt-comm-meeting-join min-h-11 shrink-0 rounded-lg px-3 text-sm font-semibold">Beitreten</button>
                    @endunless
                </article>
            @endforeach
            @forelse ($plans ?? [] as $meeting)
                @php
                    $session = $meeting->sessions->first(fn ($room) => $room->isActive());
                    $owner = (int) $meeting->owner_id === (int) auth()->id();
                    $response = $meeting->participants->firstWhere('user_id', auth()->id())?->response;
                    $upcoming = $meeting->status === 'scheduled' && $meeting->ends_at->isFuture();
                    $begin = $meeting->starts_at->setTimezone($meeting->timezone);
                    $end = $meeting->ends_at->setTimezone($meeting->timezone);
                @endphp
                <article class="rt-comm-meeting-card flex w-full items-center" wire:key="meeting-plan-{{ $meeting->id }}">
                    <button type="button" wire:click="openMeeting({{ $meeting->id }})" class="rt-comm-meeting-summary flex min-w-0 flex-1 items-center text-left">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-red dark:bg-rt-dark-surface-muted"><i class="far fa-calendar" aria-hidden="true"></i></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-bold">{{ $meeting->title }}</span>
                            <span class="block text-xs text-rt-muted dark:text-rt-dark-muted">{{ $begin->format('d.m.Y H:i') }} – {{ $end->format($begin->isSameDay($end) ? 'H:i' : 'd.m.Y H:i') }} · {{ $meeting->timezone }}</span>
                            <span class="block text-xs text-rt-muted dark:text-rt-dark-muted">{{ $meeting->status === 'cancelled' ? 'Abgesagt' : $meeting->owner?->name }} · {{ $meeting->participants->where('response', 'accepted')->count() }}/{{ $meeting->participants->count() }} zugesagt</span>
                        </span>
                    </button>
                    @if ($session && $meeting->status === 'scheduled')
                        @unless (data_get($session->settings, 'provisioning') || $session->participantFor(auth()->id())?->isRemoved())
                            <x-ui.buttons.button-basic mode="primary" wire:click="joinMeeting({{ $meeting->id }})" wire:loading.attr="disabled">Beitreten</x-ui.buttons.button-basic>
                        @endunless
                    @elseif ($upcoming && $owner && $canStart)
                        <x-ui.buttons.button-basic mode="primary" wire:click="startMeeting({{ $meeting->id }})" wire:loading.attr="disabled">Meeting starten</x-ui.buttons.button-basic>
                    @elseif ($upcoming && ! $owner && $response === 'pending')
                        <x-ui.buttons.button-basic mode="primary" wire:click="respond({{ $meeting->id }}, 'accepted')" wire:loading.attr="disabled">Zusagen</x-ui.buttons.button-basic>
                    @elseif ($upcoming && ! $owner)
                        <span class="shrink-0 text-xs font-semibold">{{ $response === 'accepted' ? 'Zugesagt' : 'Abgelehnt' }}</span>
                    @endif
                </article>
            @empty
                @if ($planningReady && $live->isEmpty())<p class="rt-comm-meeting-empty text-center text-sm">Keine passenden Termine.</p>@endif
            @endforelse
            @if ($plans?->hasPages())<x-tables.pagination :paginator="$plans" :compact="true" label="Terminseiten" />@endif
        </div>
    @elseif ($pane === 'form')
        <div class="rt-comm-meeting-heading flex shrink-0 items-center gap-2 border-b border-rt-border/60 p-2 dark:border-rt-dark-border/60"><button type="button" wire:click="showList" class="h-11 w-11 rounded-xl" aria-label="Zurück zur Meetingliste"><i class="far fa-arrow-left" aria-hidden="true"></i></button><p class="font-bold">{{ $formMode === 'instant' ? 'Jetzt starten' : 'Meeting planen' }}</p></div>
        <form wire:submit="{{ $formMode === 'instant' ? 'createMeeting' : 'savePlan' }}" class="rt-comm-meeting-form scroll-container min-h-0 flex-1 space-y-4 overflow-y-auto">
            <label class="block font-semibold">Titel<x-ui.forms.input wire:model="name" :maxlength="$formMode === 'instant' ? 80 : 120" required /></label><x-ui.forms.input-error for="name" /><x-ui.forms.input-error for="title" />
            @if ($formMode !== 'instant')
                <div class="rt-comm-meeting-times">
                    @foreach (['starts_at' => ['startsAt', 'Beginn'], 'ends_at' => ['endsAt', 'Ende']] as $field => [$property, $label])
                        @php $timeError = $errors->first($field) ?: ($timeErrors[$field][0] ?? null); @endphp
                        <div class="min-w-0">
                            <x-ui.forms.label :for="'meeting-'.$property" :value="$label" />
                            <x-ui.forms.date-time-field :id="'meeting-'.$property" wire:model.live="{{ $property }}" :required="true" :aria-label="$label" :aria-invalid="$timeError ? 'true' : null" :aria-describedby="$timeError ? 'meeting-'.$property.'-error' : null" />
                            @if ($timeError)<p id="meeting-{{ $property }}-error" role="alert" class="mt-1 text-xs font-medium text-rt-red">{{ $timeError }}</p>@endif
                        </div>
                    @endforeach
                </div>
                <p aria-live="polite" class="text-sm font-semibold empty:hidden">@if ($duration !== null)Dauer · {{ $duration }} {{ $duration === 1 ? 'Minute' : 'Minuten' }}@endif</p>
                <label class="block font-semibold">Zeitzone<x-ui.forms.input wire:model.blur="timezone" required /></label><x-ui.forms.input-error for="timezone" />
                @if ($formMode === 'plan')<fieldset><legend class="mb-2 font-semibold">Teilnehmer · {{ count($participantIds) }} ausgewählt</legend><x-ui.forms.search wire:model.live.debounce.300ms="participantSearch" placeholder="Name suchen" aria-label="Teilnehmer suchen" />
                    @foreach ($candidates as $candidate)<label class="flex min-h-11 items-center gap-3 p-3" wire:key="meeting-person-{{ $candidate->id }}"><x-ui.forms.checkbox wire:model="participantIds" value="{{ $candidate->id }}" /><span class="text-sm">{{ $candidate->name }}</span></label>@endforeach
                </fieldset><x-ui.forms.input-error for="participant_ids" />@endif
            @endif
            <label class="flex min-h-11 items-center gap-2 text-sm"><x-ui.forms.checkbox wire:model="video" />Mit Video starten</label>
            <x-ui.buttons.button-basic mode="primary" type="submit" class="w-full justify-center" wire:loading.attr="disabled">{{ $formMode === 'instant' ? 'Gespräch starten' : 'Termin speichern' }}</x-ui.buttons.button-basic>
        </form>
    @elseif ($selectedMeeting)
        @php
            $myResponse = $selectedMeeting->participants->firstWhere('user_id', auth()->id())?->response;
            $activeSession = $selectedMeeting->sessions->first(fn ($room) => $room->isActive())
                ?? $live->first(fn ($room) => $room->communicationMeetingId() === (int) $selectedMeeting->id);
            $isOwner = (int) $selectedMeeting->owner_id === (int) auth()->id();
        @endphp
        <div class="rt-comm-meeting-heading flex shrink-0 items-center gap-2 border-b border-rt-border/60 p-2 dark:border-rt-dark-border/60"><button type="button" wire:click="showList" class="h-11 w-11 rounded-xl" aria-label="Zurück zur Meetingliste"><i class="far fa-arrow-left" aria-hidden="true"></i></button><p class="min-w-0 truncate font-bold">{{ $selectedMeeting->title }}</p></div>
        <div class="rt-comm-meeting-details scroll-container min-h-0 flex-1 space-y-4 overflow-y-auto">
            <section class="space-y-3 p-4"><p class="text-sm font-semibold">{{ $selectedMeeting->starts_at->setTimezone($selectedMeeting->timezone)->format('d.m.Y H:i') }} – {{ $selectedMeeting->ends_at->setTimezone($selectedMeeting->timezone)->format('d.m.Y H:i') }}</p><p class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $selectedMeeting->timezone }} · {{ $selectedMeeting->owner?->name }}</p>
                @if ($selectedMeeting->status === 'cancelled')<p role="status" class="text-sm font-bold text-rt-red">Meeting abgesagt</p>
                @else
                    @if ($activeSession)
                        @unless (data_get($activeSession->settings, 'provisioning') || $activeSession->participantFor(auth()->id())?->isRemoved())
                            <x-ui.buttons.button-basic mode="primary" wire:click="join({{ $activeSession->id }})" wire:loading.attr="disabled">Beitreten</x-ui.buttons.button-basic>
                        @endunless
                    @elseif ($selectedMeeting->ends_at->isFuture())
                        @if ($isOwner && $canStart)
                            <x-ui.buttons.button-basic mode="primary" wire:click="startMeeting({{ $selectedMeeting->id }})" wire:loading.attr="disabled">Meeting starten</x-ui.buttons.button-basic>
                            <div class="flex flex-wrap gap-2"><button type="button" wire:click="editMeeting({{ $selectedMeeting->id }})" class="min-h-11 rounded-xl px-3 text-xs font-semibold">Termin ändern</button><button type="button" wire:click="cancelMeeting({{ $selectedMeeting->id }})" wire:confirm="Meeting wirklich absagen?" wire:loading.attr="disabled" class="min-h-11 rounded-xl px-3 text-xs font-semibold text-rt-red">Termin absagen</button></div>
                        @elseif (! $isOwner)
                            <div class="flex flex-wrap gap-2" aria-label="Termin-Zusage">
                                <button type="button" wire:click="respond({{ $selectedMeeting->id }}, 'accepted')" aria-pressed="{{ $myResponse === 'accepted' ? 'true' : 'false' }}" class="min-h-11 rounded-xl border border-rt-border px-3 text-xs font-semibold">Zusagen</button>
                                <button type="button" wire:click="respond({{ $selectedMeeting->id }}, 'declined')" aria-pressed="{{ $myResponse === 'declined' ? 'true' : 'false' }}" class="min-h-11 rounded-xl border border-rt-border px-3 text-xs font-semibold">Ablehnen</button>
                            </div>
                        @endif
                    @else
                        <p class="text-sm text-rt-muted dark:text-rt-dark-muted">Termin vergangen</p>
                    @endif
                @endif
            </section>
            <section class="p-4"><h3 class="mb-2 text-sm font-bold">Teilnehmer</h3>@foreach ($selectedMeeting->participants as $participant)<p class="flex min-h-11 items-center justify-between gap-3 text-sm"><span class="min-w-0 truncate">{{ $participant->user?->name }}</span><span class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ match ($participant->response) { 'accepted' => 'Zugesagt', 'declined' => 'Abgelehnt', default => 'Offen' } }}</span></p>@endforeach</section>
        </div>
    @endif
</div>
