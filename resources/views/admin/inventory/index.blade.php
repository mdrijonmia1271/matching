@extends('layouts.admin')

@section('title', 'Stock overview')
@section('heading', 'Stock overview')

@section('content')
    @php
        $badge = [
            'in_stock' => 'bg-emerald-100 text-emerald-700',
            'low' => 'bg-amber-100 text-amber-700',
            'out' => 'bg-rose-100 text-rose-700',
            'inactive' => 'bg-slate-100 text-slate-500',
        ];
        $status = request('status');
    @endphp

    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
        <div class="card p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Units in stock</p>
            <p class="mt-1 text-xl font-bold text-slate-900">{{ number_format($summary['units']) }}</p>
        </div>
        <div class="card p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Stock value (cost)</p>
            <p class="mt-1 text-xl font-bold text-slate-900">@money($summary['cost_value'])</p>
            @if($summary['missing_cost'])
                <p class="text-xs text-amber-600">{{ $summary['missing_cost'] }} item(s) have no purchase price</p>
            @endif
        </div>
        <div class="card p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Retail value</p>
            <p class="mt-1 text-xl font-bold text-slate-900">@money($summary['retail_value'])</p>
            <p class="text-xs text-slate-400">At regular selling price</p>
        </div>
        <a href="{{ route('admin.inventory.index', ['status' => 'low']) }}" class="card p-4 hover:border-amber-300">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Low stock</p>
            <p class="mt-1 text-xl font-bold text-amber-600">{{ $summary['low'] }}</p>
        </a>
        <a href="{{ route('admin.inventory.index', ['status' => 'out']) }}" class="card p-4 hover:border-rose-300">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Out of stock</p>
            <p class="mt-1 text-xl font-bold text-rose-600">{{ $summary['out'] }}</p>
        </a>
    </div>

    <div class="card mt-6">
        <div class="flex flex-wrap gap-1 border-b border-slate-200 px-4 pt-3">
            @foreach(['' => 'All'] + $statuses as $key => $label)
                <a href="{{ route('admin.inventory.index', array_filter(['status' => $key] + request()->except('status', 'page'))) }}"
                   class="rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium {{ (string) $status === (string) $key ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                @if($status)
                    <input type="hidden" name="status" value="{{ $status }}">
                @endif
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Product, SKU or barcode" class="input w-56" autofocus>

                <select name="category" class="input w-44">
                    <option value="">All categories</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected(request('category') == $category->id)>{{ $category->name }}</option>
                        @foreach($category->children as $child)
                            <option value="{{ $child->id }}" @selected(request('category') == $child->id)>&nbsp;&nbsp;&rsaquo; {{ $child->name }}</option>
                        @endforeach
                    @endforeach
                </select>

                <select name="brand" class="input w-36">
                    <option value="">All brands</option>
                    @foreach($brands as $brand)
                        <option value="{{ $brand->id }}" @selected(request('brand') == $brand->id)>{{ $brand->name }}</option>
                    @endforeach
                </select>

                <select name="sort" class="input w-48">
                    @foreach($sorts as $key => $label)
                        <option value="{{ $key }}" @selected(request('sort', 'name') === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn-secondary">Filter</button>
                @if(request()->hasAny(['q', 'category', 'brand', 'sort', 'status']))
                    <a href="{{ route('admin.inventory.index') }}" class="text-sm text-slate-500 hover:underline">Reset</a>
                @endif
            </form>

            <div class="ml-auto flex gap-2">
                @can('reports.export')
                    <a href="{{ route('admin.inventory.export', request()->except('page')) }}" class="btn-secondary">Export CSV</a>
                @endcan
                @can('inventory.adjust')
                    <a href="{{ route('admin.inventory.count', request()->only('q', 'category', 'brand', 'status')) }}" class="btn-primary">Count this stock</a>
                @endcan
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Product</th>
                        <th class="px-4 py-3">SKU / barcode</th>
                        <th class="px-4 py-3 text-center">Stock</th>
                        <th class="px-4 py-3 text-center">Alert at</th>
                        <th class="px-4 py-3 text-right">Purchase price</th>
                        <th class="px-4 py-3 text-right">Stock value</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($variants as $variant)
                        @php
                            $product = $variant->product;
                            $state = \App\Http\Controllers\Admin\InventoryController::statusOf($variant);
                            $cost = $variant->effective_cost;
                        @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <p class="font-medium text-slate-900">{{ $product->name }}</p>
                                <p class="text-xs text-slate-500">
                                    @if($product->has_variants){{ $variant->label }} &middot; @endif
                                    {{ $product->category?->name }}{{ $product->brand ? ' · ' . $product->brand->name : '' }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <p class="font-mono text-xs text-slate-700">{{ $variant->sku }}</p>
                                <p class="font-mono text-xs text-slate-400">{{ $variant->barcode ?? 'No barcode' }}</p>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-bold {{ $badge[$state] }}" title="{{ $statuses[$state] }}">{{ $variant->stock }}</span>
                            </td>
                            <td class="px-4 py-3 text-center text-slate-500">{{ $variant->low_stock_level }}</td>
                            <td class="px-4 py-3 text-right text-slate-600">{{ $cost !== null ? \App\Support\Money::format($cost) : '—' }}</td>
                            <td class="px-4 py-3 text-right font-medium text-slate-900">{{ $cost !== null ? \App\Support\Money::format($cost * max(0, $variant->stock)) : '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3 text-xs">
                                    <a href="{{ route('admin.stock.index', ['variant' => $variant->id]) }}" class="text-slate-500 hover:underline">History</a>
                                    @can('inventory.adjust')
                                        <a href="{{ route('admin.stock.create', ['variant' => $variant->id]) }}" class="font-semibold text-brand-600 hover:underline">Adjust</a>
                                    @endcan
                                    <a href="{{ route('admin.barcodes.index', ['variants' => [$variant->id]]) }}" class="text-slate-500 hover:underline">Label</a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-slate-500">No stock matches these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $variants->links() }}</div>
@endsection
