@extends('layouts.admin')

@section('title', 'Dashboard')
@section('heading', 'Dashboard')

@section('content')
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            ['Revenue (all time)', $canSeeFinance ? \App\Support\Money::format($revenue, false) : 'Restricted', $canSeeFinance ? 'text-emerald-600' : 'text-slate-300'],
            ['Revenue this month', $canSeeFinance ? \App\Support\Money::format($revenueThisMonth, false) : 'Restricted', $canSeeFinance ? 'text-brand-600' : 'text-slate-300'],
            ['Orders', number_format($orderCount) . ($pendingCount ? ' (' . $pendingCount . ' pending)' : ''), 'text-slate-900'],
            ['Products / Customers', number_format($productCount) . ' / ' . number_format($customerCount), 'text-slate-900'],
        ] as [$label, $value, $color])
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-bold {{ $color }}">{{ $value }}</p>
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
