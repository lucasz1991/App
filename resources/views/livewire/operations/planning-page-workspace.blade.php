<section class="ops-stack min-w-0" data-planning-workspace="{{ $page }}">
    @php
        $icons = ['overview'=>'fa-compass','plan'=>'fa-clock','calendar'=>'fa-calendar','capacity'=>'fa-chart-bar','staff'=>'fa-users','tools'=>'fa-layer-group','logistics'=>'fa-route','board'=>'fa-tachometer-alt','cases'=>'fa-exclamation-triangle','transfers'=>'fa-exchange-alt'];
        $availableViews = $this->views();
        $options = collect($availableViews)->map(fn($label,$key)=>['value'=>$key,'label'=>$label,'icon'=>$icons[$key]])->values()->all();
    @endphp
    @if(!$embedded && ($page !== 'shifts' || $view !== 'plan'))<header class="ops-toolbar">
        <x-ui.buttons.multi-toggle :options="$options" :value="$view" action="selectView" label="Ansicht wählen" />
        @if(count($this->sections()) > 1)
            <x-ui.forms.select aria-label="Bereich wählen" change="$wire.selectSection($event.target.value)">
                @foreach($this->sections() as $key=>$label)<option value="{{ $key }}" @selected($key === $section)>{{ $label }}</option>@endforeach
            </x-ui.forms.select>
        @endif
    </header>@endif
    @if($page === 'planning' && $view === 'overview')
        <section class="ops-panel ops-stack" aria-labelledby="planning-demand-heading">
            <div>
                <p class="ops-kicker">Vom Auftrag zum Dienst</p>
                <h2 id="planning-demand-heading">Bedarf im Auftrag planen</h2>
                @if(\App\Support\Operations\PlanningSchema::ready())
                    <p class="ops-muted mt-2">Auftrag auswählen und dort „Bedarf &amp; Planung“ öffnen. Hier werden Personalbedarf, Tätigkeiten und Zeiträume des Auftrags geplant.</p>
                @else
                    <p class="ops-muted mt-2">Aufträge und Schichten sind erreichbar. Die Erweiterung für auftragsbezogene Bedarfe ist noch nicht eingerichtet.</p>
                @endif
            </div>
            <div class="ops-actions">
                <x-ui.buttons.button-basic mode="primary" :href="\App\Support\Operations\OperationsPages::url('cases', ['view' => 'orders'] + array_intersect_key($context, array_flip(['customer'])))">
                    <i class="far fa-briefcase" aria-hidden="true"></i>Aufträge öffnen
                </x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic :href="\App\Support\Operations\OperationsPages::url('shifts', ['view' => 'plan'])">
                    <i class="far fa-clock" aria-hidden="true"></i>Schichtplan
                </x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic :href="\App\Support\Operations\OperationsPages::url('shifts', ['view' => 'calendar'])">
                    <i class="far fa-calendar-alt" aria-hidden="true"></i>Kalender
                </x-ui.buttons.button-basic>
            </div>
        </section>
        <section class="ops-panel ops-stack" aria-labelledby="planning-extensions-heading">
            <div>
                <h2 id="planning-extensions-heading">Erweiterte Planung</h2>
                <p class="ops-muted mt-2">Verfügbare Werkzeuge öffnen Sie direkt. Noch nicht eingerichtete Erweiterungen verändern keine bestehenden Aufträge oder Schichten.</p>
            </div>
            <dl>
                @foreach(['capacity' => 'Bedarf & Kapazität', 'staff' => 'Personalangebot', 'tools' => 'Planungswerkzeuge', 'logistics' => 'Reisen & Partner'] as $key => $label)
                    <div class="ops-row flex flex-wrap items-center justify-between gap-3" wire:key="planning-extension-{{ $key }}">
                        <dt class="font-medium">{{ $label }}</dt>
                        <dd>
                            @if(isset($availableViews[$key]))
                                <x-ui.buttons.button-basic type="button" size="sm" wire:click="selectView('{{ $key }}')" aria-label="{{ $label }} öffnen">Öffnen<i class="far fa-arrow-right" aria-hidden="true"></i></x-ui.buttons.button-basic>
                            @else
                                <span class="ops-muted">Noch nicht eingerichtet</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @elseif($page === 'shifts' && $view === 'plan')
        @if(isset($context['shift']))<div x-data x-init="$nextTick(() => $dispatch('operations-shift-detail-request', {id: @js($context['shift'])}))"></div>@endif
        <livewire:admin.operations.shift-management :key="'hub-shifts-plan'" />
    @elseif($page === 'shifts')
        <livewire:admin.operations.calendar :key="'hub-shifts-calendar'" />
    @elseif($page === 'planning' && $view === 'staff' || $page === 'duty' && in_array($view,['cases','transfers'],true))
        <livewire:operations.workforce-planning :tab="$section" :embedded="true" :context="$context" :key="'hub-workforce-'.$section" />
    @elseif($page === 'planning' && $view === 'tools' && $section === 'variants')
        <livewire:operations.plan-variants />
    @elseif($page === 'planning' && in_array($view,['capacity','tools'],true))
        <livewire:operations.planning-enhancements :tab="$section" :embedded="true" :key="'hub-planning-'.$section" />
    @elseif($page === 'planning' && $view === 'logistics')
        <livewire:operations.operations-enhancements :tab="$section" :workspace="true" :key="'hub-logistics-'.$section" />
    @elseif($page === 'duty')
        @if(!\App\Support\Operations\OperationsNavigation::enhancementReady('duty-monitor'))
            <p class="ops-panel ops-muted" role="note">Der Dienststand zeigt veröffentlichte Schichten und tatsächliche Zeitmeldungen. Überwachungsprofile und automatische Meldungsprüfungen sind noch nicht eingerichtet; es werden keine Toleranzen angenommen.</p>
        @endif
        <livewire:operations.attention-center mode="monitor" :initial-tab="$section" :embedded="true" :context="$context" :key="'hub-monitor-'.$section" />
    @endif
</section>
