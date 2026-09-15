<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class CouponController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:marketing.manage')];
    }

    public function index()
    {
        return view('admin.coupons.index', [
            'coupons' => Coupon::latest()->paginate(15),
        ]);
    }

    public function create()
    {
        return view('admin.coupons.form', ['coupon' => new Coupon(['type' => 'percent', 'is_active' => true])]);
    }

    public function store(Request $request)
    {
        $coupon = Coupon::create($this->validated($request));

        AuditLogger::log('marketing', 'coupon_created', $coupon, 'Coupon ' . $coupon->code . ' created', new: $coupon->only(['code', 'type', 'value', 'is_active']));

        return redirect()->route('admin.coupons.index')->with('success', 'Coupon created.');
    }

    public function edit(Coupon $coupon)
    {
        return view('admin.coupons.form', compact('coupon'));
    }

    public function update(Request $request, Coupon $coupon)
    {
        $coupon->fill($this->validated($request, $coupon));
        [$old, $new] = AuditLogger::changes($coupon);
        $coupon->save();

        if ($new) {
            AuditLogger::log('marketing', 'coupon_updated', $coupon, 'Coupon ' . $coupon->code . ' updated', $old, $new);
        }

        return redirect()->route('admin.coupons.index')->with('success', 'Coupon updated.');
    }

    public function destroy(Coupon $coupon)
    {
        $coupon->delete();

        AuditLogger::log('marketing', 'coupon_deleted', null, 'Coupon ' . $coupon->code . ' deleted', $coupon->only(['code', 'type', 'value']));

        return back()->with('success', 'Coupon deleted.');
    }

    protected function validated(Request $request, ?Coupon $coupon = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:coupons,code' . ($coupon ? ',' . $coupon->id : '')],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            // A percentage above 100 would give the order away for free.
            'value' => ['required', 'numeric', 'min:0', $request->input('type') === 'percent' ? 'max:100' : 'max:10000000'],
            'min_order' => ['nullable', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ], [
            'value.max' => 'A percentage discount cannot be more than 100.',
        ]);

        $data['code'] = strtoupper($data['code']);
        $data['min_order'] = $data['min_order'] ?? 0;
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
