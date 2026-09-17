@extends('layouts.admin')

@section('title', 'Returns')
@section('heading', 'Returns')

@section('content')
    <div class="grid gap-4 sm:grid-cols-3">
        <a href="{{ route('admin.returns.index', ['status' => 'requested']) }}" class="card p-5 transition hover:border-brand-300">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Open returns</p>
            <p class="mt-2 text-2xl font-bold {{ $stats['open'] > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ number_format($stats['open']) }}</p>
            <p class="text-xs text-slate-400">Requested or approved</p>
        </a>
        <a href="{{ route('admin.returns.index', ['status' => 'approved']) }}" class="card p-5 transition hover:border-brand-300">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Waiting for goods</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($stats['waiting']) }}</p>
            <p class="text-xs text-slate-400">Approved, not yet received</p>
        </a>
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Value returned</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">@money($stats['value'])</p>
            <p class="text-xs text-slate-400">Goods received back, at what they sold for</p>
        </div>
    </div>

    <div class="card mt-6">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Return, order or customer" class="input w-56">

                <select name="status" class="input w-36" aria-label="Status">
                    <option value="">Any status</option>
                    @foreach($statuses as $key => $label)
                        <option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="reason" class="input w-48" aria-label="Reason">
                    <option value="">Any reason</option>
                    @foreach($reasons as $key => $label)
                        <option value="{{ $key }}" @selected(request('reason') === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn-secondary">Filter</button>
                @if(request()->hasAny(['q', 'status', 'reason']))
                    <a href="{{ route('admin.returns.index') }}" class="text-sm text-slate-500 hover:underline">Reset</a>
                @endif
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Return</th>
                        <th class="px-4 py-3">Order</th>
                        <th class="px-4 py-3">Reason</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Units</th>
                        <th class="px-4 py-3 text-right">Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($returns as $return)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.returns.show', $return) }}" class="font-mono text-xs font-semibold text-brand-600 hover:underline">{{ $return->number }}</a>
                                <p class="text-xs text-slate-400">{{ $return->created_at?->format('d M Y') }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-xs text-slate-700">{{ $return->order?->order_number }}</span>
                                <span class="block text-xs text-slate-400">{{ $return->order?->customer_name }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-600">{{ $return->reason_label }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $return->status_color }}">{{ $return->status_label }}</span>
                            </td>
                            <td class="px-4 py-3 text-right text-slate-700">{{ number_format($return->quantity) }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-slate-900">@money($return->refund_total)</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No returns match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $returns->links() }}</div>
@endsection
