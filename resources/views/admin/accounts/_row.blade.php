@php $in = $transaction->direction === 'in'; @endphp
<tr class="hover:bg-slate-50">
    <td class="whitespace-nowrap px-4 py-3 text-slate-600">
        {{ $transaction->transacted_at?->format('d M Y') }}
        <span class="block text-xs text-slate-400">{{ $transaction->transacted_at?->format('h:i A') }}</span>
    </td>
    @if($showAccount ?? false)
        <td class="px-4 py-3 text-slate-700">{{ $transaction->account?->name }}</td>
    @endif
    <td class="px-4 py-3">
        <span class="text-slate-800">{{ $transaction->type_label }}</span>
        <span class="block text-xs text-slate-400">{{ $transaction->user?->name ?? 'System' }}</span>
    </td>
    <td class="px-4 py-3">
        @if($transaction->reference instanceof \App\Models\Order)
            <a href="{{ route('admin.orders.show', $transaction->reference) }}" class="text-xs font-semibold text-brand-600 hover:underline">{{ $transaction->reference->order_number }}</a>
        @elseif($transaction->reference instanceof \App\Models\CustomerPayment && $transaction->reference->customer)
            <a href="{{ route('admin.customers.show', $transaction->reference->customer) }}" class="text-xs font-semibold text-brand-600 hover:underline">{{ $transaction->reference->receipt_number }} &middot; {{ $transaction->reference->customer->name }}</a>
        @elseif($transaction->reference instanceof \App\Models\Refund && $transaction->reference->order)
            <a href="{{ route('admin.orders.show', $transaction->reference->order) }}" class="text-xs font-semibold text-brand-600 hover:underline">{{ $transaction->reference->number }} &middot; {{ $transaction->reference->order->order_number }}</a>
        @elseif($transaction->reference instanceof \App\Models\SupplierPayment && $transaction->reference->supplier)
            <a href="{{ route('admin.suppliers.show', $transaction->reference->supplier) }}" class="text-xs font-semibold text-brand-600 hover:underline">{{ $transaction->reference->receipt_number }} &middot; {{ $transaction->reference->supplier->name }}</a>
        @endif
        <span class="block max-w-sm truncate text-xs text-slate-500" title="{{ $transaction->note }}">{{ $transaction->note }}</span>
    </td>
    <td class="whitespace-nowrap px-4 py-3 text-right font-semibold {{ $in ? 'text-emerald-600' : 'text-rose-600' }}">
        {{ $in ? '+' : '−' }}@money($transaction->amount)
    </td>
</tr>
