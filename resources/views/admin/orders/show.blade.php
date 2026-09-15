@extends('layouts.admin')

@section('title', 'Order ' . $order->order_number)
@section('heading', 'Order ' . $order->order_number)

@section('content')
    @php
        $canCancel = auth()->user()->can('orders.cancel');
        $next = array_values(array_filter($order->nextStatuses(), fn ($status) => $status !== 'cancelled' || $canCancel));
        $couriers = ['Pathao', 'Steadfast', 'RedX', 'Paperfly', 'eCourier', 'Sundarban Courier', 'SA Paribahan', 'Own delivery'];
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.orders.index') }}" class="text-sm text-slate-500 hover:text-brand-600">&larr; Back to orders</a>
        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold">
            <span class="rounded-full px-3 py-1 {{ $order->statusColor() }}">{{ $order->status_label }}</span>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-700">{{ $order->payment_method === 'cod' ? 'Cash on delivery' : 'Online' }} &middot; {{ $order->payment_status_label }}</span>
        </div>
    </div>

    <div class="mt-4 grid gap-6 lg:grid-cols-[1fr_360px]">
        <div class="min-w-0 space-y-6">
            <div class="card">
                <div class="border-b border-slate-200 px-6 py-4">
                    <h2 class="text-base font-bold text-slate-900">Items</h2>
                    <p class="text-xs text-slate-500">Placed {{ $order->created_at->format('d M Y, g:i a') }}</p>
                </div>

                <table class="w-full text-sm">
                    <tbody class="divide-y divide-slate-100">
                        @foreach($order->items as $item)
                            <tr>
                                <td class="px-6 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="h-10 w-10 shrink-0 overflow-hidden rounded-lg bg-slate-100">
                                            @if($item->product)
                                                <img src="{{ $item->product->image_url }}" alt="" class="h-full w-full object-cover">
                                            @endif
                                        </div>
                                        <div>
                                            <p class="font-medium text-slate-900">{{ $item->product_name }}</p>
                                            <p class="text-xs text-slate-500">
                                                @if($item->variant_label){{ $item->variant_label }} &middot; @endif
                                                <span class="font-mono">{{ $item->sku ?? '—' }}</span>
                                            </p>
                                            <p class="text-xs text-slate-400">{{ $item->quantity }} &times; @money($item->price)</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-3 text-right font-semibold text-slate-900">@money($item->subtotal)</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <dl class="space-y-2 border-t border-slate-200 px-6 py-4 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-600">Subtotal</dt><dd>@money($order->subtotal)</dd></div>
                    @if($order->discount > 0)
                        <div class="flex justify-between text-emerald-600"><dt>Discount{{ $order->coupon_code ? ' (' . $order->coupon_code . ')' : '' }}</dt><dd>-@money($order->discount)</dd></div>
                    @endif
                    <div class="flex justify-between"><dt class="text-slate-600">Delivery charge</dt><dd>{{ $order->shipping_cost > 0 ? \App\Support\Money::format($order->shipping_cost) : 'Free' }}</dd></div>
                    <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-bold"><dt>Total</dt><dd>@money($order->total)</dd></div>
                </dl>
            </div>

            <section class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Status history</h2>

                @if($order->statusHistories->isEmpty())
                    <p class="mt-3 text-sm text-slate-500">No status changes recorded yet.</p>
                @else
                    <ol class="relative ml-2 mt-5 border-l border-slate-200">
                        @foreach($order->statusHistories as $history)
                            <li class="relative mb-5 ml-5 last:mb-0">
                                <span class="absolute -left-[26px] top-1 h-3 w-3 rounded-full border-2 border-white {{ $loop->first ? 'bg-brand-600' : 'bg-slate-300' }}"></span>
                                <p class="text-sm font-semibold text-slate-900">
                                    @if($history->from_label){{ $history->from_label }} &rarr; @endif{{ $history->to_label }}
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $history->created_at?->format('d M Y, g:i a') }} &middot;
                                    {{ $history->user?->name ?? ($history->from_status === null ? 'Customer' : 'System') }}
                                </p>
                                @if($history->note)
                                    <p class="mt-1 text-sm text-slate-600">{{ $history->note }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        <aside class="space-y-6">
            <section class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Order status</h2>

                @can('orders.update')
                    @if($next)
                        <form method="POST" action="{{ route('admin.orders.status', $order) }}" class="mt-4 space-y-3"
                              x-data="{ status: @js($next[0]) }"
                              @submit="if (status === 'cancelled' && ! confirm('Cancel this order? Its items go back into stock.')) $event.preventDefault()">
                            @csrf @method('PATCH')

                            <div class="grid grid-cols-2 gap-2">
                                @foreach($next as $status)
                                    <label class="flex cursor-pointer items-center justify-center rounded-lg border-2 px-3 py-2 text-sm font-semibold transition"
                                           :class="status === @js($status) ? '{{ $status === 'cancelled' ? 'border-rose-500 bg-rose-50 text-rose-700' : 'border-brand-500 bg-brand-50 text-brand-700' }}' : 'border-slate-200 text-slate-600 hover:border-slate-300'">
                                        <input type="radio" name="status" value="{{ $status }}" x-model="status" class="sr-only">
                                        {{ \App\Models\Order::STATUS_LABELS[$status] }}
                                    </label>
                                @endforeach
                            </div>

                            <div>
                                <label for="status_note" class="label">Note <span class="text-slate-400">(optional)</span></label>
                                <textarea id="status_note" name="note" rows="2" maxlength="500" class="input" placeholder="Reason, who you spoke to…"></textarea>
                            </div>

                            <button type="submit" class="btn-primary w-full">Update status</button>
                        </form>
                    @else
                        <p class="mt-3 text-sm text-slate-500">
                            This order is {{ strtolower($order->status_label) }}. No further status changes are available here.
                        </p>
                    @endif
                @else
                    <p class="mt-3 text-sm text-slate-500">You can view this order but not change it.</p>
                @endcan
            </section>

            <section class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Delivery &amp; internal note</h2>

                @can('orders.update')
                    <form method="POST" action="{{ route('admin.orders.details', $order) }}" class="mt-4 space-y-3">
                        @csrf @method('PATCH')
                        <div>
                            <label for="courier_name" class="label">Courier</label>
                            <input id="courier_name" name="courier_name" list="courier-list" maxlength="80" value="{{ old('courier_name', $order->courier_name) }}" class="input">
                            <datalist id="courier-list">
                                @foreach($couriers as $courier)
                                    <option value="{{ $courier }}">
                                @endforeach
                            </datalist>
                        </div>
                        <div>
                            <label for="tracking_number" class="label">Tracking / consignment number</label>
                            <input id="tracking_number" name="tracking_number" maxlength="100" value="{{ old('tracking_number', $order->tracking_number) }}" class="input font-mono">
                        </div>
                        <div>
                            <label for="admin_note" class="label">Admin note <span class="text-slate-400">(staff only)</span></label>
                            <textarea id="admin_note" name="admin_note" rows="3" maxlength="2000" class="input">{{ old('admin_note', $order->admin_note) }}</textarea>
                        </div>
                        <button type="submit" class="btn-secondary w-full">Save details</button>
                    </form>
                @else
                    <dl class="mt-3 space-y-2 text-sm">
                        <div><dt class="text-slate-500">Courier</dt><dd class="text-slate-900">{{ $order->courier_name ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">Tracking</dt><dd class="font-mono text-slate-900">{{ $order->tracking_number ?: '—' }}</dd></div>
                        <div><dt class="text-slate-500">Admin note</dt><dd class="whitespace-pre-line text-slate-900">{{ $order->admin_note ?: '—' }}</dd></div>
                    </dl>
                @endcan
            </section>

            <section class="card p-6">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-bold text-slate-900">Payments</h2>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $order->paymentStatusColor() }}">{{ $order->payment_status_label }}</span>
                </div>

                <dl class="mt-4 space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-600">Order total</dt><dd>@money($order->total)</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-600">Paid</dt><dd class="text-emerald-700">@money($order->paid_amount)</dd></div>
                    @if($order->refunded_amount > 0)
                        <div class="flex justify-between"><dt class="text-slate-600">Refunded</dt><dd class="text-rose-600">-@money($order->refunded_amount)</dd></div>
                    @endif
                    <div class="flex justify-between border-t border-slate-200 pt-1.5 font-bold">
                        <dt>Due</dt><dd class="{{ $order->due_amount > 0 ? 'text-rose-600' : 'text-slate-900' }}">@money($order->due_amount)</dd>
                    </div>
                </dl>

                @if($order->payments->isNotEmpty())
                    <ul class="mt-4 divide-y divide-slate-100 border-t border-slate-100 text-xs">
                        @foreach($order->payments->sortByDesc('id') as $payment)
                            <li class="py-2">
                                <div class="flex justify-between gap-2">
                                    <span class="font-semibold text-slate-800">
                                        @money($payment->amount)
                                        <span class="font-normal text-slate-500">{{ $payment->method_label }}{{ $payment->account ? ' → ' . $payment->account->name : '' }}</span>
                                    </span>
                                    <span class="font-semibold {{ $payment->status === 'success' ? 'text-emerald-600' : ($payment->status === 'failed' ? 'text-rose-600' : 'text-amber-600') }}">
                                        {{ $payment->status === 'success' ? 'Received' : ucfirst($payment->status) }}
                                    </span>
                                </div>
                                <p class="text-slate-400">
                                    {{ ($payment->paid_at ?? $payment->created_at)?->format('d M Y, g:i a') }}{{ $payment->receiver ? ' · ' . $payment->receiver->name : '' }}{{ $payment->transaction_id ? ' · ' . $payment->transaction_id : '' }}
                                </p>
                                @if($payment->note)
                                    <p class="text-slate-500">{{ $payment->note }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if(auth()->user()->canAny(['accounting.create', 'pos.sell']) && $order->status !== 'cancelled' && $order->due_amount > 0 && $methods)
                    <form method="POST" action="{{ route('admin.orders.payments.store', $order) }}" class="mt-4 space-y-3 border-t border-slate-200 pt-4"
                          x-data="{ method: @js(old('method', array_key_first($methods))), account: @js((string) old('account_id', $methodAccounts[old('method', array_key_first($methods))] ?? '')), defaults: @js($methodAccounts) }">
                        @csrf
                        <p class="text-sm font-semibold text-slate-900">Record a payment</p>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="pay_amount" class="label">Amount</label>
                                <input id="pay_amount" name="amount" type="number" step="0.01" min="0.01" max="{{ $order->due_amount }}" required value="{{ old('amount', $order->due_amount) }}" class="input">
                            </div>
                            <div>
                                <label for="pay_method" class="label">Method</label>
                                <select id="pay_method" name="method" x-model="method" @change="account = String(defaults[method] ?? account)" class="input">
                                    @foreach($methods as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div>
                            <label for="pay_account" class="label">Received into</label>
                            <select id="pay_account" name="account_id" x-model="account" required class="input">
                                <option value="">Choose account</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="pay_reference" class="label">Transaction ID <span class="text-slate-400">(optional)</span></label>
                            <input id="pay_reference" name="reference" type="text" maxlength="100" value="{{ old('reference') }}" class="input font-mono" placeholder="bKash / bank reference">
                        </div>
                        <div>
                            <label for="pay_note" class="label">Note <span class="text-slate-400">(optional)</span></label>
                            <input id="pay_note" name="note" type="text" maxlength="500" value="{{ old('note') }}" class="input" placeholder="e.g. Steadfast COD remittance">
                        </div>
                        <button type="submit" class="btn-primary w-full">Record payment</button>
                    </form>
                @endif
            </section>

            <section class="card p-6">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-bold text-slate-900">Customer</h2>
                    @if($order->customer && auth()->user()->can('customers.view'))
                        <a href="{{ route('admin.customers.show', $order->customer) }}" class="text-xs font-semibold text-brand-600 hover:underline">View profile &rarr;</a>
                    @endif
                </div>
                <p class="mt-3 text-sm text-slate-600">
                    {{ $order->customer_name }}<br>
                    {{ $order->customer_phone }}<br>
                    {{ $order->customer_email }}
                    @if($order->user)
                        <br><span class="text-xs text-slate-400">Registered account #{{ $order->user->id }}</span>
                    @else
                        <br><span class="text-xs text-slate-400">Guest checkout</span>
                    @endif
                </p>

                <h3 class="mt-5 text-sm font-semibold text-slate-900">Shipping address</h3>
                <p class="mt-1 text-sm text-slate-600">
                    {{ $order->shipping_address }}{{ $order->shipping_city ? ', ' . $order->shipping_city : '' }}
                </p>

                @if($order->note)
                    <p class="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-600"><span class="font-semibold">Customer note:</span> {{ $order->note }}</p>
                @endif
            </section>

        </aside>
    </div>
@endsection
