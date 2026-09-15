<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // Pending, cancelled, returned and refunded orders are not counted as earned revenue.
        $earned = Order::SALE_STATUSES;

        // Money figures are only computed for staff allowed to see them.
        $finance = $request->user()->can('reports.view');

        return view('admin.dashboard', [
            'canSeeFinance' => $finance,
            'revenue' => $finance ? (float) Order::whereIn('status', $earned)->sum('total') : null,
            'revenueThisMonth' => $finance ? (float) Order::whereIn('status', $earned)
                ->where('created_at', '>=', now()->startOfMonth())->sum('total') : null,
            'orderCount' => Order::count(),
            'pendingCount' => Order::where('status', 'pending')->count(),
            'productCount' => Product::count(),
            'customerCount' => User::where('is_admin', false)->count(),
            'lowStock' => ProductVariant::with('product:id,name,has_variants')
                ->forLiveProducts()
                ->active()
                ->where(fn ($q) => $q->lowStock()->orWhere(fn ($out) => $out->outOfStock()))
                ->orderBy('stock')
                ->take(8)
                ->get(),
            'recentOrders' => Order::latest()->take(8)->get(),
            'topProducts' => Product::withSum([
                'orderItems as sold' => fn ($q) => $q->whereHas('order', fn ($o) => $o->whereIn('status', $earned)),
            ], 'quantity')->orderByDesc('sold')->take(5)->get(),
        ]);
    }
}
