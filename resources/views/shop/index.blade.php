@extends('layouts.app')

@section('title', 'Shop')

@section('content')
    @php
        $activeCategory = request('category')
            ? $categories->firstWhere('slug', request('category'))
            : null;

        $heading = match (true) {
            request()->filled('q') => 'Results for "' . request('q') . '"',
            (bool) $activeCategory => $activeCategory->name,
            request()->boolean('on_sale') => 'Offers',
            request('sort') === 'popular' => 'Best Sellers',
            request('sort') === 'rating' => 'Top Rated',
            default => 'All Clothing',
        };
    @endphp

    {{-- Page header strip --}}
    <div class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-page px-4 py-8 sm:px-6">
            <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <a href="{{ route('home') }}" class="hover:text-brand-600">Home</a>
                <span class="text-slate-300">/</span>
                <span class="text-slate-700">{{ $heading }}</span>
            </nav>

            <div class="mt-3 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-slate-900">{{ $heading }}</h1>
                    <p class="mt-1.5 text-sm text-slate-500">{{ $products->total() }} style(s) available</p>
                </div>

                <form method="GET" class="flex items-center gap-2">
                    @foreach(request()->except(['sort', 'page']) as $key => $value)
                        <input type="hidden" name="{{ $key }}" value="{{ is_array($value) ? implode(',', $value) : $value }}">
                    @endforeach
                    <label for="sort" class="text-sm text-slate-600">Sort by</label>
                    <select id="sort" name="sort" class="input w-auto" onchange="this.form.submit()">
                        @foreach([
                            '' => 'Newest',
                            'price_asc' => 'Price: low to high',
                            'price_desc' => 'Price: high to low',
                            'rating' => 'Top rated',
                            'popular' => 'Most viewed',
                            'name' => 'Name A-Z',
                        ] as $value => $label)
                            <option value="{{ $value }}" @selected(request('sort') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>
            </div>
        </div>
    </div>

    <div class="mx-auto max-w-page px-4 py-8 sm:px-6">
        <div class="grid gap-8 lg:grid-cols-[264px_1fr]">
            <aside x-data="{ open: false }">
                <button @click="open = !open" class="btn-secondary mb-4 w-full lg:hidden">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M7 12h10M10 18h4"/></svg>
                    Filters
                </button>

                <form method="GET" class="space-y-5" x-bind:class="open ? 'block' : 'hidden lg:block'">
                    @if(request('q'))
                        <input type="hidden" name="q" value="{{ request('q') }}">
                    @endif
                    @if(request('sort'))
                        <input type="hidden" name="sort" value="{{ request('sort') }}">
                    @endif

                    <div class="card p-5">
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-900">Categories</h2>
                        <span class="mt-2 block h-0.5 w-10 rounded-full bg-brand-600"></span>

                        <div class="mt-4 space-y-2.5">
                            <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-600 hover:text-brand-600">
                                <input type="radio" name="category" value="" @checked(! request('category')) class="text-brand-600 focus:ring-brand-500">
                                All categories
                            </label>
                            @foreach($categories as $category)
                                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-600 hover:text-brand-600">
                                    <input type="radio" name="category" value="{{ $category->slug }}" @checked(request('category') === $category->slug) class="text-brand-600 focus:ring-brand-500">
                                    <span class="flex-1">{{ $category->name }}</span>
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">{{ $category->products_count }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="card p-5">
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-900">Price range</h2>
                        <span class="mt-2 block h-0.5 w-10 rounded-full bg-brand-600"></span>

                        <div class="mt-4 flex items-center gap-2">
                            <input type="number" name="min_price" min="0" step="1" value="{{ request('min_price') }}" placeholder="Min" class="input">
                            <span class="text-slate-400">&ndash;</span>
                            <input type="number" name="max_price" min="0" step="1" value="{{ request('max_price') }}" placeholder="Max" class="input">
                        </div>
                    </div>

                    <div class="card p-5">
                        <h2 class="text-sm font-bold uppercase tracking-wide text-slate-900">Availability</h2>
                        <span class="mt-2 block h-0.5 w-10 rounded-full bg-brand-600"></span>

                        <div class="mt-4 space-y-2.5">
                            <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-600 hover:text-brand-600">
                                <input type="checkbox" name="in_stock" value="1" @checked(request()->boolean('in_stock')) class="rounded text-brand-600 focus:ring-brand-500">
                                In stock only
                            </label>
                            <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-600 hover:text-brand-600">
                                <input type="checkbox" name="on_sale" value="1" @checked(request()->boolean('on_sale')) class="rounded text-brand-600 focus:ring-brand-500">
                                On sale
                            </label>
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary flex-1">Apply filters</button>
                        <a href="{{ route('shop.index') }}" class="btn-secondary">Reset</a>
                    </div>
                </form>

                <div class="mt-5 hidden rounded-2xl bg-ink-900 p-6 text-white lg:block">
                    <p class="text-xs font-bold uppercase tracking-wide text-brand-300">Need help?</p>
                    <p class="mt-2 text-sm text-slate-300">Not sure about the size? Call us before you order.</p>
                    <p class="mt-4 text-lg font-bold">01812-345678</p>
                    <p class="text-xs text-slate-400">Sat&ndash;Thu, 10am&ndash;8pm</p>
                </div>
            </aside>

            <div>
                @if($products->isEmpty())
                    <div class="card grid place-items-center gap-3 p-16 text-center">
                        <span class="grid h-14 w-14 place-items-center rounded-full bg-brand-50 text-brand-600">
                            <svg class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                        </span>
                        <p class="text-lg font-semibold text-slate-900">Nothing matched your filters</p>
                        <p class="text-sm text-slate-500">Try widening the price range or clearing the search.</p>
                        <a href="{{ route('shop.index') }}" class="btn-primary mt-2">Clear filters</a>
                    </div>
                @else
                    <div class="df-grid df-grid--shop">
                        @foreach($products as $product)
                            <x-product-card :product="$product" />
                        @endforeach
                    </div>

                    <div class="mt-8">{{ $products->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endsection
