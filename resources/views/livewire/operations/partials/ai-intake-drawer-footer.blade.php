@if($selected)
    <div class="rt-intake-drawer__footer" data-intake-drawer-footer>
        <div class="rt-intake-drawer__footer-status"><strong>{{ $drawerState['status'] }}</strong>@if($drawerState['reason'])<span class="ops-muted" id="intake-drawer-footer-reason-{{ $this->getId() }}">{{ $drawerState['reason'] }}</span>@endif</div>
        <div class="rt-intake-drawer__footer-actions">
            @if($drawerState['editing'])<x-ui.buttons.button-basic type="button" wire:click="cancelPositionEdit" wire:loading.attr="disabled">Abbrechen</x-ui.buttons.button-basic>
            @elseif($planningProposalId !== null)<x-ui.buttons.button-basic type="button" wire:click="closePlanning" wire:loading.attr="disabled">Abbrechen</x-ui.buttons.button-basic>
            @else<x-ui.buttons.button-basic type="button" wire:click="closeDetail">Schließen</x-ui.buttons.button-basic>@endif
            @if($drawerState['action'] === 'save-position')
                <x-ui.buttons.button-basic type="submit" :form="'intake-drawer-position-form-'.$this->getId()" mode="primary" wire:loading.attr="disabled">Position speichern</x-ui.buttons.button-basic>
            @elseif($drawerState['action'])
                <x-ui.buttons.button-basic type="button" mode="primary" wire:click="{{ $drawerState['action'] }}" wire:loading.attr="disabled" :disabled="$drawerState['disabled'] ? true : null" :aria-describedby="$drawerState['reason'] ? 'intake-drawer-footer-reason-'.$this->getId() : null">{{ $drawerState['label'] }}</x-ui.buttons.button-basic>
            @endif
        </div>
    </div>
@endif
