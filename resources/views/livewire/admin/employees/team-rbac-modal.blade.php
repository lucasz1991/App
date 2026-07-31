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
        <x-ui.buttons.button-basic wire:click="close" class="mr-2 min-h-11">
            <i class="far fa-times" aria-hidden="true"></i>
            {{ __('app.close') }}
        </x-ui.buttons.button-basic>
        <x-ui.buttons.button-basic wire:click="save" wire:loading.attr="disabled" mode="primary" class="min-h-11">
            <i class="far fa-save" wire:loading.remove wire:target="save" aria-hidden="true"></i>
            <i class="far fa-spinner fa-spin" wire:loading wire:target="save" aria-hidden="true"></i>
            {{ __('app.save') }}
        </x-ui.buttons.button-basic>
    </x-slot>
</x-dialog-modal>
@endif
</div>
