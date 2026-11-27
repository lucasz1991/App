{{-- Formularbearbeitung: Fußleiste mit Haupt-, Neben- und weiteren Aktionen (drawerState). --}}
<div class="rt-intake-drawer__footer" data-intake-drawer-footer>
    @if($drawerState['reason'])<span class="sr-only" id="intake-drawer-footer-reason-{{ $this->getId() }}">{{ $drawerState['reason'] }}</span>@endif
    <x-ui.dropdown.anchor-dropdown align="top" width="80" :dropdown-id="'intake-actions-'.$this->getId()" content-label="Weitere Aktionen für diesen Eingang" class="rt-intake-drawer__more">
        <x-slot:trigger><x-ui.buttons.button-basic type="button" aria-label="Weitere Aktionen" aria-haspopup="menu" x-bind:aria-expanded="open"><span>Mehr</span><i class="far fa-ellipsis-h" aria-hidden="true"></i></x-ui.buttons.button-basic></x-slot:trigger>
        <x-slot:content>
            @foreach($drawerState['more'] as $footerAction)
                <x-ui.dropdown.dropdown-link :as="$footerAction['href'] ? 'a' : 'button'" :href="$footerAction['href']" role="menuitem" wire:key="intake-more-{{ $footerAction['key'] }}"
                    :wire:click="$footerAction['action'] ?: null" :wire:confirm="$footerAction['confirm'] ?: null" :disabled="$footerAction['disabled'] ? true : null" wire:loading.attr="disabled" data-no-navigate>{{ $footerAction['label'] }}</x-ui.dropdown.dropdown-link>
            @endforeach
        </x-slot:content>
    </x-ui.dropdown.anchor-dropdown>
    <div class="rt-intake-drawer__footer-actions">
        @if($drawerState['cancelAction'])<x-ui.buttons.button-basic type="button" wire:click="{{ $drawerState['cancelAction'] }}" wire:loading.attr="disabled">Abbrechen</x-ui.buttons.button-basic>@endif
        @foreach(['primary', 'secondary'] as $footerGroup)
            @php $footerAction = $drawerState[$footerGroup]; @endphp
            @if($footerAction)
                @if($footerAction['action'] === 'save-position')
                    <x-ui.buttons.button-basic type="submit" :form="'intake-drawer-position-form-'.$this->getId()" mode="primary" wire:loading.attr="disabled">{{ $footerAction['label'] }}</x-ui.buttons.button-basic>
                @else
                    <x-ui.buttons.button-basic type="button" :mode="$footerGroup === 'primary' ? 'primary' : 'secondary'" :href="$footerAction['href']" :wire:click="$footerAction['action'] ?: null" :wire:confirm="$footerAction['confirm'] ?: null" :disabled="$footerAction['disabled'] ? true : null" :aria-describedby="$drawerState['reason'] ? 'intake-drawer-footer-reason-'.$this->getId() : null" wire:loading.attr="disabled" data-no-navigate>{{ $footerAction['label'] }}</x-ui.buttons.button-basic>
                @endif
            @endif
        @endforeach
    </div>
</div>
