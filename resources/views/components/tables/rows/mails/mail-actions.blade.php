<x-tables.row-actions :label="__('app.actions').' #'.$item->id">
    <x-ui.dropdown.dropdown-link wire:click="toggleMailDetails({{ $item->id }})"><i class="far fa-eye" aria-hidden="true"></i>{{ __('app.show') }}</x-ui.dropdown.dropdown-link>
    <x-ui.dropdown.dropdown-link wire:click="resendMail({{ $item->id }})"><i class="far fa-redo" aria-hidden="true"></i>{{ __('app.resend') }}</x-ui.dropdown.dropdown-link>
    @if (Auth::user()->isAdmin())
        <x-ui.dropdown.dropdown-link wire:click="sendMessageToSuperAdmin({{ $item->id }})"><i class="far fa-paper-plane" aria-hidden="true"></i>SuperAdmin Test</x-ui.dropdown.dropdown-link>
    @endif
</x-tables.row-actions>
