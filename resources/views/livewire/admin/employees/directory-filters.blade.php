<div class="grid gap-3">
    <x-tables.filter-field :label="__('app.role')" for="employee-role-filter">
        <x-ui.forms.select id="employee-role-filter" wire:model.live="role" :aria-label="__('app.role')" class="w-full">
            <option value="">{{ __('app.all_roles') }}</option>
            <option value="admin">{{ __('app.role_admin') }}</option>
            <option value="staff">{{ __('app.role_staff') }}</option>
        </x-ui.forms.select>
    </x-tables.filter-field>
    <x-tables.filter-field :label="__('app.status')" for="employee-status-filter">
        <x-ui.forms.select id="employee-status-filter" wire:model.live="accountStatus" :aria-label="__('app.status')" class="w-full">
            <option value="">{{ __('app.all_statuses') }}</option>
            <option value="active">{{ ucfirst(__('app.active')) }}</option>
            <option value="inactive">{{ ucfirst(__('app.inactive')) }}</option>
        </x-ui.forms.select>
    </x-tables.filter-field>
    <x-tables.filter-field :label="__('app.team')" for="employee-team-filter">
        <x-ui.forms.select id="employee-team-filter" wire:model.live="teamId" :aria-label="__('app.team')" class="w-full">
            <option value="">{{ __('app.all_teams') }}</option>
            @foreach($teams as $team)<option value="{{ $team->id }}">{{ $team->name }}</option>@endforeach
        </x-ui.forms.select>
    </x-tables.filter-field>
    <x-tables.filter-field :label="__('app.display')" for="employee-page-size">
        <x-ui.forms.select id="employee-page-size" wire:model.live="perPage" :aria-label="__('app.display')" class="w-full">
            @foreach([15,30,50,100] as $size)<option value="{{ $size }}">{{ __('app.per_page', ['count'=>$size]) }}</option>@endforeach
        </x-ui.forms.select>
    </x-tables.filter-field>
</div>
