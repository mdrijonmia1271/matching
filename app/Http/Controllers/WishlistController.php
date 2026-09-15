<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Wishlist;

class WishlistController extends Controller
{
    public function index()
    {
        return view('wishlist.index', [
            'items' => Wishlist::with('product.category')
                ->where('user_id', auth()->id())
                ->latest()
                ->get(),
        ]);
    }

    /** One button that adds or removes, so the product card stays a single toggle. */
    public function toggle(Product $product)
    {
        $existing = Wishlist::where('user_id', auth()->id())
            ->where('product_id', $product->id)
            ->first();

        if ($existing) {
            $existing->delete();

            return back()->with('success', 'Removed from wishlist.');
        }

        Wishlist::create(['user_id' => auth()->id(), 'product_id' => $product->id]);

        return back()->with('success', 'Saved to wishlist.');
    }

    public function destroy(Wishlist $wishlist)
    {
        abort_unless($wishlist->user_id === auth()->id(), 403);

        $wishlist->delete();

        return back()->with('success', 'Removed from wishlist.');
    }
}
