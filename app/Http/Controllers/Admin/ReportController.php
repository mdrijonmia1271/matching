<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * Read-only reports over a date range (this month unless chosen). Every figure
 * follows the dashboard's rules: a sale is an order in a sale status, a
 * purchase counts once received, and money is read from the account ledger.
 */
class ReportController extends Controller implements HasMiddleware
{
    public const CHANNELS = ['online' => 'Online', 'pos' => 'Counter (POS)', 'advance' => 'Advance'];

    /** Ledger types that are only money moving between the shop's own accounts. */
    protected const TRANSFERS = ['transfer_in', 'transfer_out'];

    /** Money out that is a running cost of the shop rather than paying for stock or refunding a sale. */
    protected const EXPENSE_TYPES = ['expense', 'withdrawal'];

    public static function middleware(): array
    {
        return [new Middleware('can:reports.view')];
    }

    public function purchases(Request $request)
    {
        $period = $this->period($request);

        $query = Purchase::received()
            ->whereBetween('received_at', $period)
            ->when($request->filled('supplier'), fn (Builder $q) => $q->where('supplier_id', $request->integer('supplier')));

        return view('admin.reports.purchases', $this->base($period) + [
            'rows' => $this->rows((clone $query)->with('supplier:id,name,company')->withSum('items as units', 'quantity')
                ->orderByDesc('received_at')->orderByDesc('id')),
            'summary' => [
                'count' => (clone $query)->count(),
                'units' => (int) PurchaseItem::whereIn('purchase_id', (clone $query)->select('id'))->sum('quantity'),
                'discount' => (float) (clone $query)->sum('discount'),
                'additional' => (float) (clone $query)->sum('additional_cost'),
                'total' => (float) (clone $query)->sum('total'),
            ],
            'suppliers' => Supplier::withTrashed()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function sales(Request $request)
    {
        $period = $this->period($request);
        $query = $this->salesQuery($request, $period);

        return view('admin.reports.sales', $this->base($period) + [
            'rows' => $this->rows((clone $query)->withSum('items as units', 'quantity')
                ->orderByDesc('created_at')->orderByDesc('id')),
            'summary' => [
                'count' => (clone $query)->count(),
                'units' => (int) OrderItem::whereIn('order_id', (clone $query)->select('id'))->sum('quantity'),
                'discount' => (float) (clone $query)->sum('discount'),
                'total' => (float) (clone $query)->sum('total'),
                'paid' => (float) (clone $query)->sum('paid_amount'),
                'due' => $this->dueOn($query),
            ],
            'channels' => self::CHANNELS,
        ]);
    }

    public function income(Request $request)
    {
        return $this->ledgerReport($request, 'in', 'admin.reports.income');
    }

    public function cost(Request $request)
    {
        return $this->ledgerReport($request, 'out', 'admin.reports.cost');
    }

    public function profitLoss(Request $request)
    {
        $period = $this->period($request);
        $sales = $this->salesQuery($request, $period);

        $salesTotal = (float) (clone $sales)->sum('total');
        $cogs = $this->costOf($sales);
        $byType = fn (string $direction, array $types) => (float) AccountTransaction::where('direction', $direction)
            ->whereIn('type', $types)->whereBetween('transacted_at', $period)->sum('amount');

        $expenses = $byType('out', self::EXPENSE_TYPES);
        $otherIncome = $byType('in', ['deposit']);
        $gross = round($salesTotal - $cogs, 2);

        return view('admin.reports.profit-loss', $this->base($period) + [
            'lines' => [
                'sales' => $salesTotal,
                'cogs' => $cogs,
                'gross' => $gross,
                'other_income' => $otherIncome,
                'expenses' => $expenses,
                'net' => round($gross + $otherIncome - $expenses, 2),
            ],
            'orders' => (clone $sales)->count(),
            'missingCost' => $this->missingCost($sales),
        ]);
    }

    public function saleProfit(Request $request)
    {
        $period = $this->period($request);
        $query = $this->salesQuery($request, $period);

        $cost = OrderItem::query()
            ->selectRaw('order_id, SUM(COALESCE(unit_cost, 0) * quantity) AS cost, SUM(CASE WHEN unit_cost IS NULL THEN 1 ELSE 0 END) AS missing')
            ->groupBy('order_id');

        $rows = $this->rows((clone $query)
            ->select('orders.*')
            ->leftJoinSub($cost, 'item_costs', 'item_costs.order_id', '=', 'orders.id')
            ->addSelect(DB::raw('COALESCE(item_costs.cost, 0) AS cost'), DB::raw('COALESCE(item_costs.missing, 0) AS missing_cost'))
            ->orderByDesc('orders.created_at')->orderByDesc('orders.id'));

        $salesTotal = (float) (clone $query)->sum('total');
        $cogs = $this->costOf($query);

        return view('admin.reports.sale-profit', $this->base($period) + [
            'rows' => $rows,
            'summary' => [
                'count' => (clone $query)->count(),
                'sales' => $salesTotal,
                'cost' => $cogs,
                'profit' => round($salesTotal - $cogs, 2),
            ],
            'missingCost' => $this->missingCost($query),
            'channels' => self::CHANNELS,
        ]);
    }

    public function cashBook(Request $request)
    {
        $period = $this->period($request);
        $accounts = Account::orderBy('sort_order')->get(['id', 'name', 'code', 'opening_balance']);
        $account = $accounts->firstWhere('id', $request->integer('account')) ?? $accounts->firstWhere('code', 'cash') ?? $accounts->first();

        $opening = $closing = 0.0;
        $entries = collect();

        if ($account) {
            $before = AccountTransaction::where('account_id', $account->id)->where('transacted_at', '<', $period[0])
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) AS net")
                ->value('net');
            $opening = round((float) $account->opening_balance + (float) $before, 2);

            $running = $opening;
            $entries = AccountTransaction::where('account_id', $account->id)
                ->whereBetween('transacted_at', $period)
                ->with('user:id,name')
                ->orderBy('transacted_at')->orderBy('id')
                ->get()
                ->each(function (AccountTransaction $entry) use (&$running) {
                    $running = round($running + $entry->signed_amount, 2);
                    $entry->setAttribute('running_balance', $running);
                });
            $closing = $running;
        }

        return view('admin.reports.cash-book', $this->base($period) + [
            'accounts' => $accounts,
            'account' => $account,
            'entries' => $entries,
            'opening' => $opening,
            'closing' => $closing,
            'totalIn' => (float) $entries->where('direction', 'in')->sum('amount'),
            'totalOut' => (float) $entries->where('direction', 'out')->sum('amount'),
        ]);
    }

    /** Income (money in) and cost (money out) share one layout; transfers between accounts are left out. */
    protected function ledgerReport(Request $request, string $direction, string $view)
    {
        $period = $this->period($request);
        $types = array_diff_key(
            array_filter(AccountTransaction::TYPES, fn ($label, $type) => $this->typeDirection($type) === $direction, ARRAY_FILTER_USE_BOTH),
            array_flip(self::TRANSFERS),
        );

        $query = AccountTransaction::where('direction', $direction)
            ->whereNotIn('type', self::TRANSFERS)
            ->whereBetween('transacted_at', $period)
            ->when(array_key_exists((string) $request->input('type'), $types), fn (Builder $q) => $q->where('type', $request->input('type')))
            ->when($request->filled('account'), fn (Builder $q) => $q->where('account_id', $request->integer('account')));

        return view($view, $this->base($period) + [
            'rows' => $this->rows((clone $query)->with(['account:id,name', 'user:id,name'])
                ->orderByDesc('transacted_at')->orderByDesc('id')),
            'byType' => (clone $query)->selectRaw('type, COUNT(*) AS entries, SUM(amount) AS total')
                ->groupBy('type')->orderByDesc('total')->get(),
            'total' => (float) (clone $query)->sum('amount'),
            'types' => $types,
            'accounts' => Account::orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    /** Which way a ledger type moves money; used to offer only the types that fit a report. */
    protected function typeDirection(string $type): string
    {
        return in_array($type, ['sale_payment', 'customer_payment', 'deposit', 'transfer_in'], true) ? 'in' : 'out';
    }

    protected function salesQuery(Request $request, array $period): Builder
    {
        return Order::whereIn('orders.status', Order::SALE_STATUSES)
            ->whereBetween('orders.created_at', $period)
            ->when(array_key_exists((string) $request->input('channel'), self::CHANNELS), fn (Builder $q) => $q->where('orders.channel', $request->input('channel')));
    }

    protected function costOf(Builder $sales): float
    {
        return round((float) OrderItem::whereIn('order_id', (clone $sales)->select('orders.id'))
            ->sum(DB::raw('COALESCE(unit_cost, 0) * quantity')), 2);
    }

    /** Sold lines with no purchase price saved: they count as zero cost, so profit reads high. */
    protected function missingCost(Builder $sales): int
    {
        return OrderItem::whereIn('order_id', (clone $sales)->select('orders.id'))->whereNull('unit_cost')->count();
    }

    protected function dueOn(Builder $sales): float
    {
        return (float) (clone $sales)->sum(DB::raw('CASE WHEN total > paid_amount THEN total - paid_amount ELSE 0 END'));
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    protected function period(Request $request): array
    {
        $parse = function (?string $value) {
            try {
                return $value ? CarbonImmutable::createFromFormat('Y-m-d', $value) : null;
            } catch (\Throwable) {
                return null;
            }
        };

        $from = ($parse($request->input('from')) ?? CarbonImmutable::today()->startOfMonth())->startOfDay();
        $to = ($parse($request->input('to')) ?? CarbonImmutable::today())->endOfDay();

        return $from->greaterThan($to) ? [$to->startOfDay(), $from->endOfDay()] : [$from, $to];
    }

    protected function base(array $period): array
    {
        return ['from' => $period[0], 'to' => $period[1], 'printing' => $this->printing()];
    }

    /** A print copy (?print=1) opens in the A4 layout and lists every row on one page. */
    protected function printing(): bool
    {
        return request()->boolean('print');
    }

    /** Fifty rows a page on screen; all of them (up to a sane cap) when printing. */
    protected function rows(Builder $query)
    {
        return $this->printing() ? $query->limit(5000)->get() : $query->paginate(50)->withQueryString();
    }
}
