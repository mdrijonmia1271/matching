@extends('layouts.admin')

@section('title', 'Advance orders')
@section('heading', 'Advance orders')

@section('content')
    @php use App\Support\Money; @endphp

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Waiting</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($stats['open']) }}</p>
            <p class="text-xs text-slate-400">Booked, goods still on the shelf</p>
        </div>
        <a href="{{ route('admin.advance-orders.index', ['filter' => 'overdue']) }}" class="card p-5 transition hover:border-brand-300">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Past the date</p>
            <p class="mt-2 text-2xl font-bold {{ $stats['overdue'] > 0 ? 'text-rose-600' : 'text-slate-900' }}">{{ number_format($stats['overdue']) }}</p>
            <p class="text-xs text-slate-400">Expected before today</p>
        </a>
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Booked value</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">@money($stats['booked'])</p>
            <p class="text-xs text-slate-400">Goods promised but not handed over</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Advance held</p>
            <p class="mt-2 text-2xl font-bold text-emerald-700">@money($stats['advance'])</p>
            <p class="text-xs text-slate-400">Money already taken on those</p>
        </div>
    </div>

    <div class="card mt-6">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Order, customer or phone" class="input w-56">

                <select name="filter" class="input w-56" aria-label="Show">
                    @foreach($filters as $key => $label)
                        <option value="{{ $key }}" @selected($filter === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <input type="date" name="from" value="{{ request('from') }}" class="input w-40" aria-label="Booked from">
                <input type="date" name="to" value="{{ request('to') }}" class="input w-40" aria-label="Booked to">

                <button type="submit" class="btn-secondary">Filter</button>
                @if(request()->hasAny(['q', 'filter', 'from', 'to']))
                    <a href="{{ route('admin.advance-orders.index') }}" class="text-sm text-slate-500 hover:underline">Reset</a>
                @endif
            </form>

            <div class="ml-auto flex items-center gap-3">
                @can('reports.export')
                    <a href="{{ route('admin.advance-orders.export', request()->only(['q', 'filter', 'from', 'to'])) }}" class="btn-secondary">Export CSV</a>
                @endcan
                @can('orders.advance')
                    <a href="{{ route('admin.advance-orders.create') }}" class="btn-primary">New advance order</a>
                @endcan
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Order</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Expected</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-center">Stock</th>
                        <th class="px-4 py-3 text-right">Total</th>
                        <th class="px-4 py-3 text-right">Advance</th>
                        <th class="px-4 py-3 text-right">Due</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($orders as $order)
                        @php $overdue = $order->isAwaitingFulfilment() && $order->expected_at && $order->expected_at->isPast(); @endphp
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.orders.show', $order) }}" class="font-mono font-semibold text-brand-600 hover:underline">{{ $order->order_number }}</a>
                                <span class="block text-xs text-slate-400">{{ $order->created_at->format('d M Y') }} &middot; {{ $order->items_count }} item(s)</span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="block font-medium text-slate-800">{{ $order->customer_name }}</span>
                                <span class="block font-mono text-xs text-slate-400">{{ $order->customer_phone ?: '—' }}</span>
                            </td>
                            <td class="px-4 py-3 {{ $overdue ? 'font-semibold text-rose-600' : 'text-slate-700' }}">
                                {{ $order->expected_at?->format('d M Y') ?? '—' }}
                                @if($overdue)
                                    <span class="block text-xs">{{ $order->expected_at->diffForHumans() }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-full px-2 py-1 text-xs {{ $order->statusColor() }}">{{ $order->status_label }}</span>
                            </td>
                            <td class="px-4 py-3 text-center text-xs">
                                @if($order->hasStockLeft())
                                    <span class="text-slate-500">Handed over</span>
                                @elseif($order->status === 'cancelled')
                                    <span class="text-slate-400">Never left</span>
                                @else
                                    <span class="text-amber-600">On the shelf</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right text-slate-900">@money($order->total)</td>
                            <td class="px-4 py-3 text-right text-emerald-700">@money($order->paid_amount)</td>
                            <td class="px-4 py-3 text-right {{ $order->due_amount > 0 ? 'font-semibold text-rose-600' : 'text-slate-400' }}">
                                @money($order->due_amount)
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-slate-500">
                                No advance orders here.
                                @can('orders.advance')
                                    <a href="{{ route('admin.advance-orders.create') }}" class="text-brand-600 hover:underline">Take the first one.</a>
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $orders->links() }}</div>
@endsection
