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
        ['Sales', [
            ['admin.orders.index', 'Orders', 'M3 3h2l2.4 12.1a2 2 0 002 1.6h7.7a2 2 0 002-1.6L21 7H6', 'admin.orders.*', 'orders.view'],
            ['admin.customers.index', 'Customers', 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z', 'admin.customers.*', 'customers.view'],
            ['admin.coupons.index', 'Coupons', 'M9 7h6m-6 4h6m-8 8h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v12a2 2 0 002 2z', 'admin.coupons.*', 'marketing.manage'],
        ]],
        ['Catalogue', [
            ['admin.products.index', 'Products', 'M20 7l-8-4-8 4m16 0v10l-8 4-8-4V7m16 0l-8 4m0 0L4 7m8 4v10', 'admin.products.*', 'products.view'],
            ['admin.categories.index', 'Categories', 'M4 6h16M4 12h16M4 18h16', 'admin.categories.*', 'products.view'],
            ['admin.brands.index', 'Brands', 'M7 7h.01M7 3h5a2 2 0 011.4.6l7 7a2 2 0 010 2.8l-5 5a2 2 0 01-2.8 0l-7-7A2 2 0 013 10V5a2 2 0 012-2z', 'admin.brands.*', 'products.view'],
            ['admin.barcodes.index', 'Barcode labels', 'M4 5v14M7 5v14M10 5v14M14 5v14M17 5v14M20 5v14', 'admin.barcodes.*', 'products.view'],
        ]],
        ['Inventory', [
            ['admin.inventory.index', 'Stock overview', 'M4 6h16M4 10h16M4 14h10M4 18h10', 'admin.inventory.index', 'inventory.view'],
            ['admin.inventory.count', 'Stock count', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', 'admin.inventory.count', 'inventory.adjust'],
            ['admin.stock.index', 'Stock history', 'M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4', 'admin.stock.*', 'inventory.view'],
        ]],
        ['Finance', [
            ['admin.accounts.index', 'Accounts', 'M3 10h18M5 10V20M9 10V20M15 10V20M19 10V20M3 20h18M12 3l9 5H3l9-5z', 'admin.accounts.*', 'accounting.view'],
        ]],
        ['Administration', [
            ['admin.staff.index', 'Staff', 'M17 20h5v-2a3 3 0 00-5.4-1.8M9 20H4v-2a3 3 0 015.4-1.8M15 7a3 3 0 11-6 0 3 3 0 016 0z', 'admin.staff.*', 'staff.view'],
            ['admin.roles.index', 'Roles & permissions', 'M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z', 'admin.roles.*', 'staff.view'],
            ['admin.settings.edit', 'Settings', 'M10.3 4.3a1.7 1.7 0 013.4 0 1.7 1.7 0 002.6 1.1 1.7 1.7 0 012.3 2.3 1.7 1.7 0 001.1 2.6 1.7 1.7 0 010 3.4 1.7 1.7 0 00-1.1 2.6 1.7 1.7 0 01-2.3 2.3 1.7 1.7 0 00-2.6 1.1 1.7 1.7 0 01-3.4 0 1.7 1.7 0 00-2.6-1.1 1.7 1.7 0 01-2.3-2.3 1.7 1.7 0 00-1.1-2.6 1.7 1.7 0 010-3.4 1.7 1.7 0 001.1-2.6 1.7 1.7 0 012.3-2.3 1.7 1.7 0 002.6-1.1zM15 12a3 3 0 11-6 0 3 3 0 016 0z', 'admin.settings.*', 'settings.manage'],
            ['admin.activity.index', 'Activity log', 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', 'admin.activity.*', 'audit.view'],
        ]],
    ];
@endphp

<div class="flex min-h-screen">
    <aside x-bind:class="sidebar ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="fixed inset-y-0 left-0 z-40 flex w-64 transform flex-col bg-slate-900 text-slate-300 transition-transform lg:static lg:transform-none">
        <div class="flex h-16 items-center gap-2 border-b border-white/10 px-5">
            <span class="grid h-8 w-8 place-items-center rounded-lg bg-brand-600 text-sm font-bold text-white">{{ strtoupper(substr(\App\Support\Settings::get('store_name'), 0, 1)) }}</span>
            <span class="truncate font-bold text-white">{{ \App\Support\Settings::get('store_name') }} Admin</span>
        </div>

        <nav class="flex-1 space-y-4 overflow-y-auto p-3">
            @foreach($sections as [$section, $items])
                @php $visible = array_filter($items, fn ($item) => $item[4] === null || $admin->can($item[4])); @endphp
                @continue(! $visible)

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
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="w-full rounded-lg px-3 py-2.5 text-left text-sm text-rose-400 hover:bg-white/10">Log out</button>
            </form>
        </div>
    </aside>

    <div x-show="sidebar" x-cloak @click="sidebar = false" class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"></div>

    <div class="flex min-w-0 flex-1 flex-col">
        <header class="flex h-16 items-center gap-4 border-b border-slate-200 bg-white px-4 sm:px-6">
            <button @click="sidebar = !sidebar" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden" aria-label="Menu">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>

            <h1 class="text-lg font-bold text-slate-900">@yield('heading', 'Dashboard')</h1>

            <div class="ml-auto flex items-center gap-3 text-sm">
                <span class="hidden text-right leading-tight sm:block">
                    <span class="block text-slate-700">{{ $admin->name }}</span>
                    <span class="block text-xs text-slate-400">{{ $admin->role?->name }}</span>
                </span>
                <span class="grid h-8 w-8 place-items-center rounded-full bg-brand-100 text-xs font-bold text-brand-700">
                    {{ strtoupper(substr($admin->name, 0, 1)) }}
                </span>
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
