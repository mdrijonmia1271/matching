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
                    <p class="text-xs font-bold uppercase tracking-[0.2em] text-brand-300">Personal styling help</p>
                    <p class="mt-3 text-sm leading-relaxed text-slate-300">Unsure of your size or the perfect match? Our team is a call or a message away &mdash; we&rsquo;ll help you choose before you order.</p>
                    {{-- The store phone from Admin → Settings, offered as a call and a
                         WhatsApp chat. wa.me needs the international form: a local
                         01XXXXXXXXX number gets Bangladesh's 88 in front. --}}
                    @php
                        $storePhone = \App\Support\Settings::get('store_phone');
                        $telNumber = preg_replace('/[^\d+]/', '', (string) $storePhone);
                        $waNumber = preg_replace('/\D/', '', (string) $storePhone);
                        if (preg_match('/^0\d{10}$/', $waNumber)) {
                            $waNumber = '88' . $waNumber;
                        }
                    @endphp
                    @if($storePhone)
                        <p class="mt-5 text-xl font-bold tracking-wide">{{ $storePhone }}</p>
                        <div class="mt-4 grid grid-cols-2 gap-2">
                            <a href="tel:{{ $telNumber }}"
                               class="inline-flex items-center justify-center gap-2 rounded-xl border border-white/15 px-3 py-2.5 text-sm font-semibold transition hover:border-white/40 hover:bg-white/10">
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.3a1 1 0 01.9.7l1.5 4.5a1 1 0 01-.5 1.2l-2.3 1.1a11 11 0 005.5 5.5l1.1-2.3a1 1 0 011.2-.5l4.5 1.5a1 1 0 01.7.9V19a2 2 0 01-2 2h-1C9.7 21 3 14.3 3 6V5z"/></svg>
                                Call
                            </a>
                            <a href="https://wa.me/{{ $waNumber }}" target="_blank" rel="noopener noreferrer"
                               class="inline-flex items-center justify-center gap-2 rounded-xl bg-[#25D366] px-3 py-2.5 text-sm font-semibold text-white transition hover:bg-[#1ebe5b]">
                                <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>
                                WhatsApp
                            </a>
                        </div>
                        <p class="mt-3 text-xs text-slate-400">Available anytime &middot; quick, friendly replies</p>
                    @endif
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
