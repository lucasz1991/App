<div data-team-rbac-livewire="{{ $embedded ? 'embedded' : 'modal' }}">
@php($createFormId = $embedded ? 'settings-team-create' : 'directory-team-create')
@if($embedded)
    <x-admin.team-rbac-manager
        :teams="$teams"
        :selected-team-id="$selectedTeamId"
        :permission-groups="$permissionGroups"
        :embedded="true"
    />
@endif
<x-dialog-modal :id="$embedded ? 'settings-team-create-dialog' : 'directory-team-rbac-dialog'" wire:model="{{ $embedded ? 'showCreateTeamModal' : 'showModal' }}" :max-width="$showCreateTeamModal ? 'xl' : '4xl'">
    <x-slot name="title">
        {{ $showCreateTeamModal ? 'Team erstellen' : __('app.teams_permissions') }}
    </x-slot>

    <x-slot name="content">
        @if($showCreateTeamModal)
            <x-admin.team-create-form :id="$createFormId" :focus-in-place="!$embedded" />
        @elseif(! $embedded)
            <x-admin.team-rbac-manager
                :teams="$teams"
                :selected-team-id="$selectedTeamId"
                :permission-groups="$permissionGroups"
            />
        @endif
    </x-slot>

    <x-slot name="footer">
        @if($showCreateTeamModal)
            <x-ui.buttons.button-basic wire:click="closeCreateTeam" wire:loading.attr="disabled" wire:target="createTeam" class="min-h-11">
                {{ __('app.cancel') }}
            </x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic type="submit" :form="$createFormId" wire:loading.attr="disabled" wire:target="createTeam" mode="primary" class="min-h-11">
                <i class="far fa-plus" wire:loading.remove wire:target="createTeam" aria-hidden="true"></i>
                <i class="far fa-spinner fa-spin" wire:loading wire:target="createTeam" aria-hidden="true"></i>
                Team erstellen
            </x-ui.buttons.button-basic>
        @else
            <x-ui.buttons.button-basic wire:click="close" class="mr-2 min-h-11">
                <i class="far fa-times" aria-hidden="true"></i>
                {{ __('app.close') }}
            </x-ui.buttons.button-basic>
            <x-ui.buttons.button-basic wire:click="save" wire:loading.attr="disabled" mode="primary" class="min-h-11">
                <i class="far fa-save" wire:loading.remove wire:target="save" aria-hidden="true"></i>
                <i class="far fa-spinner fa-spin" wire:loading wire:target="save" aria-hidden="true"></i>
                {{ __('app.save') }}
            </x-ui.buttons.button-basic>
        @endif
    </x-slot>
</x-dialog-modal>
</div>
