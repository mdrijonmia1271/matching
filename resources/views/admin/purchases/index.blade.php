@extends('layouts.admin')

@section('title', 'Purchases')
@section('heading', 'Purchases')

@section('content')
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Purchases</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($stats['purchases']) }}</p>
            <p class="text-xs text-slate-400">Matching these filters</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Value</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">@money($stats['total'])</p>
            <p class="text-xs text-slate-400">Total of the purchases listed</p>
        </div>
        <a href="{{ route('admin.purchases.index', ['status' => 'ordered']) }}" class="card p-5 transition hover:border-brand-300">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Not received yet</p>
            <p class="mt-2 text-2xl font-bold {{ $stats['open'] > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ number_format($stats['open']) }}</p>
            <p class="text-xs text-slate-400">Drafts and orders on the way</p>
        </a>
    </div>

    <div class="card mt-6">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Number, invoice or supplier" class="input w-56">

                <select name="status" class="input w-36" aria-label="Status">
                    <option value="">Any status</option>
                    @foreach($statuses as $key => $label)
                        <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="supplier" class="input w-44" aria-label="Supplier">
                    <option value="">All suppliers</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" @selected((int) request('supplier') === $supplier->id)>{{ $supplier->name }}</option>
                    @endforeach
                </select>

                <input type="date" name="from" value="{{ request('from') }}" class="input w-40" aria-label="From">
                <input type="date" name="to" value="{{ request('to') }}" class="input w-40" aria-label="To">

                <select name="sort" class="input w-40" aria-label="Sort">
                    @foreach($sorts as $key => $label)
                        <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn-secondary">Filter</button>
                @if(request()->hasAny(['q', 'status', 'supplier', 'from', 'to', 'sort']))
                    <a href="{{ route('admin.purchases.index') }}" class="text-sm text-slate-500 hover:underline">Reset</a>
                @endif
            </form>

            <div class="ml-auto flex gap-2">
                @can('reports.export')
                    <a href="{{ route('admin.purchases.export', request()->query()) }}" class="btn-secondary">Export CSV</a>
                @endcan
                @can('purchases.create')
                    <a href="{{ route('admin.purchases.create') }}" class="btn-primary">New purchase</a>
                @endcan
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Purchase</th>
                        <th class="px-4 py-3">Supplier</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($purchases as $purchase)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.purchases.show', $purchase) }}" class="font-mono text-xs font-semibold text-brand-600 hover:underline">{{ $purchase->number }}</a>
                                @if($purchase->invoice_number)
                                    <p class="text-xs text-slate-400">Invoice {{ $purchase->invoice_number }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-700">
                                {{ $purchase->supplier?->name ?? 'No supplier' }}
                                <span class="block text-xs text-slate-400">{{ $purchase->supplier?->company ?: '—' }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ $purchase->purchase_date?->format('d M Y') }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $purchase->status_color }}">{{ $purchase->status_label }}</span>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($purchase->total)</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No purchases match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $purchases->links() }}</div>
@endsection
