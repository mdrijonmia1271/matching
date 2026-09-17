<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Supplier;
use App\Services\SupplierService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Pays a supplier out of one of the shop's accounts. */
class SupplierPaymentController extends Controller implements HasMiddleware
{
    public function __construct(protected SupplierService $suppliers) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:purchases.edit'),
            new Middleware('can:accounting.create'),
        ];
    }

    public function store(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'method' => ['required', Rule::in(array_keys(SupplierService::methods()))],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.min' => 'The payment amount must be more than zero.',
            'account_id.exists' => 'Choose an active account to pay from.',
        ]);

        try {
            $payment = $this->suppliers->pay(
                $supplier,
                (float) $data['amount'],
                Account::findOrFail($data['account_id']),
                $data['method'],
                $data['note'] ?? null,
                $data['reference'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf('%s paid to %s from %s (%s).',
            Money::format($payment->amount), $supplier->name, $payment->account->name, $payment->receipt_number));
    }
}
