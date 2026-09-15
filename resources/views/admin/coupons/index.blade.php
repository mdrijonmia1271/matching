@extends('layouts.admin')

@section('title', 'Coupons')
@section('heading', 'Coupons')

@section('content')
    <div class="card">
        <div class="flex items-center justify-between border-b border-slate-200 p-4">
            <p class="text-sm text-slate-500">{{ $coupons->total() }} coupons</p>
            <a href="{{ route('admin.coupons.create') }}" class="btn-primary">Add coupon</a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Discount</th>
                        <th class="px-4 py-3">Conditions</th>
                        <th class="px-4 py-3 text-center">Used</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($coupons as $coupon)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-mono font-semibold text-slate-900">{{ $coupon->code }}</td>
                            <td class="px-4 py-3 text-slate-700">
                                {{ $coupon->type === 'percent' ? rtrim(rtrim(number_format($coupon->value, 2), '0'), '.') . '%' : \App\Support\Money::format($coupon->value) }}
                                @if($coupon->max_discount)
                                    <span class="block text-xs text-slate-400">max @money($coupon->max_discount)</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-slate-500">
                                @if($coupon->min_order > 0)
                                    Min order @money($coupon->min_order)<br>
                                @endif
                                @if($coupon->expires_at)
                                    Expires {{ $coupon->expires_at->format('d M Y') }}
                                @else
                                    No expiry
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center text-slate-700">
                                {{ $coupon->used_count }}{{ $coupon->usage_limit ? ' / ' . $coupon->usage_limit : '' }}
                            </td>
                            <td class="px-4 py-3 text-center text-xs">
                                @if($coupon->reasonUnusableFor(PHP_INT_MAX) === null)
                                    <span class="text-emerald-600">Active</span>
                                @else
                                    <span class="text-slate-400">Inactive</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('admin.coupons.edit', $coupon) }}" class="text-xs font-semibold text-brand-600 hover:underline">Edit</a>
                                    <form method="POST" action="{{ route('admin.coupons.destroy', $coupon) }}"
                                          onsubmit="return confirm('Delete this coupon?')">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-rose-600 hover:underline">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">No coupons yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-6">{{ $coupons->links() }}</div>
@endsection
