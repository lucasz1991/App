{{-- resources\views\components\tables\rows\courses\course-row.blade.php --}}
@php
    // Kurzhelfer pro Spaltenindex
    $hc = fn($i) => $hideClass($columnsMeta[$i]['hideOn'] ?? 'none');

    // Felder aus dem neuen Course-Modell
    $title     = $item->title ?? '—';
    $vtz     = $item->vtz ?? null;         // falls du ein Kurzlabel führst
    $klassenId = $item->klassen_id ?? null;
    $room      = $item->room ?? null;


    // Zeitraum (casts: 'date')
    $start = $item->planned_start_date ?? null;
    $end   = $item->planned_end_date   ?? null;

    $startLbl = $start?->locale('de')->isoFormat('ll');
    $endLbl   = $end?->locale('de')->isoFormat('ll');
    $termin_id = $item->termin_id ?? null;

    // Status aus Zeitraum ableiten
    $now = now();
    $status = 'unknown';
    if ($start && $end) {
        $status = $now->lt($start) ? 'scheduled' : ($now->between($start, $end) ? 'active' : 'completed');
    } elseif ($start && !$end) {
        $status = $now->lt($start) ? 'scheduled' : 'active';
    }



@endphp

{{-- 0: Titel --}}
<div data-rt-table-label="{{ $columnsMeta[0]['label'] ?? '' }}" class="px-2 py-2 pr-4 {{ $hc(0) }} cursor-pointer" wire:click="$dispatch('toggleCourseSelection', [{{ $item->id }}])" x-on:dblclick="window.location='{{ route('admin.courses.show', $item) }}'">
<div class="grid grid-cols-[auto_1fr] gap-2 items-center">
    <div class="flex items-center">
        <div 
            class="w-4 h-4 rounded-full border cursor-pointer transition {{ $isSelected ? 'ring-4 ring-green-300 bg-green-100 border-green-600' : 'border-gray-400' }}"
        >
        </div>
    </div>
    <div class="flex flex-col min-w-0" title="{{ $title }}">
         <div class="px-1">
             <div class=" font-semibold truncate">
                 {{ $title }}
            </div>
                <span>{{ $item->course_short_name ?? '—' }}</span>
         </div>
    </div>
</div>





</div>



{{-- 2: Zeitraum (planned_start_date / planned_end_date) --}}
<div data-rt-table-label="{{ $columnsMeta[1]['label'] ?? '' }}" class="flex justify-center px-2 py-2 text-xs {{ $hc(1) }}">
    <div class="rt-ui-surface-muted inline-flex flex-col items-center justify-center rounded-lg border border-rt-border bg-rt-surface-muted px-2 py-1.5 text-rt-text shadow-rt-xs dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-rt-dark-text">
        <div class="font-semibold text-[10px] leading-tight">
            {{ $termin_id }}
        </div>

        <div class="my-0.5 w-6 border-t border-rt-border dark:border-rt-dark-border"></div>

        @if($startLbl || $endLbl)
            <div class="font-medium text-[10px] leading-tight">
                {{ $startLbl ?? '—' }} – {{ $endLbl ?? '—' }}
            </div>
        @else
            <div class="text-[10px] text-rt-soft dark:text-rt-dark-soft">—</div>
        @endif
    </div>
</div>


{{-- 3: Status (nur Icons mit Tooltip) bg-yellow-100  text-yellow-600 text-blue-400 bg-blue-100 --}}
<div data-rt-table-label="{{ $columnsMeta[2]['label'] ?? '' }}" class="px-2 py-2 flex items-center gap-2 {{ $hc(2) }}">
    <i class="{{ $item->status_icon }}" title="{{ $item->status_icon_title }}"></i>
</div>



{{-- 1: Tutor (aus Person) --}}
<div data-rt-table-label="{{ $columnsMeta[3]['label'] ?? '' }}" class="truncate px-2 py-2 text-rt-text dark:text-rt-dark-text {{ $hc(3) }}">
    @if($item->tutor !== null)
        <span class="inline-flex items-center gap-1">
            <x-user.public-info :person="$item->tutor" />
        </span>
    @else
        <span class="text-rt-soft dark:text-rt-dark-soft">—</span>
    @endif
</div>

{{-- 4: Aktivitäten (Teilnehmer & Exporte) --}}
<div data-rt-table-label="{{ $columnsMeta[4]['label'] ?? '' }}" class="px-2 py-1 text-xs {{ $hc(4) }}">
    <div class="flex gap-1 items-center pr-8">
        @can('courses.export')
        @php
            $exportActions = [
                [
                    'can'   => $item->canExportAttendancePdf(),
                    'title' => 'Anwesenheit',
                    'icon'  => 'fal fa-clipboard-list-check fa-lg',
                    'badge' => $item->attendance_icon_html,
                    'wire'  => "exportAttendancePdf({$item->id})",
                ],
                [
                    'can'   => $item->canExportDokuPdf(),
                    'title' => 'Dokumentation',
                    'icon'  => 'fal fa-chalkboard-teacher fa-lg',
                    'badge' => $item->documentation_icon_html,
                    'wire'  => "exportDokuPdf({$item->id})",
                ],
                [
                    'can'   => $item->canExportRedThreadPdf(),
                    'title' => 'Roter Faden',
                    'icon'  => 'fal fa-file-pdf fa-lg',
                    'badge' => $item->red_thread_icon_html,
                    'wire'  => "exportRedThreadPdf({$item->id})",
                ],
                [
                    'can'   => $item->canExportMaterialConfirmationsPdf(),
                    'title' => 'Bildungsmittel-Bestätigungen',
                    'icon'  => 'fal fa-file-signature fa-lg',
                    'badge' => $item->participants_confirmations_icon_html,
                    'wire'  => "exportMaterialConfirmationsPdf({$item->id})",
                ],
                [
                    'can'   => $item->canExportExamResultsPdf(),
                    'title' => 'Pruefungsergebnisse',
                    'icon'  => 'fal fa-clipboard-check fa-lg',
                    'badge' => $item->exam_results_icon_html,
                    'wire'  => "exportExamResultsPdf({$item->id})",
                ],
                [
                    'can'   => $item->canExportCourseRatingsPdf(),
                    'title' => 'Kursbewertungen',
                    'icon'  => 'fal fa-star fa-lg',
                    'badge' => $item->course_ratings_icon_html,
                    'wire'  => "exportCourseRatingsPdf({$item->id})",
                ],
                [
                    'can'   => $item->canExportInvoicePdf() && Gate::allows('invoices.view'),
                    'title' => 'Rechnung',
                    'icon'  => 'fal fa-money-check-alt fa-lg',
                    'badge' => $item->invoice_icon_html,
                    'wire'  => "exportInvoicePdf({$item->id})",
                ],

            ];
        @endphp
        @foreach($exportActions as $action)
            <div wire:key="export-action-{{ $action['title'] }}-{{ $item->id }}">
                @if($action['can'])
                    <x-ui.dropdown.anchor-dropdown
                        align="right"
                        width="40"
                        dropdownClasses="mt-1 w-44 overflow-hidden"
                        contentClasses="bg-rt-surface text-rt-text dark:bg-rt-dark-surface dark:text-rt-dark-text"
                        :overlay="false"
                        :trap="false"
                        :scrollOnOpen="false"
                        :offset="6"
                    >
                        <x-slot name="trigger">
                            <button
                                type="button"
                                title="{{ $action['title'] }}"
                                class="rt-ui-button rt-ui-button-secondary relative mr-2 inline-flex items-center gap-1 rounded border border-rt-border bg-rt-surface px-1 py-1 text-rt-text transition hover:bg-rt-surface-muted dark:border-rt-dark-border dark:bg-rt-dark-surface dark:text-rt-dark-text dark:hover:bg-rt-dark-surface-muted"
                            >
                                <i class="{{ $action['icon'] }}"></i>
                                <span class="rt-ui-surface absolute -right-2 -top-2 rounded-full bg-rt-surface/90 p-[2px] dark:bg-rt-dark-surface/90">
                                    {!! $action['badge'] !!}
                                </span>
                            </button>
                        </x-slot>
                        <x-slot name="content">
                            <div class="py-1 text-xs text-rt-text dark:text-rt-dark-text">
                                <button
                                    type="button"
                                    wire:click="{{ $action['wire'] }}"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $action['wire'] }}"
                                    class="rt-ui-dropdown-link flex w-full items-center gap-2 px-3 py-2 text-sm hover:bg-rt-surface-muted disabled:cursor-wait disabled:opacity-60 dark:hover:bg-rt-dark-nav-hover"
                                >
                                    <i class="fal fa-download text-[14px] text-rt-muted dark:text-rt-dark-muted" wire:loading.remove wire:target="{{ $action['wire'] }}"></i>
                                    <i class="fal fa-spinner fa-spin text-[14px] text-blue-500" wire:loading wire:target="{{ $action['wire'] }}"></i>
                                    <span>{{ $action['title'] }}</span>
                                </button>
                            </div>
                        </x-slot>
                    </x-ui.dropdown.anchor-dropdown>
                @else
                    <div
                        title="{{ $action['title'] }}"
                        class="rt-ui-button rt-ui-button-secondary relative mr-2 inline-flex cursor-not-allowed items-center gap-1 rounded border border-rt-border bg-rt-surface-muted px-1 py-1 text-rt-soft opacity-60 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted dark:text-rt-dark-soft"
                    >
                        <i class="{{ $action['icon'] }}"></i>
                        <div class="rt-ui-surface absolute -right-2 -top-2 rounded-full bg-rt-surface/90 p-[2px] dark:bg-rt-dark-surface/90">
                            {!! $action['badge'] !!}
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
        @endcan
    </div>
</div>
