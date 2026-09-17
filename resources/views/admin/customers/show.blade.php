@extends('layouts.admin')

@section('title', $customer->name)
@section('heading', $customer->name)

@section('content')
    @php
        use App\Support\Money;

        $due = (float) $customer->current_due;
        $openingDue = (float) $customer->opening_due_remaining;
        $openingOriginal = (float) $customer->opening_due;
        $canViewOrders = auth()->user()->can('orders.view');
        $canCollect = $due > 0 && ! $customer->trashed() && $methods
            && auth()->user()->can('customers.edit') && auth()->user()->canAny(['accounting.create', 'pos.sell']);
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.customers.index') }}" class="text-sm text-slate-500 hover:text-brand-600">&larr; Back to customers</a>

        <div class="flex flex-wrap items-center gap-2">
            @if($customer->trashed())
                <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-600">Archived</span>
            @endif
            @can('customers.edit')
                @if($customer->trashed())
                    <form method="POST" action="{{ route('admin.customers.restore', $customer) }}">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn-secondary">Restore</button>
                    </form>
                @else
                    <a href="{{ route('admin.customers.edit', $customer) }}" class="btn-secondary">Edit</a>
                    <form method="POST" action="{{ route('admin.customers.destroy', $customer) }}" x-data
                          @submit="if (! confirm(@js('Archive ' . $customer->name . '? Their orders and payments are kept.'))) $event.preventDefault()">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn-secondary text-rose-600">Archive</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach([
            ['Total orders', number_format($customer->orders_count), 'All statuses, including cancelled', 'text-slate-900'],
            ['Total spent', Money::format((float) $customer->total_spent), 'Confirmed to delivered orders', 'text-slate-900'],
            ['Total paid', Money::format((float) $customer->total_paid), 'After refunds', 'text-emerald-700'],
            ['Current due', Money::format($due), $openingDue > 0 ? 'Includes opening due ' . Money::format($openingDue) : 'Unpaid confirmed orders', $due > 0 ? 'text-rose-600' : 'text-slate-900'],
            ['Last order', $customer->last_order_at?->format('d M Y') ?? '—', $customer->last_order_at?->diffForHumans() ?? 'No orders yet', 'text-slate-900'],
        ] as [$label, $value, $hint, $color])
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-bold {{ $color }}">{{ $value }}</p>
                <p class="text-xs text-slate-400">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="min-w-0 space-y-6">
            @if($due > 0)
                <section class="card">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="text-base font-bold text-slate-900">What is owed</h2>
                        <p class="text-xs text-slate-500">Oldest first. A collected payment is applied in this order.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">For</th>
                                    <th class="px-4 py-3">Date</th>
                                    <th class="px-4 py-3 text-right">Total</th>
                                    <th class="px-4 py-3 text-right">Paid</th>
                                    <th class="px-4 py-3 text-right">Due</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @if($openingDue > 0)
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-slate-900">Opening due</td>
                                        <td class="px-4 py-3 text-xs text-slate-500">Before this system</td>
                                        <td class="px-4 py-3 text-right">@money($openingOriginal)</td>
                                        <td class="px-4 py-3 text-right text-emerald-700">@money($openingOriginal - $openingDue)</td>
                                        <td class="px-4 py-3 text-right font-semibold text-rose-600">@money($openingDue)</td>
                                    </tr>
                                @endif
                                @foreach($outstanding as $order)
                                    <tr>
                                        <td class="px-4 py-3">
                                            @if($canViewOrders)
                                                <a href="{{ route('admin.orders.show', $order) }}" class="font-semibold text-brand-600 hover:underline">{{ $order->order_number }}</a>
                                            @else
                                                <span class="font-semibold text-slate-900">{{ $order->order_number }}</span>
                                            @endif
                                            <span class="ml-1 rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $order->statusColor() }}">{{ $order->status_label }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-slate-500">{{ $order->created_at->format('d M Y') }}</td>
                                        <td class="px-4 py-3 text-right">@money($order->total)</td>
                                        <td class="px-4 py-3 text-right text-emerald-700">@money($order->paid_amount)</td>
                                        <td class="px-4 py-3 text-right font-semibold text-rose-600">@money($order->due_amount)</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            <section class="card">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-bold text-slate-900">Orders</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Order</th>
                                <th class="px-4 py-3 text-center">Items</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Payment</th>
                                <th class="px-4 py-3 text-right">Total</th>
                                <th class="px-4 py-3 text-right">Due</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($orders as $order)
                                <tr class="hover:bg-slate-50">
                                    <td class="px-4 py-3">
                                        @if($canViewOrders)
                                            <a href="{{ route('admin.orders.show', $order) }}" class="font-semibold text-brand-600 hover:underline">{{ $order->order_number }}</a>
                                        @else
                                            <span class="font-semibold text-slate-900">{{ $order->order_number }}</span>
                                        @endif
                                        <p class="text-xs text-slate-400">{{ $order->created_at->format('d M Y, g:i a') }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-center text-slate-600">{{ $order->items_count }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $order->statusColor() }}">{{ $order->status_label }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $order->paymentStatusColor() }}">{{ $order->payment_status_label }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($order->total)</td>
                                    <td class="px-4 py-3 text-right {{ in_array($order->status, \App\Models\Order::SALE_STATUSES, true) && $order->due_amount > 0 ? 'font-semibold text-rose-600' : 'text-slate-400' }}">
                                        {{ in_array($order->status, \App\Models\Order::SALE_STATUSES, true) ? Money::format($order->due_amount) : '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No orders yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($orders->hasPages())
                    <div class="border-t border-slate-200 px-5 py-3">{{ $orders->links() }}</div>
                @endif
            </section>

            @if($returns->isNotEmpty())
                <section class="card">
                    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                        <div>
                            <h2 class="text-base font-bold text-slate-900">Returns</h2>
                            <p class="text-xs text-slate-500">Goods this customer sent back.</p>
                        </div>
                        <a href="{{ route('admin.returns.index', ['q' => $customer->name]) }}" class="text-sm text-brand-600 hover:underline">See all</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Return</th>
                                    <th class="px-4 py-3">Order</th>
                                    <th class="px-4 py-3">Status</th>
                                    <th class="px-4 py-3 text-right">Value</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($returns as $customerReturn)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <a href="{{ route('admin.returns.show', $customerReturn) }}" class="font-mono text-xs font-semibold text-brand-600 hover:underline">{{ $customerReturn->number }}</a>
                                            <span class="block text-xs text-slate-400">{{ $customerReturn->reason_label }} &middot; {{ $customerReturn->quantity }} unit(s)</span>
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $customerReturn->order?->order_number }}</td>
                                        <td class="px-4 py-3">
                                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $customerReturn->status_color }}">{{ $customerReturn->status_label }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($customerReturn->refund_total)</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            <section class="card">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-bold text-slate-900">Payments</h2>
                    <p class="text-xs text-slate-500">Money received against this customer's orders.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Order</th>
                                <th class="px-4 py-3">Method</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($payments as $payment)
                                <tr>
                                    <td class="px-4 py-3 text-xs text-slate-500">
                                        {{ ($payment->paid_at ?? $payment->created_at)?->format('d M Y, g:i a') }}
                                        @if($payment->receiver)<span class="block text-slate-400">{{ $payment->receiver->name }}</span>@endif
                                    </td>
                                    <td class="px-4 py-3 font-medium text-slate-800">{{ $payment->order?->order_number }}</td>
                                    <td class="px-4 py-3 text-slate-600">
                                        {{ $payment->method_label }}{{ $payment->account ? ' → ' . $payment->account->name : '' }}
                                        @if($payment->transaction_id)<span class="block font-mono text-xs text-slate-400">{{ $payment->transaction_id }}</span>@endif
                                        @if($payment->note)<span class="block text-xs text-slate-400">{{ $payment->note }}</span>@endif
                                    </td>
                                    <td class="px-4 py-3 text-xs font-semibold {{ $payment->status === 'success' ? 'text-emerald-600' : ($payment->status === 'failed' ? 'text-rose-600' : 'text-amber-600') }}">
                                        {{ $payment->status === 'success' ? 'Received' : ucfirst($payment->status) }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($payment->amount)</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No payments recorded yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($payments->hasPages())
                    <div class="border-t border-slate-200 px-5 py-3">{{ $payments->links() }}</div>
                @endif
            </section>
            <section class="card">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-bold text-slate-900">Due collections</h2>
                    <p class="text-xs text-slate-500">Money collected against the total due, and where each payment was applied.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Receipt</th>
                                <th class="px-4 py-3">Received</th>
                                <th class="px-4 py-3">Applied to</th>
                                <th class="px-4 py-3 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($collections as $collection)
                                <tr class="align-top">
                                    <td class="px-4 py-3">
                                        <p class="font-mono text-xs font-semibold text-slate-900">{{ $collection->receipt_number }}</p>
                                        <p class="text-xs text-slate-500">{{ $collection->paid_at?->format('d M Y, g:i a') }}</p>
                                        @if($collection->receiver)<p class="text-xs text-slate-400">{{ $collection->receiver->name }}</p>@endif
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">
                                        {{ $collection->method_label }}{{ $collection->account ? ' → ' . $collection->account->name : '' }}
                                        @if($collection->reference)<span class="block font-mono text-xs text-slate-400">{{ $collection->reference }}</span>@endif
                                        @if($collection->note)<span class="block text-xs text-slate-400">{{ $collection->note }}</span>@endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-600">
                                        @if((float) $collection->opening_due_paid > 0)
                                            <p>Opening due &middot; @money($collection->opening_due_paid)</p>
                                        @endif
                                        @foreach($collection->payments as $payment)
                                            <p>{{ $payment->order?->order_number }} &middot; @money($payment->amount)</p>
                                        @endforeach
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-emerald-700">@money($collection->amount)</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-10 text-center text-slate-500">No due collections yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($collections->hasPages())
                    <div class="border-t border-slate-200 px-5 py-3">{{ $collections->links() }}</div>
                @endif
            </section>
        </div>

        <aside class="space-y-6">
            @if($canCollect)
                <form method="POST" action="{{ route('admin.customers.payments.store', $customer) }}" class="card space-y-3 p-6"
                      x-data="{
                          amount: @js((float) old('amount', $due)),
                          due: @js($due),
                          opening: @js($openingDue),
                          orders: @js($outstanding->map(fn ($order) => ['number' => $order->order_number, 'due' => $order->due_amount])->values()),
                          method: @js(old('method', array_key_first($methods))),
                          account: @js((string) old('account_id', $methodAccounts[old('method', array_key_first($methods))] ?? '')),
                          defaults: @js($methodAccounts),
                          symbol: @js(\App\Support\Money::symbol()),
                          money(value) {
                              return this.symbol + ' ' + Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                          },
                          get plan() {
                              let left = Math.round((Number(this.amount) || 0) * 100);
                              const lines = [];
                              const take = (label, owed) => {
                                  const cents = Math.min(Math.round(owed * 100), left);
                                  if (cents > 0) { lines.push({ label: label, amount: cents / 100 }); left -= cents; }
                              };
                              take('Opening due', this.opening);
                              this.orders.forEach((order) => take(order.number, order.due));
                              return lines;
                          },
                      }">
                    @csrf
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Collect payment</h2>
                        <p class="text-xs text-slate-500">Applied to the opening due first, then to unpaid orders, oldest first.</p>
                    </div>
                    <div>
                        <label for="collect_amount" class="label">Amount</label>
                        <input id="collect_amount" name="amount" type="number" step="0.01" min="0.01" max="{{ $due }}" required x-model="amount" class="input">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label for="collect_method" class="label">Method</label>
                            <select id="collect_method" name="method" x-model="method" @change="account = String(defaults[method] ?? account)" class="input">
                                @foreach($methods as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="collect_account" class="label">Received into</label>
                            <select id="collect_account" name="account_id" x-model="account" required class="input">
                                <option value="">Choose</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="collect_reference" class="label">Transaction ID <span class="text-slate-400">(optional)</span></label>
                        <input id="collect_reference" name="reference" type="text" maxlength="100" value="{{ old('reference') }}" class="input font-mono" placeholder="bKash / bank reference">
                    </div>
                    <div>
                        <label for="collect_note" class="label">Note <span class="text-slate-400">(optional)</span></label>
                        <input id="collect_note" name="note" type="text" maxlength="500" value="{{ old('note') }}" class="input" placeholder="e.g. Paid at the shop">
                    </div>

                    <div x-show="plan.length" class="rounded-lg bg-slate-50 p-3 text-xs">
                        <p class="font-semibold text-slate-700">Will be applied to</p>
                        <template x-for="line in plan" :key="line.label">
                            <div class="mt-1 flex justify-between text-slate-600">
                                <span x-text="line.label"></span>
                                <span x-text="money(line.amount)"></span>
                            </div>
                        </template>
                    </div>
                    <p x-show="Number(amount) > due" x-cloak class="text-xs text-rose-600">More than the total due of @money($due).</p>

                    <button type="submit" class="btn-primary w-full">Record payment</button>
                </form>
            @endif

            <section class="card p-6">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="text-base font-bold text-slate-900">Contact</h2>
                    <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $customer->groupColor() }}">{{ $customer->group_label }}</span>
                </div>

                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-slate-500">Phone</dt><dd class="font-mono text-slate-900">{{ $customer->phone ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Email</dt><dd class="break-all text-slate-900">{{ $customer->email ?: '—' }}</dd></div>
                    <div>
                        <dt class="text-slate-500">Address</dt>
                        <dd class="whitespace-pre-line text-slate-900">{{ collect([$customer->address, $customer->city])->filter()->implode(', ') ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Online account</dt>
                        <dd class="text-slate-900">{{ $customer->user ? $customer->user->email . ' (#' . $customer->user->id . ')' : 'None — buys as a guest or in the shop' }}</dd>
                    </div>
                    <div><dt class="text-slate-500">Customer since</dt><dd class="text-slate-900">{{ $customer->created_at?->format('d M Y') }}</dd></div>
                </dl>
            </section>

            @if($customer->notes)
                <section class="card p-6">
                    <h2 class="text-base font-bold text-slate-900">Notes</h2>
                    <p class="mt-3 whitespace-pre-line text-sm text-slate-600">{{ $customer->notes }}</p>
                </section>
            @endif
        </aside>
    </div>
@endsection
