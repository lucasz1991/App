@if(\Illuminate\Support\Facades\Schema::hasTable('duty_activities') && \App\Support\Operations\OperationsEnhancementsSchema::ready())
    @php
        $proofService = app(\App\Services\Operations\CustomerProofService::class);
        $scope = isset($coverageTitle) ? $coverageScope : $proofService->visibleProofOwners(auth()->user());
        $overview = $proofService->proofOverview($shift, $scope);
    @endphp
    <x-operations.panel.group :title="$coverageTitle ?? 'Dienstnachweise'" :meta="$overview['notice']" data-shift-duty-proofs>
        <dl class="rt-ops-panel__rows">
            @foreach($overview['lines'] as $line)
                <x-operations.panel.row :label="$line['title']">
                    <strong>{{ $line['document'] }}</strong>
                    @if($shift->published_revision)
                        <span> · {{ $line['complete'] }} von {{ $line['required'] }} freigegeben</span>
                    @endif
                    @foreach($line['states'] as $state => $count)
                        <small class="block">@if($line['individual'] && $line['required'] > 1){{ $count }} × @endif{{ $state }}</small>
                    @endforeach
                    @if($line['unassigned'])
                        <small class="block">Belege ohne passende Zuweisung: {{ $line['unassigned'] }} · Disposition</small>
                    @endif
                </x-operations.panel.row>
            @endforeach
        </dl>
    </x-operations.panel.group>
@endif
