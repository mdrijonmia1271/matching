<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\ProductVariant;
use App\Support\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class CartService
{
    /** Guest carts are tied to this cookie, not the session id, so they survive login. */
    public const TOKEN_COOKIE = 'cart_token';

    public const TOKEN_LIFETIME_MINUTES = 60 * 24 * 30;

    /** Flat delivery charge from Admin → Settings. */
    public static function shippingCost(): float
    {
        return (float) Settings::get('delivery_charge', 60.0);
    }

    /** Orders at or above this subtotal ship free; 0 turns free delivery off. */
    public static function freeShippingFrom(): float
    {
        return (float) Settings::get('free_delivery_threshold', 0.0);
    }

    /** The current cart, creating one if the visitor does not have it yet. */
    public function current(): Cart
    {
        $cart = $this->find() ?? Cart::create(
            Auth::check()
                ? ['user_id' => Auth::id()]
                : ['token' => $this->guestToken()]
        );

        $cart->load('items.product', 'items.variant');

        // Drop lines whose product or variant was archived or switched off since it was added.
        $gone = $cart->items->filter(fn (CartItem $item) => $item->product === null
            || $item->variant === null
            || ! $item->variant->is_active);

        if ($gone->isNotEmpty()) {
            CartItem::whereKey($gone->modelKeys())->delete();
            $cart->setRelation('items', $cart->items->diff($gone)->values());
        }

        return $cart;
    }

    public function find(): ?Cart
    {
        if (Auth::check()) {
            return Cart::where('user_id', Auth::id())->first();
        }

        return Cart::whereNull('user_id')->where('token', $this->guestToken())->first();
    }

    public function itemCount(): int
    {
        $cart = $this->find();

        return $cart ? (int) $cart->items()->sum('quantity') : 0;
    }

    /**
     * Add a variant, capping the quantity at whatever stock is left.
     *
     * @return array{item: CartItem, capped: bool}
     */
    public function add(ProductVariant $variant, int $quantity = 1): array
    {
        $cart = $this->current();
        $item = $cart->items()->where('variant_id', $variant->id)->first();

        $requested = max(1, $quantity) + ($item?->quantity ?? 0);
        $allowed = max(1, min($requested, $variant->stock));

        if ($item) {
            $item->update(['quantity' => $allowed, 'price' => $variant->current_price]);
        } else {
            $item = $cart->items()->create([
                'product_id' => $variant->product_id,
                'variant_id' => $variant->id,
                'quantity' => $allowed,
                'price' => $variant->current_price,
            ]);
        }

        return ['item' => $item, 'capped' => $allowed < $requested];
    }

    public function updateQuantity(CartItem $item, int $quantity): void
    {
        if ($quantity < 1) {
            $item->delete();

            return;
        }

        $item->update(['quantity' => min($quantity, max(1, (int) $item->variant?->stock))]);
    }

    /**
     * Bring every line up to the variant's current price. Returns true when any
     * price changed, so checkout can ask the customer to review before paying.
     */
    public function reprice(Cart $cart): bool
    {
        $changed = false;

        foreach ($cart->items as $item) {
            $current = round($item->variant->current_price, 2);

            if (round((float) $item->price, 2) !== $current) {
                $item->update(['price' => $current]);
                $changed = true;
            }
        }

        return $changed;
    }

    public function remove(CartItem $item): void
    {
        $item->delete();
    }

    public function clear(): void
    {
        $cart = $this->find();

        if ($cart) {
            $cart->items()->delete();
            $cart->update(['coupon_code' => null]);
        }
    }

    /** Move a guest cart onto the user account after login/registration. */
    public function mergeGuestCart(int $userId): void
    {
        $guestCart = Cart::whereNull('user_id')->where('token', $this->guestToken())->first();

        if (! $guestCart) {
            return;
        }

        $userCart = Cart::firstOrCreate(['user_id' => $userId]);

        foreach ($guestCart->items()->with('variant')->get() as $item) {
            if (! $item->variant) {
                continue;
            }

            $existing = $userCart->items()->where('variant_id', $item->variant_id)->first();

            if ($existing) {
                $existing->update([
                    'quantity' => min($existing->quantity + $item->quantity, max(1, $item->variant->stock)),
                ]);
            } else {
                $userCart->items()->create($item->only(['product_id', 'variant_id', 'quantity', 'price']));
            }
        }

        if ($guestCart->coupon_code && ! $userCart->coupon_code) {
            $userCart->update(['coupon_code' => $guestCart->coupon_code]);
        }

        $guestCart->items()->delete();
        $guestCart->delete();
    }

    public function applyCoupon(Coupon $coupon): void
    {
        $this->current()->update(['coupon_code' => $coupon->code]);
    }

    public function removeCoupon(): void
    {
        $this->find()?->update(['coupon_code' => null]);
    }

    public function appliedCoupon(): ?Coupon
    {
        $code = $this->find()?->coupon_code;

        return $code ? Coupon::where('code', $code)->first() : null;
    }

    /**
     * Money breakdown for the current cart.
     *
     * @return array{subtotal: float, discount: float, shipping: float, total: float, coupon: ?Coupon}
     */
    public function totals(): array
    {
        $cart = $this->find();
        $subtotal = $cart ? round($cart->load('items')->subtotal(), 2) : 0.0;

        $coupon = $this->appliedCoupon();
        $discount = 0.0;

        if ($coupon && $coupon->reasonUnusableFor($subtotal) === null) {
            $discount = $coupon->discountFor($subtotal);
        } else {
            $coupon = null;
        }

        $freeFrom = self::freeShippingFrom();
        $shipping = ($subtotal <= 0 || ($freeFrom > 0 && $subtotal >= $freeFrom)) ? 0.0 : self::shippingCost();

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'shipping' => $shipping,
            'total' => round($subtotal - $discount + $shipping, 2),
            'coupon' => $coupon,
        ];
    }

    /**
     * The guest's cart token, minting and queueing a cookie the first time.
     *
     * Memoised on the request (not on this service, which is a singleton) so
     * every caller in one request agrees on the token even before the freshly
     * queued cookie has reached the browser.
     */
    protected function guestToken(): string
    {
        $request = request();

        if ($request->attributes->has(self::TOKEN_COOKIE)) {
            return $request->attributes->get(self::TOKEN_COOKIE);
        }

        $token = $request->cookie(self::TOKEN_COOKIE);

        if (! is_string($token) || strlen($token) !== 40) {
            $token = Str::random(40);
            Cookie::queue(self::TOKEN_COOKIE, $token, self::TOKEN_LIFETIME_MINUTES);
        }

        $request->attributes->set(self::TOKEN_COOKIE, $token);

        return $token;
    }
}
