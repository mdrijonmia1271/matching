@extends($printing ? 'layouts.report-print' : 'layouts.admin')

@section('title', 'Sales report')
@section('heading', 'Sales report')
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

    @include('admin.reports.partials.cards', ['cards' => [
        ['Orders', number_format($summary['count']), 'text-slate-900', 'Pending, cancelled and returned left out'],
        ['Units sold', number_format($summary['units']), 'text-slate-900', null],
        ['Discount given', Money::format($summary['discount']), 'text-slate-900', null],
        ['Total sales', Money::format($summary['total']), 'text-brand-600', null],
        ['Paid', Money::format($summary['paid']), 'text-emerald-600', null],
        ['Due', Money::format($summary['due']), $summary['due'] > 0 ? 'text-amber-600' : 'text-slate-900', null],
    ]])

    <div class="card mt-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    @if($printing)<th class="sl px-4 py-3">SL</th>@endif
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">Order</th>
                    <th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3">Channel</th>
                    <th class="px-4 py-3 text-right">Units</th>
                    <th class="px-4 py-3 text-right">Total</th>
                    <th class="px-4 py-3 text-right">Paid</th>
                    <th class="px-4 py-3 text-right">Due</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($rows as $order)
                    <tr class="hover:bg-slate-50">
                        @if($printing)<td class="sl px-4 py-3">{{ $loop->iteration }}</td>@endif
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $order->created_at->format('d M Y') }}</td>
                        <td class="px-4 py-3"><a href="{{ route('admin.orders.show', $order) }}" class="font-semibold text-brand-600 hover:underline">{{ $order->order_number }}</a></td>
                        <td class="px-4 py-3 text-slate-700">{{ $order->customer_name }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $channels[$order->channel] ?? ucfirst((string) $order->channel) }}</td>
                        <td class="px-4 py-3 text-right text-slate-700">{{ number_format((int) $order->units) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-900">@money($order->total)</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-emerald-700">@money($order->paid_amount)</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right {{ $order->due_amount > 0 ? 'font-semibold text-amber-600' : 'text-slate-400' }}">{{ Money::format($order->due_amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 8 + ($printing ? 1 : 0) }}" class="px-4 py-10 text-center text-slate-500">No sales in this period.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-slate-900 font-bold">
                    <td class="px-4 py-3 text-slate-900" colspan="{{ 4 + ($printing ? 1 : 0) }}">Total · {{ number_format($summary['count']) }} order(s)</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-slate-900">{{ number_format($summary['units']) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-slate-900">{{ Money::format($summary['total']) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-emerald-700">{{ Money::format($summary['paid']) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-amber-600">{{ Money::format($summary['due']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @unless($printing)<div class="mt-6">{{ $rows->links() }}</div>@endunless
@endsection
