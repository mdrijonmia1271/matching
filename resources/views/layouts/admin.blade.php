<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Admin') &mdash; {{ \App\Support\Settings::get('store_name') }} Admin</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="min-h-full bg-slate-100 font-sans text-slate-800 antialiased" x-data="{ sidebar: false }">

@php
    $admin = auth()->user();

    // [section, [[route name, label, icon path, active-state pattern, permission|null], ...]]
    $sections = [
        [null, [
            ['admin.dashboard', 'Dashboard', 'M3 12l9-9 9 9M5 10v10h14V10', 'admin.dashboard', null],
        ]],
        ['Product / Catalogue', [
            ['admin.categories.index', 'Categories', 'M9 5l7 7-7 7', 'admin.categories.*', 'products.view'],
            ['admin.products.index', 'Products', 'M9 5l7 7-7 7', 'admin.products.*', 'products.view'],
            ['admin.purchases.index', 'Purchases', 'M9 5l7 7-7 7', 'admin.purchases.*', 'purchases.view'],
            ['admin.barcodes.index', 'Barcode labels', 'M9 5l7 7-7 7', 'admin.barcodes.*', 'products.view'],
        ], true, 'M4 5a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm9 0a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1h-5a1 1 0 01-1-1V5zm-9 9a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1v-5zm9 0a1 1 0 011-1h5a1 1 0 011 1v5a1 1 0 01-1 1h-5a1 1 0 01-1-1v-5z'],
        ['Sales', [
            ['admin.pos.index', 'Counter (POS)', 'M9 5l7 7-7 7', 'admin.pos.*', 'pos.sell'],
            ['admin.orders.index', 'Orders', 'M9 5l7 7-7 7', 'admin.orders.*', 'orders.view'],
            ['admin.advance-orders.index', 'Advance orders', 'M9 5l7 7-7 7', 'admin.advance-orders.*', 'orders.view'],
            ['admin.returns.index', 'Returns', 'M9 5l7 7-7 7', 'admin.returns.*', 'orders.view'],
            ['admin.coupons.index', 'Coupons', 'M9 5l7 7-7 7', 'admin.coupons.*', 'marketing.manage'],
        ], true, 'M3 3h2l2.4 12.1a2 2 0 002 1.6h7.7a2 2 0 002-1.6L21 7H6'],
        ['Customer / Supplier', [
            ['admin.customers.index', 'Customers', 'M9 5l7 7-7 7', 'admin.customers.*', 'customers.view'],
            ['admin.customer-dues.index', 'Customer dues', 'M9 5l7 7-7 7', 'admin.customer-dues.*', 'customers.view'],
            ['admin.suppliers.index', 'Suppliers', 'M9 5l7 7-7 7', 'admin.suppliers.*', 'purchases.view'],
        ], true, 'M17 20h5v-2a3 3 0 00-5.4-1.8M9 20H4v-2a3 3 0 015.4-1.8M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
        ['Stock', [
            ['admin.inventory.index', 'Stock overview', 'M9 5l7 7-7 7', 'admin.inventory.index', 'inventory.view'],
            ['admin.inventory.count', 'Stock count', 'M9 5l7 7-7 7', 'admin.inventory.count', 'inventory.adjust'],
            ['admin.stock.index', 'Stock history', 'M9 5l7 7-7 7', 'admin.stock.*', 'inventory.view'],
        ], true, 'M20 7l-8-4-8 4m16 0v10l-8 4-8-4V7m16 0l-8 4m0 0L4 7m8 4v10'],
        // Collapsible: the third element marks a section that folds under its heading;
        // an optional fourth is its heading icon (the chart icon otherwise).
        ['Team & Access', [
            ['admin.staff.index', 'Staff', 'M9 5l7 7-7 7', 'admin.staff.*', 'staff.view'],
            ['admin.roles.index', 'Role & Permission', 'M9 5l7 7-7 7', 'admin.roles.*', 'staff.view'],
        ], true, 'M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6zm-3 9l2 2 4-4'],
        ['Administration', [
            ['admin.settings.edit', 'Settings', 'M9 5l7 7-7 7', 'admin.settings.edit', 'settings.manage'],
            ['admin.settings.hero.edit', 'Hero section', 'M9 5l7 7-7 7', 'admin.settings.hero.*', 'settings.manage'],
            ['admin.settings.stats.edit', 'Stats strip', 'M9 5l7 7-7 7', 'admin.settings.stats.*', 'settings.manage'],
            ['admin.brands.index', 'Brands', 'M9 5l7 7-7 7', 'admin.brands.*', 'products.view'],
        ], true, 'M10.3 4.3a1.7 1.7 0 013.4 0 1.7 1.7 0 002.6 1.1 1.7 1.7 0 012.3 2.3 1.7 1.7 0 001.1 2.6 1.7 1.7 0 010 3.4 1.7 1.7 0 00-1.1 2.6 1.7 1.7 0 01-2.3 2.3 1.7 1.7 0 00-2.6 1.1 1.7 1.7 0 01-3.4 0 1.7 1.7 0 00-2.6-1.1 1.7 1.7 0 01-2.3-2.3 1.7 1.7 0 00-1.1-2.6 1.7 1.7 0 010-3.4 1.7 1.7 0 001.1-2.6 1.7 1.7 0 012.3-2.3 1.7 1.7 0 002.6-1.1zM15 12a3 3 0 11-6 0 3 3 0 016 0z'],
        ['Reports', [
            ['admin.reports.purchases', 'Purchase report', 'M9 5l7 7-7 7', 'admin.reports.purchases', 'reports.view'],
            ['admin.reports.sales', 'Sales report', 'M9 5l7 7-7 7', 'admin.reports.sales', 'reports.view'],
            ['admin.reports.income', 'Income report', 'M9 5l7 7-7 7', 'admin.reports.income', 'reports.view'],
            ['admin.reports.cost', 'Cost report', 'M9 5l7 7-7 7', 'admin.reports.cost', 'reports.view'],
            ['admin.reports.profit-loss', 'Profit / Loss', 'M9 5l7 7-7 7', 'admin.reports.profit-loss', 'reports.view'],
            ['admin.reports.sale-profit', 'Sale profit', 'M9 5l7 7-7 7', 'admin.reports.sale-profit', 'reports.view'],
            ['admin.reports.cash-book', 'Cash book', 'M9 5l7 7-7 7', 'admin.reports.cash-book', 'reports.view'],
        ], true],
        [null, [
            ['admin.accounts.index', 'Accounts', 'M3 10h18M5 10V20M9 10V20M15 10V20M19 10V20M3 20h18M12 3l9 5H3l9-5z', 'admin.accounts.*', 'accounting.view'],
            ['admin.newsletter.index', 'Newsletter', 'M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z', 'admin.newsletter.*', 'marketing.manage'],
            ['admin.activity.index', 'Activity log', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', 'admin.activity.*', 'audit.view'],
        ]],
    ];
@endphp

<div class="flex min-h-screen">
    <aside x-bind:class="sidebar ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="fixed inset-y-0 left-0 z-40 flex w-64 transform flex-col bg-slate-900 text-slate-300 transition-transform lg:static lg:transform-none">
        <div class="flex h-16 items-center gap-2 border-b border-white/10 px-5">
            <x-store-logo variant="admin" />
        </div>

        <nav class="flex-1 space-y-4 overflow-y-auto p-3">
            @foreach($sections as $entry)
                @php
                    [$section, $items] = $entry;
                    $visible = array_filter($items, fn ($item) => $item[4] === null || $admin->can($item[4]));
                    $collapsible = $entry[2] ?? false;
                    $sectionIcon = $entry[3] ?? 'M9 17v-2m3 2v-4m3 4v-6M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z';
                    $sectionActive = collect($visible)->contains(fn ($item) => request()->routeIs($item[3]));
                @endphp
                @continue(! $visible)

                @if($collapsible)
                    <div x-data="{ open: @js($sectionActive) }" class="space-y-1">
                        <button type="button" @click="open = !open" :aria-expanded="open"
                                class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-semibold transition {{ $sectionActive ? 'text-white' : 'hover:bg-white/10 hover:text-white' }}">
                            <svg class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $sectionIcon }}"/></svg>
                            <span class="flex-1 text-left">{{ $section }}</span>
                            <svg class="h-4 w-4 text-sky-400 transition-transform" :class="open ? '' : '-rotate-90'" fill="currentColor" viewBox="0 0 20 20"><path d="M5.3 7.3a1 1 0 011.4 0L10 10.6l3.3-3.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 010-1.4z"/></svg>
                        </button>
                        <div x-show="open" x-cloak class="space-y-0.5 pl-4">
                            @foreach($visible as [$route, $label, $icon, $pattern])
                                @php $active = request()->routeIs($pattern); @endphp
                                <a href="{{ route($route) }}"
                                   class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium transition {{ $active ? 'bg-brand-600 text-white' : 'hover:bg-white/10 hover:text-white' }}">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                                    {{ $label }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                    @continue
                @endif

                <div class="space-y-1">
                    @if($section)
                        <p class="px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $section }}</p>
                    @endif
                    @foreach($visible as [$route, $label, $icon, $pattern])
                        @php $active = request()->routeIs($pattern); @endphp
                        <a href="{{ route($route) }}"
                           class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ $active ? 'bg-brand-600 text-white' : 'hover:bg-white/10 hover:text-white' }}">
                            <svg class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/></svg>
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            @endforeach
        </nav>

        <div class="space-y-1 border-t border-white/10 p-3">
            <a href="{{ route('home') }}" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm hover:bg-white/10 hover:text-white">
                View storefront
            </a>
        </div>
    </aside>

    <div x-show="sidebar" x-cloak @click="sidebar = false" class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"></div>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="flex h-16 items-center gap-4 border-b border-slate-200 bg-white px-4 sm:px-6">
            <button @click="sidebar = !sidebar" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden" aria-label="Menu">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>

            <h1 class="text-lg font-bold text-slate-900">@yield('heading', 'Dashboard')</h1>

            {{-- Avatar menu: the signed-in staff member's own account. --}}
            <div class="relative ml-auto" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false">
                <button type="button" @click="open = ! open" :aria-expanded="open" aria-haspopup="menu"
                        class="flex items-center gap-3 rounded-lg px-2 py-1 text-sm transition hover:bg-slate-50">
                    <span class="hidden text-right leading-tight sm:block">
                        <span class="block text-slate-700">{{ $admin->name }}</span>
                        <span class="block text-xs text-slate-400">{{ $admin->role?->name }}</span>
                    </span>
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">
                        {{ strtoupper(substr($admin->name, 0, 1)) }}
                    </span>
                    <svg class="h-4 w-4 text-slate-400 transition-transform" :class="open ? 'rotate-180' : ''" fill="currentColor" viewBox="0 0 20 20"><path d="M5.3 7.3a1 1 0 011.4 0L10 10.6l3.3-3.3a1 1 0 111.4 1.4l-4 4a1 1 0 01-1.4 0l-4-4a1 1 0 010-1.4z"/></svg>
                </button>

                <div x-show="open" x-cloak x-transition.origin.top.right role="menu"
                     class="absolute right-0 z-50 mt-2 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 text-sm shadow-lg">
                    <div class="border-b border-slate-100 px-4 py-3">
                        <p class="truncate font-semibold text-slate-900">{{ $admin->name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ $admin->email }}</p>
                    </div>
                    <a href="{{ route('admin.profile.show') }}" role="menuitem" class="flex items-center gap-3 px-4 py-2.5 text-slate-700 hover:bg-slate-50">
                        <svg class="h-4.5 w-4.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        View profile
                    </a>
                    <a href="{{ route('admin.profile.password') }}" role="menuitem" class="flex items-center gap-3 px-4 py-2.5 text-slate-700 hover:bg-slate-50">
                        <svg class="h-4.5 w-4.5 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        Change password
                    </a>
                    <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100">
                        @csrf
                        <button role="menuitem" class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-rose-600 hover:bg-rose-50">
                            <svg class="h-4.5 w-4.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                            Log out
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6">
            <x-flash />
            <div class="mt-4">@yield('content')</div>
        </main>
    </div>
</div>

</body>
</html>
