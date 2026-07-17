@props(['title'])

<div class="bg-slate-50 border border-slate-200 rounded shadow-sm p-4 space-y-2">
    <h2 class="text-lg font-semibold text-gray-800 mb-2">{{ $title }}</h2>
    {{ $slot }}
</div>
