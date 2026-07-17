<div class="p-6">
    @section('title')
        {{ __('Kontake Admin') }}
    @endsection
    <div class="flex justify-between space-x-3">
        <x-button wire:click="create" class="btn-xs text-xs">Neuer Kontakt</x-button>
        <livewire:admin.contacts.search-contacts-form />
    </div>


    @if ($showForm)
        <div class="mt-4 p-4 bg-gray-100 rounded">
            <input type="text" wire:model="name" placeholder="Name" class="w-full p-2 border rounded">
            <input type="text" wire:model="company" placeholder="Firma" class="w-full p-2 border rounded mt-2">
            <input type="email" wire:model="email" placeholder="E-Mail" class="w-full p-2 border rounded mt-2">
            <input type="text" wire:model="phone" placeholder="Telefon" class="w-full p-2 border rounded mt-2">
            <input type="text" wire:model="address" placeholder="Adresse" class="w-full p-2 border rounded mt-2">
            <input type="text" wire:model="city" placeholder="Stadt" class="w-full p-2 border rounded mt-2">
            <input type="text" wire:model="postal_code" placeholder="PLZ" class="w-full p-2 border rounded mt-2">
            <input type="text" wire:model="country" placeholder="Land" class="w-full p-2 border rounded mt-2">
            <input type="text" wire:model="category" placeholder="Kategorie" class="w-full p-2 border rounded mt-2">
            
            <div class="mt-2">
                <button wire:click="{{ $contactId ? 'update' : 'store' }}" class="bg-green-500 text-white px-4 py-2 rounded">Speichern</button>
                <button wire:click="$set('showForm', false)" class="bg-gray-500 text-white px-4 py-2 rounded">Abbrechen</button>
            </div>
        </div>
    @endif

    <table class="w-full mt-4 border text-left mb-3">
        <thead>
            <tr class="bg-gray-200">
                <th class="p-2 border">Branche</th>
                <th class="p-2 border">Name</th>
                <th class="p-2 border">Anschrift</th>
                <th class="p-2 border">E-Mail</th>
                <th class="p-2 border">Telefon</th>
                <th class="p-2 border">Website</th>
                <th class="p-2 border"></th>
            </tr>
        </thead>
        <tbody>
            @foreach($contacts as $contact)
                <tr>
                    <td class="p-2 border">{{ $contact->category }}</td>
                    <td class="p-2 border font-semibold">{{ $contact->name }}</td>
                    <td class="p-2 border">{{ $contact->address }}</td>
                    <td class="p-2 border font-semibold">{{ $contact->email }}</td>
                    <td class="p-2 border">{{ $contact->phone }}</td>
                    <td class="p-2 border">{{ $contact->website }}</td>
                    <td class="p-2 border">
                        @if(is_null($contact->additional_data) || empty($contact->additional_data))
                            <span class="px-2 py-1 text-gray-700 bg-gray-200 rounded text-xs">unkontaktiert</span>
                        @else
                            <span class="px-2 py-1 text-green-700 bg-green-200 rounded text-xs">kontaktiert</span>
                        @endif
                    </td>                
                </tr>
            @endforeach
        </tbody>
    </table>

    {{ $contacts->links() }}
</div>
