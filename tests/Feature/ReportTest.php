<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * One counter sale of 2 × 1,000 costing 2 × 600 (paid in cash), one 1,000
     * order with 400 paid, a pending order that must never count, a received
     * purchase of 900, and cash money in 500 / money out 300.
     */
    protected function seedPeriod(): void
    {
        $category = Category::create(['name' => 'Kurti', 'is_active' => true]);
        $product = new Product([
            'category_id' => $category->id, 'name' => 'Premium Kurti', 'price' => 1000,
            'cost_price' => 600, 'is_active' => true, 'has_variants' => true,
        ]);
        $product->withoutDefaultVariant = true;
        $product->save();

        $variant = new ProductVariant(['sku' => 'KUR-M', 'size' => 'M', 'is_active' => true]);
        $variant->product_id = $product->id;
        $variant->stock = 10;
        $variant->save();

        $cash = Account::where('code', 'cash')->firstOrFail();

        $this->actingAs($this->staff())->post(route('admin.pos.store'), [
            'items' => [['variant_id' => $variant->id, 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'account_id' => $cash->id, 'amount' => 2000]],
        ])->assertSessionHas('success');

        foreach ([[1000, 400, 'confirmed'], [5000, 0, 'pending']] as [$total, $paid, $status]) {
            Order::create([
                'customer_name' => 'Lima', 'customer_email' => 'lima@example.com', 'customer_phone' => '01777777777',
                'shipping_address' => 'Mirpur, Dhaka', 'subtotal' => $total, 'total' => $total, 'paid_amount' => $paid,
                'status' => $status, 'payment_method' => 'cod',
            ]);
        }

        Purchase::create([
            'supplier_id' => Supplier::create(['name' => 'Karim Fabrics'])->id, 'status' => 'received',
            'purchase_date' => today(), 'received_at' => now(), 'subtotal' => 900, 'total' => 900,
        ]);

        app(AccountService::class)->entry($cash, 'in', 500, 'Owner top-up');
        app(AccountService::class)->entry($cash, 'out', 300, 'Shop rent');
    }

    public function test_every_report_adds_up_for_the_period(): void
    {
        $this->seedPeriod();
        $admin = $this->staff();
        $get = fn (string $name, array $query = []) => $this->actingAs($admin)->get(route($name, $query))->assertOk();

        $this->assertSame(900.0, $get('admin.reports.purchases')->viewData('summary')['total']);

        $sales = $get('admin.reports.sales')->viewData('summary');
        $this->assertSame([2, 3000.0, 2400.0, 600.0], [$sales['count'], $sales['total'], $sales['paid'], $sales['due']]);
        $this->assertSame(2000.0, $get('admin.reports.sales', ['channel' => 'pos'])->viewData('summary')['total']);

        $this->assertSame(2500.0, $get('admin.reports.income')->viewData('total'));
        $this->assertSame(300.0, $get('admin.reports.cost')->viewData('total'));

        $pl = $get('admin.reports.profit-loss')->assertSee('Net profit')->viewData('lines');
        $this->assertSame(
            ['sales' => 3000.0, 'cogs' => 1200.0, 'gross' => 1800.0, 'other_income' => 500.0, 'expenses' => 300.0, 'net' => 2000.0],
            $pl,
        );

        $profit = $get('admin.reports.sale-profit');
        $this->assertSame(1800.0, $profit->viewData('summary')['profit']);
        $this->assertCount(2, $profit->viewData('rows'));

        $book = $get('admin.reports.cash-book');
        $this->assertSame(0.0, $book->viewData('opening'));
        $this->assertSame(2500.0, $book->viewData('totalIn'));
        $this->assertSame(300.0, $book->viewData('totalOut'));
        $this->assertSame(2200.0, $book->viewData('closing'));
        $this->assertSame(2200.0, $book->viewData('entries')->last()->running_balance);
    }

    public function test_a_later_range_carries_the_balance_forward_and_shows_nothing_new(): void
    {
        $this->seedPeriod();
        $admin = $this->staff();
        $range = ['from' => today()->addDay()->toDateString(), 'to' => today()->addDays(3)->toDateString()];

        $this->assertSame(0.0, $this->actingAs($admin)->get(route('admin.reports.sales', $range))->viewData('summary')['total']);

        $book = $this->actingAs($admin)->get(route('admin.reports.cash-book', $range));
        $this->assertSame(2200.0, $book->viewData('opening'));
        $this->assertSame(2200.0, $book->viewData('closing'));
    }

    public function test_reports_need_the_report_permission_and_appear_in_the_menu(): void
    {
        $this->actingAs($this->staff())->get(route('admin.dashboard'))
            ->assertSee('Reports')->assertSee(route('admin.reports.cash-book'));

        $sales = $this->staff('sales_staff');
        $this->actingAs($sales)->get(route('admin.reports.profit-loss'))->assertForbidden();
        $this->actingAs($sales)->get(route('admin.dashboard'))->assertDontSee(route('admin.reports.cash-book'));
    }

    public function test_every_report_has_a_total_row_and_an_a4_print_copy(): void
    {
        $this->seedPeriod();
        $admin = $this->staff();
        $reports = ['purchases', 'sales', 'income', 'cost', 'profit-loss', 'sale-profit', 'cash-book'];

        foreach ($reports as $report) {
            $screen = $this->actingAs($admin)->get(route('admin.reports.' . $report))->assertOk();
            $screen->assertSee(route('admin.reports.' . $report, ['print' => 1]), false);

            $print = $this->actingAs($admin)->get(route('admin.reports.' . $report, ['print' => 1]))->assertOk()
                ->assertSee('Print / Save as PDF')
                ->assertDontSee('>Show</button>', false);

            if (! in_array($report, ['profit-loss', 'cash-book'], true)) {
                $screen->assertSee('Total ·');
                // Every row, not a page of them.
                $this->assertInstanceOf(\Illuminate\Support\Collection::class, $print->viewData('rows'));
            }
        }

        // The print copy is the table alone: no summary cards and no Print button of its own.
        $this->actingAs($admin)->get(route('admin.reports.sales', ['print' => 1]))
            ->assertSee('Total · 2 order(s)')->assertSee(\App\Support\Money::format(3000))
            ->assertDontSee('Units sold')->assertDontSee('>Print / PDF</a>', false)
            ->assertSee('>SL</th>', false);
        $this->actingAs($admin)->get(route('admin.reports.sales'))->assertDontSee('>SL</th>', false);

        // Rows and the total line stay as wide as the header once SL is added, on every table.
        foreach (['purchases', 'sales', 'income', 'cost', 'sale-profit', 'cash-book'] as $report) {
            $html = $this->actingAs($admin)->get(route('admin.reports.' . $report, ['print' => 1]))->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $width = fn (\DOMElement $row) => array_sum(array_map(
                fn (\DOMElement $cell) => (int) ($cell->getAttribute('colspan') ?: 1),
                array_filter(iterator_to_array($row->childNodes), fn ($node) => $node instanceof \DOMElement),
            ));
            $rows = iterator_to_array($dom->getElementsByTagName('tr'));
            $header = $width($rows[0]);
            foreach ($rows as $row) {
                $this->assertSame($header, $width($row), $report . ' has a row that does not line up with its header');
            }
        }
        $this->actingAs($admin)->get(route('admin.reports.sales'))->assertSee('Units sold')->assertSee('>Print / PDF</a>', false);
    }
}
