@extends('layouts.admin')

@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')
    @php
        $money = fn ($value) => $value === null ? 'Restricted' : \App\Support\Money::format($value, false);
        $moneyColor = fn ($value, $color) => $value === null ? 'text-slate-300' : $color;
    @endphp

    <div class="mb-3 flex items-baseline justify-between">
        <h2 class="text-base font-bold text-slate-900">Today</h2>
        <span class="text-xs text-slate-500">{{ today()->format('l, j F Y') }}</span>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach([
            ["Today's purchase", $money($today['purchase']), $moneyColor($today['purchase'], 'text-slate-900'),
                $today['purchase_count'] !== null ? $today['purchase_count'] . ' received' : null],
            ["Today's sales", $money($today['sales']), $moneyColor($today['sales'], 'text-brand-600'),
                $today['sales_count'] !== null ? $today['sales_count'] . ' order(s)' : null],
            ["Today's due", $money($today['due']), $moneyColor($today['due'], 'text-amber-600'), 'Unpaid on today\'s sales'],
            ["Today's paid", $money($today['paid']), $moneyColor($today['paid'], 'text-emerald-600'), 'Money received today'],
            ["Today's cost", $money($today['cost']), $moneyColor($today['cost'], 'text-slate-900'), 'Purchase cost of items sold'],
            ["Today's income", $money($today['income']), $moneyColor($today['income'], ($today['income'] ?? 0) < 0 ? 'text-rose-600' : 'text-emerald-600'), 'Sales − cost'],
            ["Today's stock in", number_format($today['stock_in']) . ' pcs', 'text-emerald-600', null],
            ["Today's stock out", number_format($today['stock_out']) . ' pcs', 'text-rose-600', null],
            ['Present stock', number_format($today['stock_units']) . ' pcs', 'text-slate-900',
                $today['stock_value'] !== null ? 'Worth ' . \App\Support\Money::format($today['stock_value'], false) . ' at cost' : null],
        ] as [$label, $value, $color, $hint])
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-bold {{ $color }}">{{ $value }}</p>
                @if($hint)<p class="mt-1 text-xs text-slate-400">{{ $hint }}</p>@endif
            </div>
        @endforeach
    </div>

    <h2 class="mb-3 mt-8 text-base font-bold text-slate-900">Overall</h2>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach([
            ['Total purchase', $money($overall['purchase']), $moneyColor($overall['purchase'], 'text-slate-900'),
                $overall['purchase_count'] !== null ? number_format($overall['purchase_count']) . ' received' : null],
            ['Total sales', $money($overall['sales']), $moneyColor($overall['sales'], 'text-brand-600'),
                $overall['sales_count'] !== null ? number_format($overall['sales_count']) . ' order(s)' : null],
            ['Total due', $money($overall['due']), $moneyColor($overall['due'], 'text-amber-600'), 'Still unpaid on all sales'],
            ['Total paid', $money($overall['paid']), $moneyColor($overall['paid'], 'text-emerald-600'), 'All money received'],
            ['Total cost', $money($overall['cost']), $moneyColor($overall['cost'], 'text-slate-900'), 'Purchase cost of items sold'],
            ['Total income', $money($overall['income']), $moneyColor($overall['income'], ($overall['income'] ?? 0) < 0 ? 'text-rose-600' : 'text-emerald-600'), 'Sales − cost'],
            ['Revenue this month', $money($revenueThisMonth), $moneyColor($revenueThisMonth, 'text-brand-600'), null],
            ['Orders', number_format($orderCount), 'text-slate-900', $pendingCount ? $pendingCount . ' pending' : null],
            ['Products / Customers', number_format($productCount) . ' / ' . number_format($customerCount), 'text-slate-900', null],
        ] as [$label, $value, $color, $hint])
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-bold {{ $color }}">{{ $value }}</p>
                @if($hint)<p class="mt-1 text-xs text-slate-400">{{ $hint }}</p>@endif
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h2 class="text-base font-bold text-slate-900">Recent orders</h2>
                <a href="{{ route('admin.orders.index') }}" class="text-sm font-medium text-brand-600 hover:underline">View all</a>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Order</th>
                            <th class="px-5 py-3">Customer</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($recentOrders as $order)
                            <tr class="hover:bg-slate-50">
                                <td class="px-5 py-3">
                                    <a href="{{ route('admin.orders.show', $order) }}" class="font-semibold text-brand-600 hover:underline">{{ $order->order_number }}</a>
                                    <p class="text-xs text-slate-400">{{ $order->created_at->diffForHumans() }}</p>
                                </td>
                                <td class="px-5 py-3 text-slate-700">{{ $order->customer_name }}</td>
                                <td class="px-5 py-3">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $order->statusColor() }}">{{ ucfirst($order->status) }}</span>
                                </td>
                                <td class="px-5 py-3 text-right font-semibold text-slate-900">@money($order->total)</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-5 py-8 text-center text-slate-500">No orders yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <h2 class="text-base font-bold text-slate-900">Low stock</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse($lowStock as $variant)
                        <li class="flex items-center justify-between gap-2">
                            <a href="{{ route('admin.stock.index', ['variant' => $variant->id]) }}" class="min-w-0 truncate text-slate-700 hover:text-brand-600">
                                {{ $variant->product?->name }}
                                @if($variant->product?->has_variants)<span class="text-xs text-slate-400">{{ $variant->label }}</span>@endif
                            </a>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-bold {{ $variant->stock <= 0 ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700' }}">
                                {{ $variant->stock }}
                            </span>
                        </li>
                    @empty
                        <li class="text-slate-500">Everything is well stocked.</li>
                    @endforelse
                </ul>
            </div>

            <div class="card p-5">
                <h2 class="text-base font-bold text-slate-900">Best sellers</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse($topProducts as $product)
                        <li class="flex items-center justify-between gap-2">
                            <span class="truncate text-slate-700">{{ $product->name }}</span>
                            <span class="shrink-0 text-xs font-bold text-slate-500">{{ (int) $product->sold }} sold</span>
                        </li>
                    @empty
                        <li class="text-slate-500">No sales yet.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection
