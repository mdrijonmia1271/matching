@extends('layouts.admin')

@section('title', 'Customers')
@section('heading', 'Customers')

@section('content')
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Customers</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($stats['customers']) }}</p>
            <p class="text-xs text-slate-400">Active records</p>
        </div>
        <a href="{{ route('admin.customers.index', ['due' => 'with', 'sort' => 'due']) }}" class="card p-5 transition hover:border-brand-300">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Customers with due</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">{{ number_format($stats['with_due']) }}</p>
            <p class="text-xs text-slate-400">Show them, highest due first</p>
        </a>
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Total due</p>
            <p class="mt-2 text-2xl font-bold {{ $stats['total_due'] > 0 ? 'text-rose-600' : 'text-slate-900' }}">@money($stats['total_due'])</p>
            <p class="text-xs text-slate-400">Opening dues plus unpaid confirmed orders</p>
        </div>
    </div>

    <div class="card mt-6">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 p-4">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, phone or email" class="input w-60">

                <select name="group" class="input w-36" aria-label="Customer group">
                    <option value="">All groups</option>
                    @foreach(\App\Models\Customer::GROUPS as $key => $label)
                        <option value="{{ $key }}" @selected(request('group') === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                <select name="due" class="input w-36" aria-label="Due">
                    <option value="">Any due</option>
                    <option value="with" @selected(request('due') === 'with')>With due</option>
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
                @if(request()->hasAny(['q', 'group', 'due', 'status', 'sort']))
                    <a href="{{ route('admin.customers.index') }}" class="text-sm text-slate-500 hover:underline">Reset</a>
                @endif
            </form>

            @can('customers.create')
                <a href="{{ route('admin.customers.create') }}" class="btn-primary ml-auto">Add customer</a>
            @endcan
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Phone</th>
                        <th class="px-4 py-3">Group</th>
                        <th class="px-4 py-3 text-center">Orders</th>
                        <th class="px-4 py-3 text-right">Spent</th>
                        <th class="px-4 py-3 text-right">Paid</th>
                        <th class="px-4 py-3 text-right">Due</th>
                        <th class="px-4 py-3">Last order</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($customers as $customer)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('admin.customers.show', $customer) }}" class="font-semibold text-brand-600 hover:underline">{{ $customer->name }}</a>
                                @if($customer->user_id)
                                    <span class="ml-1 text-xs text-slate-400">&middot; online account</span>
                                @endif
                                <p class="text-xs text-slate-400">{{ $customer->email ?: '—' }}</p>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $customer->phone ?: '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $customer->groupColor() }}">{{ $customer->group_label }}</span>
                            </td>
                            <td class="px-4 py-3 text-center text-slate-600">{{ $customer->orders_count }}</td>
                            <td class="px-4 py-3 text-right text-slate-900">@money($customer->total_spent)</td>
                            <td class="px-4 py-3 text-right text-emerald-700">@money($customer->total_paid)</td>
                            <td class="px-4 py-3 text-right font-semibold {{ (float) $customer->current_due > 0 ? 'text-rose-600' : 'text-slate-400' }}">@money($customer->current_due)</td>
                            <td class="px-4 py-3 text-xs text-slate-500">{{ $customer->last_order_at?->format('d M Y') ?? 'No orders' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-slate-500">No customers match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $customers->links() }}</div>
@endsection
