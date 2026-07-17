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
<div class="px-2 py-2  pr-4 {{ $hc(0) }} cursor-pointer" wire:click="$dispatch('toggleCourseSelection', [{{ $item->id }}])" x-on:dblclick="window.location='{{ route('admin.courses.show', $item) }}'">
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
<div class="flex justify-center px-2 py-2 text-xs {{ $hc(1) }}">
    <div class="inline-flex flex-col items-center justify-center px-2 py-1.5 rounded-lg border border-slate-200 bg-slate-50 text-slate-700 shadow-sm">
        <div class="font-semibold text-[10px] leading-tight">
            {{ $termin_id }}
        </div>

        <div class="w-6 border-t border-slate-200 my-0.5"></div>

        @if($startLbl || $endLbl)
            <div class="font-medium text-[10px] leading-tight">
                {{ $startLbl ?? '—' }} – {{ $endLbl ?? '—' }}
            </div>
        @else
            <div class="text-gray-400 text-[10px]">—</div>
        @endif
    </div>
</div>


{{-- 3: Status (nur Icons mit Tooltip) bg-yellow-100  text-yellow-600 text-blue-400 bg-blue-100 --}}
<div class="px-2 py-2 flex items-center gap-2 {{ $hc(2) }}">
    <i class="{{ $item->status_icon }}" title="{{ $item->status_icon_title }}"></i>
</div>



{{-- 1: Tutor (aus Person) --}}
<div class="px-2 py-2 text-gray-700 truncate {{ $hc(3) }}">
    @if($item->tutor !== null)
        <span class="inline-flex items-center gap-1">
            <x-user.public-info :person="$item->tutor" />
        </span>
    @else
        <span class="text-gray-400">—</span>
    @endif
</div>

{{-- 4: Aktivitäten (Teilnehmer & Exporte) --}}
<div class="px-2 py-1 text-xs {{ $hc(4) }}">
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
                        dropdownClasses="mt-1 w-44 bg-white rounded-lg shadow-lg border border-gray-200 overflow-hidden"
                        contentClasses="bg-white"
                        :overlay="false"
                        :trap="false"
                        :scrollOnOpen="false"
                        :offset="6"
                    >
                        <x-slot name="trigger">
                            <div
                                title="{{ $action['title'] }}"
                                class="relative inline-flex items-center gap-1 px-1 py-1 rounded bg-gray-50 text-gray-700 border border-gray-300 mr-2 cursor-pointer hover:bg-gray-100 hover:border-gray-400 transition"
                            >
                                <i class="{{ $action['icon'] }}"></i>
                                <div class="absolute -top-2 -right-2 bg-white/70 rounded-full aspect-square p-[2px]">
                                    {!! $action['badge'] !!}
                                </div>
                            </div>
                        </x-slot>
                        <x-slot name="content">
                            <div class="py-1 text-xs text-gray-700">
                                <button
                                    type="button"
                                    wire:click="{{ $action['wire'] }}"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $action['wire'] }}"
                                    class="flex w-full items-center gap-2 px-3 py-2 hover:bg-gray-50 text-sm disabled:opacity-60 disabled:cursor-wait"
                                >
                                    <i class="fal fa-download text-[14px] text-gray-500" wire:loading.remove wire:target="{{ $action['wire'] }}"></i>
                                    <i class="fal fa-spinner fa-spin text-[14px] text-blue-500" wire:loading wire:target="{{ $action['wire'] }}"></i>
                                    <span>{{ $action['title'] }}</span>
                                </button>
                            </div>
                        </x-slot>
                    </x-ui.dropdown.anchor-dropdown>
                @else
                    <div
                        title="{{ $action['title'] }}"
                        class="relative inline-flex items-center gap-1 px-1 py-1 rounded bg-gray-50 text-gray-400 border border-gray-200 mr-2 cursor-not-allowed opacity-60"
                    >
                        <i class="{{ $action['icon'] }}"></i>
                        <div class="absolute -top-2 -right-2 bg-white/70 rounded-full aspect-square p-[2px]">
                            {!! $action['badge'] !!}
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
        @endcan
    </div>
</div>
