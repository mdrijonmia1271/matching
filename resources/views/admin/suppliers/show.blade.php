@extends('layouts.admin')

@section('title', $supplier->name)
@section('heading', $supplier->name)

@section('content')
    @php
        use App\Support\Money;

        $balance = (float) $supplier->current_balance;
        $canPay = $balance > 0 && ! $supplier->trashed() && $methods
            && auth()->user()->can('purchases.edit') && auth()->user()->can('accounting.create');
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.suppliers.index') }}" class="text-sm text-slate-500 hover:text-brand-600">&larr; Back to suppliers</a>

        <div class="flex flex-wrap items-center gap-2">
            @if($supplier->trashed())
                <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-600">Archived</span>
            @endif
            @can('purchases.create')
                @unless($supplier->trashed())
                    <a href="{{ route('admin.purchases.create', ['supplier' => $supplier->id]) }}" class="btn-secondary">New purchase</a>
                @endunless
            @endcan
            @can('purchases.edit')
                @if($supplier->trashed())
                    <form method="POST" action="{{ route('admin.suppliers.restore', $supplier) }}">
                        @csrf @method('PATCH')
                        <button type="submit" class="btn-secondary">Restore</button>
                    </form>
                @else
                    <a href="{{ route('admin.suppliers.edit', $supplier) }}" class="btn-secondary">Edit</a>
                    <form method="POST" action="{{ route('admin.suppliers.destroy', $supplier) }}" x-data
                          @submit="if (! confirm(@js('Archive ' . $supplier->name . '? Their payment history is kept.'))) $event.preventDefault()">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn-secondary text-rose-600">Archive</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach([
            ['Opening due', Money::format((float) $supplier->opening_due), 'Owed before this system', 'text-slate-900'],
            ['Total purchased', Money::format((float) $supplier->purchases_total), number_format($supplier->purchases_count) . ' received ' . \Illuminate\Support\Str::plural('purchase', $supplier->purchases_count), 'text-slate-900'],
            ['Total paid', Money::format((float) $supplier->total_paid), number_format($supplier->payments_count) . ' ' . \Illuminate\Support\Str::plural('payment', $supplier->payments_count), 'text-emerald-700'],
            ['Balance', Money::format($balance), $balance > 0 ? 'What the shop still owes' : 'Nothing owed', $balance > 0 ? 'text-rose-600' : 'text-slate-900'],
            ['Last payment', $supplier->last_payment_at?->format('d M Y') ?? '—', $supplier->last_payment_at?->diffForHumans() ?? 'No payments yet', 'text-slate-900'],
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
            <section class="card">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Purchases</h2>
                        <p class="text-xs text-slate-500">The last 10 purchases from this supplier.</p>
                    </div>
                    <a href="{{ route('admin.purchases.index', ['supplier' => $supplier->id]) }}" class="text-sm text-brand-600 hover:underline">See all</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Purchase</th>
                                <th class="px-4 py-3">Date</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($purchases as $purchase)
                                <tr>
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.purchases.show', $purchase) }}" class="font-mono text-xs font-semibold text-brand-600 hover:underline">{{ $purchase->number }}</a>
                                        @if($purchase->invoice_number)<span class="block text-xs text-slate-400">Invoice {{ $purchase->invoice_number }}</span>@endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-500">{{ $purchase->purchase_date?->format('d M Y') }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $purchase->status_color }}">{{ $purchase->status_label }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($purchase->total)</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No purchases yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card">
                <div class="border-b border-slate-200 px-5 py-4">
                    <h2 class="text-base font-bold text-slate-900">Payments</h2>
                    <p class="text-xs text-slate-500">Money paid to this supplier, newest first.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Receipt</th>
                                <th class="px-4 py-3">Paid from</th>
                                <th class="px-4 py-3">Note</th>
                                <th class="px-4 py-3 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($payments as $payment)
                                <tr class="align-top">
                                    <td class="px-4 py-3">
                                        <p class="font-mono text-xs font-semibold text-slate-900">{{ $payment->receipt_number }}</p>
                                        <p class="text-xs text-slate-500">{{ $payment->paid_at?->format('d M Y, g:i a') }}</p>
                                        @if($payment->payer)<p class="text-xs text-slate-400">{{ $payment->payer->name }}</p>@endif
                                    </td>
                                    <td class="px-4 py-3 text-slate-600">
                                        {{ $payment->method_label }}{{ $payment->account ? ' → ' . $payment->account->name : '' }}
                                        @if($payment->reference)<span class="block font-mono text-xs text-slate-400">{{ $payment->reference }}</span>@endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-500">{{ $payment->note ?: '—' }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($payment->amount)</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-10 text-center text-slate-500">No payments yet.</td></tr>
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
            @if($canPay)
                <form method="POST" action="{{ route('admin.suppliers.payments.store', $supplier) }}" class="card space-y-3 p-6"
                      x-data="{ method: @js(old('method', array_key_first($methods))), account: @js((string) old('account_id', $methodAccounts[old('method', array_key_first($methods))] ?? '')), defaults: @js($methodAccounts) }">
                    @csrf
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Pay supplier</h2>
                        <p class="text-xs text-slate-500">The money goes out of the account you choose.</p>
                    </div>
                    <div>
                        <label for="pay_amount" class="label">Amount</label>
                        <input id="pay_amount" name="amount" type="number" step="0.01" min="0.01" max="{{ $balance }}" required value="{{ old('amount', $balance) }}" class="input">
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label for="pay_method" class="label">Method</label>
                            <select id="pay_method" name="method" x-model="method" @change="account = String(defaults[method] ?? account)" class="input">
                                @foreach($methods as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="pay_account" class="label">Paid from</label>
                            <select id="pay_account" name="account_id" x-model="account" required class="input">
                                <option value="">Choose</option>
                                @foreach($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }} ({{ Money::format($balances[$account->id] ?? 0, false) }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="pay_reference" class="label">Transaction / cheque no. <span class="text-slate-400">(optional)</span></label>
                        <input id="pay_reference" name="reference" type="text" maxlength="100" value="{{ old('reference') }}" class="input font-mono">
                    </div>
                    <div>
                        <label for="pay_note" class="label">Note <span class="text-slate-400">(optional)</span></label>
                        <input id="pay_note" name="note" type="text" maxlength="500" value="{{ old('note') }}" class="input" placeholder="e.g. Payment for September stock">
                    </div>
                    <button type="submit" class="btn-primary w-full">Record payment</button>
                </form>
            @endif

            <section class="card p-6">
                <h2 class="text-base font-bold text-slate-900">Contact</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-slate-500">Company</dt><dd class="text-slate-900">{{ $supplier->company ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Phone</dt><dd class="font-mono text-slate-900">{{ $supplier->phone ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Email</dt><dd class="break-all text-slate-900">{{ $supplier->email ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Address</dt><dd class="whitespace-pre-line text-slate-900">{{ $supplier->address ?: '—' }}</dd></div>
                    <div><dt class="text-slate-500">Supplier since</dt><dd class="text-slate-900">{{ $supplier->created_at?->format('d M Y') }}</dd></div>
                </dl>
            </section>

            @if($supplier->notes)
                <section class="card p-6">
                    <h2 class="text-base font-bold text-slate-900">Notes</h2>
                    <p class="mt-3 whitespace-pre-line text-sm text-slate-600">{{ $supplier->notes }}</p>
                </section>
            @endif
        </aside>
    </div>
@endsection
