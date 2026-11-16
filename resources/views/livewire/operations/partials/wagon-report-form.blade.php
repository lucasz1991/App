{{--
    Meldezettel Zugvorbereitung (drittes Blatt der Excel-Liste v16_5).
    Zugnummer, Datum, Laufweg, erstes und letztes Fahrzeug sowie Gefahrgut
    übernimmt die Datei aus der Wagenliste; hier stehen nur die Angaben, die
    es sonst nirgends gibt.
--}}
@php
    $scope = $scope ?? 'desktop';

    $tasks = [
        'coupling' => __('app.report_task_coupling'),
        'wagonInspection' => __('app.report_task_wagon_inspection'),
        'dangerousGoodsCheck' => __('app.report_task_dangerous_goods'),
        'fullBrakeTest' => __('app.report_task_full_brake_test'),
        'tailSignal' => __('app.report_task_tail_signal'),
        'securingRemoved' => __('app.report_task_securing_removed'),
        'simpleBrakeTest' => __('app.report_task_simple_brake_test'),
    ];

    $choiceGroups = [
        [
            'model' => 'report.papers',
            'label' => __('app.report_papers'),
            'options' => [
                'electronic' => __('app.report_papers_electronic'),
                'paper' => __('app.report_papers_paper'),
                'both' => __('app.report_papers_both'),
                'none' => __('app.report_papers_none'),
            ],
        ],
        [
            'model' => 'report.transportOrder',
            'label' => __('app.report_transport_order'),
            'options' => [
                'complete' => __('app.report_transport_order_complete'),
                'standing' => __('app.report_transport_order_standing'),
                'none' => __('app.report_transport_order_none'),
            ],
        ],
        [
            'model' => 'report.uicCheck',
            'label' => __('app.report_uic_check'),
            'options' => [
                'yes' => __('app.yes'),
                'no' => __('app.no'),
            ],
        ],
    ];
@endphp

<section class="rt-wagon-card" aria-labelledby="wagon-report-train-{{ $scope }}">
    <header class="rt-wagon-card-head">
        <span class="rt-wagon-card-icon"><i class="far fa-file-signature" aria-hidden="true"></i></span>
        <h4 id="wagon-report-train-{{ $scope }}">{{ __('app.wagon_report') }}</h4>
    </header>
    <div class="rt-wagon-card-body">
        <div class="rt-wl-fields rt-wl-fields--4">
            <label class="rt-wl-field rt-wl-span-2">
                <span class="rt-wl-label">{{ __('app.customer') }}</span>
                <input x-model="meta.customer" class="rt-wl-input" autocomplete="off">
            </label>
            <label class="rt-wl-field">
                <span class="rt-wl-label">{{ __('app.track') }}</span>
                <input x-model="meta.track" class="rt-wl-input" maxlength="40" autocomplete="off">
            </label>
            <label class="rt-wl-field">
                <span class="rt-wl-label">
                    {{ __('app.time') }}
                    <button type="button" class="rt-wl-link" @click="setCurrentTime()">{{ __('app.now') }}</button>
                </span>
                <input x-model="meta.preparedTime" class="rt-wl-input rt-wl-input--center" inputmode="numeric" maxlength="5" placeholder="HH:MM" autocomplete="off">
            </label>
            <label class="rt-wl-field rt-wl-span-all">
                <span class="rt-wl-label">{{ __('app.report_prepared_by') }}</span>
                <input x-model="meta.preparedBy" class="rt-wl-input" autocomplete="off">
            </label>
        </div>

        @foreach ($choiceGroups as $group)
            <div class="rt-wl-field mt-3">
                <span class="rt-wl-label" id="wagon-{{ \Illuminate\Support\Str::slug($group['model']) }}-{{ $scope }}">{{ $group['label'] }}</span>
                <div class="rt-wl-chips" role="group" aria-labelledby="wagon-{{ \Illuminate\Support\Str::slug($group['model']) }}-{{ $scope }}">
                    @foreach ($group['options'] as $value => $optionLabel)
                        <button
                            type="button"
                            class="rt-wl-chip"
                            @click="{{ $group['model'] }} = {{ $group['model'] }} === @js($value) ? '' : @js($value)"
                            :aria-pressed="{{ $group['model'] }} === @js($value)"
                            :data-active="{{ $group['model'] }} === @js($value) ? 'true' : 'false'"
                        >{{ $optionLabel }}</button>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</section>

<section class="rt-wagon-card" aria-labelledby="wagon-report-tasks-{{ $scope }}">
    <header class="rt-wagon-card-head">
        <span class="rt-wagon-card-icon"><i class="far fa-tasks" aria-hidden="true"></i></span>
        <h4 id="wagon-report-tasks-{{ $scope }}">{{ __('app.report_tasks') }}</h4>
    </header>
    <div class="rt-wagon-card-body">
        @foreach ($tasks as $task => $label)
            <label class="rt-wl-check-row">
                <input type="checkbox" x-model="report.tasks.{{ $task }}">
                <span class="min-w-0 flex-1">{{ $loop->iteration }}. {{ $label }}</span>
            </label>

            @if ($task === 'wagonInspection')
                <div class="rt-wl-field mb-1 ml-8" x-show="report.tasks.wagonInspection">
                    <span class="rt-wl-label" id="wagon-report-level-{{ $scope }}">{{ __('app.report_inspection_level') }}</span>
                    <div class="rt-wl-chips" role="group" aria-labelledby="wagon-report-level-{{ $scope }}">
                        @foreach (['1', '2', '3', '4'] as $level)
                            <button
                                type="button"
                                class="rt-wl-chip"
                                @click="report.wagonInspectionLevel = report.wagonInspectionLevel === @js($level) ? '' : @js($level)"
                                :aria-pressed="report.wagonInspectionLevel === @js($level)"
                                :data-active="report.wagonInspectionLevel === @js($level) ? 'true' : 'false'"
                            >{{ $level }}</button>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</section>

<section class="rt-wagon-card" aria-labelledby="wagon-report-extra-{{ $scope }}">
    <header class="rt-wagon-card-head">
        <span class="rt-wagon-card-icon"><i class="far fa-list-ol" aria-hidden="true"></i></span>
        <h4 id="wagon-report-extra-{{ $scope }}">{{ __('app.report_extra_work') }}</h4>
    </header>
    <div class="rt-wagon-card-body grid gap-2">
        @for ($row = 0; $row < 5; $row++)
            <label class="rt-wl-extra-row">
                <span aria-hidden="true">{{ $row + 1 }}</span>
                <input x-model="report.extraWork[{{ $row }}]" class="rt-wl-input" maxlength="160" autocomplete="off" aria-label="{{ __('app.report_extra_work') }} {{ $row + 1 }}">
            </label>
        @endfor
    </div>
</section>

<section class="rt-wagon-card" aria-labelledby="wagon-report-remarks-{{ $scope }}">
    <header class="rt-wagon-card-head">
        <span class="rt-wagon-card-icon"><i class="far fa-comment-alt-lines" aria-hidden="true"></i></span>
        <h4 id="wagon-report-remarks-{{ $scope }}">{{ __('app.report_remarks') }}</h4>
    </header>
    <div class="rt-wagon-card-body">
        <textarea x-model="report.remarks" rows="4" maxlength="600" class="rt-wl-input !mt-0" aria-labelledby="wagon-report-remarks-{{ $scope }}"></textarea>
    </div>
</section>
