<section class="ops-stack min-w-0" data-planning-workspace="{{ $page }}">
    @php
        $icons = ['plan'=>'fa-clock','calendar'=>'fa-calendar','capacity'=>'fa-chart-bar','staff'=>'fa-users','tools'=>'fa-layer-group','logistics'=>'fa-route','board'=>'fa-tachometer-alt','cases'=>'fa-exclamation-triangle','transfers'=>'fa-exchange-alt'];
        $options = collect($this->views())->map(fn($label,$key)=>['value'=>$key,'label'=>$label,'icon'=>$icons[$key]])->values()->all();
    @endphp
    @if($page !== 'shifts' || $view !== 'plan')<header class="ops-toolbar">
        <x-ui.buttons.multi-toggle :options="$options" :value="$view" action="selectView" label="Ansicht wählen" />
        @if(count($this->sections()) > 1)
            <x-ui.forms.select aria-label="Bereich wählen" change="$wire.selectSection($event.target.value)">
                @foreach($this->sections() as $key=>$label)<option value="{{ $key }}" @selected($key === $section)>{{ $label }}</option>@endforeach
            </x-ui.forms.select>
        @endif
    </header>@endif
    @if($page === 'shifts' && $view === 'plan')
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
        <livewire:operations.attention-center mode="monitor" :initial-tab="$section" :embedded="true" :context="$context" :key="'hub-monitor-'.$section" />
    @endif
</section>
