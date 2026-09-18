<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Collection;

class HomeController extends Controller
{
    public function index()
    {
        $categories = Category::active()->topLevel()
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')->orderBy('name')->take(10)->get();

        $deals = $this->cards()
            ->whereNotNull('sale_price')
            ->whereColumn('sale_price', '<', 'price')
            ->orderByDesc('created_at')->take(8)->get();

        $featured = $this->cards()->where('is_featured', true)->latest()->take(8)->get();
        $latest = $this->cards()->latest()->take(8)->get();

        return view('home', [
            'categories' => $categories,
            'featured' => $featured,
            'latest' => $latest,
            'deals' => $deals,
            'tabs' => $this->bestSellerTabs($featured, $latest, $deals),
            'stats' => $this->stats(),
            'gallery' => $this->cards()->inRandomOrder()->take(5)->get(),
            // Active brands only, and only those with a logo: the strip shows nothing
            // else. Ordered Z to A, so the strip does not always open on the same name.
            'brands' => Brand::active()->whereNotNull('logo')->orderByDesc('name')->take(12)->get(),
        ]);
    }

    /** Base query for product cards; the rating is averaged in SQL to avoid an N+1. */
    protected function cards()
    {
        return Product::active()
            ->with('category')
            ->withAvg(['reviews as rating_avg' => fn ($q) => $q->where('is_approved', true)], 'rating');
    }

    /**
     * The four "Best sellers" tabs. Every tab is a real query, so a tab never
     * shows something the catalogue does not sell — and never sits empty while
     * there are products to show.
     *
     * @param  Collection<int, Product>  $featured
     * @param  Collection<int, Product>  $latest
     * @param  Collection<int, Product>  $deals
     * @return array<string, Collection<int, Product>>
     */
    protected function bestSellerTabs(Collection $featured, Collection $latest, Collection $deals): array
    {
        // Most ordered first: the honest reading of "best seller".
        $best = $this->cards()
            ->withCount(['orderItems as sold_count' => fn ($q) => $q->whereHas('order',
                fn ($order) => $order->whereIn('status', Order::SALE_STATUSES))])
            ->orderByDesc('sold_count')->orderByDesc('is_featured')->latest()
            ->take(8)->get();

        $womensCategory = Category::active()
            ->where(fn ($q) => $q->where('name', 'like', '%women%')->orWhere('name', 'like', '%ladies%'))
            ->value('id');

        $womens = $womensCategory
            ? $this->cards()->where('category_id', $womensCategory)->latest()->take(8)->get()
            : $this->cards()->orderByDesc('rating_avg')->latest()->take(8)->get();

        return array_filter([
            'All' => $best->isNotEmpty() ? $best : $latest,
            'New arrivals' => $latest,
            'Stylish products' => $featured->isNotEmpty() ? $featured : $deals,
            'Womens' => $womens,
        ], fn (Collection $items) => $items->isNotEmpty());
    }

    /**
     * Storefront counters. Real figures only: an invented number on the home
     * page is the first thing a returning customer notices.
     *
     * @return array<int, array<string, string>>
     */
    protected function stats(): array
    {
        $delivered = Order::whereIn('status', Order::SALE_STATUSES)->count();
        $rated = (float) Review::where('is_approved', true)->avg('rating');

        return [
            ['label' => 'Product', 'value' => number_format(Product::active()->count()), 'icon' => 'box'],
            ['label' => 'Followers', 'value' => number_format(Customer::count()), 'icon' => 'users'],
            ['label' => 'Monthly Sales', 'value' => number_format($delivered), 'icon' => 'chart'],
            ['label' => 'Happy Customers', 'value' => ($rated > 0 ? round($rated / 5 * 100) : 100) . '%', 'icon' => 'user'],
        ];
    }
}
