<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Shop') &mdash; {{ \App\Support\Settings::get('store_name') }}</title>
    <meta name="description" content="@yield('meta_description', 'Matching - women&rsquo;s clothing in Bangladesh: sarees, salwar kameez, kurtis, lehengas, abayas, hijabs and shawls with cash on delivery.')">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Jost:wght@300;400;500;600;700&family=Noto+Sans+Bengali:wght@600;700&family=Parisienne&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/css/dream-fashion.css', 'resources/js/app.js'])
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="df-body">

@php
    $navCategories = $headerCategories ?? collect();
    $storeName = \App\Support\Settings::get('store_name');
@endphp

{{-- ============================================================
     HEADER
     ============================================================ --}}
<header class="df-head" x-data="{ mobileOpen: false, searchOpen: false }">
    <div class="df-wrap df-head__row">
        <a href="{{ route('home') }}" class="df-logo"><x-store-logo /></a>

        <nav class="df-nav" aria-label="Main">
            <div class="df-nav__item">
                <a href="{{ route('home') }}" class="df-nav__link {{ request()->routeIs('home') ? 'is-active' : '' }}">
                    Home
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="m6 9 6 6 6-6"/></svg>
                </a>
                <div class="df-drop">
                    <a href="{{ route('home') }}">Home</a>
                    <a href="{{ route('home') }}#best-sellers">Best sellers</a>
                    <a href="{{ route('shop.index', ['on_sale' => 1]) }}">Offers</a>
                </div>
            </div>

            <div class="df-nav__item">
                <a href="{{ route('shop.index') }}" class="df-nav__link {{ request()->routeIs('shop.*') ? 'is-active' : '' }}">
                    Shop
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="m6 9 6 6 6-6"/></svg>
                </a>
                <div class="df-drop">
                    <a href="{{ route('shop.index') }}">All products</a>
                    @foreach($navCategories->take(6) as $category)
                        <a href="{{ route('shop.index', ['category' => $category->slug]) }}">{{ $category->name }}</a>
                    @endforeach
                </div>
            </div>

            <a href="{{ route('shop.index') }}" class="df-nav__link">Faq</a>
            <a href="{{ route('shop.index', ['sort' => 'popular']) }}" class="df-nav__link">Vlog</a>
            <a href="{{ route('cart.index') }}" class="df-nav__link">Contact</a>
        </nav>

        <div class="df-head__tools">
            @auth
                <div class="df-acct" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" @click="open = !open" class="df-tool">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="3.6"/><path stroke-linecap="round" d="M5 20a7 7 0 0 1 14 0"/></svg>
                        <span>{{ Str::limit(auth()->user()->name, 9) }}</span>
                    </button>
                    <div class="df-drop df-drop--right" x-show="open" x-cloak>
                        @if(auth()->user()->isStaff())
                            <a href="{{ route('admin.dashboard') }}">Admin panel</a>
                        @endif
                        <a href="{{ route('orders.index') }}">My orders</a>
                        <a href="{{ route('wishlist.index') }}">Wishlist</a>
                        <a href="{{ route('profile.edit') }}">Account settings</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit">Log out</button>
                        </form>
                    </div>
                </div>
            @else
                <a href="{{ route('login') }}" class="df-tool">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="3.6"/><path stroke-linecap="round" d="M5 20a7 7 0 0 1 14 0"/></svg>
                    <span>Log in</span>
                </a>
            @endauth

            <button type="button" class="df-tool df-tool--icon" @click="searchOpen = !searchOpen" aria-label="Search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m20 20-3.6-3.6"/></svg>
            </button>

            <a href="{{ route('cart.index') }}" class="df-tool df-tool--icon df-cart" aria-label="Cart">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 8h12l1.2 11.4A1.6 1.6 0 0 1 17.6 21H6.4a1.6 1.6 0 0 1-1.6-1.6L6 8Z"/><path stroke-linecap="round" d="M9 8V6.5a3 3 0 0 1 6 0V8"/></svg>
                <em>{{ $headerCartCount ?? 0 }}</em>
            </a>

            <button type="button" class="df-burger" @click="mobileOpen = !mobileOpen" aria-label="Menu">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>

    {{-- Search drawer --}}
    <div class="df-search" x-show="searchOpen" x-cloak>
        <form action="{{ route('shop.index') }}" method="GET" class="df-wrap df-search__form">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search for products..." aria-label="Search products">
            <button type="submit" class="df-btn df-btn--dark df-btn--sm">Search</button>
        </form>
    </div>

    {{-- Mobile menu --}}
    <div class="df-mobile" x-show="mobileOpen" x-cloak>
        <a href="{{ route('home') }}">Home</a>
        <a href="{{ route('shop.index') }}">Shop</a>
        <a href="{{ route('shop.index', ['on_sale' => 1]) }}">Offers</a>
        <a href="{{ route('cart.index') }}">Cart</a>
        @auth
            <a href="{{ route('orders.index') }}">My orders</a>
            <a href="{{ route('profile.edit') }}">Account</a>
        @else
            <a href="{{ route('login') }}">Log in</a>
        @endauth
        @foreach($navCategories->take(8) as $category)
            <a href="{{ route('shop.index', ['category' => $category->slug]) }}">{{ $category->name }}</a>
        @endforeach
    </div>
</header>

<main class="df-main">
    <div class="df-wrap"><x-flash /></div>
    @yield('content')
</main>

{{-- ============================================================
     FOOTER
     ============================================================ --}}
<footer class="df-foot">
    <div class="df-wrap df-foot__grid">
        <div class="df-foot__brand" style="margin-top: -25px;">
            {{-- <a href="{{ route('home') }}" class="df-logo df-logo--foot"><x-store-logo /></a> --}}
            <p class="df-pay__title">You Can Pay By</p>
            <div class="df-pay" aria-label="Accepted payments">
                @foreach(config('shop.payment_badges', []) as $badge)
                    @php $logo = public_path('images/payments/' . $badge['image']); @endphp
                    <span class="df-pay__card">
                        @if(is_file($logo))
                            <img src="{{ asset('images/payments/' . $badge['image']) }}" alt="{{ $badge['name'] }}" loading="lazy">
                        @else
                            {{-- No file dropped in: fall back to the mark drawn in markup. --}}
                            @include('partials.payment-mark', ['key' => $badge['key'], 'name' => $badge['name']])
                        @endif
                    </span>
                @endforeach
            </div>
        </div>

        <div class="df-foot__col">
            <h3>Support</h3>
            <ul>
                <li><a href="{{ route('shop.index') }}">Made to order</a></li>
                <li><a href="{{ route('orders.index') }}">Delivery Info</a></li>
                <li><a href="{{ route('shop.index') }}">Repairs for life</a></li>
                <li><a href="{{ route('cart.index') }}">Gift wrap</a></li>
            </ul>
        </div>

        <div class="df-foot__col">
            <h3>About US</h3>
            <p>
                Rhoncus commodo elit at imperdiet dui accumsan sit amet nulla. Platea dictumst
                vestibulum rhoncus est pellentesque elit.
            </p>
            <div class="df-social">
                <a href="#" aria-label="Facebook"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M13.5 21v-7h2.4l.4-2.8h-2.8V9.4c0-.8.2-1.4 1.4-1.4h1.5V5.5c-.3 0-1.2-.1-2.2-.1-2.2 0-3.7 1.3-3.7 3.8v2H8v2.8h2.5V21h3Z"/></svg></a>
                <a href="#" aria-label="X"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.5 3h3.2l-7 8 7.3 10h-5.6l-4.4-6.1L5.8 21H2.6l7.3-8.4L2.9 3h5.7l4.1 5.7z"/></svg></a>
                <a href="#" aria-label="Instagram"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3.5" y="3.5" width="17" height="17" rx="4.6"/><circle cx="12" cy="12" r="3.6"/><circle cx="17.2" cy="6.9" r="1" fill="currentColor"/></svg></a>
                <a href="#" aria-label="YouTube"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M21.6 7.2a2.6 2.6 0 0 0-1.8-1.8C18 5 12 5 12 5s-6 0-7.8.4A2.6 2.6 0 0 0 2.4 7.2C2 9 2 12 2 12s0 3 .4 4.8a2.6 2.6 0 0 0 1.8 1.8C6 19 12 19 12 19s6 0 7.8-.4a2.6 2.6 0 0 0 1.8-1.8C22 15 22 12 22 12s0-3-.4-4.8ZM10 15.2V8.8L15.5 12 10 15.2Z"/></svg></a>
            </div>
        </div>
    </div>

    <div class="df-foot__bar">
        <div class="df-wrap df-foot__bar-row">
            <p>
                &copy; {{ date('Y') }} {{ $storeName }} Design Themes
                <span class="df-foot__by">
                    Developed by
                    <a href="https://zarosoft.com" target="_blank" rel="noopener noreferrer">zarosoft.com</a>
                </span>
            </p>
            <nav class="df-foot__bar-links">
                <a href="{{ route('cart.index') }}">Contact</a>
                <a href="{{ route('shop.index') }}">T &amp; C</a>
                <a href="{{ route('shop.index') }}">Dealers</a>
                <a href="{{ route('shop.index') }}">FAQ</a>
                <a href="{{ route('shop.index') }}">Gift Card</a>
                <a href="{{ route('shop.index') }}">Transparency</a>
                <a href="{{ route('shop.index') }}">Privacy Policy</a>
            </nav>
            <a href="#top" class="df-top" aria-label="Back to top">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" d="m6 14 6-6 6 6"/></svg>
            </a>
        </div>
    </div>
</footer>

</body>
</html>
