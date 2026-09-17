<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\RefundService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Gives money back to a customer, out of one of the shop's accounts. */
class RefundController extends Controller implements HasMiddleware
{
    public function __construct(protected RefundService $refunds) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:orders.refund'),
            // Money leaves an account, so the accounting permission is needed as well.
            new Middleware('can:accounting.create'),
        ];
    }

    public function store(Request $request, Order $order)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
            'method' => ['required', Rule::in(array_keys(RefundService::methods()))],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('is_active', true)],
            'order_return_id' => ['nullable', Rule::exists('order_returns', 'id')->where('order_id', $order->id)],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.min' => 'The refund amount must be more than zero.',
            'account_id.exists' => 'Choose an active account to refund from.',
            'order_return_id.exists' => 'That return is not on this order.',
        ]);

        try {
            $refund = $this->refunds->refund(
                $order,
                (float) $data['amount'],
                Account::findOrFail($data['account_id']),
                $data['method'],
                ! empty($data['order_return_id']) ? OrderReturn::find($data['order_return_id']) : null,
                $data['note'] ?? null,
                $data['reference'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $order->refresh();

        return back()->with('success', sprintf('%s refunded from %s (%s). %s',
            Money::format((float) $refund->amount),
            $refund->account->name,
            $refund->number,
            $order->refundable_amount > 0
                ? Money::format($order->refundable_amount) . ' could still go back.'
                : 'The order is now fully refunded.'));
    }
}
