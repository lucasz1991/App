@if(\Illuminate\Support\Facades\Schema::hasTable('duty_activities') && \App\Support\Operations\OperationsEnhancementsSchema::ready())
    <x-operations.panel.group title="Dienstnachweise">
        @foreach(app(\App\Services\Operations\CustomerProofService::class)->coverage($shift) as $line)
            <x-operations.panel.row :label="$line['title']">{{ $line['document'] }} · {{ $line['complete'] }} von {{ $line['required'] }} freigegeben</x-operations.panel.row>
        @endforeach
    </x-operations.panel.group>
@endif
