@extends('layouts.admin')

@section('title', 'Settings')
@section('heading', 'Store settings')

@section('content')
    <form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" class="max-w-4xl space-y-6">
        @csrf @method('PUT')

        <section class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Store</h2>
            <p class="mt-1 text-sm text-slate-500">Shown on the storefront, invoices and barcode labels.</p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="store_name" class="label">Store name</label>
                    <input id="store_name" name="store_name" type="text" required maxlength="120" value="{{ old('store_name', $settings['store_name']) }}" class="input">
                </div>
                <div>
                    <label for="currency_symbol" class="label">Currency symbol</label>
                    <input id="currency_symbol" name="currency_symbol" type="text" required maxlength="5" value="{{ old('currency_symbol', $settings['currency_symbol']) }}" class="input" placeholder="Tk or ৳">
                </div>
                <div>
                    <label for="store_phone" class="label">Phone</label>
                    <input id="store_phone" name="store_phone" type="tel" maxlength="40" value="{{ old('store_phone', $settings['store_phone']) }}" class="input">
                </div>
                <div>
                    <label for="store_email" class="label">Email</label>
                    <input id="store_email" name="store_email" type="email" maxlength="150" value="{{ old('store_email', $settings['store_email']) }}" class="input">
                </div>
                <div class="sm:col-span-2">
                    <label for="store_address" class="label">Address</label>
                    <textarea id="store_address" name="store_address" rows="2" maxlength="500" class="input">{{ old('store_address', $settings['store_address']) }}</textarea>
                </div>
                <div class="sm:col-span-2">
                    <label for="store_logo" class="label">Logo <span class="text-slate-400">(PNG, JPG or WebP, max 1 MB)</span></label>
                    <div class="flex flex-wrap items-center gap-4">
                        @if($settings['store_logo'])
                            <img src="{{ asset('storage/' . $settings['store_logo']) }}" alt="Current logo" class="h-14 w-auto rounded border border-slate-200 bg-white p-1">
                            <label class="flex items-center gap-2 text-sm text-slate-600">
                                <input type="checkbox" name="remove_logo" value="1" class="rounded text-rose-600 focus:ring-rose-500"> Remove logo
                            </label>
                        @endif
                        <input id="store_logo" name="store_logo" type="file" accept="image/png,image/jpeg,image/webp" class="input max-w-sm p-2">
                    </div>
                </div>
            </div>
        </section>

        <section class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Orders &amp; delivery</h2>

            <div class="mt-5 grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="order_prefix" class="label">Order number prefix</label>
                    <input id="order_prefix" name="order_prefix" type="text" required maxlength="10" value="{{ old('order_prefix', $settings['order_prefix']) }}" class="input uppercase">
                    <p class="mt-1 text-xs text-slate-500">New orders look like {{ $settings['order_prefix'] }}-{{ now()->format('ymd') }}-AB12CD.</p>
                </div>
                <div>
                    <label for="delivery_charge" class="label">Delivery charge</label>
                    <input id="delivery_charge" name="delivery_charge" type="number" step="0.01" min="0" required value="{{ old('delivery_charge', $settings['delivery_charge']) }}" class="input">
                </div>
                <div>
                    <label for="free_delivery_threshold" class="label">Free delivery from</label>
                    <input id="free_delivery_threshold" name="free_delivery_threshold" type="number" step="0.01" min="0" required value="{{ old('free_delivery_threshold', $settings['free_delivery_threshold']) }}" class="input">
                    <p class="mt-1 text-xs text-slate-500">Set to 0 to always charge delivery.</p>
                </div>
            </div>
        </section>

        <section class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Inventory</h2>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="low_stock_threshold" class="label">Default low stock threshold</label>
                    <input id="low_stock_threshold" name="low_stock_threshold" type="number" min="0" required value="{{ old('low_stock_threshold', $settings['low_stock_threshold']) }}" class="input">
                    <p class="mt-1 text-xs text-slate-500">Used when a product variant has no threshold of its own.</p>
                </div>
                <label class="flex items-start gap-2 self-center text-sm text-slate-700">
                    <input type="hidden" name="allow_negative_stock" value="0">
                    <input type="checkbox" name="allow_negative_stock" value="1" @checked(old('allow_negative_stock', $settings['allow_negative_stock'])) class="mt-0.5 rounded text-brand-600 focus:ring-brand-500">
                    <span>Allow negative stock<span class="block text-xs text-slate-500">Lets staff sell or remove more than is recorded. Leave off unless your counts are often behind.</span></span>
                </label>
            </div>
        </section>

        <section class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Payment methods</h2>
            <p class="mt-1 text-sm text-slate-500">Cash on delivery and the online gateway appear at storefront checkout; the others are for shop sales and recording payments.</p>

            @php $enabled = old('payment_methods', $settings['payment_methods']); @endphp
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach($paymentMethods as $key => $label)
                    <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-700 has-[:checked]:border-brand-400 has-[:checked]:bg-brand-50/50">
                        <input type="checkbox" name="payment_methods[]" value="{{ $key }}" @checked(in_array($key, (array) $enabled, true)) class="rounded text-brand-600 focus:ring-brand-500">
                        {{ $label }}
                    </label>
                @endforeach
            </div>

            <div class="mt-4 max-w-sm">
                <label for="online_payment_account" class="label">Online gateway payments go into</label>
                <select id="online_payment_account" name="online_payment_account" class="input">
                    @foreach($accounts as $account)
                        <option value="{{ $account->code }}" @selected(old('online_payment_account', $settings['online_payment_account']) === $account->code)>{{ $account->name }}</option>
                    @endforeach
                </select>
            </div>
        </section>

        <section class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Invoice</h2>
            <div class="mt-4">
                <label for="invoice_note" class="label">Note printed at the bottom of invoices</label>
                <textarea id="invoice_note" name="invoice_note" rows="2" maxlength="500" class="input">{{ old('invoice_note', $settings['invoice_note']) }}</textarea>
            </div>
        </section>

        <button type="submit" class="btn-primary">Save settings</button>
    </form>
@endsection
