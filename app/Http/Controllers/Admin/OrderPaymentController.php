<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Order;
use App\Services\PaymentService;
use App\Support\Money;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Records money received against an order (courier remittance, bKash, cash at the counter...). */
class OrderPaymentController extends Controller implements HasMiddleware
{
    public function __construct(protected PaymentService $payments) {}

    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, Closure $next) {
                abort_unless($request->user()->canAny(['accounting.create', 'pos.sell']), 403, 'You do not have permission to record payments.');

                return $next($request);
            }),
        ];
    }

    public function store(Request $request, Order $order)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'method' => ['required', Rule::in(array_keys(PaymentService::manualMethods()))],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.min' => 'The payment amount must be more than zero.',
            'account_id.exists' => 'Choose an active account to receive the money.',
        ]);

        try {
            $this->payments->record(
                $order,
                (float) $data['amount'],
                Account::findOrFail($data['account_id']),
                $data['method'],
                $data['note'] ?? null,
                $data['reference'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment of ' . Money::format($data['amount']) . ' recorded for order ' . $order->order_number . '.');
    }
}
