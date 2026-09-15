@extends('layouts.admin')

@section('title', 'Accounts')
@section('heading', 'Accounts')

@section('content')
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <div class="card bg-slate-900 p-5 text-white">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total in active accounts</p>
            <p class="mt-2 text-2xl font-bold">@money($total)</p>
        </div>
        @foreach($accounts as $account)
            <a href="{{ route('admin.accounts.show', $account) }}" class="card p-5 transition hover:border-brand-300 {{ $account->is_active ? '' : 'opacity-60' }}">
                <p class="flex items-center justify-between text-xs font-medium uppercase tracking-wide text-slate-500">
                    {{ $account->name }}
                    @unless($account->is_active)<span class="normal-case text-slate-400">Inactive</span>@endunless
                </p>
                <p class="mt-2 text-2xl font-bold {{ ($balances[$account->id] ?? 0) < 0 ? 'text-rose-600' : 'text-slate-900' }}">@money($balances[$account->id] ?? 0)</p>
                <p class="text-xs text-slate-400">{{ $account->type_label }}{{ $account->account_number ? ' · ' . $account->account_number : '' }}</p>
            </a>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-[1fr_360px]">
        <div class="card min-w-0">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-base font-bold text-slate-900">Latest transactions</h2>
                <p class="text-xs text-slate-500">Open an account to see its full history.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Account</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Details</th>
                            <th class="px-4 py-3 text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($recent as $transaction)
                            @include('admin.accounts._row', ['transaction' => $transaction, 'showAccount' => true])
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-slate-500">No money has been recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <aside class="space-y-6">
            @can('accounting.create')
                <form method="POST" action="{{ route('admin.accounts.entries.store') }}" class="card space-y-3 p-5" x-data="{ direction: @js(old('direction', 'in')) }">
                    @csrf
                    <h2 class="text-base font-bold text-slate-900">Money in / out</h2>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach(['in' => 'Money in', 'out' => 'Money out'] as $key => $label)
                            <label class="flex cursor-pointer items-center justify-center rounded-lg border-2 px-3 py-2 text-sm font-semibold"
                                   :class="direction === @js($key) ? '{{ $key === 'in' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-rose-500 bg-rose-50 text-rose-700' }}' : 'border-slate-200 text-slate-500'">
                                <input type="radio" name="direction" value="{{ $key }}" x-model="direction" class="sr-only">{{ $label }}
                            </label>
                        @endforeach
                    </div>
                    <select name="account_id" required class="input" aria-label="Account">
                        @foreach($accounts->where('is_active', true) as $account)
                            <option value="{{ $account->id }}" @selected(old('account_id') == $account->id)>{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <input name="amount" type="number" step="0.01" min="0.01" required value="{{ old('amount') }}" class="input" placeholder="Amount">
                    <input name="note" type="text" required maxlength="300" value="{{ old('note') }}" class="input" placeholder="What is it for? e.g. Owner added cash">
                    <button type="submit" class="btn-primary w-full">Record</button>
                </form>

                <form method="POST" action="{{ route('admin.accounts.transfers.store') }}" class="card space-y-3 p-5">
                    @csrf
                    <h2 class="text-base font-bold text-slate-900">Transfer between accounts</h2>
                    <div class="grid grid-cols-2 gap-2">
                        <select name="from_account_id" required class="input" aria-label="From account">
                            @foreach($accounts->where('is_active', true) as $account)
                                <option value="{{ $account->id }}" @selected(old('from_account_id', $accounts->firstWhere('code', 'cash')?->id) == $account->id)>From {{ $account->name }}</option>
                            @endforeach
                        </select>
                        <select name="to_account_id" required class="input" aria-label="To account">
                            @foreach($accounts->where('is_active', true) as $account)
                                <option value="{{ $account->id }}" @selected(old('to_account_id', $accounts->firstWhere('code', 'bank')?->id) == $account->id)>To {{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <input name="amount" type="number" step="0.01" min="0.01" required class="input" placeholder="Amount">
                    <input name="note" type="text" maxlength="300" class="input" placeholder="Note (optional), e.g. Cash deposited at bank">
                    <button type="submit" class="btn-secondary w-full">Transfer</button>
                </form>
            @endcan

            @can('accounting.edit')
                <form method="POST" action="{{ route('admin.accounts.store') }}" class="card space-y-3 p-5">
                    @csrf
                    <h2 class="text-base font-bold text-slate-900">Add account</h2>
                    <input name="name" type="text" required maxlength="60" class="input" placeholder="e.g. City Bank, Rocket">
                    <select name="type" class="input" aria-label="Account type">
                        @foreach($types as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <input name="account_number" type="text" maxlength="60" class="input" placeholder="Account / wallet number (optional)">
                    <input name="opening_balance" type="number" step="0.01" min="0" class="input" placeholder="Opening balance (optional)">
                    <button type="submit" class="btn-secondary w-full">Add account</button>
                </form>
            @endcan
        </aside>
    </div>
@endsection
