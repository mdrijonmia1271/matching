@extends('layouts.app')

@section('title', 'Order ' . $order->order_number)

@section('content')
    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        <div class="card p-8 text-center">
            <span class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-emerald-100 text-emerald-600">
                <svg class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7"/></svg>
            </span>

            <h1 class="mt-5 text-2xl font-bold text-slate-900">Thank you for your order!</h1>
            <p class="mt-2 text-sm text-slate-600">
                Order <span class="font-semibold text-slate-900">{{ $order->order_number }}</span> has been placed.
                A confirmation was sent to {{ $order->customer_email }}.
            </p>

            <div class="mt-6 flex flex-wrap justify-center gap-2 text-xs">
                <span class="rounded-full px-3 py-1 font-semibold {{ $order->statusColor() }}">{{ ucfirst($order->status) }}</span>
                <span class="rounded-full bg-slate-100 px-3 py-1 font-semibold text-slate-700">
                    {{ $order->payment_method === 'cod' ? 'Cash on delivery' : 'Online payment' }} &middot; {{ $order->payment_status_label }}
                </span>
            </div>
        </div>

        <div class="card mt-6 p-6">
            <h2 class="text-base font-bold text-slate-900">Order summary</h2>

            <div class="mt-4 divide-y divide-slate-100">
                @foreach($order->items as $item)
                    <div class="flex items-center justify-between gap-4 py-3 text-sm">
                        <div>
                            <p class="font-medium text-slate-900">{{ $item->product_name }}</p>
                            <p class="text-xs text-slate-500">{{ $item->variant_label ? $item->variant_label . ' · ' : '' }}{{ $item->quantity }} &times; @money($item->price)</p>
                        </div>
                        <p class="font-semibold text-slate-900">@money($item->subtotal)</p>
                    </div>
                @endforeach
            </div>

            <dl class="mt-4 space-y-2 border-t border-slate-200 pt-4 text-sm">
                <div class="flex justify-between"><dt class="text-slate-600">Subtotal</dt><dd>@money($order->subtotal)</dd></div>
                @if($order->discount > 0)
                    <div class="flex justify-between text-emerald-600"><dt>Discount{{ $order->coupon_code ? ' (' . $order->coupon_code . ')' : '' }}</dt><dd>-@money($order->discount)</dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-slate-600">Shipping</dt><dd>{{ $order->shipping_cost > 0 ? \App\Support\Money::format($order->shipping_cost) : 'Free' }}</dd></div>
                <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-bold"><dt>Total</dt><dd>@money($order->total)</dd></div>
            </dl>

            <div class="mt-6 border-t border-slate-200 pt-4 text-sm">
                <p class="font-semibold text-slate-900">Delivering to</p>
                <p class="mt-1 text-slate-600">
                    {{ $order->customer_name }}<br>
                    {{ $order->shipping_address }}{{ $order->shipping_city ? ', ' . $order->shipping_city : '' }}<br>
                    {{ $order->customer_phone }}
                </p>
            </div>
        </div>

        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="{{ route('shop.index') }}" class="btn-primary">Continue shopping</a>
            @auth
                <a href="{{ route('orders.show', $order) }}" class="btn-secondary">View order</a>
            @endauth
        </div>
    </div>
@endsection
