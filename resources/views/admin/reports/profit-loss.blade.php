@extends($printing ? 'layouts.report-print' : 'layouts.admin')

@section('title', 'Profit / Loss')
@section('heading', 'Profit / Loss')

@section('content')
    @php
        use App\Support\Money;

        $net = $lines['net'];
        $statement = [
            ['Sales', $lines['sales'], null, number_format($orders) . ' order(s) in a sale status'],
            ['Cost of goods sold', -$lines['cogs'], null, 'Purchase price of the items sold'],
            ['Gross profit', $lines['gross'], 'subtotal', null],
            ['Other income', $lines['other_income'], null, 'Money in entries from Accounts'],
            ['Expenses', -$lines['expenses'], null, 'Expense and money out entries from Accounts'],
        ];
    @endphp

    @unless($printing)

    <div class="card mb-6 p-4">
        <form method="GET" class="flex flex-wrap items-center gap-2">
            @include('admin.reports.partials.period')
            <button class="btn-secondary">Show</button>
        </form>
    </div>

    @endunless

    @if($missingCost && ! $printing)
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ $missingCost }} sold item(s) in this period have no purchase price saved, so they count as zero cost and profit reads higher than it is.
        </div>
    @endif

    <div class="mx-auto max-w-2xl">
        @unless($printing)@include('admin.reports.partials.table-bar')@endunless
    </div>

    <div class="card mx-auto mt-4 max-w-2xl">
        <div class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-base font-bold text-slate-900">Statement</h2>
            <p class="text-xs text-slate-500">{{ $from->format('d M Y') }} – {{ $to->format('d M Y') }}</p>
        </div>

        <table class="w-full text-sm">
            <tbody class="divide-y divide-slate-100">
                @foreach($statement as [$label, $amount, $style, $hint])
                    <tr class="{{ $style === 'subtotal' ? 'bg-slate-50 font-semibold' : '' }}">
                        <td class="px-5 py-3">
                            <span class="text-slate-800">{{ $label }}</span>
                            @if($hint)<span class="block text-xs text-slate-400">{{ $hint }}</span>@endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-3 text-right {{ $amount < 0 ? 'text-rose-600' : 'text-slate-900' }}">
                            {{ $amount < 0 ? '− ' . Money::format(abs($amount)) : Money::format($amount) }}
                        </td>
                    </tr>
                @endforeach
                <tr class="border-t-2 border-slate-900">
                    <td class="px-5 py-4 text-base font-bold text-slate-900">{{ $net < 0 ? 'Net loss' : 'Net profit' }}</td>
                    <td class="whitespace-nowrap px-5 py-4 text-right text-xl font-bold {{ $net < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ Money::format(abs($net)) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
@endsection
