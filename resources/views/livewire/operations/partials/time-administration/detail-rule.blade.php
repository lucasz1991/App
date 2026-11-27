                    @php
                        $configLabels = ['type'=>'Prüfung','days'=>'Prüfzeitraum (Tage)','limit'=>'Obergrenze','activity'=>'Abschnitt','timezone'=>'Zeitzone','wage_code'=>'Lohnart','multiplier_bps'=>'Bewertung (Basispunkte)','rounding_minutes'=>'Rundung (min)','rounding_mode'=>'Rundungsart','window_start'=>'Zeitfenster ab','window_end'=>'Zeitfenster bis','weekdays'=>'Wochentage','additive'=>'Zusätzliche Zuschlagsmenge'];
                        $configValues = ['type'=>['rolling_minutes'=>'Minuten im Prüfzeitraum','consecutive_days'=>'Diensttage in Folge','night_count'=>'Nachtdienste im Prüfzeitraum'], 'rounding_mode'=>['none'=>'Keine','nearest'=>'Kaufmännisch','up'=>'Aufrunden','down'=>'Abrunden'], 'activity'=>['all'=>'Gesamter Dienst'] + \App\Services\Operations\WorkTimeActivityService::KINDS];
                        $weekdayLabels = [1=>'Mo', 2=>'Di', 3=>'Mi', 4=>'Do', 5=>'Fr', 6=>'Sa', 7=>'So'];
                    @endphp
                    <dl class="ops-meta">
                        <div><dt>Regelart</dt><dd>{{ $selected->kind === 'planning' ? 'Planungsgrenze' : 'Vergütungsbewertung' }}</dd></div>
                        <div><dt>Gültigkeit</dt><dd>{{ $selected->starts_on->format('d.m.Y') }} – {{ $selected->ends_on?->format('d.m.Y') ?? 'offen' }}</dd></div>
                        @foreach($selected->configuration as $key => $value)
                            <div><dt>{{ $configLabels[$key] ?? 'Regelwert' }}</dt><dd>@if($key === 'weekdays' && is_array($value)){{ $value ? implode(' · ', array_map(fn ($day) => $weekdayLabels[$day] ?? $day, $value)) : 'Alle Wochentage' }}@elseif(is_bool($value)){{ $value ? 'Ja' : 'Nein' }}@elseif(is_scalar($value)){{ $configValues[$key][$value] ?? $value }}@else{{ is_array($value) ? implode(', ', $value) : 'Offen' }}@endif</dd></div>
                        @endforeach
                    </dl>
