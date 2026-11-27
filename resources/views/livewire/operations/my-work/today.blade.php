{{-- Mein Arbeitstag, Reiter „Heute": Kurzstatus, Stempeluhr, Tagesübersicht. Daten aus MyWork::render(). --}}
<div class="rt-today rt-workday-overview-page">
    @include('livewire.operations.partials.personal-glance')
    @include('livewire.operations.partials.personal-workday-actions')
    <x-operations.workday-overview :overview="$workdayOverview" :timezone="$displayTimezone" :request-count="$requestCount" :sickness-url="route('operations.mine', ['area' => 'sickness'])" :vacation-url="route('operations.mine', ['area' => 'absences', 'new' => 1])" />
    <div class="rt-today-install rt-today-enter" style="--i:6">@include('livewire.operations.partials.pwa-install-card')</div>
</div>
