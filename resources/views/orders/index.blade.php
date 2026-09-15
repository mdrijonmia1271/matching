@extends('layouts.app')

@section('title', 'My orders')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6">
        <h1 class="text-2xl font-bold text-slate-900">My orders</h1>

        @if($orders->isEmpty())
            <div class="card mt-8 grid place-items-center gap-3 p-16 text-center">
                <p class="text-lg font-semibold text-slate-900">No orders yet</p>
                <p class="text-sm text-slate-500">Once you place an order it will show up here.</p>
                <a href="{{ route('shop.index') }}" class="btn-primary mt-2">Start shopping</a>
            </div>
        @else
            <div class="mt-8 space-y-4">
                @foreach($orders as $order)
                    <div class="card flex flex-wrap items-center gap-4 p-5">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-3">
                                <a href="{{ route('orders.show', $order) }}" class="text-sm font-bold text-slate-900 hover:text-brand-600">{{ $order->order_number }}</a>
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $order->statusColor() }}">{{ $order->status_label }}</span>
                                @if($order->payment_status === 'paid')
                                    <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">Paid</span>
                                @elseif($order->payment_method !== 'cod')
                                    <span class="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-semibold text-rose-800">{{ $order->payment_status_label }}</span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $order->created_at->format('d M Y, g:i a') }} &middot; {{ $order->items_count }} item(s)
                            </p>
                        </div>

                        <p class="text-base font-bold text-slate-900">@money($order->total)</p>

                        <div class="flex gap-2">
                            <a href="{{ route('orders.show', $order) }}" class="btn-secondary">Details</a>

                            @if($order->payment_method !== 'cod' && $order->payment_status !== 'paid' && $order->status !== 'cancelled')
                                <form method="POST" action="{{ route('orders.pay', $order) }}">
                                    @csrf
                                    <button class="btn-primary">Pay now</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-8">{{ $orders->links() }}</div>
        @endif
    </div>
@endsection
