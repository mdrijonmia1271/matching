<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Services\PaymentService;
use App\Support\Money;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Collects money against a customer's total due: opening due first, then unpaid orders oldest first. */
class CustomerPaymentController extends Controller implements HasMiddleware
{
    public function __construct(protected CustomerService $customers) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:customers.edit'),
            new Middleware(function (Request $request, Closure $next) {
                abort_unless($request->user()->canAny(['accounting.create', 'pos.sell']), 403, 'You do not have permission to record payments.');

                return $next($request);
            }),
        ];
    }

    public function store(Request $request, Customer $customer)
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
            $receipt = $this->customers->collectDue(
                $customer,
                (float) $data['amount'],
                Account::findOrFail($data['account_id']),
                $data['method'],
                $data['note'] ?? null,
                $data['reference'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $parts = $receipt->payments->map(fn ($payment) => $payment->order->order_number . ' ' . Money::format($payment->amount));

        if ((float) $receipt->opening_due_paid > 0) {
            $parts->prepend('opening due ' . Money::format($receipt->opening_due_paid));
        }

        return back()->with('success', sprintf('%s received (%s), applied to %s.',
            Money::format($receipt->amount), $receipt->receipt_number, $parts->implode(', ')));
    }
}
