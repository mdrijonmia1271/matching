@extends('layouts.admin')

@section('title', 'Edit ' . $account->name)
@section('heading', 'Edit account')

@section('content')
    <form method="POST" action="{{ route('admin.accounts.update', $account) }}" class="card max-w-xl space-y-4 p-6">
        @csrf @method('PUT')

        <div>
            <label for="name" class="label">Name</label>
            <input id="name" name="name" type="text" required maxlength="60" value="{{ old('name', $account->name) }}" class="input">
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="type" class="label">Type</label>
                <select id="type" name="type" class="input">
                    @foreach($types as $key => $label)
                        <option value="{{ $key }}" @selected(old('type', $account->type) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="account_number" class="label">Account / wallet number</label>
                <input id="account_number" name="account_number" type="text" maxlength="60" value="{{ old('account_number', $account->account_number) }}" class="input">
            </div>
        </div>

        <div>
            <label for="opening_balance" class="label">Opening balance</label>
            <input id="opening_balance" name="opening_balance" type="number" step="0.01" min="0" required value="{{ old('opening_balance', $account->opening_balance) }}" class="input">
            <p class="mt-1 text-xs text-slate-500">The money that was already in this account before you started recording in the system. Changes are logged.</p>
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $account->is_active)) class="rounded text-brand-600 focus:ring-brand-500">
            Active (can receive and pay out money)
        </label>

        <div class="flex gap-3">
            <button type="submit" class="btn-primary">Save</button>
            <a href="{{ route('admin.accounts.show', $account) }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
@endsection
