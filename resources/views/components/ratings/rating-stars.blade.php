@php  
    $scoreZeroToFive = $score*5;
@endphp

<div class="flex items-center">
    @for ($i = 0; $i < 5; $i++)
        @php
            $starPercentage = min(max($scoreZeroToFive - $i, 0), 1) * 100;
            $uniqueId = uniqid('star_'.$i.'_');
        @endphp
        <div class="w-5 h-5 relative">
            <svg class="w-full h-full" viewBox="0 0 20 20">
                <defs>
                    <linearGradient id="{{ $uniqueId }}">
                        <stop offset="{{ $starPercentage }}%" stop-color="#fbbf24"/>
                        <stop offset="{{ $starPercentage }}%" stop-color="#d1d5db"/>
                    </linearGradient>
                </defs>
                <path fill="url(#{{ $uniqueId }})" d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.957a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.37 2.448a1 1 0 00-.364 1.118l1.286 3.957c.3.921-.755 1.688-1.54 1.118l-3.37-2.448a1 1 0 00-1.176 0l-3.37 2.448c-.784.57-1.838-.197-1.54-1.118l1.286-3.957a1 1 0 00-.364-1.118L2.049 9.384c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.286-3.957z"/>
            </svg>
        </div>
    @endfor
    <span class="ml-2 text-sm text-gray-700">
        {{ number_format($scoreZeroToFive, 1) }}
        @if($score > 0)
            <span class="text-gray-500">/ 5</span>
        @endif
    </span>
</div>
