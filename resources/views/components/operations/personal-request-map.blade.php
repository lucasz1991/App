@props(['preview' => null, 'location' => null, 'id' => null])
@php($locationPreview = $preview ?? ['state' => 'unknown', 'label' => 'Standort nicht verortet'])
<div {{ $attributes->class('rt-request-map') }} @if($id) id="{{ $id }}" @endif data-personal-request-map>
    <x-operations.timeline-mini-map :preview="$locationPreview" :location="$location" />
</div>
