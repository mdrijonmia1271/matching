@extends($printing ? 'layouts.report-print' : 'layouts.admin')

@section('title', 'Purchase report')
@section('heading', 'Purchase report')
@section('filters', request('supplier') ? 'Supplier: ' . $suppliers->firstWhere('id', (int) request('supplier'))?->name : 'All suppliers')

@section('content')
    @php use App\Support\Money; @endphp

    @unless($printing)

    <div class="card mb-6 p-4">
        <form method="GET" class="flex flex-wrap items-center gap-2">
            @include('admin.reports.partials.period')
            <select name="supplier" class="input w-48" aria-label="Supplier">
                <option value="">All suppliers</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected((int) request('supplier') === $supplier->id)>{{ $supplier->name }}</option>
                @endforeach
            </select>
            <button class="btn-secondary">Show</button>
        </form>
    </div>

    @endunless

    @include('admin.reports.partials.cards', ['cards' => [
        ['Purchases', number_format($summary['count']), 'text-slate-900', 'Received in this period'],
        ['Units', number_format($summary['units']), 'text-slate-900', null],
        ['Discount', Money::format($summary['discount']), 'text-emerald-600', null],
        ['Additional cost', Money::format($summary['additional']), 'text-slate-900', null],
        ['Total purchase', Money::format($summary['total']), 'text-brand-600', null],
    ]])

    <div class="card mt-4 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    @if($printing)<th class="sl px-4 py-3">SL</th>@endif
                    <th class="px-4 py-3">Received</th>
                    <th class="px-4 py-3">Purchase</th>
                    <th class="px-4 py-3">Supplier</th>
                    <th class="px-4 py-3">Supplier invoice</th>
                    <th class="px-4 py-3 text-right">Units</th>
                    <th class="px-4 py-3 text-right">Discount</th>
                    <th class="px-4 py-3 text-right">Additional</th>
                    <th class="px-4 py-3 text-right">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($rows as $purchase)
                    <tr class="hover:bg-slate-50">
                        @if($printing)<td class="sl px-4 py-3">{{ $loop->iteration }}</td>@endif
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $purchase->received_at?->format('d M Y') }}</td>
                        <td class="px-4 py-3"><a href="{{ route('admin.purchases.show', $purchase) }}" class="font-semibold text-brand-600 hover:underline">{{ $purchase->number }}</a></td>
                        <td class="px-4 py-3 text-slate-700">{{ $purchase->supplier?->name ?? 'No supplier' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $purchase->invoice_number ?: '—' }}</td>
                        <td class="px-4 py-3 text-right text-slate-700">{{ number_format((int) $purchase->units) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-slate-500">@money($purchase->discount)</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-slate-500">@money($purchase->additional_cost)</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-semibold text-slate-900">@money($purchase->total)</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ 8 + ($printing ? 1 : 0) }}" class="px-4 py-10 text-center text-slate-500">No purchases received in this period.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-slate-900 font-bold">
                    <td class="px-4 py-3 text-slate-900" colspan="{{ 4 + ($printing ? 1 : 0) }}">Total · {{ number_format($summary['count']) }} purchase(s)</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-slate-900">{{ number_format($summary['units']) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-slate-600">{{ Money::format($summary['discount']) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-slate-600">{{ Money::format($summary['additional']) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-slate-900">{{ Money::format($summary['total']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @unless($printing)<div class="mt-6">{{ $rows->links() }}</div>@endunless
@endsection
