<x-dropdown align="right" width="48">
    <x-slot name="trigger">
        <x-ui.dropdown.action-trigger />
    </x-slot>

    <x-slot name="content">
        {{-- Details: normale Navigation --}}
        <x-dropdown-link href="{{ route('admin.courses.show', $item) }}">
            <i class="far fa-eye mr-2"></i>
            Details
        </x-dropdown-link>
        <x-dropdown-link href="#" 
        wire:click.prevent="$dispatch('openCourseExportModal',[ [ {{ $item->id }} ]])" class="hover:bg-green-100" :can="'courses.export'">
            <i class="far fa-download mr-2"></i>
            Exportieren
        </x-dropdown-link>
    </x-slot>
</x-dropdown>
