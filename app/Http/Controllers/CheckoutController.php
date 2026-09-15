<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CartService;
use App\Services\OrderService;
use App\Services\Payment\PaymentManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(
        protected CartService $cart,
        protected OrderService $orders,
        protected PaymentManager $payments,
    ) {}

    public function show()
    {
        $cart = $this->cart->current();

        if ($cart->items->isEmpty()) {
            return redirect()->route('shop.index')->with('error', 'Your cart is empty.');
        }

        return view('checkout.index', [
            'cart' => $cart,
            'totals' => $this->cart->totals(),
            'gateways' => $this->payments->all(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'shipping_address' => ['required', 'string', 'max:500'],
            'shipping_city' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['required', Rule::in($this->payments->keys())],
        ]);

        try {
            $order = $this->orders->placeFromCart($data);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Lets a guest see this order's confirmation from this browser session only.
        $request->session()->push('placed_orders', $order->order_number);

        $this->orders->sendConfirmation($order);

        if ($redirectUrl = $this->payments->get($order->payment_method)->initiate($order)) {
            return redirect()->away($redirectUrl);
        }

        return redirect()->route('checkout.success', $order);
    }

    public function success(Request $request, Order $order)
    {
        abort_unless($this->canView($request, $order), 403);

        return view('checkout.success', ['order' => $order->load('items')]);
    }

    /**
     * The confirmation page shows the customer's address and phone, so it is
     * limited to: the signed link from the confirmation email, the order's own
     * account, staff, or the browser session that placed it.
     */
    protected function canView(Request $request, Order $order): bool
    {
        if ($request->hasValidSignature()) {
            return true;
        }

        if ($user = $request->user()) {
            if ($user->isStaff() || ($order->user_id !== null && $order->user_id === $user->id)) {
                return true;
            }
        }

        return in_array($order->order_number, (array) $request->session()->get('placed_orders', []), true);
    }
}
