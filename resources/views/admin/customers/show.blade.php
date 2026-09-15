@extends('layouts.admin')

@section('title', $customer->name)
@section('heading', $customer->name)

@section('content')
    @php
        use App\Support\Money;

        $due = (float) $customer->current_due;
        $openingDue = (float) $customer->opening_due;
        $canViewOrders = auth()->user()->can('orders.view');
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
                        <p class="text-xs text-slate-500">Oldest first. Take payments from each order's page.</p>
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
                                        <td class="px-4 py-3 text-right">@money($openingDue)</td>
                                        <td class="px-4 py-3 text-right text-slate-400">—</td>
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
        </div>

        <aside class="space-y-6">
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
