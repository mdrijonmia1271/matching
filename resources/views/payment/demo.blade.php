@extends('layouts.app')

@section('title', 'Payment')

@section('content')
    <div class="mx-auto max-w-md px-4 py-16 sm:px-6">
        <div class="card overflow-hidden">
            <div class="bg-slate-900 px-6 py-5 text-white">
                <p class="text-xs uppercase tracking-widest text-slate-400">Sandbox gateway</p>
                <p class="mt-1 text-lg font-bold">Confirm your payment</p>
            </div>

            <div class="p-6">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-600">Merchant</dt><dd class="font-medium text-slate-900">{{ \App\Support\Settings::get('store_name') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Order</dt><dd class="font-medium text-slate-900">{{ $order->order_number }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Transaction</dt><dd class="font-mono text-xs text-slate-700">{{ $payment->transaction_id }}</dd></div>
                    <div class="flex justify-between border-t border-slate-200 pt-3 text-base"><dt class="font-bold">Amount</dt><dd class="font-bold">@money($payment->amount)</dd></div>
                </dl>

                <p class="mt-5 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    This is a demo gateway. No real payment is processed &mdash; replace
                    <code class="font-mono">DemoOnlineGateway</code> with your provider (SSLCommerz, bKash, Stripe) to go live.
                </p>

                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <form method="POST" action="{{ $successUrl }}">
                        @csrf
                        <button type="submit" class="btn-primary w-full">Pay @money($payment->amount)</button>
                    </form>
                    <form method="POST" action="{{ $failUrl }}">
                        @csrf
                        <button type="submit" class="btn-secondary w-full">Cancel payment</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
