@extends($printing ? 'layouts.report-print' : 'layouts.admin')

@section('title', 'Sale profit')
@section('heading', 'Sale profit')
@section('filters', $channels[request('channel')] ?? 'All channels')

@section('content')
    @php use App\Support\Money; @endphp

    @unless($printing)

    <div class="card mb-6 p-4">
        <form method="GET" class="flex flex-wrap items-center gap-2">
            @include('admin.reports.partials.period')
            <select name="channel" class="input w-44" aria-label="Channel">
                <option value="">All channels</option>
                @foreach($channels as $value => $label)
                    <option value="{{ $value }}" @selected(request('channel') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="btn-secondary">Show</button>
        </form>
    </div>

    @endunless

    @if($missingCost && ! $printing)
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ $missingCost }} sold item(s) have no purchase price saved and count as zero cost. Orders marked <span class="font-semibold">no cost</span> below show more profit than they made.
        </div>
    @endif

    @include('admin.reports.partials.cards', ['cards' => [
        ['Orders', number_format($summary['count']), 'text-slate-900', null],
        ['Sales', Money::format($summary['sales']), 'text-brand-600', null],
        ['Cost', Money::format($summary['cost']), 'text-slate-900', 'Purchase price of items sold'],
        ['Profit', Money::format($summary['profit']), $summary['profit'] < 0 ? 'text-rose-600' : 'text-emerald-600',
            $summary['sales'] > 0 ? round($summary['profit'] / $summary['sales'] * 100, 1) . '% margin' : null],
    ]])

    <div class="card mt-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    @if($printing)<th class="sl px-4 py-3">SL</th>@endif
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Order</th>
                    <th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3 text-right">Sale</th>
                    <th class="px-4 py-3 text-right">Cost</th>
                    <th class="px-4 py-3 text-right">Profit</th>
                    <th class="px-4 py-3 text-right">Margin</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($rows as $order)
                    @php
                        $profit = round((float) $order->total - (float) $order->cost, 2);
                        $margin = (float) $order->total > 0 ? round($profit / (float) $order->total * 100, 1) : null;
                    @endphp
                    <tr class="hover:bg-slate-50">
                        @if($printing)<td class="sl px-4 py-3">{{ $loop->iteration }}</td>@endif
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $order->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.orders.show', $order) }}" class="font-semibold text-brand-600 hover:underline">{{ $order->order_number }}</a>
                            @if($order->missing_cost > 0)
                                <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-700">no cost</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-700">{{ $order->customer_name }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-slate-900">@money($order->total)</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-slate-600">{{ Money::format((float) $order->cost) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-semibold {{ $profit < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ Money::format($profit) }}</td>
                        <td class="px-4 py-3 text-right text-slate-500">{{ $margin !== null ? $margin . '%' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 7 + ($printing ? 1 : 0) }}" class="px-4 py-10 text-center text-slate-500">No sales in this period.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-slate-900 font-bold">
                    <td class="px-4 py-3 text-slate-900" colspan="{{ 3 + ($printing ? 1 : 0) }}">Total · {{ number_format($summary['count']) }} order(s)</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-slate-900">{{ Money::format($summary['sales']) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-slate-600">{{ Money::format($summary['cost']) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right {{ $summary['profit'] < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ Money::format($summary['profit']) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-slate-600">{{ $summary['sales'] > 0 ? round($summary['profit'] / $summary['sales'] * 100, 1) . '%' : '—' }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @unless($printing)<div class="mt-6">{{ $rows->links() }}</div>@endunless
@endsection
