@extends('layouts.admin')

@section('title', 'Orders')
@section('heading', 'Orders')

@section('content')
    <div class="card">
        <div class="flex flex-wrap gap-1 border-b border-slate-200 px-4 pt-3">
            @foreach(['' => 'All'] + \App\Models\Order::STATUS_LABELS as $key => $label)
                @php $count = $key === '' ? $statusCounts->sum() : ($statusCounts[$key] ?? 0); @endphp
                <a href="{{ route('admin.orders.index', array_filter(['status' => $key] + request()->except('status', 'page'))) }}"
                   class="rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium {{ (string) request('status') === (string) $key ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                    {{ $label }} <span class="text-xs text-slate-400">{{ $count }}</span>
                </a>
            @endforeach
        </div>

        <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-4">
            @if(request('status'))
                <input type="hidden" name="status" value="{{ request('status') }}">
            @endif
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Order no., name, phone, email or tracking no." class="input w-72">

            <select name="payment_status" class="input w-40">
                <option value="">Any payment</option>
                @foreach(\App\Models\Order::PAYMENT_STATUSES as $status => $label)
                    <option value="{{ $status }}" @selected(request('payment_status') === $status)>{{ $label }}</option>
                @endforeach
            </select>

            <input type="date" name="from" value="{{ request('from') }}" class="input w-40" aria-label="From date">
            <input type="date" name="to" value="{{ request('to') }}" class="input w-40" aria-label="To date">

            <button type="submit" class="btn-secondary">Filter</button>
            @if(request()->hasAny(['q', 'status', 'payment_status', 'from', 'to']))
                <a href="{{ route('admin.orders.index') }}" class="text-sm text-slate-500 hover:underline">Reset</a>
            @endif
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Order</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3 text-center">Items</th>
                        <th class="px-4 py-3">Payment</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($orders as $order)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.orders.show', $order) }}" class="font-semibold text-brand-600 hover:underline">{{ $order->order_number }}</a>
                                <p class="text-xs text-slate-400">{{ $order->created_at->format('d M Y, g:i a') }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-slate-800">{{ $order->customer_name }}</p>
                                <p class="text-xs text-slate-400">{{ $order->customer_phone }}</p>
                            </td>
                            <td class="px-4 py-3 text-center text-slate-600">{{ $order->items_count }}</td>
                            <td class="px-4 py-3">
                                <p class="text-xs text-slate-600">{{ $order->isPosSale() ? 'Counter' : ($order->payment_method === 'cod' ? 'COD' : 'Online') }}</p>
                                <span class="text-xs font-semibold {{ $order->payment_status === 'paid' ? 'text-emerald-600' : ($order->payment_status === 'unpaid' ? 'text-amber-600' : 'text-rose-600') }}">
                                    {{ $order->payment_status_label }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $order->statusColor() }}">{{ $order->status_label }}</span>
                                @if($order->tracking_number)
                                    <p class="mt-1 text-xs text-slate-500">{{ $order->courier_name }} <span class="font-mono">{{ $order->tracking_number }}</span></p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($order->total)</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No orders match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $orders->links() }}</div>
@endsection
