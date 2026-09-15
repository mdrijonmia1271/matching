@extends('layouts.app')

@section('title', 'Home')

@section('content')
    <div class="mx-auto max-w-page space-y-6 px-4 py-6 sm:px-6">

        {{-- Hero slider --}}
        @if($slides->isNotEmpty())
            <section x-data="{
                        active: 0,
                        count: {{ $slides->count() }},
                        timer: null,
                        start() { this.timer = setInterval(() => this.next(), 6000) },
                        stop() { clearInterval(this.timer) },
                        next() { this.active = (this.active + 1) % this.count },
                        go(i) { this.active = i; this.stop(); this.start() },
                     }"
                     x-init="start()" @mouseenter="stop()" @mouseleave="start()"
                     class="relative grid overflow-hidden rounded-2xl bg-gradient-to-br from-brand-50 via-white to-brand-100">

                @foreach($slides as $index => $slide)
                    @php $product = $slide['product']; @endphp

                    {{--
                        Every slide sits in the same grid cell. The outgoing slide is hidden
                        instantly rather than fading, so two headlines never overlap.
                    --}}
                    <div x-show="active === {{ $index }}"
                         x-transition:enter="transition ease-out duration-500"
                         x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                         x-transition:leave="duration-0" x-transition:leave-end="opacity-0"
                         @if($index > 0) x-cloak @endif
                         class="col-start-1 row-start-1 grid lg:grid-cols-[1fr_44%]">

                        {{-- Copy column carries the padding; the image cell stays flush to the edges. --}}
                        <div class="flex flex-col justify-center px-7 py-9 sm:px-11 sm:py-11">
                            <p class="inline-flex w-fit rounded-full bg-brand-100 px-3.5 py-1.5 text-xs font-bold uppercase tracking-wide text-brand-700">
                                {{ $slide['eyebrow'] }}
                            </p>

                            <h1 class="mt-4 text-4xl font-bold leading-[1.08] tracking-tight text-slate-900 sm:text-5xl">
                                Elegant {{ $slide['title'] }}<br>
                                <span class="text-brand-600">{{ $slide['subtitle'] }}</span>
                            </h1>

                            <p class="mt-4 max-w-md text-slate-600">
                                {{ $slide['text'] ?? 'Handpicked fabric, honest pricing and delivery all over Bangladesh.' }}
                            </p>

                            <div class="mt-7 flex flex-wrap gap-3">
                                <a href="{{ route('shop.show', $product) }}" class="btn-primary px-6 py-3">
                                    Shop now
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                                </a>
                                <a href="{{ route('shop.index', ['category' => $product->category?->slug]) }}" class="btn-secondary px-6 py-3">
                                    View collection
                                </a>
                            </div>

                            <dl class="mt-8 flex flex-wrap gap-x-9 gap-y-4">
                                @foreach([
                                    ['Free delivery', \App\Services\CartService::freeShippingFrom() > 0 ? 'On ' . \App\Support\Money::format(\App\Services\CartService::freeShippingFrom(), false) . '+ orders' : 'Across Bangladesh', 'M3 7h10v8H3zM13 10h4l3 3v2h-7z'],
                                    ['Secure payment', 'COD or online', 'M5 11V8a7 7 0 0 1 14 0v3M4 11h16v10H4z'],
                                    ['Easy exchange', 'Within 7 days', 'M4 12a8 8 0 0 1 13-6m3 6a8 8 0 0 1-13 6M17 3v4h-4M7 21v-4h4'],
                                ] as [$title, $note, $icon])
                                    <div class="flex items-center gap-2.5">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white text-brand-600 shadow-sm">
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                                        </span>
                                        <div>
                                            <dt class="text-sm font-semibold text-slate-900">{{ $title }}</dt>
                                            <dd class="text-xs text-slate-500">{{ $note }}</dd>
                                        </div>
                                    </div>
                                @endforeach
                            </dl>
                        </div>

                        {{-- Full-bleed model shot: fills its cell right up to the hero edges. --}}
                        <div class="relative min-h-[300px]">
                            <a href="{{ route('shop.show', $product) }}" class="absolute inset-0">
                                <img src="{{ $product->image_url }}" alt="{{ $product->name }}"
                                     class="h-full w-full object-cover object-[50%_22%]">
                            </a>

                            {{-- Softens the photo's straight edge into the hero gradient. --}}
                            <span aria-hidden="true"
                                  class="pointer-events-none absolute inset-y-0 left-0 hidden w-28 bg-gradient-to-r from-brand-50 via-brand-50/50 to-transparent lg:block"></span>

                            <div class="absolute right-4 top-1/2 w-36 -translate-y-1/2 rounded-xl bg-ink-900 p-4 text-center text-white shadow-xl sm:right-6 sm:w-44 sm:p-5">
                                <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Up to</p>
                                <p class="text-4xl font-bold leading-none sm:text-5xl">{{ $product->discount_percent }}<span class="text-2xl">%</span></p>
                                <p class="text-sm font-semibold uppercase tracking-wide">Off</p>
                                <p class="mt-1 text-[11px] text-slate-400">on {{ $product->category?->name ?? 'new arrivals' }}</p>
                                <a href="{{ route('shop.index', ['on_sale' => 1]) }}"
                                   class="mt-4 block rounded-lg bg-white px-3 py-2 text-xs font-bold uppercase tracking-wide text-ink-900 transition hover:bg-brand-100">
                                    Shop now
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach

                @if($slides->count() > 1)
                    {{-- Dots sit over the bottom of the photo, as in the reference layout. --}}
                    <div class="absolute bottom-4 left-1/2 z-20 flex -translate-x-1/2 gap-2 rounded-full bg-black/25 px-3 py-2 backdrop-blur-sm lg:left-[70%]">
                        @foreach($slides as $index => $slide)
                            <button @click="go({{ $index }})" aria-label="Slide {{ $index + 1 }}"
                                    class="h-2 rounded-full transition-all"
                                    x-bind:class="active === {{ $index }} ? 'w-6 bg-white' : 'w-2 bg-white/60'"></button>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        {{-- Category circles --}}
        @if($categories->isNotEmpty())
            <section class="card px-4 py-6">
                <div class="flex gap-6 overflow-x-auto pb-2 sm:justify-evenly">
                    @foreach($categories as $category)
                        <a href="{{ route('shop.index', ['category' => $category->slug]) }}" class="group w-28 shrink-0 text-center">
                            <span class="mx-auto block h-20 w-20 overflow-hidden rounded-full ring-1 ring-slate-200 transition group-hover:ring-2 group-hover:ring-brand-400">
                                @if($category->image)
                                    <img src="{{ asset('storage/' . $category->image) }}" alt="{{ $category->name }}"
                                         class="h-full w-full object-cover transition duration-300 group-hover:scale-110">
                                @else
                                    <span class="grid h-full w-full place-items-center bg-brand-50 text-xl font-bold text-brand-600">{{ strtoupper(substr($category->name, 0, 1)) }}</span>
                                @endif
                            </span>
                            <span class="mt-3 block truncate text-xs font-semibold text-slate-700 group-hover:text-brand-600">{{ $category->name }}</span>
                        </a>
                    @endforeach

                    <a href="{{ route('shop.index') }}" class="group w-28 shrink-0 text-center">
                        <span class="mx-auto grid h-20 w-20 place-items-center rounded-full bg-slate-100 text-slate-500 ring-1 ring-slate-200 transition group-hover:bg-brand-50 group-hover:text-brand-600">
                            <svg class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg>
                        </span>
                        <span class="mt-3 block text-xs font-semibold text-slate-700 group-hover:text-brand-600">All categories</span>
                    </a>
                </div>
            </section>
        @endif

        {{-- Trust bar --}}
        <section class="rounded-2xl bg-brand-50/70 px-4 py-6">
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-5">
                @foreach([
                    ['Free delivery', \App\Services\CartService::freeShippingFrom() > 0 ? \App\Support\Money::format(\App\Services\CartService::freeShippingFrom(), false) . '+ orders' : 'Across Bangladesh', 'M3 7h10v8H3zM13 10h4l3 3v2h-7z'],
                    ['100% authentic', 'Quality checked fabric', 'm9 12 2 2 4-4M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z'],
                    ['Secure payment', 'COD or online', 'M5 11V8a7 7 0 0 1 14 0v3M4 11h16v10H4z'],
                    ['Easy exchange', 'Within 7 days', 'M4 12a8 8 0 0 1 13-6m3 6a8 8 0 0 1-13 6M17 3v4h-4M7 21v-4h4'],
                    ['Here to help', 'Sat-Thu, 10am-8pm', 'M4 14v-3a8 8 0 0 1 16 0v3M4 14h3v5H5a1 1 0 0 1-1-1zm16 0h-3v5h2a1 1 0 0 0 1-1z'],
                ] as [$title, $note, $icon])
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-white text-brand-600 shadow-sm">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-slate-900">{{ $title }}</p>
                            <p class="truncate text-xs text-slate-500">{{ $note }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Featured --}}
        @if($featured->isNotEmpty())
            <section class="pt-6">
                <x-section-heading title="Featured Products" :link="route('shop.index', ['sort' => 'popular'])" link-label="View all products" />

                <div class="mt-6 grid grid-cols-2 gap-5 lg:grid-cols-4">
                    @foreach($featured as $product)
                        <x-product-card :product="$product" />
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Deals strip --}}
        @if($deals->isNotEmpty())
            <section class="overflow-hidden rounded-2xl bg-ink-900 px-6 py-8 sm:px-10">
                <div class="grid items-center gap-8 lg:grid-cols-[1fr_2fr]">
                    <div>
                        <p class="inline-flex rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-wide text-brand-300">Limited time</p>
                        <h2 class="mt-4 text-3xl font-bold text-white">Flash deals on this season&rsquo;s picks</h2>
                        <p class="mt-3 text-sm text-slate-400">Discounted sarees, kurtis and abayas while stock lasts.</p>
                        <a href="{{ route('shop.index', ['on_sale' => 1]) }}" class="btn mt-6 bg-white px-6 py-3 text-ink-900 hover:bg-brand-100">
                            See all offers
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M5 12h14m-6-6 6 6-6 6"/></svg>
                        </a>
                    </div>

                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        @foreach($deals->take(4) as $deal)
                            <a href="{{ route('shop.show', $deal) }}" class="group rounded-xl bg-white/5 p-3 transition hover:bg-white/10">
                                <span class="block overflow-hidden rounded-lg">
                                    <img src="{{ $deal->image_url }}" alt="{{ $deal->name }}" loading="lazy"
                                         class="aspect-[3/4] w-full object-cover transition duration-300 group-hover:scale-105">
                                </span>
                                <p class="mt-2.5 truncate text-xs font-semibold text-white">{{ $deal->name }}</p>
                                <p class="mt-1 text-xs">
                                    <span class="font-bold text-brand-300">@money($deal->current_price)</span>
                                    <span class="ml-1 text-slate-500 line-through">@money($deal->price)</span>
                                </p>
                            </a>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif

        {{-- New arrivals --}}
        @if($latest->isNotEmpty())
            <section class="pt-6 pb-4">
                <x-section-heading title="New Arrivals" :link="route('shop.index')" link-label="Browse the shop" />

                <div class="mt-6 grid grid-cols-2 gap-5 lg:grid-cols-4">
                    @foreach($latest as $product)
                        <x-product-card :product="$product" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
