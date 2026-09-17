<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Support\CsvExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/** Who owes the shop money, how much, and for how long. */
class CustomerDueController extends Controller implements HasMiddleware
{
    public const AGES = [
        '30' => 'Unpaid over 30 days',
        '60' => 'Unpaid over 60 days',
        '90' => 'Unpaid over 90 days',
    ];

    public const SORTS = [
        'due' => 'Highest due',
        'oldest' => 'Oldest unpaid',
        'name' => 'Name',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('can:customers.view'),
            new Middleware('can:reports.export', only: ['export']),
        ];
    }

    public function index(Request $request)
    {
        return view('admin.customers.dues', [
            'customers' => $this->query($request)->paginate(25)->withQueryString(),
            'ageing' => $this->ageing(),
            'customersWithDue' => Customer::withDue()->count(),
            'ages' => self::AGES,
            'sorts' => self::SORTS,
            'sort' => $this->sort($request),
        ]);
    }

    public function export(Request $request)
    {
        $rows = (function () use ($request) {
            foreach ($this->query($request)->cursor() as $customer) {
                $lastPaid = collect([$customer->last_payment_at, $customer->last_collection_at])->filter()->max();

                yield [
                    $customer->name,
                    (string) $customer->phone,
                    $customer->group_label,
                    (float) $customer->opening_due_remaining,
                    round((float) $customer->current_due - (float) $customer->opening_due_remaining, 2),
                    (float) $customer->current_due,
                    $customer->oldest_unpaid_at?->format('Y-m-d') ?? '',
                    $customer->oldest_unpaid_at ? (int) $customer->oldest_unpaid_at->diffInDays(now()) : '',
                    $lastPaid?->format('Y-m-d') ?? '',
                ];
            }
        })();

        return CsvExport::download('customer-dues-' . now()->format('Y-m-d-His') . '.csv',
            ['Customer', 'Phone', 'Group', 'Opening due', 'Order due', 'Total due', 'Oldest unpaid order', 'Days unpaid', 'Last payment'], $rows);
    }

    protected function query(Request $request): Builder
    {
        $unpaid = fn () => Order::query()
            ->whereColumn('orders.customer_id', 'customers.id')
            ->whereIn('orders.status', Order::SALE_STATUSES)
            ->whereColumn('orders.total', '>', 'orders.paid_amount');

        $query = Customer::withTotals()
            ->withDue()
            ->addSelect([
                'oldest_unpaid_at' => $unpaid()->select('orders.created_at')->orderBy('orders.created_at')->limit(1),
                'last_payment_at' => Payment::query()
                    ->join('orders', 'orders.id', '=', 'payments.order_id')
                    ->whereColumn('orders.customer_id', 'customers.id')
                    ->where('payments.status', 'success')
                    ->selectRaw('MAX(payments.paid_at)'),
                'last_collection_at' => CustomerPayment::query()
                    ->whereColumn('customer_payments.customer_id', 'customers.id')
                    ->selectRaw('MAX(customer_payments.paid_at)'),
            ])
            ->search($request->input('q'))
            ->when(array_key_exists((string) $request->input('group'), Customer::GROUPS), fn ($q) => $q->where('customers.customer_group', $request->input('group')))
            ->when(array_key_exists((string) $request->input('age'), self::AGES), fn ($q) => $q->whereHas('orders', fn ($orders) => $orders
                ->whereIn('status', Order::SALE_STATUSES)
                ->whereColumn('total', '>', 'paid_amount')
                ->where('created_at', '<', now()->subDays((int) $request->input('age')))));

        match ($this->sort($request)) {
            // Opening dues (no order date) come first: they are older than anything in the system.
            'oldest' => $query->orderBy('oldest_unpaid_at'),
            'name' => $query->orderBy('customers.name'),
            default => $query->orderByDesc('current_due'),
        };

        return $query->orderBy('customers.id');
    }

    protected function sort(Request $request): string
    {
        return array_key_exists((string) $request->input('sort'), self::SORTS) ? (string) $request->input('sort') : 'due';
    }

    /**
     * Everything owed by active customers, split by how long it has been unpaid.
     *
     * @return array{opening: float, '0-30': float, '31-60': float, '61-90': float, '90+': float, total: float}
     */
    protected function ageing(): array
    {
        $buckets = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];

        Order::whereIn('status', Order::SALE_STATUSES)
            ->whereColumn('total', '>', 'paid_amount')
            ->whereIn('customer_id', Customer::select('id'))
            ->select(['id', 'total', 'paid_amount', 'created_at'])
            ->each(function (Order $order) use (&$buckets) {
                $days = (int) $order->created_at->diffInDays(now());
                $key = match (true) {
                    $days <= 30 => '0-30',
                    $days <= 60 => '31-60',
                    $days <= 90 => '61-90',
                    default => '90+',
                };

                $buckets[$key] += $order->due_amount;
            });

        $opening = (float) Customer::sum('opening_due')
            - (float) CustomerPayment::whereIn('customer_id', Customer::select('id'))->sum('opening_due_paid');

        $ageing = ['opening' => round($opening, 2)] + array_map(fn (float $amount) => round($amount, 2), $buckets);
        $ageing['total'] = round(array_sum($ageing), 2);

        return $ageing;
    }
}
