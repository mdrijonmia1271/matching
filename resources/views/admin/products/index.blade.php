@extends('layouts.admin')

@section('title', 'Products')
@section('heading', 'Products')

@section('content')
    @php $threshold = (int) \App\Support\Settings::get('low_stock_threshold', 5); @endphp

    <div class="card">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, SKU, barcode, brand or tag" class="input w-64" autofocus>

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

                <select name="stock" class="input w-36">
                    <option value="">Any stock</option>
                    <option value="low" @selected(request('stock') === 'low')>Low stock</option>
                    <option value="out" @selected(request('stock') === 'out')>Out of stock</option>
                </select>

                <select name="status" class="input w-32">
                    <option value="">Current</option>
                    <option value="hidden" @selected(request('status') === 'hidden')>Hidden</option>
                    <option value="archived" @selected(request('status') === 'archived')>Archived</option>
                </select>

                <button type="submit" class="btn-secondary">Filter</button>
                @if(request()->hasAny(['q', 'category', 'brand', 'stock', 'status']))
                    <a href="{{ route('admin.products.index') }}" class="text-sm text-slate-500 hover:underline">Reset</a>
                @endif
            </form>

            <div class="ml-auto flex gap-2">
                <a href="{{ route('admin.barcodes.index') }}" class="btn-secondary">Barcode labels</a>
                @can('products.create')
                    <a href="{{ route('admin.products.create') }}" class="btn-primary">Add product</a>
                @endcan
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Product</th>
                        <th class="px-4 py-3">Category</th>
                        <th class="px-4 py-3 text-right">Purchase price</th>
                        <th class="px-4 py-3 text-right">Sale price</th>
                        <th class="px-4 py-3 text-right">Discounted price</th>
                        <th class="px-4 py-3 text-center">Variants</th>
                        <th class="px-4 py-3 text-center">Stock</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($products as $product)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $product->image_url }}" alt="" class="h-10 w-10 rounded-lg object-cover">
                                    <div class="min-w-0">
                                        <p class="truncate font-medium text-slate-900">{{ $product->name }}</p>
                                        <p class="text-xs text-slate-400">
                                            <span class="font-mono">{{ $product->sku }}</span>
                                            @if($product->brand)&middot; {{ $product->brand->name }}@endif
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ $product->category?->name ?? '—' }}
                                @if($product->subcategory)<span class="block text-xs text-slate-400">&rsaquo; {{ $product->subcategory->name }}</span>@endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-slate-600">
                                {{ $product->cost_price !== null ? \App\Support\Money::format($product->cost_price) : '—' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right {{ $product->on_sale ? 'text-slate-400 line-through' : 'font-semibold text-slate-900' }}">
                                @money($product->price)
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                @if($product->on_sale)
                                    <span class="font-semibold text-emerald-600">@money($product->current_price)</span>
                                    <span class="block text-xs text-rose-500">{{ $product->discount_label }}</span>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center text-slate-600">
                                {{ $product->has_variants ? $product->variants_count : '—' }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-full px-2 py-0.5 text-xs font-bold {{ $product->stock <= 0 ? 'bg-rose-100 text-rose-700' : ($product->stock <= $threshold ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700') }}">
                                    {{ $product->stock }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center text-xs">
                                <span class="{{ $product->is_active ? 'text-emerald-600' : 'text-slate-400' }}">{{ $product->trashed() ? 'Archived' : ($product->is_active ? 'Active' : 'Hidden') }}</span>
                                @if($product->is_featured)
                                    <span class="ml-1 rounded bg-brand-100 px-1.5 py-0.5 font-semibold text-brand-700">Featured</span>
                                @endif
                                @if($product->is_new_arrival)
                                    <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 font-semibold text-emerald-700">New</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    @if($product->trashed())
                                        <a href="{{ route('admin.stock.index', ['product' => $product->id]) }}" class="text-xs text-slate-500 hover:underline">History</a>
                                        @can('products.delete')
                                            <form method="POST" action="{{ route('admin.products.restore', $product) }}">
                                                @csrf @method('PATCH')
                                                <button class="text-xs font-semibold text-emerald-600 hover:underline">Restore</button>
                                            </form>
                                            @include('admin.products.partials.delete-button')
                                        @endcan
                                    @else
                                        <a href="{{ route('shop.show', $product) }}" target="_blank" class="text-xs text-slate-500 hover:underline">View</a>
                                        @can('inventory.view')
                                            <a href="{{ route('admin.stock.index', ['product' => $product->id]) }}" class="text-xs text-slate-500 hover:underline">History</a>
                                        @endcan
                                        <a href="{{ route('admin.barcodes.index', ['product' => $product->id]) }}" class="text-xs text-slate-500 hover:underline">Barcode</a>
                                        @can('products.edit')
                                            <a href="{{ route('admin.products.edit', $product) }}" class="text-xs font-semibold text-brand-600 hover:underline">Edit</a>
                                        @endcan
                                        @can('products.delete')
                                            @include('admin.products.partials.delete-button')
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-10 text-center text-slate-500">No products match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $products->links() }}</div>
@endsection
