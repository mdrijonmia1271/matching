@extends('layouts.admin')

@section('title', 'Stock')
@section('heading', 'Stock management')

@section('content')
    @php
        $cards = [
            ['Units in stock', number_format($stats['units']), 'text-slate-900', null],
            ['Stock value (cost)', \App\Support\Money::format($stats['value']), 'text-slate-900', $stats['missing_cost'] ? $stats['missing_cost'] . ' variant(s) have no purchase price' : null],
            ['Low stock', $stats['low'], 'text-amber-600', null],
            ['Out of stock', $stats['out'], 'text-rose-600', null],
            ['Today in', '+' . number_format($stats['today_in']), 'text-emerald-600', null],
            ['Today out', '−' . number_format($stats['today_out']), 'text-rose-600', null],
        ];
    @endphp

    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
        @foreach($cards as [$label, $value, $color, $hint])
            <div class="card p-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-bold {{ $color }}">{{ $value }}</p>
                @if($hint)
                    <p class="text-xs text-amber-600">{{ $hint }}</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="card mt-6">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                @if($variant || $product)
                    @if($variant)
                        <input type="hidden" name="variant" value="{{ $variant->id }}">
                    @else
                        <input type="hidden" name="product" value="{{ $product->id }}">
                    @endif
                    <span class="inline-flex items-center gap-2 rounded-full bg-brand-100 px-3 py-1 text-xs font-semibold text-brand-700">
                        {{ $variant ? $variant->full_name . ' · ' . $variant->stock . ' in stock' : $product->name . ' · ' . $product->stock . ' in stock' }}
                        <a href="{{ route('admin.stock.index', request()->except('product', 'variant', 'page')) }}" class="opacity-60 hover:opacity-100" aria-label="Clear product">&times;</a>
                    </span>
                @else
                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Product, SKU or barcode" class="input w-52">
                @endif

                <select name="type" class="input w-32">
                    <option value="">In &amp; out</option>
                    <option value="in" @selected(request('type') === 'in')>Stock in</option>
                    <option value="out" @selected(request('type') === 'out')>Stock out</option>
                </select>

                <select name="reason" class="input w-48">
                    <option value="">Any reason</option>
                    @foreach($reasons as $key => $label)
                        <option value="{{ $key }}" @selected(request('reason') === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <input type="date" name="from" value="{{ request('from') }}" class="input w-40" aria-label="From date">
                <input type="date" name="to" value="{{ request('to') }}" class="input w-40" aria-label="To date">

                <button type="submit" class="btn-secondary">Filter</button>
                @if(request()->hasAny(['q', 'product', 'variant', 'type', 'reason', 'from', 'to']))
                    <a href="{{ route('admin.stock.index') }}" class="text-sm text-slate-500 hover:underline">Reset</a>
                @endif
                @can('reports.export')
                    <a href="{{ route('admin.stock.export', request()->except('page')) }}" class="text-sm font-medium text-brand-600 hover:underline">Export CSV</a>
                @endcan
            </form>

            @can('inventory.adjust')
                <div class="ml-auto flex gap-2">
                    <a href="{{ route('admin.stock.create', ['type' => 'in', 'variant' => $variant?->id, 'product' => $product?->id]) }}" class="btn-primary">+ Stock in</a>
                    <a href="{{ route('admin.stock.create', ['type' => 'out', 'variant' => $variant?->id, 'product' => $product?->id]) }}" class="btn-secondary">− Stock out</a>
                    @if($variant || $product)
                        <a href="{{ $variant ? route('admin.barcodes.index', ['variants' => [$variant->id]]) : route('admin.barcodes.index', ['product' => $product->id]) }}" class="btn-secondary">Print labels</a>
                    @endif
                </div>
            @endcan
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Product</th>
                        <th class="px-4 py-3 text-center">Qty</th>
                        <th class="px-4 py-3 text-center">Stock</th>
                        <th class="px-4 py-3">Reason</th>
                        <th class="px-4 py-3">By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($movements as $movement)
                        <tr class="hover:bg-slate-50">
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                <a href="{{ route('admin.stock.show', $movement) }}" class="hover:text-brand-600 hover:underline">{{ $movement->created_at->format('d M Y') }}</a>
                                <span class="block text-xs text-slate-400">{{ $movement->created_at->format('h:i A') }}</span>
                            </td>
                            <td class="px-4 py-3">
                                @if($movement->product)
                                    <a href="{{ route('admin.stock.index', ['variant' => $movement->variant_id]) }}" class="font-medium text-slate-900 hover:underline">{{ $movement->product->name }}</a>
                                    <span class="block text-xs text-slate-400">
                                        @if($movement->variant && $movement->product->has_variants){{ $movement->variant->label }} &middot; @endif
                                        <span class="font-mono">{{ $movement->variant?->sku ?? $movement->product->sku }}</span>
                                    </span>
                                @else
                                    <span class="text-slate-400">Deleted product</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-full px-2 py-0.5 text-xs font-bold {{ $movement->type === 'in' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                                    {{ $movement->type === 'in' ? '+' : '−' }}{{ $movement->quantity }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-center text-slate-600">
                                {{ $movement->stock_before }} &rarr; <span class="font-semibold text-slate-900">{{ $movement->stock_after }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-slate-700">{{ $movement->reason_label }}</span>
                                @if($movement->order)
                                    <a href="{{ route('admin.orders.show', $movement->order) }}" class="block text-xs text-brand-600 hover:underline">{{ $movement->order->order_number }}</a>
                                @endif
                                @if($movement->note)
                                    <span class="block max-w-xs truncate text-xs text-slate-400" title="{{ $movement->note }}">{{ $movement->note }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $movement->user?->name ?? ($movement->order ? 'Customer' : 'System') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No stock movements match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $movements->links() }}</div>
@endsection
