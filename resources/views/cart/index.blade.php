@extends('layouts.app')

@section('title', 'Your cart')

@section('content')
    <div class="mx-auto max-w-page px-4 py-8 sm:px-6">
        <h1 class="text-2xl font-bold text-slate-900">Your cart</h1>

        @if($cart->items->isEmpty())
            <div class="card mt-8 grid place-items-center gap-3 p-16 text-center">
                <p class="text-lg font-semibold text-slate-900">Your cart is empty</p>
                <p class="text-sm text-slate-500">Browse the shop and add something you like.</p>
                <a href="{{ route('shop.index') }}" class="btn-primary mt-2">Start shopping</a>
            </div>
        @else
            <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_360px]">
                <div class="card divide-y divide-slate-100">
                    @foreach($cart->items as $item)
                        <div class="flex flex-wrap items-center gap-4 p-4">
                            <a href="{{ route('shop.show', $item->product) }}" class="h-20 w-20 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                                <img src="{{ $item->product->image_url }}" alt="{{ $item->product->name }}" class="h-full w-full object-cover">
                            </a>

                            <div class="min-w-0 flex-1">
                                <a href="{{ route('shop.show', $item->product) }}" class="text-sm font-semibold text-slate-900 hover:text-brand-600">
                                    {{ $item->product->name }}
                                </a>
                                @if($item->product->has_variants)
                                    <p class="mt-0.5 text-xs font-medium text-slate-600">{{ $item->variant->label }}</p>
                                @endif
                                <p class="mt-0.5 text-xs text-slate-500">@money($item->price) each</p>
                                @if($item->quantity > $item->variant->stock)
                                    <p class="mt-1 text-xs font-medium text-rose-600">Only {{ max(0, $item->variant->stock) }} left in stock</p>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('cart.update', $item) }}" class="flex items-center gap-2">
                                @csrf @method('PATCH')
                                <input type="number" name="quantity" value="{{ $item->quantity }}" min="0" max="99"
                                       class="input w-20 text-center" aria-label="Quantity">
                                <button type="submit" class="btn-secondary px-3">Update</button>
                            </form>

                            <p class="w-24 text-right text-sm font-bold text-slate-900">@money($item->subtotal())</p>

                            <form method="POST" action="{{ route('cart.destroy', $item) }}">
                                @csrf @method('DELETE')
                                <button class="rounded-lg p-2 text-slate-400 hover:bg-rose-50 hover:text-rose-600" aria-label="Remove">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/></svg>
                                </button>
                            </form>
                        </div>
                    @endforeach

                    <div class="flex items-center justify-between p-4">
                        <a href="{{ route('shop.index') }}" class="text-sm font-medium text-brand-600 hover:underline">&larr; Continue shopping</a>
                        <form method="POST" action="{{ route('cart.clear') }}">
                            @csrf @method('DELETE')
                            <button class="text-sm text-rose-600 hover:underline">Clear cart</button>
                        </form>
                    </div>
                </div>

                <aside class="card h-fit p-5">
                    <h2 class="text-base font-bold text-slate-900">Order summary</h2>

                    <dl class="mt-4 space-y-2.5 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-slate-600">Subtotal</dt>
                            <dd class="font-medium text-slate-900">@money($totals['subtotal'])</dd>
                        </div>
                        @if($totals['discount'] > 0)
                            <div class="flex justify-between text-emerald-600">
                                <dt>Discount ({{ $totals['coupon']->code }})</dt>
                                <dd class="font-medium">-@money($totals['discount'])</dd>
                            </div>
                        @endif
                        <div class="flex justify-between">
                            <dt class="text-slate-600">Shipping</dt>
                            <dd class="font-medium text-slate-900">
                                {{ $totals['shipping'] > 0 ? \App\Support\Money::format($totals['shipping']) : 'Free' }}
                            </dd>
                        </div>
                        <div class="flex justify-between border-t border-slate-200 pt-3 text-base">
                            <dt class="font-bold text-slate-900">Total</dt>
                            <dd class="font-bold text-slate-900">@money($totals['total'])</dd>
                        </div>
                    </dl>

                    @if($totals['shipping'] > 0 && \App\Services\CartService::freeShippingFrom() > 0)
                        <p class="mt-3 rounded-lg bg-brand-50 px-3 py-2 text-xs text-brand-700">
                            Add @money(\App\Services\CartService::freeShippingFrom() - $totals['subtotal']) more for free delivery.
                        </p>
                    @endif

                    <div class="mt-5 border-t border-slate-200 pt-5">
                        @if($totals['coupon'])
                            <div class="flex items-center justify-between rounded-lg bg-emerald-50 px-3 py-2 text-sm">
                                <span class="font-semibold text-emerald-800">{{ $totals['coupon']->code }} applied</span>
                                <form method="POST" action="{{ route('cart.coupon.remove') }}">
                                    @csrf @method('DELETE')
                                    <button class="text-xs text-emerald-700 hover:underline">Remove</button>
                                </form>
                            </div>
                        @else
                            <form method="POST" action="{{ route('cart.coupon.apply') }}" class="flex gap-2">
                                @csrf
                                <input type="text" name="code" placeholder="Coupon code" class="input uppercase">
                                <button type="submit" class="btn-secondary">Apply</button>
                            </form>
                        @endif
                    </div>

                    <a href="{{ route('checkout.index') }}" class="btn-primary mt-5 w-full">Proceed to checkout</a>
                </aside>
            </div>
        @endif
    </div>
@endsection
