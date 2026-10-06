@props(['name' => 'orders'])
@php
    // Portal-only line icons. The standard app components keep their existing icons.
    $paths = match ($name) {
        'overview' => ['M3 10.5 12 3l9 7.5', 'M5 9v12h5v-7h4v7h5V9'],
        'requests' => ['M4 4h16v16H4z', 'M4 13h5l2 3h2l2-3h5', 'M8 8h8'],
        'offers' => ['M5 3h10l4 4v14H5z', 'M14 3v5h5', 'm9 15 2 2 5-5'],
        'orders', 'train' => ['M7 3h10a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z', 'M5 10h14M12 3v7M8 15h.01M16 15h.01M8 19l-2 3M16 19l2 3'],
        'calendar', 'fa-calendar-alt' => ['M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z', 'M7 3v4M17 3v4M3 10h18M8 14h2M14 14h2M8 17h2'],
        'fa-calendar-day' => ['M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z', 'M7 3v4M17 3v4M3 10h18M9 14h6v4H9z'],
        'fa-calendar-week' => ['M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z', 'M7 3v4M17 3v4M3 10h18M6 14h12v4H6z'],
        'fa-list-ul' => ['M9 6h12M9 12h12M9 18h12M3 6h1M3 12h1M3 18h1'],
        'proofs' => ['M9 4H5v17h14V4h-4', 'M9 2h6v5H9z', 'm8 14 3 3 5-6'],
        'documents' => ['M3 6h6l2 2h10v12H3z', 'M3 6V4h7l2 2h7v2'],
        'messages' => ['M4 4h16v13H9l-5 4z', 'M8 8h8M8 12h5'],
        'reports' => ['M4 3v18h17', 'M8 17v-5M13 17V8M18 17V5'],
        'profile' => ['M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M4 21a8 8 0 0 1 16 0'],
        'menu' => ['M4 7h16M4 12h16M4 17h16'],
        'close' => ['m6 6 12 12M6 18 18 6'],
        'sun' => ['M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z', 'M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5 19 19M5 19l1.5-1.5M17.5 6.5 19 5'],
        'moon' => ['M20 14.5A8.5 8.5 0 0 1 9.5 4 8.5 8.5 0 1 0 20 14.5Z'],
        'logout' => ['M9 4H4v16h5M9 12h12m-4-4 4 4-4 4'],
        'plus' => ['M12 5v14M5 12h14'],
        'chevron-left' => ['m15 5-7 7 7 7'],
        'chevron-right' => ['m9 5 7 7-7 7'],
        'arrow-right' => ['M4 12h16m-6-6 6 6-6 6'],
        'download' => ['M12 3v12m-5-5 5 5 5-5', 'M4 16v5h16v-5'],
        'shield' => ['m12 2 8 4v6c0 5-8 10-8 10S4 17 4 12V6z', 'm8 12 3 3 5-6'],
        'check' => ['m5 12 4 4L19 6'],
        'mail' => ['M3 5h18v14H3z', 'm3 5 9 8 9-8'],
        'key' => ['M8 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z', 'm11 11 10 10M16 16l3-3M18 18l3-3'],
        'copy' => ['M8 8h13v13H8z', 'M16 8V3H3v13h5'],
        default => ['M4 12h16m-6-6 6 6-6 6'],
    };
@endphp
<svg {{ $attributes->class(['rt-customer-portal__icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @foreach($paths as $path)<path d="{{ $path }}" />@endforeach
</svg>
