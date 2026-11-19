<?php

use App\Filament\Pages\SalesReports;
use App\Filament\Widgets\BestSellersTable;
use App\Filament\Widgets\LowStockTable;
use App\Filament\Widgets\ReportSummaryStats;
use App\Filament\Widgets\SalesByCashierTable;
use App\Filament\Widgets\SalesByCategoryTable;
use App\Filament\Widgets\SalesByProductTable;
use App\Filament\Widgets\SalesTrendChart;
use App\Filament\Widgets\TodayStatsOverview;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Filament\Widgets\AccountWidget;
use Livewire\Livewire;

it('opens the dashboard and reports for admins', function () {
    $this->actingAs(admin())->get('/admin')->assertOk();
    $this->actingAs(admin())->get('/admin/sales-reports')->assertOk();
});

it('keeps reports and figures away from cashiers', function () {
    $this->actingAs(cashier())->get('/admin')->assertOk();
    $this->actingAs(cashier())->get('/admin/sales-reports')->assertForbidden();

    expect(TodayStatsOverview::canView())->toBeFalse()
        ->and(SalesByProductTable::canView())->toBeFalse();
});

it("shows today's sales on the stats widget", function () {
    $product = Product::factory()->create(['selling_price' => '50.00', 'stock_quantity' => 10]);
    checkout(cashier(), cartOf([[$product, 3]]), cashCents: 15000);

    Livewire::actingAs(admin())->test(TodayStatsOverview::class)
        ->assertSee('₱150.00')
        ->assertSee("Today's sales");
});

dataset('report widgets', [
    TodayStatsOverview::class,
    ReportSummaryStats::class,
    SalesTrendChart::class,
    BestSellersTable::class,
    LowStockTable::class,
    SalesByProductTable::class,
    SalesByCategoryTable::class,
    SalesByCashierTable::class,
]);

it('shows every report widget to an active admin', function (string $widget) {
    $this->actingAs(admin());

    expect($widget::canView())->toBeTrue();
})->with('report widgets');

it('hides every report widget from cashiers, inactive admins and guests', function (string $widget) {
    expect($widget::canView())->toBeFalse();

    $this->actingAs(cashier());
    expect($widget::canView())->toBeFalse();

    $this->actingAs(admin(['is_active' => false]));
    expect($widget::canView())->toBeFalse();
})->with('report widgets');

it('puts the stats, chart, best sellers and low stock on the admin dashboard', function () {
    $this->actingAs(admin())->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(AccountWidget::class)
        ->assertSeeLivewire(TodayStatsOverview::class)
        ->assertSeeLivewire(SalesTrendChart::class)
        ->assertSeeLivewire(BestSellersTable::class)
        ->assertSeeLivewire(LowStockTable::class);
});

it('shows a cashier the dashboard without any figures', function () {
    $product = Product::factory()->create(['selling_price' => '50.00', 'stock_quantity' => 10]);
    checkout(cashier(), cartOf([[$product, 3]]), cashCents: 15000);

    $this->actingAs(cashier())->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(AccountWidget::class)
        ->assertDontSeeLivewire(TodayStatsOverview::class)
        ->assertDontSeeLivewire(SalesTrendChart::class)
        ->assertDontSeeLivewire(BestSellersTable::class)
        ->assertDontSeeLivewire(LowStockTable::class)
        ->assertDontSee('₱150.00');
});

it('puts the summary, chart and the three breakdowns on Sales Reports', function () {
    $this->actingAs(admin())->get('/admin/sales-reports')
        ->assertOk()
        ->assertSee('Sales Reports')
        ->assertSeeLivewire(ReportSummaryStats::class)
        ->assertSeeLivewire(SalesTrendChart::class)
        ->assertSeeLivewire(SalesByProductTable::class)
        ->assertSeeLivewire(SalesByCategoryTable::class)
        ->assertSeeLivewire(SalesByCashierTable::class)
        ->assertDontSeeLivewire(TodayStatsOverview::class);
});

it('links Sales Reports in the navigation for admins', function () {
    $this->actingAs(admin())->get('/admin')->assertOk()->assertSee('/admin/sales-reports');
});

it('does not link Sales Reports in the navigation for cashiers', function () {
    $this->actingAs(cashier())->get('/admin')->assertOk()->assertDontSee('/admin/sales-reports');
});

it('sends guests to the login page', function () {
    $this->get('/admin/sales-reports')->assertRedirect('/admin/login');
});

it('refuses Sales Reports to an inactive admin', function () {
    $this->actingAs(admin(['is_active' => false]))->get('/admin/sales-reports')->assertForbidden();
});

it('refuses the Sales Reports component to a cashier', function () {
    Livewire::actingAs(cashier())->test(SalesReports::class)->assertForbidden();
});

it('defaults the filters to the last 30 days, grouped by day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 14:00'));

    Livewire::actingAs(admin())->test(SalesReports::class)
        ->assertSet('filters.from', '2026-10-18')
        ->assertSet('filters.to', '2026-11-16')
        ->assertSet('filters.period', 'day');
});

it('keeps valid filters from the URL', function () {
    Livewire::withQueryParams(['filters' => ['from' => '2026-11-01', 'to' => '2026-11-07', 'period' => 'week']])
        ->actingAs(admin())
        ->test(SalesReports::class)
        ->assertSet('filters', ['from' => '2026-11-01', 'to' => '2026-11-07', 'period' => 'week']);
});

it('repairs tampered filters sent over Livewire', function (string $path, mixed $value, array $expected) {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 14:00'));

    $page = Livewire::actingAs(admin())->test(SalesReports::class)
        ->set($path, $value)
        ->assertSuccessful();

    expect($page->get('filters'))->toBe($expected);
})->with([
    'array start date' => ['filters.from', ['2026-11-01'], ['from' => null, 'to' => '2026-11-16', 'period' => 'day']],
    'nested array end date' => ['filters.to', [['x']], ['from' => '2026-10-18', 'to' => null, 'period' => 'day']],
    'garbage start date' => ['filters.from', 'next monday', ['from' => null, 'to' => '2026-11-16', 'period' => 'day']],
    'impossible end date' => ['filters.to', '2026-02-30', ['from' => '2026-10-18', 'to' => null, 'period' => 'day']],
    'number start date' => ['filters.from', 20261101, ['from' => null, 'to' => '2026-11-16', 'period' => 'day']],
    'unknown period' => ['filters.period', 'year', ['from' => '2026-10-18', 'to' => '2026-11-16', 'period' => 'day']],
    'array period' => ['filters.period', ['week'], ['from' => '2026-10-18', 'to' => '2026-11-16', 'period' => 'day']],
    'unknown key' => ['filters.junk', ['x'], ['from' => '2026-10-18', 'to' => '2026-11-16', 'period' => 'day']],
    'valid weekly' => ['filters.period', 'week', ['from' => '2026-10-18', 'to' => '2026-11-16', 'period' => 'week']],
    'valid start date' => ['filters.from', '2026-11-01', ['from' => '2026-11-01', 'to' => '2026-11-16', 'period' => 'day']],
]);

it('stores only repaired filters in the session', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 14:00'));
    $admin = admin();

    Livewire::actingAs($admin)->test(SalesReports::class)->set('filters.from', ['x'])->set('filters.period', 'year');

    Livewire::actingAs($admin)->test(SalesReports::class)
        ->assertSet('filters', ['from' => null, 'to' => '2026-11-16', 'period' => 'day']);
});

it('does not fail on tampered filters in the URL', function (string $query) {
    $this->actingAs(admin())->get("/admin/sales-reports?{$query}")->assertOk();
})->with([
    'array start date' => 'filters[from][]=2026-11-01',
    'nested array end date' => 'filters[to][0][]=x',
    'garbage dates' => 'filters[from]=yesterday&filters[to]=soon',
    'array period' => 'filters[period][]=week',
    'unknown period' => 'filters[period]=year',
    'start after end' => 'filters[from]=2026-11-10&filters[to]=2026-11-01',
    'fifty-year range' => 'filters[from]=1976-01-01&filters[to]=2026-11-16',
    'huge year' => 'filters[from]=99999-01-01',
]);

it('opens Sales Reports with the default filters when the URL filters value is not a set of filters', function (string $query) {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 14:00'));

    $this->actingAs(admin())->get("/admin/sales-reports?{$query}")
        ->assertOk()
        ->assertSee('2026-10-18')
        ->assertSee('2026-11-16');
})->with([
    'text' => 'filters=x',
    'number' => 'filters=123',
    'null' => 'filters=null',
    'quoted text' => 'filters="abc"',
    'empty' => 'filters=',
]);

it('still applies valid filters from the URL to the page', function () {
    $this->actingAs(admin())->get('/admin/sales-reports?filters[from]=2026-11-01&filters[to]=2026-11-07&filters[period]=week')
        ->assertOk()
        ->assertSee('2026-11-01')
        ->assertSee('2026-11-07');
});

it('repairs tampered filters read from the URL', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 14:00'));

    Livewire::withQueryParams(['filters' => ['from' => ['2026-11-01'], 'to' => '2026-11-10', 'period' => ['week'], 'junk' => [['x']]]])
        ->actingAs(admin())
        ->test(SalesReports::class)
        ->assertSuccessful()
        ->assertSet('filters', ['from' => null, 'to' => '2026-11-10', 'period' => 'day']);
});
