@extends('layouts.admin')

@section('title', $customer->exists ? 'Edit customer' : 'Add customer')
@section('heading', $customer->exists ? 'Edit ' . $customer->name : 'Add customer')

@section('content')
    <form method="POST" action="{{ $customer->exists ? route('admin.customers.update', $customer) : route('admin.customers.store') }}"
          class="grid max-w-5xl gap-6 lg:grid-cols-[1fr_320px]">
        @csrf
        @if($customer->exists) @method('PUT') @endif

        <section class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Contact details</h2>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="name" class="label">Full name</label>
                    <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name', $customer->name) }}" class="input">
                    @error('name')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="phone" class="label">Phone</label>
                    <input id="phone" name="phone" type="tel" maxlength="30" value="{{ old('phone', $customer->phone) }}" class="input font-mono" placeholder="01XXXXXXXXX">
                    @error('phone')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @else
                        <p class="mt-1 text-xs text-slate-500">Saved as 01XXXXXXXXX. Each number belongs to one customer.</p>
                    @enderror
                </div>
                <div>
                    <label for="email" class="label">Email <span class="text-slate-400">(optional)</span></label>
                    <input id="email" name="email" type="email" maxlength="150" value="{{ old('email', $customer->email) }}" class="input">
                    @error('email')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="address" class="label">Address</label>
                    <textarea id="address" name="address" rows="2" maxlength="500" class="input">{{ old('address', $customer->address) }}</textarea>
                </div>
                <div>
                    <label for="city" class="label">City / area</label>
                    <input id="city" name="city" type="text" maxlength="100" value="{{ old('city', $customer->city) }}" class="input">
                </div>
            </div>
        </section>

        <aside class="space-y-6">
            <section class="card space-y-4 p-6">
                <h2 class="text-base font-bold text-slate-900">Account</h2>

                <div>
                    <label for="customer_group" class="label">Customer group</label>
                    <select id="customer_group" name="customer_group" required class="input">
                        @foreach(\App\Models\Customer::GROUPS as $key => $label)
                            <option value="{{ $key }}" @selected(old('customer_group', $customer->customer_group) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('customer_group')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="opening_due" class="label">Opening due</label>
                    <input id="opening_due" name="opening_due" type="number" step="0.01" min="0" value="{{ old('opening_due', $customer->opening_due ?? 0) }}" class="input">
                    @error('opening_due')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @else
                        <p class="mt-1 text-xs text-slate-500">What they already owed before using this system, e.g. from the old khata.</p>
                    @enderror
                </div>

                <div>
                    <label for="notes" class="label">Notes <span class="text-slate-400">(staff only)</span></label>
                    <textarea id="notes" name="notes" rows="4" maxlength="2000" class="input">{{ old('notes', $customer->notes) }}</textarea>
                </div>
            </section>

            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1">{{ $customer->exists ? 'Save changes' : 'Add customer' }}</button>
                <a href="{{ $customer->exists ? route('admin.customers.show', $customer) : route('admin.customers.index') }}" class="btn-secondary">Cancel</a>
            </div>
        </aside>
    </form>
@endsection
