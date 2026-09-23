<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'today' => $this->today($finance),
            'overall' => $this->money($finance),
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

    /**
     * Today's figures. Money figures are null for staff without reports.view;
     * stock quantities are shown to everyone who can open the dashboard.
     */
    protected function today(bool $finance): array
    {
        $day = [today(), today()->endOfDay()];

        $movedToday = fn (string $type) => (int) StockMovement::where('type', $type)->whereBetween('created_at', $day)->sum('quantity');

        $onHand = ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereNull('products.deleted_at')
            ->where('product_variants.stock', '>', 0)
            ->selectRaw('COALESCE(SUM(product_variants.stock), 0) as units')
            ->selectRaw('COALESCE(SUM(product_variants.stock * COALESCE(product_variants.cost_price, products.cost_price, 0)), 0) as value')
            ->first();

        $figures = [
            'stock_in' => $movedToday('in'),
            'stock_out' => $movedToday('out'),
            'stock_units' => (int) $onHand->units,
            'stock_value' => $finance ? round((float) $onHand->value, 2) : null,
        ];

        return $figures + $this->money($finance, $day);
    }

    /**
     * Purchase, sales, due, paid, cost and income, for one period or (with no
     * period) all time. Null for staff without reports.view.
     *
     * @param  array{0: \Carbon\CarbonInterface, 1: \Carbon\CarbonInterface}|null  $period
     */
    protected function money(bool $finance, ?array $period = null): array
    {
        if (! $finance) {
            return array_fill_keys(['purchase', 'purchase_count', 'sales', 'sales_count', 'due', 'paid', 'cost', 'income'], null);
        }

        $within = fn ($query, string $column) => $period ? $query->whereBetween($column, $period) : $query;

        $purchases = $within(Purchase::received(), 'received_at');
        $sales = $within(Order::whereIn('status', Order::SALE_STATUSES), 'created_at');

        $salesTotal = (float) (clone $sales)->sum('total');
        $cost = (float) OrderItem::whereIn('order_id', (clone $sales)->select('id'))
            ->sum(DB::raw('COALESCE(unit_cost, 0) * quantity'));

        return [
            'purchase' => (float) (clone $purchases)->sum('total'),
            'purchase_count' => (clone $purchases)->count(),
            'sales' => $salesTotal,
            'sales_count' => (clone $sales)->count(),
            'due' => (float) (clone $sales)->sum(DB::raw('CASE WHEN total > paid_amount THEN total - paid_amount ELSE 0 END')),
            // Money actually received in the period, whichever day the order was placed.
            'paid' => (float) $within(AccountTransaction::where('direction', 'in')
                ->whereIn('type', ['sale_payment', 'customer_payment']), 'transacted_at')
                ->sum('amount'),
            'cost' => round($cost, 2),
            'income' => round($salesTotal - $cost, 2),
        ];
    }
}
