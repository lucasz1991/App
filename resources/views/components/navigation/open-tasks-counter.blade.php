@php
    $count = \App\Models\AdminTask::open()->where('task_type', 'reportbook_review')->count();
@endphp

@if($count > 0)
    <span class="ml-2 inline-flex items-center rounded-full bg-red-500 px-2 py-0.5 text-xs font-semibold text-white"  title="{{ $count }} offene Aufgaben">
        {{ $count }}
    </span>
@endif
