@extends('layouts.admin')

@section('title', 'Suppliers')
@section('heading', 'Suppliers')

@section('content')
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Suppliers</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($stats['suppliers']) }}</p>
            <p class="text-xs text-slate-400">Active records</p>
        </div>
        <a href="{{ route('admin.suppliers.index', ['balance' => 'owed', 'sort' => 'balance']) }}" class="card p-5 transition hover:border-brand-300">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Suppliers we owe</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($stats['owed']) }}</p>
            <p class="text-xs text-slate-400">Show them, highest balance first</p>
        </a>
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Total payable</p>
            <p class="mt-2 text-2xl font-bold {{ $stats['payable'] > 0 ? 'text-rose-600' : 'text-slate-900' }}">@money($stats['payable'])</p>
            <p class="text-xs text-slate-400">What the shop owes all suppliers</p>
        </div>
    </div>

    <div class="card mt-6">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, company or phone" class="input w-60">

                <select name="balance" class="input w-36" aria-label="Balance">
                    <option value="">Any balance</option>
                    <option value="owed" @selected(request('balance') === 'owed')>We owe</option>
                </select>

                <select name="status" class="input w-32" aria-label="Status">
                    <option value="">Active</option>
                    <option value="archived" @selected(request('status') === 'archived')>Archived</option>
                </select>

                <select name="sort" class="input w-40" aria-label="Sort">
                    @foreach($sorts as $key => $label)
                        <option value="{{ $key }}" @selected($sort === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <button type="submit" class="btn-secondary">Filter</button>
                @if(request()->hasAny(['q', 'balance', 'status', 'sort']))
                    <a href="{{ route('admin.suppliers.index') }}" class="text-sm text-slate-500 hover:underline">Reset</a>
                @endif
            </form>

            <div class="ml-auto flex gap-2">
                @can('reports.export')
                    <a href="{{ route('admin.suppliers.export', request()->query()) }}" class="btn-secondary">Export CSV</a>
                @endcan
                @can('purchases.create')
                    <a href="{{ route('admin.suppliers.create') }}" class="btn-primary">Add supplier</a>
                @endcan
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Supplier</th>
                        <th class="px-4 py-3">Phone</th>
                        <th class="px-4 py-3 text-right">Opening due</th>
                        <th class="px-4 py-3 text-right">Purchased</th>
                        <th class="px-4 py-3 text-right">Paid</th>
                        <th class="px-4 py-3 text-right">Balance</th>
                        <th class="px-4 py-3">Last payment</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($suppliers as $supplier)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.suppliers.show', $supplier) }}" class="font-semibold text-brand-600 hover:underline">{{ $supplier->name }}</a>
                                <p class="text-xs text-slate-400">{{ $supplier->company ?: '—' }}</p>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $supplier->phone ?: '—' }}</td>
                            <td class="px-4 py-3 text-right text-slate-700">@money($supplier->opening_due)</td>
                            <td class="px-4 py-3 text-right text-slate-700">@money($supplier->purchases_total)</td>
                            <td class="px-4 py-3 text-right text-emerald-700">@money($supplier->total_paid)</td>
                            <td class="px-4 py-3 text-right font-semibold {{ (float) $supplier->current_balance > 0 ? 'text-rose-600' : 'text-slate-400' }}">@money($supplier->current_balance)</td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ $supplier->last_payment_at?->format('d M Y') ?? 'Never' }}</td>
                            <td class="px-4 py-3">
                                @can('purchases.edit')
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route('admin.suppliers.edit', $supplier) }}" class="text-xs font-semibold text-brand-600 hover:underline">Edit</a>
                                        <form method="POST" action="{{ route('admin.suppliers.destroy', $supplier) }}"
                                              onsubmit="return confirm(@js('Delete ' . $supplier->name . ' permanently? This cannot be undone.'))">
                                            @csrf @method('DELETE')
                                            <button class="text-xs text-rose-600 hover:underline">Delete</button>
                                        </form>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-slate-500">No suppliers match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $suppliers->links() }}</div>
@endsection
