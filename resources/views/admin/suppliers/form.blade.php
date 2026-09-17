@extends('layouts.admin')

@section('title', $supplier->exists ? 'Edit supplier' : 'Add supplier')
@section('heading', $supplier->exists ? 'Edit ' . $supplier->name : 'Add supplier')

@section('content')
    <form method="POST" action="{{ $supplier->exists ? route('admin.suppliers.update', $supplier) : route('admin.suppliers.store') }}"
          class="grid max-w-5xl gap-6 lg:grid-cols-[1fr_320px]">
        @csrf
        @if($supplier->exists) @method('PUT') @endif

        <section class="card p-6">
            <h2 class="text-base font-bold text-slate-900">Contact details</h2>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="label">Contact name</label>
                    <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name', $supplier->name) }}" class="input">
                    @error('name')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="company" class="label">Company <span class="text-slate-400">(optional)</span></label>
                    <input id="company" name="company" type="text" maxlength="150" value="{{ old('company', $supplier->company) }}" class="input">
                </div>
                <div>
                    <label for="phone" class="label">Phone</label>
                    <input id="phone" name="phone" type="tel" maxlength="30" value="{{ old('phone', $supplier->phone) }}" class="input font-mono" placeholder="01XXXXXXXXX">
                    @error('phone')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @else
                        <p class="mt-1 text-xs text-slate-500">Saved as 01XXXXXXXXX. Each number belongs to one supplier.</p>
                    @enderror
                </div>
                <div>
                    <label for="email" class="label">Email <span class="text-slate-400">(optional)</span></label>
                    <input id="email" name="email" type="email" maxlength="150" value="{{ old('email', $supplier->email) }}" class="input">
                    @error('email')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="address" class="label">Address</label>
                    <textarea id="address" name="address" rows="2" maxlength="500" class="input">{{ old('address', $supplier->address) }}</textarea>
                </div>
            </div>
        </section>

        <aside class="space-y-6">
            <section class="card space-y-4 p-6">
                <h2 class="text-base font-bold text-slate-900">Balance</h2>

                <div>
                    <label for="opening_due" class="label">Opening due</label>
                    <input id="opening_due" name="opening_due" type="number" step="0.01" min="0" value="{{ old('opening_due', $supplier->opening_due ?? 0) }}" class="input">
                    @error('opening_due')
                        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                    @else
                        <p class="mt-1 text-xs text-slate-500">What the shop already owed this supplier before using this system.</p>
                    @enderror
                </div>

                <div>
                    <label for="notes" class="label">Notes <span class="text-slate-400">(staff only)</span></label>
                    <textarea id="notes" name="notes" rows="4" maxlength="2000" class="input" placeholder="What they supply, payment terms, bank details…">{{ old('notes', $supplier->notes) }}</textarea>
                </div>
            </section>

            <div class="flex gap-3">
                <button type="submit" class="btn-primary flex-1">{{ $supplier->exists ? 'Save changes' : 'Add supplier' }}</button>
                <a href="{{ $supplier->exists ? route('admin.suppliers.show', $supplier) : route('admin.suppliers.index') }}" class="btn-secondary">Cancel</a>
            </div>
        </aside>
    </form>
@endsection
