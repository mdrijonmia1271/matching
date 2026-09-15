@extends('layouts.admin')

@section('title', $coupon->exists ? 'Edit coupon' : 'New coupon')
@section('heading', $coupon->exists ? 'Edit coupon' : 'New coupon')

@section('content')
    <form method="POST"
          action="{{ $coupon->exists ? route('admin.coupons.update', $coupon) : route('admin.coupons.store') }}"
          class="card max-w-2xl p-6">
        @csrf
        @if($coupon->exists) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="code" class="label">Code</label>
                <input id="code" name="code" type="text" required value="{{ old('code', $coupon->code) }}"
                       class="input uppercase" placeholder="EID25">
            </div>

            <div>
                <label for="type" class="label">Discount type</label>
                <select id="type" name="type" class="input">
                    <option value="percent" @selected(old('type', $coupon->type) === 'percent')>Percentage</option>
                    <option value="fixed" @selected(old('type', $coupon->type) === 'fixed')>Fixed amount</option>
                </select>
            </div>

            <div>
                <label for="value" class="label">Value</label>
                <input id="value" name="value" type="number" step="0.01" min="0" required
                       value="{{ old('value', $coupon->value) }}" class="input">
                <p class="mt-1 text-xs text-slate-500">Percent (e.g. 10) or a flat amount in Tk.</p>
            </div>

            <div>
                <label for="max_discount" class="label">Max discount <span class="text-slate-400">(optional)</span></label>
                <input id="max_discount" name="max_discount" type="number" step="0.01" min="0"
                       value="{{ old('max_discount', $coupon->max_discount) }}" class="input">
            </div>

            <div>
                <label for="min_order" class="label">Minimum order</label>
                <input id="min_order" name="min_order" type="number" step="0.01" min="0"
                       value="{{ old('min_order', $coupon->min_order ?? 0) }}" class="input">
            </div>

            <div>
                <label for="usage_limit" class="label">Usage limit <span class="text-slate-400">(optional)</span></label>
                <input id="usage_limit" name="usage_limit" type="number" min="1"
                       value="{{ old('usage_limit', $coupon->usage_limit) }}" class="input">
            </div>

            <div>
                <label for="starts_at" class="label">Starts at <span class="text-slate-400">(optional)</span></label>
                <input id="starts_at" name="starts_at" type="datetime-local" class="input"
                       value="{{ old('starts_at', $coupon->starts_at?->format('Y-m-d\TH:i')) }}">
            </div>

            <div>
                <label for="expires_at" class="label">Expires at <span class="text-slate-400">(optional)</span></label>
                <input id="expires_at" name="expires_at" type="datetime-local" class="input"
                       value="{{ old('expires_at', $coupon->expires_at?->format('Y-m-d\TH:i')) }}">
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $coupon->is_active ?? true)) class="rounded text-brand-600 focus:ring-brand-500">
                Coupon is active
            </label>
        </div>

        <div class="mt-6 flex gap-3">
            <button type="submit" class="btn-primary">{{ $coupon->exists ? 'Save changes' : 'Create coupon' }}</button>
            <a href="{{ route('admin.coupons.index') }}" class="btn-secondary">Cancel</a>
        </div>
    </form>
@endsection
