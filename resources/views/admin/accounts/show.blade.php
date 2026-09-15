@extends('layouts.admin')

@section('title', $account->name)
@section('heading', $account->name)

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('admin.accounts.index') }}" class="text-sm text-slate-500 hover:text-brand-600">&larr; All accounts</a>
        <div class="flex gap-2">
            @can('reports.export')
                <a href="{{ route('admin.accounts.export', [$account] + request()->except('page')) }}" class="btn-secondary">Export CSV</a>
            @endcan
            @can('accounting.edit')
                <a href="{{ route('admin.accounts.edit', $account) }}" class="btn-secondary">Edit account</a>
            @endcan
        </div>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Current balance</p>
            <p class="mt-2 text-2xl font-bold {{ $balance < 0 ? 'text-rose-600' : 'text-slate-900' }}">@money($balance)</p>
            <p class="text-xs text-slate-400">{{ $account->type_label }}{{ $account->account_number ? ' · ' . $account->account_number : '' }}{{ $account->is_active ? '' : ' · Inactive' }}</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Opening balance</p>
            <p class="mt-2 text-2xl font-bold text-slate-900">@money($account->opening_balance)</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Money in {{ request()->hasAny(['from', 'to', 'type', 'q', 'direction']) ? '(filtered)' : '' }}</p>
            <p class="mt-2 text-2xl font-bold text-emerald-600">@money($moneyIn)</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Money out {{ request()->hasAny(['from', 'to', 'type', 'q', 'direction']) ? '(filtered)' : '' }}</p>
            <p class="mt-2 text-2xl font-bold text-rose-600">@money($moneyOut)</p>
        </div>
    </div>

    <div class="card mt-6">
        <form method="GET" class="flex flex-wrap items-center gap-2 border-b border-slate-200 p-4">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search notes" class="input w-52">
            <select name="type" class="input w-44">
                <option value="">Any type</option>
                @foreach($types as $key => $label)
                    <option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="direction" class="input w-32">
                <option value="">In &amp; out</option>
                <option value="in" @selected(request('direction') === 'in')>Money in</option>
                <option value="out" @selected(request('direction') === 'out')>Money out</option>
            </select>
            <input type="date" name="from" value="{{ request('from') }}" class="input w-40" aria-label="From date">
            <input type="date" name="to" value="{{ request('to') }}" class="input w-40" aria-label="To date">
            <button type="submit" class="btn-secondary">Filter</button>
            @if(request()->hasAny(['q', 'type', 'direction', 'from', 'to']))
                <a href="{{ route('admin.accounts.show', $account) }}" class="text-sm text-slate-500 hover:underline">Reset</a>
            @endif
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">Details</th>
                        <th class="px-4 py-3 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($transactions as $transaction)
                        @include('admin.accounts._row', ['transaction' => $transaction])
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-slate-500">No transactions match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $transactions->links() }}</div>
@endsection
