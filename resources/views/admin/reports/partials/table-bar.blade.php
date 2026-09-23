{{-- Sits right above a report's table: the period, and the button that opens the A4 print copy. --}}
<div class="mt-4 flex items-center justify-between gap-3">
    <p class="text-xs text-slate-500">{{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}</p>
    <a href="{{ request()->fullUrlWithQuery(['print' => 1, 'page' => null]) }}" target="_blank" class="btn-secondary">Print / PDF</a>
</div>
