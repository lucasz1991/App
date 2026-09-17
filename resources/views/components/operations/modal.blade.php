@props(['title', 'maxWidth' => '3xl', 'id' => null])
@php($scopedId = $id ?? 'ops-modal-'.md5((isset($__livewire) ? $__livewire->getId() : $title).':'.$attributes->wire('model')))
<x-dialog-modal :id="$scopedId" :max-width="$maxWidth" {{ $attributes }}>
    <x-slot:title>{{ $title }}</x-slot:title>
    <x-slot:content><div class="rt-ops ops-stack"><x-operations.feedback />{{ $slot }}</div></x-slot:content>
    @isset($footer)
        <x-slot:footer>{{ $footer }}</x-slot:footer>
    @endisset
</x-dialog-modal>
