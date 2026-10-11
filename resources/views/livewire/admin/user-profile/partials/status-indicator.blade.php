{{-- Presentation only: the existing Anchor owns hover, touch, focus and Escape. --}}
<x-ui.dropdown.anchor-dropdown
    align="right" width="64" content-role="dialog" :content-label="$indicatorLabel"
    :dropdown-id="$profilePrefix.'-status-'.$indicatorKey" :layer-group="$profilePrefix.'-status'"
    :open-on-hover="true" :offset="8" content-classes="employee-profile-status-detail p-3 bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-white">
    <x-slot:trigger>
        <button type="button" class="employee-profile__status-trigger" data-profile-status-trigger="{{ $indicatorKey }}"
            data-state="{{ $indicatorState }}" aria-label="{{ $indicatorLabel }}: {{ $indicatorValue }}. Details anzeigen"
            aria-haspopup="dialog">
            <i class="{{ $indicatorIcon }}" aria-hidden="true"></i>
            <span class="employee-profile__status-signal" aria-hidden="true">
                <i class="far {{ match ($indicatorState) { 'active', 'ready' => 'fa-check', 'inactive', 'denied' => 'fa-times', 'unknown', 'unset' => 'fa-question', default => 'fa-info' } }}"></i>
            </span>
        </button>
    </x-slot:trigger>
    <x-slot:content>
        <div class="flex items-center justify-between gap-2">
            <p class="text-xs font-semibold">{{ $indicatorLabel }}</p>
            <button type="button" class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-rt-muted hover:bg-rt-surface-muted focus-visible:outline focus-visible:outline-2 focus-visible:outline-rt-red dark:text-rt-dark-muted dark:hover:bg-rt-dark-surface-muted"
                aria-label="Statusdetails schließen" @click="close(true)">
                <i class="far fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <p class="mt-1 text-sm">{{ $indicatorValue }}</p>
        @if($indicatorNote)
            <p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $indicatorNote }}</p>
        @endif
        @if($indicatorPhoneCount !== null)
            <p class="mt-1 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $indicatorPhoneCount }} {{ $indicatorPhoneCount === 1 ? 'Handy zugewiesen' : 'Handys zugewiesen' }}</p>
        @endif
        @if($indicatorEvidence)
            <p class="mt-2 text-xs text-rt-muted dark:text-rt-dark-muted">{{ $indicatorEvidence }}</p>
        @endif
    </x-slot:content>
</x-ui.dropdown.anchor-dropdown>
