<?php

use App\Filament\Widgets\BestSellersTable;
use App\Filament\Widgets\LowStockTable;
use App\Filament\Widgets\ReportSummaryStats;
use App\Filament\Widgets\SalesByCashierTable;
use App\Filament\Widgets\SalesByCategoryTable;
use App\Filament\Widgets\SalesByProductTable;
use App\Filament\Widgets\SalesTrendChart;
use App\Filament\Widgets\TodayStatsOverview;
use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Pos\Cart;
use App\Services\TransactionService;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Livewire\invade;

/**
 * Ring up a sale at the given local time.
 */
function ringUpAt(string $dateTime, Product $product, int $quantity, ?User $user = null, int $discountCents = 0): Transaction
{
    test()->travelTo(CarbonImmutable::parse($dateTime));

    return checkout($user ?? cashier(), cartOf([[$product, $quantity]]), cashCents: 100000000, discountCents: $discountCents);
}

/**
 * The label => value pairs a stats widget shows.
 *
 * @param  array<string, mixed>  $parameters
 * @return array<string, string>
 */
function statsShownBy(string $widget, array $parameters = []): array
{
    $stats = invade(Livewire::test($widget, $parameters)->instance())->getStats();

    return collect($stats)->mapWithKeys(fn (Stat $stat): array => [(string) $stat->getLabel() => (string) $stat->getValue()])->all();
}

/**
 * The chart data a chart widget draws.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{datasets: array<int, array<string, mixed>>, labels: array<int, string>}
 */
function chartDrawnBy(string $widget, array $parameters = []): array
{
    return invade(Livewire::test($widget, $parameters)->instance())->getData();
}

beforeEach(function () {
    $this->admin = admin();
    $this->actingAs($this->admin);
    $this->coke = Product::factory()->create(['name' => 'Coke', 'selling_price' => '20.00', 'stock_quantity' => 1000]);
    $this->chips = Product::factory()->create(['name' => 'Chips', 'selling_price' => '10.00', 'stock_quantity' => 1000]);
    $this->range = ['from' => '2026-11-02', 'to' => '2026-11-15', 'period' => 'day'];
});

it("shows today's sales, count and average without voided or earlier sales", function () {
    ringUpAt('2026-11-15 23:59:59', $this->coke, 4);  // yesterday
    ringUpAt('2026-11-16 00:00:00', $this->coke, 2);  // 40.00
    ringUpAt('2026-11-16 10:00:00', $this->chips, 1); // 10.00
    $voided = ringUpAt('2026-11-16 11:00:00', $this->coke, 9);
    app(TransactionService::class)->void($voided, $this->admin, 'Test');
    $this->travelTo(CarbonImmutable::parse('2026-11-16 23:59:59'));

    expect(statsShownBy(TodayStatsOverview::class))->toMatchArray([
        "Today's sales" => '₱50.00',
        'Transactions today' => '2',
        'Average sale' => '₱25.00',
    ]);
});

it('counts products and active low-stock products on the dashboard', function () {
    Product::factory()->create(['stock_quantity' => 2, 'low_stock_threshold' => 5]);
    Product::factory()->create(['stock_quantity' => 5, 'low_stock_threshold' => 5]);
    Product::factory()->inactive()->create(['stock_quantity' => 0, 'low_stock_threshold' => 5]);
    Product::factory()->create(['stock_quantity' => 0, 'low_stock_threshold' => 5])->delete();

    expect(statsShownBy(TodayStatsOverview::class))->toMatchArray([
        'Products' => '5',
        'Low stock' => '2',
    ]);
});

it('links the low-stock figure to the product list filtered to low stock', function () {
    Product::factory()->create(['name' => 'Almost out', 'stock_quantity' => 2, 'low_stock_threshold' => 5]);

    $stats = invade(Livewire::test(TodayStatsOverview::class)->instance())->getStats();
    $lowStock = collect($stats)->first(fn (Stat $stat): bool => $stat->getLabel() === 'Low stock');

    $this->get($lowStock->getUrl())
        ->assertOk()
        ->assertSee('Almost out')
        ->assertDontSee('Chips');
});

it("agrees with Sales Reports on today's net sales after a discount", function () {
    ringUpAt('2026-11-16 09:00:00', $this->coke, 2, $this->admin, discountCents: 550);
    ringUpAt('2026-11-16 10:00:00', $this->chips, 1);

    $today = statsShownBy(TodayStatsOverview::class);
    $report = statsShownBy(ReportSummaryStats::class, ['pageFilters' => ['from' => '2026-11-16', 'to' => '2026-11-16']]);

    expect($today["Today's sales"])->toBe('₱44.50')
        ->and($report['Total sales'])->toBe('₱44.50')
        ->and($report['Transactions'])->toBe($today['Transactions today'])
        ->and($report['Average sale'])->toBe($today['Average sale']);
});

it('summarises the filtered range on Sales Reports', function () {
    ringUpAt('2026-11-01 23:59:59', $this->coke, 5);  // before the range
    ringUpAt('2026-11-02 00:00:00', $this->coke, 2);  // 40.00
    ringUpAt('2026-11-15 23:59:59', $this->chips, 3); // 30.00
    $voided = ringUpAt('2026-11-10 12:00:00', $this->coke, 9);
    app(TransactionService::class)->void($voided, $this->admin, 'Test');

    expect(statsShownBy(ReportSummaryStats::class, ['pageFilters' => $this->range]))->toBe([
        'Total sales' => '₱70.00',
        'Transactions' => '2',
        'Average sale' => '₱35.00',
    ]);
});

it('shows the 30 days up to today by day on the dashboard chart', function () {
    ringUpAt('2026-10-17 12:00:00', $this->coke, 5); // 31 days ago
    ringUpAt('2026-10-18 08:00:00', $this->coke, 1);
    ringUpAt('2026-11-16 08:00:00', $this->chips, 3);
    $voided = ringUpAt('2026-11-16 09:00:00', $this->coke, 9);
    app(TransactionService::class)->void($voided, $this->admin, 'Test');

    $chart = chartDrawnBy(SalesTrendChart::class);
    $data = $chart['datasets'][0]['data'];

    expect($chart['labels'])->toHaveCount(30)
        ->and($chart['labels'][0])->toBe('Oct 18')
        ->and($chart['labels'][29])->toBe('Nov 16')
        ->and($data[0])->toEqual(20)
        ->and($data[29])->toEqual(30)
        ->and(array_sum(array_slice($data, 1, 28)))->toEqual(0);
});

it('follows the range and grouping filters on the Sales Reports chart', function () {
    ringUpAt('2026-11-02 09:00:00', $this->coke, 1);
    ringUpAt('2026-11-08 09:00:00', $this->coke, 1);
    ringUpAt('2026-11-09 09:00:00', $this->chips, 1);

    $weekly = chartDrawnBy(SalesTrendChart::class, ['pageFilters' => [...$this->range, 'period' => 'week']]);
    $monthly = chartDrawnBy(SalesTrendChart::class, ['pageFilters' => [...$this->range, 'period' => 'month']]);

    expect($weekly['labels'])->toBe(['Week of Nov 2', 'Week of Nov 9'])
        ->and($weekly['datasets'][0]['data'])->toEqual([40, 10])
        ->and($monthly['labels'])->toBe(['Nov 2026'])
        ->and($monthly['datasets'][0]['data'])->toEqual([50]);
});

it('groups the chart by day when the grouping is tampered with', function (mixed $period) {
    $chart = chartDrawnBy(SalesTrendChart::class, ['pageFilters' => [...$this->range, 'period' => $period]]);

    expect($chart['labels'])->toHaveCount(14)->and($chart['labels'][0])->toBe('Nov 2');
})->with(['year', [['week']], null, 7]);

it('lists the best sellers of the last 30 days by quantity', function () {
    $candy = Product::factory()->create(['name' => 'Candy', 'selling_price' => '1.00', 'stock_quantity' => 1000]);
    $old = Product::factory()->create(['name' => 'Old stock', 'selling_price' => '1.00', 'stock_quantity' => 1000]);
    $gone = Product::factory()->create(['name' => 'Voided item', 'selling_price' => '1.00', 'stock_quantity' => 1000]);
    ringUpAt('2026-10-17 12:00:00', $old, 99);
    ringUpAt('2026-11-10 12:00:00', $this->coke, 2);
    ringUpAt('2026-11-11 12:00:00', $candy, 7);
    ringUpAt('2026-11-12 12:00:00', $this->chips, 3);
    $voided = ringUpAt('2026-11-13 12:00:00', $gone, 50);
    app(TransactionService::class)->void($voided, $this->admin, 'Test');
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00'));

    Livewire::test(BestSellersTable::class)
        ->assertSuccessful()
        ->assertSeeInOrder(['Candy', 'Chips', 'Coke'])
        ->assertSee('₱7.00')
        ->assertDontSee('Old stock')
        ->assertDontSee('Voided item');
});

it('limits best sellers to ten products', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00'));

    foreach (range(1, 11) as $quantity) {
        $product = Product::factory()->create(['name' => "Seller {$quantity}x", 'selling_price' => '1.00', 'stock_quantity' => 100]);
        checkout(cashier(), cartOf([[$product, $quantity]]), cashCents: 100000);
    }

    Livewire::test(BestSellersTable::class)
        ->assertSee('Seller 11x')
        ->assertSee('Seller 2x')
        ->assertDontSee('Seller 1x');
});

it('lists active low-stock products, lowest first', function () {
    Product::factory()->create(['name' => 'Almost out', 'stock_quantity' => 3, 'low_stock_threshold' => 5]);
    Product::factory()->create(['name' => 'Sold out', 'stock_quantity' => 0, 'low_stock_threshold' => 5]);
    Product::factory()->create(['name' => 'Plenty', 'stock_quantity' => 50, 'low_stock_threshold' => 5]);
    Product::factory()->inactive()->create(['name' => 'Discontinued', 'stock_quantity' => 0, 'low_stock_threshold' => 5]);
    Product::factory()->create(['name' => 'Deleted one', 'stock_quantity' => 0, 'low_stock_threshold' => 5])->delete();

    Livewire::test(LowStockTable::class)
        ->assertSuccessful()
        ->assertSeeInOrder(['Sold out', 'Almost out'])
        ->assertDontSee('Plenty')
        ->assertDontSee('Discontinued')
        ->assertDontSee('Deleted one');
});

it('shows an empty low-stock list when nothing is running low', function () {
    Livewire::test(LowStockTable::class)->assertSee('Nothing is running low');
});

it('shows sales by product from snapshots, marking manual items', function () {
    ringUpAt('2026-11-03 09:00:00', $this->coke, 3);
    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00'));
    $cart = new Cart;
    $cart->addManual('Ice tubig', 4, 500);
    checkout(cashier(), $cart, cashCents: 100000);
    $voided = ringUpAt('2026-11-04 09:00:00', $this->chips, 1);
    app(TransactionService::class)->void($voided, $this->admin, 'Test');
    ringUpAt('2026-11-20 09:00:00', $this->chips, 1); // after the range

    $this->coke->update(['name' => 'Renamed cola']);
    $this->coke->delete();

    Livewire::test(SalesByProductTable::class, ['pageFilters' => $this->range])
        ->assertSuccessful()
        ->assertSee('Manual item')
        ->assertSeeInOrder(['Coke', '₱60.00', 'Ice tubig', '₱20.00'])
        ->assertDontSee('Renamed cola')
        ->assertDontSee('Chips');
});

it('shows sales by category with a note on multi-category products', function () {
    $drinks = Category::factory()->create(['name' => 'Drinks']);
    $cold = Category::factory()->create(['name' => 'Cold items']);
    $this->coke->categories()->attach([$drinks->id, $cold->id]);
    ringUpAt('2026-11-03 09:00:00', $this->coke, 2);
    ringUpAt('2026-11-03 10:00:00', $this->chips, 1);

    Livewire::test(SalesByCategoryTable::class, ['pageFilters' => $this->range])
        ->assertSuccessful()
        ->assertSee('A product in several categories is counted in each one.')
        ->assertSee('Drinks')
        ->assertSee('Cold items')
        ->assertSeeInOrder(['Uncategorized', '₱10.00']);
});

it('shows sales by cashier for the filtered range', function () {
    $ana = cashier(['name' => 'Ana Reyes']);
    $ben = cashier(['name' => 'Ben Cruz']);
    $cora = cashier(['name' => 'Cora Voided']);
    ringUpAt('2026-11-03 09:00:00', $this->coke, 2, $ana);
    ringUpAt('2026-11-03 10:00:00', $this->chips, 1, $ben);
    ringUpAt('2026-11-20 10:00:00', $this->chips, 9, $ben); // after the range
    $voided = ringUpAt('2026-11-04 09:00:00', $this->chips, 1, $cora);
    app(TransactionService::class)->void($voided, $this->admin, 'Test');

    Livewire::test(SalesByCashierTable::class, ['pageFilters' => $this->range])
        ->assertSuccessful()
        ->assertSeeInOrder(['Ana Reyes', '₱40.00', 'Ben Cruz', '₱10.00'])
        ->assertDontSee('₱100.00')
        ->assertDontSee('Cora Voided');
});

it('does not fail on tampered page filters', function (string $widget, mixed $filters) {
    Livewire::test($widget, ['pageFilters' => $filters])->assertSuccessful();
})->with([
    ReportSummaryStats::class,
    SalesTrendChart::class,
    SalesByProductTable::class,
    SalesByCategoryTable::class,
    SalesByCashierTable::class,
])->with([
    'arrays' => [['from' => ['2026-11-01'], 'to' => [['x']], 'period' => ['week']]],
    'garbage' => [['from' => 'yesterday', 'to' => '2026-02-30', 'period' => 'year']],
    'numbers' => [['from' => 20261101, 'to' => 1.5, 'period' => 7]],
    'fifty years' => [['from' => '1976-01-01', 'to' => '2026-11-16']],
    'start after end' => [['from' => '2026-11-15', 'to' => '2026-11-02']],
    'empty' => [[]],
    'none' => [null],
]);

it('reads report tables in a fixed number of queries however many products sold', function (string $widget) {
    $countQueries = function () use ($widget): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test($widget, ['pageFilters' => $this->range]);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    ringUpAt('2026-11-03 09:00:00', $this->coke, 1);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00'));
    $few = $countQueries();

    $categories = Category::factory()->count(3)->create();
    $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00:00'));

    foreach (range(1, 8) as $index) {
        $product = Product::factory()->create(['selling_price' => '1.00', 'stock_quantity' => 100, 'low_stock_threshold' => 200]);
        $product->categories()->attach($categories->random());
        checkout(cashier(), cartOf([[$product, 1]]), cashCents: 100000);
    }

    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00'));

    expect($countQueries())->toBe($few);
})->with([
    BestSellersTable::class,
    LowStockTable::class,
    SalesByProductTable::class,
    SalesByCategoryTable::class,
    SalesByCashierTable::class,
]);

it('notes that product, category and best-seller sales are before sale discounts', function (string $widget) {
    Livewire::test($widget, ['pageFilters' => $this->range])->assertSee('Before sale discounts');
})->with([
    BestSellersTable::class,
    SalesByProductTable::class,
    SalesByCategoryTable::class,
]);

it('refuses every report widget to a cashier', function (string $widget) {
    Livewire::actingAs(cashier())->test($widget)->assertForbidden();
})->with([
    TodayStatsOverview::class,
    ReportSummaryStats::class,
    SalesTrendChart::class,
    BestSellersTable::class,
    LowStockTable::class,
    SalesByProductTable::class,
    SalesByCategoryTable::class,
    SalesByCashierTable::class,
]);
