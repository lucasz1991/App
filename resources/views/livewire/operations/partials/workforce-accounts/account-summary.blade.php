            @if($tab === 'account')
                <form wire:submit="applyAccountPeriod" class="rt-personnel-period rt-personnel-period--account" aria-label="Kontenzeitraum" novalidate>
                    <div class="rt-personnel-period__intro">
                        <h3>{{ $accountKind === 'time' ? ($personal ? 'Mein Zeitkonto' : 'Zeitkonto') : 'Kontenübersicht' }}</h3>
                        <p>{{ \Carbon\CarbonImmutable::parse($accountPeriod['from'])->format('d.m.Y') }} – {{ \Carbon\CarbonImmutable::parse($accountPeriod['until'])->format('d.m.Y') }}</p>
                    </div>
                    <x-operations.field label="Von" model="from" type="date" required />
                    <x-operations.field label="Bis" model="until" type="date" required />
                    <div class="rt-personnel-period__action"><x-ui.buttons.button-basic type="submit" wire:loading.attr="disabled" wire:target="applyAccountPeriod">Anzeigen</x-ui.buttons.button-basic></div>
                </form>
            @endif
            @if($tab === 'account')
                @if($accountKind !== 'vacation')<section class="rt-personnel-metrics" aria-label="Arbeitszeitkonto"><header class="rt-personnel-section-heading"><h3>Arbeitszeit</h3></header><dl class="rt-personnel-metrics__grid rt-personnel-metrics__grid--time">
                    @foreach(['balance_minutes'=>'Saldo','target_minutes'=>'Soll','actual_minutes'=>'Freigegebenes Ist','credited_minutes'=>'Kontogutschrift','absence_credit_minutes'=>'Urlaubsgutschrift'] as $key=>$label)
                        <div @class(['rt-personnel-metric', 'rt-personnel-metric--lead' => $key === 'balance_minutes'])><dt>{{ $label }}</dt><dd @class(['rt-personnel-metric__value', 'rt-personnel-metric__value--unavailable' => $summary[$key] === null])>{{ $summary[$key] === null ? 'Prüfung erforderlich' : ($summary[$key] < 0 ? '−' : '').\App\Support\Operations\OperationsDateTime::duration(abs($summary[$key]) * 60) }}</dd></div>
                    @endforeach
                </dl>
                @if($summary['adjustment_minutes'] != 0)
                    <p class="rt-personnel-notice">Gebuchte Zeitkorrekturen im Zeitraum: {{ $summary['adjustment_minutes'] > 0 ? '+' : '' }}{{ $summary['adjustment_minutes'] }} min</p>
                @endif
                @if($summary['balance_minutes'] === null)
                    <ul class="rt-personnel-notice rt-personnel-notice--warning" aria-label="Gründe für die offene Bewertung">
                        @if($summary['missing_dates'])<li>Für {{ count($summary['missing_dates']) }} {{ count($summary['missing_dates']) === 1 ? 'Tag' : 'Tage' }} fehlt ein gültiges Arbeitsmodell.</li>@endif
                        @if($summary['missing_valuations'])<li>Die Bewertung von Arbeitsabschnitten fehlt.</li>@endif
                        @if($summary['missing_time_allocations'])<li>Arbeitszeiten müssen dem Zeitraum zugeordnet werden.</li>@endif
                        @if($summary['missing_absence_allocations'])<li>Die Zuordnung einer genehmigten Abwesenheit zum Konto ist offen.</li>@endif
                    </ul>
                @endif
                </section>@endif
                @php($vacation = $summary['vacation'])
                @if($accountKind !== 'time')<section class="rt-personnel-metrics" aria-label="Urlaubskonto"><header class="rt-personnel-section-heading"><h3>Urlaub</h3></header><dl class="rt-personnel-metrics__grid rt-personnel-metrics__grid--vacation">@foreach(['available'=>'Verfügbar','reserved'=>'Reserviert','approved'=>'Genehmigt','used'=>'Verbraucht','expired'=>'Abgelaufen','credited'=>'Anspruch'] as $key=>$label)<div @class(['rt-personnel-metric', 'rt-personnel-metric--lead' => $key === 'available'])><dt>{{ $label }}</dt><dd @class(['rt-personnel-metric__value', 'rt-personnel-metric__value--unavailable' => $vacation['configured'] && $vacation[$key] === null])>{{ !$vacation['configured'] ? '—' : ($vacation[$key] === null ? 'Prüfung erforderlich' : ($vacation['unit'] === 'days' ? number_format($vacation[$key] / 100, 2, ',', '.').' Tage' : $vacation[$key].' min')) }}</dd></div>@endforeach</dl>
                @if(!$vacation['configured'])<p class="rt-personnel-notice">Urlaubsrichtlinie fehlt.</p>@endif
                @if($summary['missing_absence_allocations'] !== [])<p class="rt-personnel-notice rt-personnel-notice--warning">Urlaubsübernahme prüfen.</p>@endif</section>@endif
            @endif
