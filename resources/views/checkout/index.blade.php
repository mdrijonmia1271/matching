@extends('layouts.app')

@section('title', 'Checkout')

@section('content')
    <div class="mx-auto max-w-page px-4 py-8 sm:px-6">
        <h1 class="text-2xl font-bold text-slate-900">Checkout</h1>

        <form method="POST" action="{{ route('checkout.store') }}" class="mt-8 grid gap-8 lg:grid-cols-[1fr_380px]">
            @csrf

            <div class="space-y-6">
                <section class="card p-6">
                    <h2 class="text-base font-bold text-slate-900">Delivery details</h2>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="customer_name" class="label">Full name</label>
                            <input id="customer_name" name="customer_name" type="text" required
                                   value="{{ old('customer_name', auth()->user()->name ?? '') }}" class="input">
                        </div>
                        <div>
                            <label for="customer_phone" class="label">Phone</label>
                            <input id="customer_phone" name="customer_phone" type="tel" required
                                   value="{{ old('customer_phone', auth()->user()->phone ?? '') }}" class="input" placeholder="01XXXXXXXXX">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="customer_email" class="label">Email</label>
                            <input id="customer_email" name="customer_email" type="email" required
                                   value="{{ old('customer_email', auth()->user()->email ?? '') }}" class="input">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="shipping_address" class="label">Street address</label>
                            <textarea id="shipping_address" name="shipping_address" rows="3" required class="input">{{ old('shipping_address', auth()->user()->address ?? '') }}</textarea>
                        </div>
                        <div>
                            <label for="shipping_city" class="label">City</label>
                            <input id="shipping_city" name="shipping_city" type="text" value="{{ old('shipping_city') }}" class="input">
                        </div>
                        <div>
                            <label for="note" class="label">Order note <span class="text-slate-400">(optional)</span></label>
                            <input id="note" name="note" type="text" value="{{ old('note') }}" class="input" placeholder="Delivery instructions">
                        </div>
                    </div>
                </section>

                <section class="card p-6">
                    <h2 class="text-base font-bold text-slate-900">Payment method</h2>

                    <div class="mt-4 space-y-3">
                        @foreach($gateways as $key => $gateway)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-4 transition hover:border-brand-300 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50">
                                <input type="radio" name="payment_method" value="{{ $key }}"
                                       @checked(old('payment_method', 'cod') === $key)
                                       class="mt-0.5 text-brand-600 focus:ring-brand-500" required>
                                <span>
                                    <span class="block text-sm font-semibold text-slate-900">{{ $gateway->label() }}</span>
                                    <span class="block text-xs text-slate-500">{{ $gateway->description() }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </section>
            </div>

            <aside class="card h-fit p-5">
                <h2 class="text-base font-bold text-slate-900">Your order</h2>

                <div class="mt-4 max-h-72 space-y-3 overflow-y-auto pr-1">
                    @foreach($cart->items as $item)
                        <div class="flex items-center gap-3">
                            <div class="relative h-14 w-14 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                                <img src="{{ $item->product->image_url }}" alt="" class="h-full w-full object-cover">
                                <span class="absolute -right-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full bg-slate-900 px-1 text-[11px] font-bold text-white">{{ $item->quantity }}</span>
                            </div>
                            <p class="min-w-0 flex-1 truncate text-sm text-slate-700">
                                {{ $item->product->name }}
                                @if($item->product->has_variants)<span class="block text-xs text-slate-500">{{ $item->variant->label }}</span>@endif
                            </p>
                            <p class="text-sm font-semibold text-slate-900">@money($item->subtotal())</p>
                        </div>
                    @endforeach
                </div>

                <dl class="mt-5 space-y-2.5 border-t border-slate-200 pt-4 text-sm">
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
                        <dd class="font-medium text-slate-900">{{ $totals['shipping'] > 0 ? \App\Support\Money::format($totals['shipping']) : 'Free' }}</dd>
                    </div>
                    <div class="flex justify-between border-t border-slate-200 pt-3 text-base">
                        <dt class="font-bold text-slate-900">Total</dt>
                        <dd class="font-bold text-slate-900">@money($totals['total'])</dd>
                    </div>
                </dl>

                <button type="submit" class="btn-primary mt-5 w-full">Place order</button>

                <a href="{{ route('cart.index') }}" class="mt-3 block text-center text-sm text-slate-500 hover:text-brand-600">Back to cart</a>
            </aside>
        </form>
    </div>
@endsection
