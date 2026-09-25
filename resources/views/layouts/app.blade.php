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
    <link
        href="https://fonts.googleapis.com/css2?family=Jost:wght@300;400;500;600;700&family=Noto+Sans+Bengali:wght@600;700&family=Parisienne&display=swap"
        rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/css/dream-fashion.css', 'resources/js/app.js'])
    <style>
        [x-cloak] {
            display: none !important
        }

        .df-foot__by a {
            color: #f1f1ea;
            text-decoration: none !important;
        }
    </style>
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
                    <a href="{{ route('home') }}"
                        class="df-nav__link {{ request()->routeIs('home') ? 'is-active' : '' }}">
                        Home
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </a>
                    <div class="df-drop">
                        <a href="{{ route('home') }}">Home</a>
                        <a href="{{ route('home') }}#best-sellers">Best sellers</a>
                        <a href="{{ route('shop.index', ['on_sale' => 1]) }}">Offers</a>
                    </div>
                </div>

                <div class="df-nav__item">
                    <a href="{{ route('shop.index') }}"
                        class="df-nav__link {{ request()->routeIs('shop.*') ? 'is-active' : '' }}">
                        Shop
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </a>
                    <div class="df-drop">
                        <a href="{{ route('shop.index') }}">All products</a>
                        @foreach ($navCategories->take(6) as $category)
                            <a
                                href="{{ route('shop.index', ['category' => $category->slug]) }}">{{ $category->name }}</a>
                        @endforeach
                    </div>
                </div>

                <a href="{{ route('contact') }}" class="df-nav__link">Contact</a>
            </nav>

            <div class="df-head__tools">
                @auth
                    <div class="df-acct" x-data="{ open: false }" @click.outside="open = false">
                        <button type="button" @click="open = !open" class="df-tool">
                            <svg class="df-ico-desk" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <circle cx="12" cy="8" r="3.6" />
                                <path stroke-linecap="round" d="M5 20a7 7 0 0 1 14 0" />
                            </svg>
                            <svg class="df-ico-mob" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                                <circle cx="12" cy="7.5" r="4" />
                                <path stroke-linejoin="round" d="M4 21v-1.5A5.5 5.5 0 0 1 9.5 14h5a5.5 5.5 0 0 1 5.5 5.5V21Z" />
                            </svg>
                            <span>{{ Str::limit(auth()->user()->name, 9) }}</span>
                        </button>
                        <div class="df-drop df-drop--right" x-show="open" x-cloak>
                            @if (auth()->user()->isStaff())
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
                        <svg class="df-ico-desk" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <circle cx="12" cy="8" r="3.6" />
                            <path stroke-linecap="round" d="M5 20a7 7 0 0 1 14 0" />
                        </svg>
                        <svg class="df-ico-mob" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                            <circle cx="12" cy="7.5" r="4" />
                            <path stroke-linejoin="round" d="M4 21v-1.5A5.5 5.5 0 0 1 9.5 14h5a5.5 5.5 0 0 1 5.5 5.5V21Z" />
                        </svg>
                        <span>Log in</span>
                    </a>
                @endauth

                <button type="button" class="df-tool df-tool--icon" @click="searchOpen = !searchOpen"
                    aria-label="Search">
                    <svg class="df-ico-desk" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <circle cx="11" cy="11" r="7" />
                        <path stroke-linecap="round" d="m20 20-3.6-3.6" />
                    </svg>
                    <svg class="df-ico-mob df-ico-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                        <circle cx="10.5" cy="10.5" r="6.5" />
                        <path stroke-linecap="round" d="m15.3 15.3 4.2 4.2" />
                    </svg>
                </button>

                <a href="{{ route('cart.index') }}" class="df-tool df-tool--icon df-cart" aria-label="Cart">
                    <svg class="df-ico-desk" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M6 8h12l1.2 11.4A1.6 1.6 0 0 1 17.6 21H6.4a1.6 1.6 0 0 1-1.6-1.6L6 8Z" />
                        <path stroke-linecap="round" d="M9 8V6.5a3 3 0 0 1 6 0V8" />
                    </svg>
                    <svg class="df-ico-mob" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
                        <path stroke-linejoin="round" d="M5 8h14v13H5Z" />
                        <path d="M9 8V6a3 3 0 0 1 6 0v2" />
                    </svg>
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
                <input type="search" name="q" value="{{ request('q') }}"
                    placeholder="Search for products..." aria-label="Search products">
                <button type="submit" class="df-btn df-btn--dark df-btn--sm">Search</button>
            </form>
        </div>

        {{-- Mobile menu: a drawer from the left with the same links as the
             desktop nav. Home and Shop open their sub-links in place; the
             account links sit underneath. --}}
        <div class="df-drawer" x-show="mobileOpen" x-cloak x-data="{ sub: null }"
            x-effect="document.body.style.overflow = mobileOpen ? 'hidden' : ''"
            @keydown.escape.window="mobileOpen = false">
            <div class="df-drawer__shade" x-show="mobileOpen" x-transition.opacity @click="mobileOpen = false"></div>

            <nav class="df-drawer__panel" x-show="mobileOpen"
                x-transition:enter="df-drawer-in" x-transition:enter-start="df-drawer-from"
                x-transition:leave="df-drawer-in" x-transition:leave-end="df-drawer-from"
                aria-label="Mobile menu">
                <div class="df-drawer__head">
                    <span>Menu</span>
                    <button type="button" @click="mobileOpen = false" aria-label="Close menu">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" d="M5 5l14 14M19 5 5 19" />
                        </svg>
                    </button>
                </div>

                <div class="df-drawer__group">
                    <button type="button" class="df-drawer__link" @click="sub = sub === 'home' ? null : 'home'"
                        :aria-expanded="sub === 'home'">
                        Home
                        <svg :class="sub === 'home' && 'is-open'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" />
                        </svg>
                    </button>
                    <div class="df-drawer__sub" x-show="sub === 'home'">
                        <a href="{{ route('home') }}">Home</a>
                        <a href="{{ route('home') }}#best-sellers" @click="mobileOpen = false">Best sellers</a>
                        <a href="{{ route('shop.index', ['on_sale' => 1]) }}">Offers</a>
                    </div>

                    <button type="button" class="df-drawer__link" @click="sub = sub === 'shop' ? null : 'shop'"
                        :aria-expanded="sub === 'shop'">
                        Shop
                        <svg :class="sub === 'shop' && 'is-open'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" />
                        </svg>
                    </button>
                    <div class="df-drawer__sub" x-show="sub === 'shop'">
                        <a href="{{ route('shop.index') }}">All products</a>
                        @foreach ($navCategories->take(6) as $category)
                            <a href="{{ route('shop.index', ['category' => $category->slug]) }}">{{ $category->name }}</a>
                        @endforeach
                    </div>

                    <a href="{{ route('contact') }}" class="df-drawer__link">Contact</a>
                </div>

                <div class="df-drawer__account">
                    @auth
                        @if (auth()->user()->isStaff())
                            <a href="{{ route('admin.dashboard') }}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                                    <rect x="3.5" y="3.5" width="7" height="7" rx="1" /><rect x="13.5" y="3.5" width="7" height="7" rx="1" />
                                    <rect x="3.5" y="13.5" width="7" height="7" rx="1" /><rect x="13.5" y="13.5" width="7" height="7" rx="1" />
                                </svg>
                                Admin panel
                            </a>
                        @endif
                        <a href="{{ route('orders.index') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                                <path stroke-linejoin="round" d="M5 8h14v13H5Z" /><path d="M9 8V6a3 3 0 0 1 6 0v2" />
                            </svg>
                            My Orders
                        </a>
                        <a href="{{ route('wishlist.index') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                                <path stroke-linejoin="round" d="M12 20.5S3.5 15.3 3.5 9.2A4.7 4.7 0 0 1 12 6.4a4.7 4.7 0 0 1 8.5 2.8c0 6.1-8.5 11.3-8.5 11.3Z" />
                            </svg>
                            My Wish List
                        </a>
                        <a href="{{ route('profile.edit') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                                <circle cx="12" cy="12" r="9" /><circle cx="12" cy="10" r="3.2" />
                                <path d="M6.3 18.6a6.5 6.5 0 0 1 11.4 0" />
                            </svg>
                            Account Settings
                        </a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 4H6v16h8M10 12h10m-3-3 3 3-3 3" />
                                </svg>
                                Log Out
                            </button>
                        </form>
                    @else
                        <a href="{{ route('login') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                                <circle cx="12" cy="12" r="9" /><circle cx="12" cy="10" r="3.2" />
                                <path d="M6.3 18.6a6.5 6.5 0 0 1 11.4 0" />
                            </svg>
                            Sign In
                        </a>
                        <a href="{{ route('register') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                                <circle cx="10" cy="8" r="3.6" /><path d="M3.5 20a6.5 6.5 0 0 1 11-4.7" />
                                <path stroke-linecap="round" d="M18 14v6m-3-3h6" />
                            </svg>
                            Create an Account
                        </a>
                        <a href="{{ route('wishlist.index') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                                <path stroke-linejoin="round" d="M12 20.5S3.5 15.3 3.5 9.2A4.7 4.7 0 0 1 12 6.4a4.7 4.7 0 0 1 8.5 2.8c0 6.1-8.5 11.3-8.5 11.3Z" />
                            </svg>
                            My Wish List
                        </a>
                    @endauth
                </div>
            </nav>
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
                    @foreach (config('shop.payment_badges', []) as $badge)
                        @php $logo = public_path('images/payments/' . $badge['image']); @endphp
                        <span class="df-pay__card">
                            @if (is_file($logo))
                                <img src="{{ asset('images/payments/' . $badge['image']) }}"
                                    alt="{{ $badge['name'] }}" loading="lazy">
                            @else
                                {{-- No file dropped in: fall back to the mark drawn in markup. --}}
                                @include('partials.payment-mark', [
                                    'key' => $badge['key'],
                                    'name' => $badge['name'],
                                ])
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
                </ul>
            </div>

            <div class="df-foot__col">
                <h3>About US</h3>
                <p>
                    Matching brings together a curated collection of Indian, Pakistani and Bangladeshi fashion,
                    including three-pieces, sarees, batik and more.
                </p>
                <div class="df-social">
                    {{-- Brand icons in their own colours. --}}
                    <a href="https://www.facebook.com/share/1Bzz3ZCT4X/" target="_blank" rel="noopener noreferrer"
                        aria-label="Facebook"><svg viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="11.5" fill="#fff" />
                            <path fill="#1877F2"
                                d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.04V9.41c0-3.02 1.8-4.7 4.54-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.5c-1.5 0-1.96.93-1.96 1.89v2.26h3.32l-.53 3.5h-2.8V24C19.62 23.1 24 18.1 24 12.07Z" />
                        </svg></a>
                    <a href="https://www.tiktok.com/@_matching_shop" target="_blank" rel="noopener noreferrer"
                        aria-label="TikTok"><svg viewBox="0 0 24 24">
                            <defs>
                                <path id="df-tiktok-note"
                                    d="M16.6 5.8A4.3 4.3 0 0 1 15.5 3h-3.1v12.4a2.6 2.6 0 0 1-2.6 2.5 2.6 2.6 0 0 1-2.6-2.6 2.6 2.6 0 0 1 3.4-2.5V9.6a5.8 5.8 0 0 0-.8-.1 5.7 5.7 0 0 0-5.7 5.7A5.7 5.7 0 0 0 9.8 21a5.7 5.7 0 0 0 5.7-5.7V9a7.4 7.4 0 0 0 4.3 1.4V7.3a4.3 4.3 0 0 1-3.2-1.5Z" />
                            </defs>
                            <rect width="24" height="24" rx="5.5" fill="#000" />
                            <g transform="translate(3 3) scale(.75)">
                                <use href="#df-tiktok-note" fill="#25F4EE" x="-.8" y="-.8" />
                                <use href="#df-tiktok-note" fill="#FE2C55" x=".8" y=".8" />
                                <use href="#df-tiktok-note" fill="#fff" />
                            </g>
                        </svg></a>
                    <a href="#" aria-label="Instagram"><svg viewBox="0 0 24 24">
                            <defs>
                                <radialGradient id="df-instagram" cx="30%" cy="107%" r="150%">
                                    <stop offset="0" stop-color="#fdf497" />
                                    <stop offset=".05" stop-color="#fdf497" />
                                    <stop offset=".45" stop-color="#fd5949" />
                                    <stop offset=".6" stop-color="#d6249f" />
                                    <stop offset=".9" stop-color="#285aeb" />
                                </radialGradient>
                            </defs>
                            <rect width="24" height="24" rx="6" fill="url(#df-instagram)" />
                            <g fill="none" stroke="#fff" stroke-width="1.8">
                                <rect x="5" y="5" width="14" height="14" rx="4" />
                                <circle cx="12" cy="12" r="3.3" />
                            </g>
                            <circle cx="16.4" cy="7.6" r="1" fill="#fff" />
                        </svg></a>
                    <a href="#" aria-label="YouTube"><svg viewBox="0 0 24 24">
                            <path fill="#FF0000"
                                d="M23.5 6.2a3 3 0 0 0-2.1-2.1C19.5 3.6 12 3.6 12 3.6s-7.5 0-9.4.5A3 3 0 0 0 .5 6.2 31 31 0 0 0 0 12a31 31 0 0 0 .5 5.8 3 3 0 0 0 2.1 2.1c1.9.5 9.4.5 9.4.5s7.5 0 9.4-.5a3 3 0 0 0 2.1-2.1A31 31 0 0 0 24 12a31 31 0 0 0-.5-5.8Z" />
                            <path fill="#fff" d="M9.6 15.6 15.8 12 9.6 8.4Z" />
                        </svg></a>
                </div>
            </div>
        </div>

        <div class="df-foot__bar">
            <div class="df-wrap df-foot__bar-row">
                <p>
                    &copy; 2020 {{ $storeName }} Design Themes
                    <span class="df-foot__by">
                        Developed by
                        <a href="https://zarosoft.com" target="_blank" rel="noopener noreferrer">zarosoft.com</a>
                    </span>
                </p>
                <nav class="df-foot__bar-links">
                    <a href="{{ route('contact') }}">Contact</a>
                    <a href="{{ route('terms') }}">Terms &amp; Conditions</a>
                    <a href="{{ route('transparency') }}">Transparency</a>
                    <a href="{{ route('privacy') }}">Privacy Policy</a>
                </nav>
                <a href="#top" class="df-top" aria-label="Back to top">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                        <path stroke-linecap="round" d="m6 14 6-6 6 6" />
                    </svg>
                </a>
            </div>
        </div>
    </footer>

</body>

</html>
