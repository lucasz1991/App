{{-- resources/views/components/tables/rows/admin-tasks/task-row.blade.php --}}
@props(['item'])

@php
    $task = $item;
    $hc = fn($i) => $hideClass($columnsMeta[$i]['hideOn'] ?? 'none');
    $id            = $task->id;
    $typeText      = $task->task_type_text ?? '—';
    $description   = $task->description ?: 'Keine Beschreibung angegeben.';
    $contextText   = $task->context_text ?? null;
    $contextDesc   = $task->context_description ?? null;
    $creatorName   = $task->creator?->name ?? 'Unbekannt';
    $assignedName  = $task->assignedAdmin?->name ?? 'Niemand';
    $createdAtLbl  = $task->created_at?->format('d.m.Y H:i');
    $dueAtLbl      = $task->due_at?->format('d.m.Y H:i');
    $statusTone    = match ($task->status) {
        \App\Models\AdminTask::STATUS_OPEN => 'red',
        \App\Models\AdminTask::STATUS_IN_PROGRESS => 'amber',
        default => 'green',
    };
@endphp

{{-- 0: ID --}}
<div
    data-rt-table-label="{{ $columnsMeta[0]['label'] ?? '' }}"
    class="px-2 py-2 {{ $hc(0) }} cursor-pointer"
    wire:click="$dispatch('openAdminTaskDetail',[ { taskId: {{ $task->id }}  }])"
>
    <div class="flex items-center gap-2 text-xs text-rt-muted dark:text-rt-dark-muted">
        <span class="h-2.5 w-2.5 rounded-full
            @if($task->status === \App\Models\AdminTask::STATUS_OPEN)
                bg-red-400
            @elseif($task->status === \App\Models\AdminTask::STATUS_IN_PROGRESS)
                bg-amber-400
            @else
                bg-emerald-400
            @endif
        "></span>

        <span class="font-mono text-[11px] text-rt-muted dark:text-rt-dark-muted">
            #{{ $id }}
        </span>
    </div>
</div>

{{-- 1: Typ + Kurzbeschreibung --}}
<div
    data-rt-table-label="{{ $columnsMeta[1]['label'] ?? '' }}"
    class="px-2 py-2 pr-4 {{ $hc(1) }} cursor-pointer"
    wire:click="$dispatch('openAdminTaskDetail',[ { taskId: {{ $task->id }}  }])"
>
    <div class="flex flex-col min-w-0 space-y-1" title="{{ $typeText }}">
        <div class="flex items-center gap-2 min-w-0">
            <div class="flex-1 min-w-0">
                <div class="truncate text-[12px] font-semibold text-rt-muted dark:text-rt-dark-muted">
                    {{ $typeText }}
                </div>
                  <div class="truncate text-[13px] font-semibold text-rt-text dark:text-rt-dark-text">
                    {{ $contextText }}
                </div>
            </div>
        </div>
    </div>
</div>

{{-- 3: Ersteller --}}
<div
    data-rt-table-label="{{ $columnsMeta[2]['label'] ?? '' }}"
    class="px-2 py-2 {{ $hc(2) }} cursor-pointer"
    wire:click="$dispatch('openAdminTaskDetail',[ { taskId: {{ $task->id }}  }])"
>
    <div class="flex flex-col gap-0.5">
        <x-user.public-info :person="$task->creator->person" />
        @if($createdAtLbl)
            <span class="text-[11px] text-rt-soft dark:text-rt-dark-soft">
                {{ $createdAtLbl }}
            </span>
        @endif
    </div>
</div>

{{-- 4: Status --}}
<div
    data-rt-table-label="{{ $columnsMeta[3]['label'] ?? '' }}"
    class="px-2 py-2 flex items-center justify-end gap-2 {{ $hc(3) }} cursor-pointer"
    wire:click="$dispatch('openAdminTaskDetail',[ { taskId: {{ $task->id }}  }])"
>
    <span data-rt-tone="{{ $statusTone }}" class="rt-ui-badge inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium
        @if($task->status === \App\Models\AdminTask::STATUS_OPEN)
            bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300
        @elseif($task->status === \App\Models\AdminTask::STATUS_IN_PROGRESS)
            bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300
        @else
            bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300
        @endif
    ">
        <span class="mr-1 text-xs">{{ $task->status_icon }}</span>
        {{ $task->status_text }}
    </span>
</div>
