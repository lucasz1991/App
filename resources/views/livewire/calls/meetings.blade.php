<div class="flex h-full min-h-0 flex-col overflow-hidden text-rt-text dark:text-rt-dark-text" wire:poll.visible.10s>
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
                    <button type="button" wire:click="showCreate('instant')" class="rt-floating-toolbar__button" aria-label="Meeting jetzt starten" title="Jetzt starten"><i class="far fa-video" aria-hidden="true"></i></button>
                    <button type="button" wire:click="showCreate('plan')" @disabled(! $planningReady) class="rt-floating-toolbar__button" aria-label="Meeting planen" title="Termin planen"><i class="far fa-plus" aria-hidden="true"></i></button>
                @endif
            </x-slot:actions>
        </x-ui.floating.toolbar>
        <div class="scroll-container min-h-0 flex-1 space-y-3 overflow-y-auto bg-[#e4e8ee] p-3 dark:bg-rt-dark-surface-muted">
            @unless ($planningReady)<p role="status" class="rounded-2xl bg-white p-4 text-sm dark:bg-rt-dark-surface">Die Terminplanung ist noch nicht verfügbar. Sofortmeetings bleiben verfügbar.</p>@endunless
            @foreach ($live as $room)
                <article class="flex items-center gap-3 rounded-2xl bg-white p-4 dark:bg-rt-dark-surface" wire:key="meeting-live-{{ $room->id }}">
                    <i class="far fa-signal-stream text-emerald-600" aria-hidden="true"></i><div class="min-w-0 flex-1"><p class="truncate text-sm font-bold">{{ $room->name }}</p><p class="text-xs text-rt-muted dark:text-rt-dark-muted">Läuft jetzt · {{ $room->owner?->name }}</p></div>
                    <button type="button" wire:click="join({{ $room->id }})" wire:loading.attr="disabled" class="min-h-11 rounded-xl px-3 text-xs font-semibold text-emerald-700">Beitreten</button>
                </article>
            @endforeach
            @forelse ($plans ?? [] as $meeting)
                <button type="button" wire:click="openMeeting({{ $meeting->id }})" class="flex w-full items-center gap-3 rounded-2xl bg-white p-4 text-left dark:bg-rt-dark-surface" wire:key="meeting-plan-{{ $meeting->id }}">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-rt-surface-muted text-rt-red dark:bg-rt-dark-surface-muted"><i class="far fa-calendar" aria-hidden="true"></i></span>
                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold">{{ $meeting->title }}</span><span class="block text-xs text-rt-muted dark:text-rt-dark-muted">{{ $meeting->starts_at->setTimezone($meeting->timezone)->format('d.m.Y H:i') }} · {{ $meeting->timezone }}</span><span class="block text-xs text-rt-muted dark:text-rt-dark-muted">{{ $meeting->status === 'cancelled' ? 'Abgesagt' : $meeting->owner?->name }} · {{ $meeting->participants->where('response', 'accepted')->count() }}/{{ $meeting->participants->count() }} zugesagt</span></span><i class="far fa-chevron-right text-xs text-rt-muted" aria-hidden="true"></i>
                </button>
            @empty
                @if ($planningReady)<p class="py-10 text-center text-sm text-rt-muted dark:text-rt-dark-muted">Keine passenden Termine.</p>@endif
            @endforelse
            @if ($plans?->hasPages()){{ $plans->links() }}@endif
        </div>
    @elseif ($pane === 'form')
        <div class="flex shrink-0 items-center gap-2 border-b border-rt-border/60 p-2 dark:border-rt-dark-border/60"><button type="button" wire:click="showList" class="h-11 w-11 rounded-xl" aria-label="Zurück zur Meetingliste"><i class="far fa-arrow-left" aria-hidden="true"></i></button><p class="text-sm font-bold">{{ $formMode === 'instant' ? 'Jetzt starten' : 'Meeting planen' }}</p></div>
        <form wire:submit="{{ $formMode === 'instant' ? 'createMeeting' : 'savePlan' }}" class="scroll-container min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
            <label class="block text-xs font-semibold">Titel<x-ui.forms.input wire:model="name" class="mt-1 w-full" maxlength="120" required /></label><x-input-error for="name" /><x-input-error for="title" />
            @if ($formMode !== 'instant')
                <label class="block text-xs font-semibold">Zeitzone<x-ui.forms.input wire:model="timezone" class="mt-1 w-full" required /></label><x-input-error for="timezone" />
                <label class="block text-xs font-semibold">Beginn<x-ui.forms.input type="datetime-local" wire:model="startsAt" class="mt-1 w-full" required /></label><x-input-error for="starts_at" />
                <label class="block text-xs font-semibold">Ende<x-ui.forms.input type="datetime-local" wire:model="endsAt" class="mt-1 w-full" required /></label><x-input-error for="ends_at" />
                @if ($formMode === 'plan')<fieldset class="space-y-2"><legend class="mb-2 text-xs font-semibold">Teilnehmer · {{ count($participantIds) }} ausgewählt</legend><label class="block"><span class="sr-only">Teilnehmer suchen</span><x-ui.forms.input type="search" wire:model.live.debounce.300ms="participantSearch" class="w-full" placeholder="Name suchen" /></label>
                    @foreach ($candidates as $candidate)<label class="flex min-h-11 items-center gap-3 rounded-xl bg-rt-surface-muted p-3 dark:bg-rt-dark-surface-muted" wire:key="meeting-person-{{ $candidate->id }}"><x-ui.forms.checkbox wire:model="participantIds" value="{{ $candidate->id }}" /><span class="text-sm">{{ $candidate->name }}</span></label>@endforeach
                </fieldset><x-input-error for="participant_ids" />@endif
            @endif
            <label class="flex min-h-11 items-center gap-2 text-sm"><x-ui.forms.checkbox wire:model="video" />Mit Video starten</label>
            <x-ui.buttons.button-basic type="submit" class="w-full justify-center" wire:loading.attr="disabled">{{ $formMode === 'instant' ? 'Gespräch starten' : 'Termin speichern' }}</x-ui.buttons.button-basic>
        </form>
    @elseif ($selectedMeeting)
        @php $myResponse = $selectedMeeting->participants->firstWhere('user_id', auth()->id())?->response; $activeSession = $selectedMeeting->sessions->first(fn ($room) => $room->isActive()); $isOwner = (int) $selectedMeeting->owner_id === (int) auth()->id(); @endphp
        <div class="flex shrink-0 items-center gap-2 border-b border-rt-border/60 p-2 dark:border-rt-dark-border/60"><button type="button" wire:click="showList" class="h-11 w-11 rounded-xl" aria-label="Zurück zur Meetingliste"><i class="far fa-arrow-left" aria-hidden="true"></i></button><p class="min-w-0 truncate text-sm font-bold">{{ $selectedMeeting->title }}</p></div>
        <div class="scroll-container min-h-0 flex-1 space-y-4 overflow-y-auto bg-[#e4e8ee] p-4 dark:bg-rt-dark-surface-muted">
            <section class="space-y-3 rounded-2xl bg-white p-4 dark:bg-rt-dark-surface"><p class="text-sm font-semibold">{{ $selectedMeeting->starts_at->setTimezone($selectedMeeting->timezone)->format('d.m.Y H:i') }} – {{ $selectedMeeting->ends_at->setTimezone($selectedMeeting->timezone)->format('d.m.Y H:i') }}</p><p class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ $selectedMeeting->timezone }} · {{ $selectedMeeting->owner?->name }}</p>
                @if ($selectedMeeting->status === 'cancelled')<p role="status" class="text-sm font-bold text-rt-red">Meeting abgesagt</p>
                @else
                    @unless ($isOwner)<div class="flex flex-wrap gap-2" aria-label="Termin-Zusage"><button type="button" wire:click="respond({{ $selectedMeeting->id }}, 'accepted')" aria-pressed="{{ $myResponse === 'accepted' ? 'true' : 'false' }}" class="min-h-11 rounded-xl border border-rt-border px-3 text-xs font-semibold">Zusagen</button><button type="button" wire:click="respond({{ $selectedMeeting->id }}, 'declined')" aria-pressed="{{ $myResponse === 'declined' ? 'true' : 'false' }}" class="min-h-11 rounded-xl border border-rt-border px-3 text-xs font-semibold">Ablehnen</button></div>@endunless
                    @if ($activeSession)<x-ui.buttons.button-basic wire:click="joinMeeting({{ $selectedMeeting->id }})" wire:loading.attr="disabled">Beitreten</x-ui.buttons.button-basic>
                    @elseif ($isOwner && $canStart)<x-ui.buttons.button-basic wire:click="startMeeting({{ $selectedMeeting->id }})" wire:loading.attr="disabled">Meeting starten</x-ui.buttons.button-basic>@endif
                    @if ($isOwner && ! $activeSession)<div class="flex flex-wrap gap-2"><button type="button" wire:click="editMeeting({{ $selectedMeeting->id }})" class="min-h-11 rounded-xl px-3 text-xs font-semibold">Termin ändern</button><button type="button" wire:click="cancelMeeting({{ $selectedMeeting->id }})" wire:confirm="Meeting wirklich absagen?" wire:loading.attr="disabled" class="min-h-11 rounded-xl px-3 text-xs font-semibold text-rt-red">Termin absagen</button></div>@endif
                @endif
            </section>
            <section class="rounded-2xl bg-white p-4 dark:bg-rt-dark-surface"><h3 class="mb-2 text-sm font-bold">Teilnehmer</h3>@foreach ($selectedMeeting->participants as $participant)<p class="flex min-h-11 items-center justify-between gap-3 text-sm"><span class="min-w-0 truncate">{{ $participant->user?->name }}</span><span class="text-xs text-rt-muted dark:text-rt-dark-muted">{{ match ($participant->response) { 'accepted' => 'Zugesagt', 'declined' => 'Abgelehnt', default => 'Offen' } }}</span></p>@endforeach</section>
        </div>
    @endif
</div>
