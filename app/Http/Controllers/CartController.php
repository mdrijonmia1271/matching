<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Product;
use App\Services\CartService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(protected CartService $cart) {}

    public function index()
    {
        return view('cart.index', [
            'cart' => $this->cart->current(),
            'totals' => $this->cart->totals(),
        ]);
    }

    public function store(Request $request, Product $product)
    {
        abort_unless($product->is_active, 404);

        $data = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'variant_id' => ['nullable', 'integer'],
        ]);

        // Products with sizes/colours need the shopper's choice; others have one default variant.
        $variant = $product->has_variants
            ? $product->variants()->active()->find($data['variant_id'] ?? 0)
            : $product->variants()->active()->first();

        if (! $variant) {
            return back()->with('error', $product->has_variants
                ? 'Please choose a colour and size for ' . $product->name . '.'
                : $product->name . ' is not available right now.');
        }

        $variant->setRelation('product', $product);

        if ($variant->stock <= 0) {
            return back()->with('error', $variant->full_name . ' is out of stock.');
        }

        $result = $this->cart->add($variant, (int) ($data['quantity'] ?? 1));

        $message = $result['capped']
            ? 'Added to cart. Only ' . $variant->stock . ' unit(s) of ' . $variant->full_name . ' available, so the quantity was adjusted.'
            : $variant->full_name . ' added to cart.';

        return back()->with($result['capped'] ? 'warning' : 'success', $message);
    }

    public function update(Request $request, CartItem $item)
    {
        $this->authorizeItem($item);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:0', 'max:99'],
        ]);

        $this->cart->updateQuantity($item, (int) $data['quantity']);

        return back()->with('success', 'Cart updated.');
    }

    public function destroy(CartItem $item)
    {
        $this->authorizeItem($item);
        $this->cart->remove($item);

        return back()->with('success', 'Item removed from cart.');
    }

    public function clear()
    {
        $this->cart->clear();

        return back()->with('success', 'Cart cleared.');
    }

    public function applyCoupon(Request $request)
    {
        $code = strtoupper(trim($request->validate([
            'code' => ['required', 'string', 'max:50'],
        ])['code']));

        $coupon = Coupon::where('code', $code)->first();

        if (! $coupon) {
            return back()->with('error', 'Coupon code not found.');
        }

        $subtotal = $this->cart->totals()['subtotal'];

        if ($reason = $coupon->reasonUnusableFor($subtotal)) {
            return back()->with('error', $reason);
        }

        $this->cart->applyCoupon($coupon);

        return back()->with('success', 'Coupon ' . $coupon->code . ' applied.');
    }

    public function removeCoupon()
    {
        $this->cart->removeCoupon();

        return back()->with('success', 'Coupon removed.');
    }

    /** Cart items are addressable by id, so confirm the item belongs to this visitor. */
    protected function authorizeItem(CartItem $item): void
    {
        abort_unless($item->cart_id === $this->cart->current()->id, 403);
    }
}
