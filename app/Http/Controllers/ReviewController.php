<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function store(Request $request, Product $product)
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $hasBought = Order::where('user_id', auth()->id())
            ->whereIn('status', Order::SALE_STATUSES)
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))
            ->exists();

        if (! $hasBought) {
            return back()->with('error', 'Only customers who ordered this product can review it.');
        }

        Review::updateOrCreate(
            ['product_id' => $product->id, 'user_id' => auth()->id()],
            $data + ['is_approved' => true],
        );

        return back()->with('success', 'Thanks for your review!');
    }

    public function destroy(Review $review)
    {
        abort_unless($review->user_id === auth()->id(), 403);

        $review->delete();

        return back()->with('success', 'Review deleted.');
    }
}
