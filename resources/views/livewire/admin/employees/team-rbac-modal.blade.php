<div data-team-rbac-livewire="{{ $embedded ? 'embedded' : 'modal' }}">
@if($embedded)
    <x-admin.team-rbac-manager
        :teams="$teams"
        :selected-team-id="$selectedTeamId"
        :permission-groups="$permissionGroups"
        :embedded="true"
    />
@else
<x-dialog-modal wire:model="showModal" maxWidth="4xl">
    <x-slot name="title">
        {{ __('app.teams_permissions') }}
    </x-slot>

    <x-slot name="content">
        <x-admin.team-rbac-manager
            :teams="$teams"
            :selected-team-id="$selectedTeamId"
            :permission-groups="$permissionGroups"
        />
    </x-slot>

    <x-slot name="footer">
        <x-ui.buttons.button-basic wire:click="close" class="mr-2">
            <i class="far fa-times"></i>
            {{ __('app.close') }}
        </x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic wire:click="save" wire:loading.attr="disabled">
            <i class="fal fa-save" wire:loading.remove wire:target="save"></i>
            <i class="fal fa-spinner fa-spin text-rt-red" wire:loading wire:target="save"></i>
            {{ __('app.save') }}
        </x-ui.buttons.button-basic>
    </x-slot>
</x-dialog-modal>
@endif
</div>
