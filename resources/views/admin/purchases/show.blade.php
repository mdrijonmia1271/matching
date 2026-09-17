@extends('layouts.admin')

@section('title', $purchase->number)
@section('heading', $purchase->number)

@section('content')
    @php
        use App\Support\Money;

        $admin = auth()->user();
        $canEdit = $purchase->isOpen() && $admin->can('purchases.edit');
        $canPay = $admin->can('accounting.create');
        $labelLink = route('admin.barcodes.index', [
            'variants' => $purchase->items->pluck('variant_id')->all(),
            'qty' => $purchase->items->pluck('quantity', 'variant_id')->all(),
        ]);
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.purchases.index') }}" class="text-sm text-slate-500 hover:text-brand-600">&larr; Back to purchases</a>

        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $purchase->status_color }}">{{ $purchase->status_label }}</span>

            @if($purchase->isReceived())
                @can('products.view')
                    <a href="{{ $labelLink }}" class="btn-secondary">Print labels for this purchase</a>
                @endcan
            @endif

            @if($canEdit)
                <a href="{{ route('admin.purchases.edit', $purchase) }}" class="btn-secondary">Edit</a>
                <form method="POST" action="{{ route('admin.purchases.cancel', $purchase) }}" x-data
                      @submit="if (! confirm(@js('Cancel purchase ' . $purchase->number . '? Nothing has been stocked in yet.'))) $event.preventDefault()">
                    @csrf
                    <button type="submit" class="btn-secondary text-rose-600">Cancel purchase</button>
                </form>
            @endif
        </div>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            ['Supplier', $purchase->supplier?->name ?? '—', $purchase->supplier?->company ?: 'Supplier', 'text-slate-900'],
            ['Total', Money::format((float) $purchase->total), number_format($purchase->items->sum('quantity')) . ' units', 'text-slate-900'],
            ['Supplier balance', Money::format($balance), $balance > 0 ? 'What the shop owes them' : 'Nothing owed', $balance > 0 ? 'text-rose-600' : 'text-slate-900'],
            ['Received', $purchase->received_at?->format('d M Y') ?? '—', $purchase->receiver?->name ?? 'Not received yet', $purchase->isReceived() ? 'text-emerald-700' : 'text-slate-900'],
        ] as [$label, $value, $hint, $color])
            <div class="card p-5">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 truncate text-2xl font-bold {{ $color }}">{{ $value }}</p>
                <p class="text-xs text-slate-400">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="min-w-0 space-y-6">
            <section class="card">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-bold text-slate-900">Items</h2>
                    <p class="text-xs text-slate-500">
                        {{ $purchase->isReceived()
                            ? 'Landed cost includes each line’s share of the discount and additional cost.'
                            : 'Landed cost is worked out when the goods are received.' }}
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Product</th>
                                <th class="px-4 py-3 text-right">Qty</th>
                                <th class="px-4 py-3 text-right">Unit cost</th>
                                <th class="px-4 py-3 text-right">Landed cost</th>
                                <th class="px-4 py-3 text-right">Line total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($purchase->items as $item)
                                <tr>
                                    <td class="px-4 py-3">
                                        <span class="font-medium text-slate-800">{{ $item->variant?->full_name ?? 'Deleted product' }}</span>
                                        <span class="block font-mono text-xs text-slate-400">{{ $item->variant?->sku }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-slate-700">{{ number_format($item->quantity) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-700">@money($item->unit_cost)</td>
                                    <td class="px-4 py-3 text-right {{ $item->landed_unit_cost !== null ? 'font-semibold text-slate-900' : 'text-slate-400' }}">
                                        {{ $item->landed_unit_cost !== null ? Money::format((float) $item->landed_unit_cost) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($item->line_total)</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">This purchase has no items.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot class="border-t border-slate-200 text-sm">
                            <tr><td colspan="4" class="px-4 py-2 text-right text-slate-500">Goods</td><td class="px-4 py-2 text-right text-slate-900">@money($purchase->subtotal)</td></tr>
                            <tr><td colspan="4" class="px-4 py-2 text-right text-slate-500">Discount</td><td class="px-4 py-2 text-right text-emerald-700">− @money($purchase->discount)</td></tr>
                            <tr><td colspan="4" class="px-4 py-2 text-right text-slate-500">Additional cost</td><td class="px-4 py-2 text-right text-slate-900">+ @money($purchase->additional_cost)</td></tr>
                            <tr class="text-base font-bold"><td colspan="4" class="px-4 py-3 text-right">Total</td><td class="px-4 py-3 text-right">@money($purchase->total)</td></tr>
                        </tfoot>
                    </table>
                </div>
            </section>

            @if($movements->isNotEmpty())
                <section class="card">
                    <div class="border-b border-slate-200 px-5 py-4">
                        <h2 class="text-base font-bold text-slate-900">Stock added</h2>
                        <p class="text-xs text-slate-500">Every unit received left a stock movement behind.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Product</th>
                                    <th class="px-4 py-3 text-right">Quantity</th>
                                    <th class="px-4 py-3 text-right">Stock after</th>
                                    <th class="px-4 py-3 text-right">Unit cost</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($movements as $movement)
                                    <tr>
                                        <td class="px-4 py-3">
                                            <a href="{{ route('admin.stock.show', $movement) }}" class="text-brand-600 hover:underline">{{ $movement->variant?->full_name }}</a>
                                        </td>
                                        <td class="px-4 py-3 text-right text-emerald-700">+{{ number_format($movement->quantity) }}</td>
                                        <td class="px-4 py-3 text-right text-slate-700">{{ number_format($movement->stock_after) }}</td>
                                        <td class="px-4 py-3 text-right text-slate-700">@money($movement->unit_cost)</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>

        <aside class="space-y-6">
            @if($canEdit)
                <form method="POST" action="{{ route('admin.purchases.receive', $purchase) }}" class="card space-y-3 p-6"
                      x-data="{
                          pay: 0,
                          method: @js(array_key_first($methods)),
                          account: @js((string) ($methodAccounts[array_key_first($methods)] ?? '')),
                          defaults: @js($methodAccounts),
                      }">
                    @csrf
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Receive goods</h2>
                        <p class="text-xs text-slate-500">
                            Adds {{ number_format($purchase->items->sum('quantity')) }} units to stock and bills
                            {{ Money::format((float) $purchase->total) }} to {{ $purchase->supplier?->name }}. This cannot be undone.
                        </p>
                    </div>

                    @if($canPay)
                        <div>
                            <label for="pay_now" class="label">Pay now <span class="text-slate-400">(optional)</span></label>
                            <input id="pay_now" name="pay_now" type="number" step="0.01" min="0" max="{{ (float) $purchase->total + max(0, $balance) }}"
                                   x-model="pay" class="input" placeholder="0.00">
                            <p class="mt-1 text-xs text-slate-400">Leave empty to pay later from the supplier page.</p>
                        </div>

                        <div class="grid grid-cols-2 gap-2" x-show="Number(pay) > 0" x-cloak>
                            <div>
                                <label for="receive_method" class="label">Method</label>
                                <select id="receive_method" name="method" x-model="method" @change="account = String(defaults[method] ?? account)" class="input">
                                    @foreach($methods as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="receive_account" class="label">Paid from</label>
                                <select id="receive_account" name="account_id" x-model="account" class="input">
                                    <option value="">Choose</option>
                                    @foreach($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }} ({{ Money::format($balances[$account->id] ?? 0, false) }})</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @endif

                    <button type="submit" class="btn-primary w-full">Receive into stock</button>
                </form>
            @endif

            <section class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Details</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-slate-500">Supplier</dt>
                        <dd>
                            @if($purchase->supplier)
                                <a href="{{ route('admin.suppliers.show', $purchase->supplier) }}" class="text-brand-600 hover:underline">{{ $purchase->supplier->name }}</a>
                            @else — @endif
                        </dd>
                    </div>
                    <div><dt class="text-slate-500">Purchase date</dt><dd class="text-slate-900">{{ $purchase->purchase_date?->format('d M Y') }}</dd></div>
                    <div><dt class="text-slate-500">Supplier invoice</dt><dd class="font-mono text-slate-900">{{ $purchase->invoice_number ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Created by</dt><dd class="text-slate-900">{{ $purchase->creator?->name ?? 'System' }} · {{ $purchase->created_at?->format('d M Y') }}</dd></div>
                    @if($purchase->cancelled_at)
                        <div><dt class="text-slate-500">Cancelled</dt><dd class="text-rose-600">{{ $purchase->cancelled_at->format('d M Y, g:i a') }}</dd></div>
                    @endif
                </dl>
            </section>

            @if($purchase->note)
                <section class="card p-6">
                    <h2 class="text-base font-bold text-slate-900">Note</h2>
                    <p class="mt-3 whitespace-pre-line text-sm text-slate-600">{{ $purchase->note }}</p>
                </section>
            @endif
        </aside>
    </div>
@endsection
