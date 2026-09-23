{{-- Date range inputs plus quick ranges; used inside each report's filter form. --}}
@php
    $today = today();
    $quick = [
        'Today' => [$today, $today],
        'This month' => [$today->copy()->startOfMonth(), $today],
        'Last month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
        'This year' => [$today->copy()->startOfYear(), $today],
    ];
@endphp

<label class="sr-only" for="from">From</label>
<input id="from" type="date" name="from" value="{{ $from->toDateString() }}" class="input w-40">
<span class="text-sm text-slate-400">to</span>
<label class="sr-only" for="to">To</label>
<input id="to" type="date" name="to" value="{{ $to->toDateString() }}" class="input w-40">

<div class="flex w-full flex-wrap gap-1.5 sm:order-last">
    @foreach($quick as $label => [$start, $end])
        @php $on = $from->isSameDay($start) && $to->isSameDay($end); @endphp
        <a href="{{ request()->fullUrlWithQuery(['from' => $start->toDateString(), 'to' => $end->toDateString(), 'page' => null]) }}"
           class="rounded-full px-3 py-1 text-xs font-medium {{ $on ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' }}">{{ $label }}</a>
    @endforeach
</div>
