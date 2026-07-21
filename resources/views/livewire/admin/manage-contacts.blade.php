<div class="p-6">
    @section('title')
        {{ __('Kontake Admin') }}
    @endsection
    <div class="flex justify-between space-x-3">
        <x-button wire:click="create" class="btn-xs text-xs">Neuer Kontakt</x-button>
        <livewire:admin.contacts.search-contacts-form />
    </div>


    @if ($showForm)
        <div class="rt-ui-surface-muted mt-4 space-y-2 rounded-lg border border-rt-border bg-rt-surface-muted p-4 dark:border-rt-dark-border dark:bg-rt-dark-surface-muted">
            <x-ui.forms.input type="text" wire:model="name" placeholder="Name" />
            <x-ui.forms.input type="text" wire:model="company" placeholder="Firma" />
            <x-ui.forms.input type="email" wire:model="email" placeholder="E-Mail" />
            <x-ui.forms.input type="text" wire:model="phone" placeholder="Telefon" />
            <x-ui.forms.input type="text" wire:model="address" placeholder="Adresse" />
            <x-ui.forms.input type="text" wire:model="city" placeholder="Stadt" />
            <x-ui.forms.input type="text" wire:model="postal_code" placeholder="PLZ" />
            <x-ui.forms.input type="text" wire:model="country" placeholder="Land" />
            <x-ui.forms.input type="text" wire:model="category" placeholder="Kategorie" />
            
            <div class="mt-2 flex flex-wrap gap-2">
                <x-ui.buttons.button-basic wire:click="{{ $contactId ? 'update' : 'store' }}" mode="success">Speichern</x-ui.buttons.button-basic>
                <x-ui.buttons.button-basic wire:click="$set('showForm', false)" mode="secondary">Abbrechen</x-ui.buttons.button-basic>
            </div>
        </div>
    @endif

    <div class="rt-ui-surface mt-4 mb-3 overflow-x-auto rounded-xl border border-rt-border bg-rt-surface dark:border-rt-dark-border dark:bg-rt-dark-surface">
    <table class="min-w-[56rem] w-full text-left text-rt-text dark:text-rt-dark-text">
        <thead>
            <tr class="rt-ui-surface-muted bg-rt-surface-muted dark:bg-rt-dark-surface-muted">
                <th class="border border-rt-border p-2 dark:border-rt-dark-border">Branche</th>
                <th class="border border-rt-border p-2 dark:border-rt-dark-border">Name</th>
                <th class="border border-rt-border p-2 dark:border-rt-dark-border">Anschrift</th>
                <th class="border border-rt-border p-2 dark:border-rt-dark-border">E-Mail</th>
                <th class="border border-rt-border p-2 dark:border-rt-dark-border">Telefon</th>
                <th class="border border-rt-border p-2 dark:border-rt-dark-border">Website</th>
                <th class="border border-rt-border p-2 dark:border-rt-dark-border"></th>
            </tr>
        </thead>
        <tbody>
            @foreach($contacts as $contact)
                <tr>
                    <td class="border border-rt-border p-2 dark:border-rt-dark-border">{{ $contact->category }}</td>
                    <td class="border border-rt-border p-2 font-semibold dark:border-rt-dark-border">{{ $contact->name }}</td>
                    <td class="border border-rt-border p-2 dark:border-rt-dark-border">{{ $contact->address }}</td>
                    <td class="border border-rt-border p-2 font-semibold dark:border-rt-dark-border">{{ $contact->email }}</td>
                    <td class="border border-rt-border p-2 dark:border-rt-dark-border">{{ $contact->phone }}</td>
                    <td class="border border-rt-border p-2 dark:border-rt-dark-border">{{ $contact->website }}</td>
                    <td class="border border-rt-border p-2 dark:border-rt-dark-border">
                        @if(is_null($contact->additional_data) || empty($contact->additional_data))
                            <x-ui.badge color="slate">unkontaktiert</x-ui.badge>
                        @else
                            <x-ui.badge color="green">kontaktiert</x-ui.badge>
                        @endif
                    </td>                
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>

    {{ $contacts->links() }}
</div>
