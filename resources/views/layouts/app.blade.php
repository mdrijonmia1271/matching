<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Shop') &mdash; {{ \App\Support\Settings::get('store_name') }}</title>
    <meta name="description" content="@yield('meta_description', 'Matching - women&rsquo;s clothing in Bangladesh: sarees, salwar kameez, kurtis, lehengas, abayas, hijabs and shawls with cash on delivery.')">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="flex min-h-full flex-col bg-slate-50 font-sans text-slate-800 antialiased">

@php
    $navCategories = $headerCategories ?? collect();

    $navLinks = [
        ['label' => 'Home', 'url' => route('home'), 'active' => request()->routeIs('home')],
        ['label' => 'New Arrivals', 'url' => route('shop.index'), 'active' => request()->routeIs('shop.index') && ! request()->hasAny(['on_sale', 'category', 'sort', 'q'])],
        ['label' => 'Best Sellers', 'url' => route('shop.index', ['sort' => 'popular']), 'active' => request('sort') === 'popular'],
        ['label' => 'Top Rated', 'url' => route('shop.index', ['sort' => 'rating']), 'active' => request('sort') === 'rating'],
        ['label' => 'Offers', 'url' => route('shop.index', ['on_sale' => 1]), 'active' => request()->boolean('on_sale')],
    ];
@endphp

<header class="sticky top-0 z-40 bg-white shadow-sm">

    {{-- Utility bar --}}
    <div class="border-b border-slate-100 bg-white">
        <div class="mx-auto flex h-10 max-w-page items-center gap-6 px-4 text-xs text-slate-600 sm:px-6">
            @php
                $storePhone = \App\Support\Settings::get('store_phone');
                $storeEmail = \App\Support\Settings::get('store_email');
                $freeFrom = \App\Services\CartService::freeShippingFrom();
            @endphp
            @if($storePhone)
                <a href="tel:{{ preg_replace('/[^0-9+]/', '', $storePhone) }}" class="hidden items-center gap-1.5 hover:text-brand-600 sm:flex">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 0 1 2-2h2l2 5-2 1a12 12 0 0 0 6 6l1-2 5 2v2a2 2 0 0 1-2 2A16 16 0 0 1 3 5z"/></svg>
                    {{ $storePhone }}
                </a>
            @endif
            @if($storeEmail)
                <a href="mailto:{{ $storeEmail }}" class="hidden items-center gap-1.5 hover:text-brand-600 md:flex">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path stroke-linecap="round" d="m3 7 9 6 9-6"/></svg>
                    {{ $storeEmail }}
                </a>
            @endif

            <p class="mx-auto text-center font-medium text-slate-700">
                @if($freeFrom > 0)
                    🚚 Free delivery on orders over @money($freeFrom, false)
                @endif
            </p>

            <a href="{{ route('orders.index') }}" class="hidden items-center gap-1.5 hover:text-brand-600 sm:flex">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>
                Track Order
            </a>
            <span class="hidden items-center gap-1.5 text-slate-500 lg:flex">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 17h.01M9.5 9.5a2.5 2.5 0 1 1 3 2.45V13"/></svg>
                Cash on delivery
            </span>
            <span class="font-semibold text-slate-700">BDT</span>
        </div>
    </div>

    {{-- Main header --}}
    <div class="mx-auto max-w-page px-4 sm:px-6" x-data="{ mobileOpen: false }">
        <div class="flex h-20 items-center gap-4">
            <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2.5">
                {{-- <span class="grid h-11 w-11 place-items-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white shadow-lg shadow-brand-600/25">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l1 13H5L6 7z"/>
                        <path stroke-linecap="round" d="M9 7a3 3 0 0 1 6 0"/>
                    </svg>
                </span> --}}
                <img src="{{ asset('images/logo-1.png') }}" alt="" style="height: 210px; width: auto; margin-top: 41px;">
                {{-- <span class="flex flex-col leading-none">
                    <span class="text-2xl font-bold tracking-tight text-slate-900">Matching</span>
                    <span class="mt-0.5 text-[10px] font-medium uppercase tracking-[0.22em] text-brand-600">Style you love</span>
                </span> --}}
            </a>

            {{-- Category-scoped search --}}
            <form action="{{ route('shop.index') }}" method="GET" class="mx-auto hidden w-full max-w-2xl lg:block">
                <div class="flex overflow-hidden rounded-lg border border-slate-300 focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/20">
                    <label for="search-category" class="sr-only">Category</label>
                    <select id="search-category" name="category"
                            class="w-40 shrink-0 border-0 border-r border-slate-200 bg-slate-50 py-2.5 pl-4 pr-8 text-sm text-slate-600 focus:ring-0">
                        <option value="">All Categories</option>
                        @foreach($navCategories as $category)
                            <option value="{{ $category->slug }}" @selected(request('category') === $category->slug)>{{ $category->name }}</option>
                        @endforeach
                    </select>

                    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search for products..."
                           class="min-w-0 flex-1 border-0 px-4 py-2.5 text-sm placeholder:text-slate-400 focus:ring-0" aria-label="Search products">

                    <button type="submit" class="grid w-14 shrink-0 place-items-center bg-brand-600 text-white transition hover:bg-brand-700" aria-label="Search">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.5-3.5"/></svg>
                    </button>
                </div>
            </form>

            <nav class="ml-auto flex items-center gap-1 sm:gap-4">
                @auth
                    <a href="{{ route('wishlist.index') }}" class="hidden items-center gap-2 text-slate-600 hover:text-brand-600 sm:flex">
                        <span class="relative">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-7-4.6-9.2-9A5 5 0 0 1 12 6.2 5 5 0 0 1 21.2 12C19 16.4 12 21 12 21z"/></svg>
                            <span class="absolute -right-2 -top-2 grid h-4 min-w-4 place-items-center rounded-full bg-brand-600 px-1 text-[10px] font-bold text-white">{{ auth()->user()->wishlists->count() }}</span>
                        </span>
                        <span class="hidden text-sm font-medium xl:inline">Wishlist</span>
                    </a>
                @endauth

                <a href="{{ route('cart.index') }}" class="flex items-center gap-2 text-slate-600 hover:text-brand-600">
                    <span class="relative">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l2.4 12.1a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 7H6"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></svg>
                        <span class="absolute -right-2 -top-2 grid h-4 min-w-4 place-items-center rounded-full bg-brand-600 px-1 text-[10px] font-bold text-white">{{ $headerCartCount ?? 0 }}</span>
                    </span>
                    <span class="hidden text-sm font-medium xl:inline">Cart</span>
                </a>

                @auth
                    <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                        <button @click="open = !open" class="flex items-center gap-2 text-slate-600 hover:text-brand-600">
                            <span class="grid h-8 w-8 place-items-center rounded-full bg-brand-50 text-xs font-bold text-brand-700">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                            <span class="hidden text-sm font-medium lg:inline">{{ Str::limit(auth()->user()->name, 10) }}</span>
                        </button>
                        <div x-show="open" x-cloak x-transition.origin.top.right class="absolute right-0 mt-3 w-52 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-xl">
                            @if(auth()->user()->isStaff())
                                <a href="{{ route('admin.dashboard') }}" class="block px-4 py-2 text-sm font-semibold text-brand-700 hover:bg-slate-50">Admin panel</a>
                                <hr class="my-1 border-slate-100">
                            @endif
                            <a href="{{ route('orders.index') }}" class="block px-4 py-2 text-sm hover:bg-slate-50">My orders</a>
                            <a href="{{ route('wishlist.index') }}" class="block px-4 py-2 text-sm hover:bg-slate-50">Wishlist</a>
                            <a href="{{ route('profile.edit') }}" class="block px-4 py-2 text-sm hover:bg-slate-50">Account settings</a>
                            <hr class="my-1 border-slate-100">
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-rose-600 hover:bg-rose-50">Log out</button>
                            </form>
                        </div>
                    </div>
                @else
                    <a href="{{ route('login') }}" class="flex items-center gap-2 text-slate-600 hover:text-brand-600">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 21a8 8 0 0 1 16 0"/></svg>
                        <span class="hidden text-sm font-medium sm:inline">Login / Register</span>
                    </a>
                @endauth

                <button @click="mobileOpen = !mobileOpen" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" aria-label="Menu">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </nav>
        </div>

        {{-- Mobile drawer --}}
        <div x-show="mobileOpen" x-cloak class="border-t border-slate-100 py-4 lg:hidden">
            <form action="{{ route('shop.index') }}" method="GET" class="mb-4 flex gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Search for products..." class="input">
                <button type="submit" class="btn-primary px-4">Go</button>
            </form>
            <div class="grid gap-1">
                @foreach($navLinks as $link)
                    <a href="{{ $link['url'] }}" class="rounded-lg px-3 py-2 text-sm font-medium {{ $link['active'] ? 'bg-brand-50 text-brand-700' : 'text-slate-700 hover:bg-slate-50' }}">{{ $link['label'] }}</a>
                @endforeach
                <p class="mt-3 px-3 text-xs font-semibold uppercase tracking-wide text-slate-400">Categories</p>
                @foreach($navCategories as $category)
                    <a href="{{ route('shop.index', ['category' => $category->slug]) }}" class="rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">{{ $category->name }}</a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Category bar --}}
    <div class="hidden border-t border-slate-100 lg:block">
        <div class="mx-auto flex max-w-page items-center gap-8 px-4 sm:px-6">
            <div class="relative py-3" x-data="{ open: false }" @click.outside="open = false">
                <button @click="open = !open"
                        class="flex w-56 items-center gap-3 rounded-lg bg-brand-600 px-4 py-3 text-sm font-bold uppercase tracking-wide text-white transition hover:bg-brand-700">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    All Categories
                    <svg class="ml-auto h-4 w-4 transition" x-bind:class="open && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" d="m6 9 6 6 6-6"/></svg>
                </button>

                <div x-show="open" x-cloak x-transition.origin.top.left
                     class="absolute left-0 top-full z-50 w-72 overflow-hidden rounded-xl border border-slate-200 bg-white py-2 shadow-xl">
                    @foreach($navCategories as $category)
                        <a href="{{ route('shop.index', ['category' => $category->slug]) }}"
                           class="flex items-center gap-3 px-4 py-2.5 text-sm text-slate-700 hover:bg-brand-50 hover:text-brand-700">
                            <span class="h-8 w-8 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                                @if($category->image)
                                    <img src="{{ asset('storage/' . $category->image) }}" alt="" class="h-full w-full object-cover">
                                @endif
                            </span>
                            <span class="flex-1">{{ $category->name }}</span>
                            <svg class="h-4 w-4 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="m9 6 6 6-6 6"/></svg>
                        </a>
                    @endforeach
                </div>
            </div>

            <nav class="flex items-center gap-8 text-sm font-semibold uppercase tracking-wide">
                @foreach($navLinks as $link)
                    <a href="{{ $link['url'] }}"
                       class="relative py-5 transition {{ $link['active'] ? 'text-brand-600' : 'text-slate-700 hover:text-brand-600' }}">
                        {{ $link['label'] }}
                        @if($link['active'])
                            <span class="absolute inset-x-0 bottom-3 h-0.5 rounded-full bg-brand-600"></span>
                        @endif
                    </a>
                @endforeach
            </nav>

            <a href="{{ route('shop.index', ['on_sale' => 1]) }}" class="ml-auto flex items-center gap-2 text-sm font-semibold text-rose-600 hover:text-rose-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 2 4 14h6l-1 8 9-12h-6l1-8z"/></svg>
                Flash Deals
            </a>
        </div>
    </div>
</header>

<main class="flex-1">
    <div class="mx-auto max-w-page px-4 sm:px-6">
        <x-flash />
    </div>
    @yield('content')
</main>

<footer class="mt-16 bg-ink-900 text-slate-300">
    <div class="mx-auto grid max-w-page gap-10 px-4 py-14 sm:px-6 md:grid-cols-2 lg:grid-cols-4">
        <div>
            <div class="flex items-center gap-2.5">
                <span class="grid h-11 w-11 place-items-center rounded-xl bg-gradient-to-br from-brand-500 to-brand-700 text-white">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 7h12l1 13H5L6 7z"/>
                        <path stroke-linecap="round" d="M9 7a3 3 0 0 1 6 0"/>
                    </svg>
                </span>
                <span class="flex flex-col leading-none">
                    <span class="text-2xl font-bold text-white">Matching</span>
                    <span class="mt-0.5 text-[10px] font-medium uppercase tracking-[0.22em] text-brand-300">Style you love</span>
                </span>
            </div>
            <p class="mt-4 text-sm text-slate-400">
                Women&rsquo;s clothing for every occasion &mdash; saree, three piece, kurti, lehenga, abaya and shawl &mdash;
                delivered across Bangladesh.
            </p>
            <div class="mt-5 flex gap-2 text-xs">
                <span class="rounded-md bg-white/10 px-2.5 py-1.5 font-semibold">Cash on delivery</span>
                <span class="rounded-md bg-white/10 px-2.5 py-1.5 font-semibold">7-day exchange</span>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-bold uppercase tracking-wide text-white">Shop</h3>
            <ul class="mt-4 space-y-2.5 text-sm">
                <li><a href="{{ route('shop.index') }}" class="hover:text-brand-300">All clothing</a></li>
                @foreach($navCategories->take(5) as $category)
                    <li><a href="{{ route('shop.index', ['category' => $category->slug]) }}" class="hover:text-brand-300">{{ $category->name }}</a></li>
                @endforeach
            </ul>
        </div>

        <div>
            <h3 class="text-sm font-bold uppercase tracking-wide text-white">Account</h3>
            <ul class="mt-4 space-y-2.5 text-sm">
                <li><a href="{{ route('orders.index') }}" class="hover:text-brand-300">Track my order</a></li>
                <li><a href="{{ route('wishlist.index') }}" class="hover:text-brand-300">Wishlist</a></li>
                <li><a href="{{ route('cart.index') }}" class="hover:text-brand-300">Cart</a></li>
                <li><a href="{{ route('profile.edit') }}" class="hover:text-brand-300">Account settings</a></li>
                <li><a href="{{ route('shop.index', ['on_sale' => 1]) }}" class="hover:text-brand-300">Offers</a></li>
            </ul>
        </div>

        <div>
            <h3 class="text-sm font-bold uppercase tracking-wide text-white">Get in touch</h3>
            <ul class="mt-4 space-y-2.5 text-sm">
                <li class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-brand-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 0 1 2-2h2l2 5-2 1a12 12 0 0 0 6 6l1-2 5 2v2a2 2 0 0 1-2 2A16 16 0 0 1 3 5z"/></svg>
                    01812-345678
                </li>
                <li class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-brand-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path stroke-linecap="round" d="m3 7 9 6 9-6"/></svg>
                    support@matching.test
                </li>
                <li class="flex items-start gap-2">
                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-brand-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-6 7-11a7 7 0 1 0-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></svg>
                    Dhanmondi, Dhaka 1205
                </li>
                <li class="pt-1 text-slate-400">Sat&ndash;Thu, 10am&ndash;8pm</li>
            </ul>
        </div>
    </div>

    <div class="border-t border-white/10">
        <div class="mx-auto flex max-w-page flex-col items-center justify-between gap-2 px-4 py-5 text-xs text-slate-400 sm:flex-row sm:px-6">
            <p>&copy; {{ date('Y') }} Matching. All rights reserved.</p>
            <p>Prices in Bangladeshi Taka (৳)</p>
        </div>
    </div>
</footer>

</body>
</html>
