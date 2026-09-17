@extends('layouts.admin')

@section('title', $return->number)
@section('heading', 'Return ' . $return->number)

@section('content')
    @php
        $admin = auth()->user();
        $canDecide = $return->isOpen() && $admin->can('orders.update');
        $canReceive = $return->status === 'approved' && $admin->can('inventory.adjust');
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.returns.index') }}" class="text-sm text-slate-500 hover:text-brand-600">&larr; Back to returns</a>

        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $return->status_color }}">{{ $return->status_label }}</span>

            @if($return->status === 'requested' && $admin->can('orders.update'))
                <form method="POST" action="{{ route('admin.returns.approve', $return) }}">
                    @csrf
                    <button type="submit" class="btn-primary">Approve</button>
                </form>
            @endif

            @if($canReceive)
                <form method="POST" action="{{ route('admin.returns.receive', $return) }}" x-data
                      @submit="if (! confirm(@js('Receive ' . $return->quantity . ' unit(s) back into stock? This cannot be undone.'))) $event.preventDefault()">
                    @csrf
                    <button type="submit" class="btn-primary">Receive into stock</button>
                </form>
            @endif

            @if($canDecide)
                <form method="POST" action="{{ route('admin.returns.reject', $return) }}" x-data
                      @submit="if (! confirm(@js('Reject return ' . $return->number . '? Those units can be returned again later.'))) $event.preventDefault()">
                    @csrf
                    <button type="submit" class="btn-secondary text-rose-600">Reject</button>
                </form>
            @endif
        </div>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            ['Order', $return->order?->order_number ?? '—', $return->order?->customer_name ?? '', 'text-slate-900'],
            ['Units coming back', number_format($return->quantity), $return->items->count() . ' ' . \Illuminate\Support\Str::plural('line', $return->items->count()), 'text-slate-900'],
            ['Value', \App\Support\Money::format((float) $return->refund_total), 'What these goods sold for', 'text-slate-900'],
            ['Reason', $return->reason_label, $return->requester?->name ? 'Raised by ' . $return->requester->name : '', 'text-slate-900'],
        ] as [$label, $value, $hint, $color])
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 truncate text-lg font-bold {{ $color }}">{{ $value }}</p>
                <p class="text-xs text-slate-400">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    @unless($return->isReceived())
        <div class="mt-4 rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-800">
            Nothing has moved yet. Stock only changes when the goods are received, and the money is a separate refund.
        </div>
    @endunless

    <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="min-w-0 space-y-6">
            <section class="card">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-bold text-slate-900">Items</h2>
                    <p class="text-xs text-slate-500">Damaged units come in and are written off, so the loss shows in the stock history.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Product</th>
                                <th class="px-4 py-3">Condition</th>
                                <th class="px-4 py-3 text-right">Qty</th>
                                <th class="px-4 py-3 text-right">Price</th>
                                <th class="px-4 py-3 text-right">Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($return->items as $item)
                                <tr>
                                    <td class="px-4 py-3">
                                        <span class="font-medium text-slate-800">{{ $item->orderItem?->product_name ?? $item->variant?->full_name }}</span>
                                        <span class="block font-mono text-xs text-slate-400">{{ $item->orderItem?->sku ?? $item->variant?->sku }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $item->isDamaged() ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700' }}">
                                            {{ $item->condition_label }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-slate-700">{{ number_format($item->quantity) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-700">@money($item->unit_price)</td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($item->line_total)</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            @if($movements->isNotEmpty())
                <section class="card">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="text-base font-bold text-slate-900">Stock moved</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Product</th>
                                    <th class="px-4 py-3">Movement</th>
                                    <th class="px-4 py-3 text-right">Quantity</th>
                                    <th class="px-4 py-3 text-right">Stock after</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($movements as $movement)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <a href="{{ route('admin.stock.show', $movement) }}" class="text-brand-600 hover:underline">{{ $movement->variant?->full_name }}</a>
                                        </td>
                                        <td class="px-4 py-3 text-xs text-slate-600">{{ $movement->reason_label }}</td>
                                        <td class="px-4 py-3 text-right {{ $movement->type === 'in' ? 'text-emerald-700' : 'text-rose-600' }}">
                                            {{ $movement->type === 'in' ? '+' : '−' }}{{ number_format($movement->quantity) }}
                                        </td>
                                        <td class="px-4 py-3 text-right text-slate-700">{{ number_format($movement->stock_after) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        <aside class="space-y-6">
            <section class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Progress</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-slate-500">Requested</dt><dd class="text-slate-900">{{ $return->created_at?->format('d M Y, g:i a') }} · {{ $return->requester?->name ?? 'System' }}</dd></div>
                    @if($return->approved_at)
                        <div><dt class="text-slate-500">Approved</dt><dd class="text-slate-900">{{ $return->approved_at->format('d M Y, g:i a') }} · {{ $return->approver?->name }}</dd></div>
                    @endif
                    @if($return->received_at)
                        <div><dt class="text-slate-500">Received</dt><dd class="text-emerald-700">{{ $return->received_at->format('d M Y, g:i a') }} · {{ $return->receiver?->name }}</dd></div>
                    @endif
                    @if($return->rejected_at)
                        <div><dt class="text-slate-500">Rejected</dt><dd class="text-rose-600">{{ $return->rejected_at->format('d M Y, g:i a') }}</dd></div>
                    @endif
                </dl>
            </section>

            <section class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Order</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-slate-500">Order</dt>
                        <dd>
                            @if($return->order)
                                <a href="{{ route('admin.orders.show', $return->order) }}" class="font-mono text-brand-600 hover:underline">{{ $return->order->order_number }}</a>
                                <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $return->order->statusColor() }}">{{ $return->order->status_label }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Customer</dt>
                        <dd>
                            @if($return->customer)
                                <a href="{{ route('admin.customers.show', $return->customer) }}" class="text-brand-600 hover:underline">{{ $return->customer->name }}</a>
                            @else
                                {{ $return->order?->customer_name ?? '—' }}
                            @endif
                        </dd>
                    </div>
                    <div><dt class="text-slate-500">Paid on the order</dt><dd class="text-slate-900">@money($return->order?->paid_amount ?? 0)</dd></div>
                </dl>
                @if($return->refunds->isNotEmpty())
                    <div class="mt-4 border-t border-slate-200 pt-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Refunded against this return</p>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach($return->refunds as $refund)
                                <li class="flex justify-between gap-2">
                                    <span class="text-slate-700">@money($refund->amount) <span class="text-xs text-slate-400">{{ $refund->method_label }}</span></span>
                                    <span class="font-mono text-xs text-slate-400">{{ $refund->number }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if($return->isReceived() && $return->order && $return->order->refundable_amount > 0 && auth()->user()->can('orders.refund'))
                    <a href="{{ route('admin.orders.show', $return->order) }}#refund" class="btn-secondary mt-4 w-full text-center">Refund on this order</a>
                    <p class="mt-2 text-xs text-slate-500">
                        The goods are back. @money($return->order->refundable_amount) of what was paid can still go back to the customer.
                    </p>
                @else
                    <p class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
                        A return records goods, not money. Refunds are made on the order.
                    </p>
                @endif
            </section>

            @if($return->note)
                <section class="card p-6">
                    <h2 class="text-base font-bold text-slate-900">Note</h2>
                    <p class="mt-3 whitespace-pre-line text-sm text-slate-600">{{ $return->note }}</p>
                </section>
            @endif
        </aside>
    </div>
@endsection
