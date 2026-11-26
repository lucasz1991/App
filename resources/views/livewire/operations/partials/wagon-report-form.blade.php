{{--
    Meldezettel Zugvorbereitung (Blatt der Excel-Liste v17).
    Zugnummer, Datum, Laufweg, erstes und letztes Fahrzeug sowie Gefahrgut
    übernimmt die Datei aus der Wagenliste; hier stehen nur die Angaben, die
    es sonst nirgends gibt.

    $part teilt das Formular für die Arbeitsfläche am Desktop auf zwei Karten
    auf: 'train' (Kunde, Gleis, Angaben zum Zug, Bestätigung) und 'tasks' (Aufträge,
    Zusatzarbeiten, Besonderheiten). Ohne Angabe erscheint alles, so nutzt es
    die geführte Eingabe am Telefon.
--}}
@php
    $scope = $scope ?? 'desktop';
    $part = $part ?? 'all';
    $showTrain = in_array($part, ['all', 'train'], true);
    $showTasks = in_array($part, ['all', 'tasks'], true);

    $tasks = [
        'coupling' => __('app.report_task_coupling'),
        'wagonInspection' => __('app.report_task_wagon_inspection'),
        'dangerousGoodsCheck' => __('app.report_task_dangerous_goods'),
        'fullBrakeTest' => __('app.report_task_full_brake_test'),
        'tailSignal' => __('app.report_task_tail_signal'),
        'securingRemoved' => __('app.report_task_securing_removed'),
        'simpleBrakeTest' => __('app.report_task_simple_brake_test'),
    ];

    // Angaben zum Zug wie im Vordruck (VDV) und in der Excel-Liste v17:
    // zwei Spalten mit je zwei Ankreuzfeldern, je Spalte höchstens ein Kreuz,
    // leer ist erlaubt. Ein zweiter Klick nimmt das Kreuz wieder weg.
    $choiceGroups = [
        [
            'model' => 'report.papers',
            'key' => 'papers',
            'label' => __('app.report_papers'),
            'options' => [
                'complete' => __('app.report_papers_complete'),
                'none' => __('app.report_papers_none'),
            ],
        ],
        [
            'model' => 'report.transportOrder',
            'key' => 'transport-order',
            'label' => __('app.report_transport_order'),
            'options' => [
                'complete' => __('app.report_transport_order_complete'),
                'standingOrNone' => __('app.report_transport_order_standing_or_none'),
            ],
        ],
    ];
@endphp

@if ($showTrain)
    <section
        class="rt-wagon-card"
        @if ($scope === 'desktop') aria-label="{{ __('app.wagon_report') }}" @else aria-labelledby="wagon-report-train-{{ $scope }}" @endif
    >
        {{-- Am Desktop trägt die umgebende Karte bereits die Überschrift. --}}
        @if ($scope !== 'desktop')
            <header class="rt-wagon-card-head">
                <span class="rt-wagon-card-icon"><i class="far fa-file-signature" aria-hidden="true"></i></span>
                <h4 id="wagon-report-train-{{ $scope }}">{{ __('app.wagon_report') }}</h4>
            </header>
        @endif
        <div class="rt-wagon-card-body">
            <div class="rt-wl-fields rt-wl-fields--4">
                <label class="rt-wl-field rt-wl-span-2">
                    <span class="rt-wl-label">{{ __('app.customer') }}</span>
                    <input x-model="meta.customer" class="rt-wl-input" autocomplete="off">
                </label>
                <label class="rt-wl-field rt-wl-span-2">
                    <span class="rt-wl-label">{{ __('app.track') }}</span>
                    <input x-model="meta.track" class="rt-wl-input" maxlength="40" autocomplete="off">
                </label>
            </div>

            <div class="rt-wl-train-details" data-wagon-train-details>
                <span class="rt-wl-train-details-title">{{ __('app.report_train_details') }}</span>
                <div class="rt-wl-train-details-grid">
                    @foreach ($choiceGroups as $group)
                        <div class="rt-wl-train-details-group" role="group" aria-labelledby="wagon-report-{{ $group['key'] }}-{{ $scope }}">
                            <span class="rt-wl-label" id="wagon-report-{{ $group['key'] }}-{{ $scope }}">{{ $group['label'] }}</span>
                            @foreach ($group['options'] as $value => $optionLabel)
                                <label class="rt-wl-check-row" :data-done="{{ $group['model'] }} === @js($value) ? 'true' : 'false'">
                                    <input
                                        type="checkbox"
                                        :checked="{{ $group['model'] }} === @js($value)"
                                        @change="{{ $group['model'] }} = $event.target.checked ? @js($value) : ''"
                                    >
                                    <span class="min-w-0 flex-1">{{ $optionLabel }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Bestätigung: wer die Wagenliste erstellt hat und wann. --}}
            <div class="rt-wl-fields rt-wl-fields--4 rt-wl-fields--confirm">
                <label class="rt-wl-field rt-wl-span-all">
                    <span class="rt-wl-label">{{ __('app.report_prepared_by') }}</span>
                    <input x-model="meta.preparedBy" class="rt-wl-input" autocomplete="off">
                </label>
                <label class="rt-wl-field rt-wl-span-2">
                    <span class="rt-wl-label">
                        {{ __('app.time') }}
                        <button type="button" class="rt-wl-link" @click="setCurrentTime()">{{ __('app.now') }}</button>
                    </span>
                    <input x-model="meta.preparedTime" class="rt-wl-input rt-wl-input--center" inputmode="numeric" maxlength="5" placeholder="HH:MM" autocomplete="off">
                </label>
            </div>
        </div>
    </section>
@endif

@if ($showTasks)
    <section class="rt-wagon-card" aria-labelledby="wagon-report-tasks-{{ $scope }}">
        <header class="rt-wagon-card-head">
            <span class="rt-wagon-card-icon"><i class="far fa-tasks" aria-hidden="true"></i></span>
            <h4 id="wagon-report-tasks-{{ $scope }}">{{ __('app.report_tasks') }}</h4>
            <span class="rt-wl-state" x-text="reportProgressLabel"></span>
        </header>
        <div class="rt-wagon-card-body">
            <div class="rt-wl-progress" aria-hidden="true">
                <span :style="`transform: scaleX(${reportProgress.ratio})`"></span>
            </div>

            @foreach ($tasks as $task => $label)
                <label class="rt-wl-check-row" :data-done="report.tasks.{{ $task }} ? 'true' : 'false'">
                    <input type="checkbox" x-model="report.tasks.{{ $task }}">
                    <span class="min-w-0 flex-1"><span class="rt-wl-check-no">{{ $loop->iteration }}</span>{{ $label }}</span>
                </label>

                @if ($task === 'wagonInspection')
                    <div class="rt-wl-check-sub" x-show="report.tasks.wagonInspection">
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
        <div class="rt-wagon-card-body rt-wl-extra">
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
            <textarea x-model="report.remarks" rows="4" maxlength="600" class="rt-wl-input rt-wl-input--area" aria-labelledby="wagon-report-remarks-{{ $scope }}"></textarea>
        </div>
    </section>
@endif
