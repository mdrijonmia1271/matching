@props(['rating' => 0, 'count' => null, 'size' => 'h-4 w-4'])

@php $rating = (float) $rating; @endphp

<div class="flex items-center gap-1" title="{{ number_format($rating, 1) }} out of 5">
    <div class="flex">
        @for($i = 1; $i <= 5; $i++)
            <svg class="{{ $size }} {{ $i <= round($rating) ? 'text-amber-400' : 'text-slate-300' }}" fill="currentColor" viewBox="0 0 20 20">
                <path d="M10 1.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.5 7.7l5.9-.9L10 1.5z"/>
            </svg>
        @endfor
    </div>
    @if($count !== null)
        <span class="text-xs text-slate-500">({{ $count }})</span>
    @endif
</div>
