@extends('layouts.app')

@section('title', 'Order ' . $order->order_number)

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6">
        <a href="{{ route('orders.index') }}" class="text-sm text-slate-500 hover:text-brand-600">&larr; Back to orders</a>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">{{ $order->order_number }}</h1>
                <p class="mt-1 text-sm text-slate-500">Placed {{ $order->created_at->format('d M Y, g:i a') }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $order->statusColor() }}">{{ $order->status_label }}</span>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                    {{ $order->payment_method_label }} &middot; {{ $order->payment_status_label }}
                </span>
            </div>
        </div>

        <div class="mt-8 grid gap-6 lg:grid-cols-[1fr_320px]">
            <div class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Items</h2>

                <div class="mt-4 divide-y divide-slate-100">
                    @foreach($order->items as $item)
                        <div class="flex items-center gap-4 py-3">
                            <div class="h-14 w-14 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                                @if($item->product)
                                    <img src="{{ $item->product->image_url }}" alt="" class="h-full w-full object-cover">
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                @if($item->product)
                                    <a href="{{ route('shop.show', $item->product) }}" class="text-sm font-medium text-slate-900 hover:text-brand-600">{{ $item->product_name }}</a>
                                @else
                                    <p class="text-sm font-medium text-slate-900">{{ $item->product_name }}</p>
                                @endif
                                @if($item->variant_label)
                                    <p class="text-xs font-medium text-slate-600">{{ $item->variant_label }}</p>
                                @endif
                                <p class="text-xs text-slate-500">{{ $item->quantity }} &times; @money($item->price)</p>
                            </div>
                            <p class="text-sm font-semibold text-slate-900">@money($item->subtotal)</p>
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
                    @if($order->paid_amount > 0 && $order->due_amount > 0)
                        <div class="flex justify-between text-emerald-700"><dt>Paid</dt><dd>@money($order->paid_amount)</dd></div>
                        <div class="flex justify-between font-semibold text-rose-600"><dt>Due</dt><dd>@money($order->due_amount)</dd></div>
                    @endif
                </dl>
            </div>

            <aside class="space-y-6">
                <div class="card p-6">
                    <h2 class="text-base font-bold text-slate-900">Delivery</h2>
                    <p class="mt-3 text-sm text-slate-600">
                        {{ $order->customer_name }}<br>
                        {{ $order->shipping_address }}{{ $order->shipping_city ? ', ' . $order->shipping_city : '' }}<br>
                        {{ $order->customer_phone }}<br>
                        {{ $order->customer_email }}
                    </p>
                    @if($order->note)
                        <p class="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-600"><span class="font-semibold">Note:</span> {{ $order->note }}</p>
                    @endif
                    @if($order->tracking_number)
                        <p class="mt-3 rounded-lg bg-indigo-50 p-3 text-xs text-indigo-800">
                            <span class="font-semibold">{{ $order->courier_name ?: 'Courier' }}</span> tracking number:
                            <span class="font-mono">{{ $order->tracking_number }}</span>
                        </p>
                    @endif
                </div>

                @if($order->statusHistories->isNotEmpty())
                    <div class="card p-6">
                        <h2 class="text-base font-bold text-slate-900">Order progress</h2>
                        <ul class="mt-3 space-y-2 text-sm">
                            @foreach($order->statusHistories as $history)
                                <li class="flex justify-between gap-3">
                                    <span class="font-medium text-slate-800">{{ $history->to_label }}</span>
                                    <span class="text-xs text-slate-500">{{ $history->created_at?->format('d M, g:i a') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($order->payments->isNotEmpty())
                    <div class="card p-6">
                        <h2 class="text-base font-bold text-slate-900">Payments</h2>
                        <ul class="mt-3 space-y-2 text-xs text-slate-600">
                            @foreach($order->payments as $payment)
                                <li class="flex justify-between gap-2">
                                    <span class="font-mono">{{ $payment->transaction_id ?? '-' }}</span>
                                    <span class="font-semibold {{ $payment->status === 'success' ? 'text-emerald-600' : ($payment->status === 'failed' ? 'text-rose-600' : 'text-amber-600') }}">
                                        {{ ucfirst($payment->status) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="space-y-3">
                    @if($order->canPayOnline())
                        <form method="POST" action="{{ route('orders.pay', $order) }}">
                            @csrf
                            <button class="btn-primary w-full">Pay now</button>
                        </form>
                    @endif

                    @if($order->canBeCancelledByCustomer())
                        <form method="POST" action="{{ route('orders.cancel', $order) }}"
                              onsubmit="return confirm('Cancel this order? Stock will be released.')">
                            @csrf
                            <button class="btn-secondary w-full text-rose-600">Cancel order</button>
                        </form>
                    @endif
                </div>
            </aside>
        </div>
    </div>
@endsection
