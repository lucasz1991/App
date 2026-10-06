@props(['title'=>null,'eyebrow'=>null,'description'=>null,'count'=>null])
<section {{ $attributes->class('ops-stack min-w-0') }}>
    @isset($actions)<div class="ops-toolbar justify-end">{{ $actions }}</div>@endisset
    {{ $slot }}
</section>
