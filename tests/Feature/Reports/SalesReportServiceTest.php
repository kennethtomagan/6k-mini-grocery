<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Pos\Cart;
use App\Services\SalesReportService;
use App\Services\TransactionService;
use App\Support\ReportRange;
use Carbon\CarbonImmutable;

function sellOn(string $dateTime, Product $product, int $quantity, ?User $user = null): Transaction
{
    test()->travelTo(CarbonImmutable::parse($dateTime));

    return checkout($user ?? cashier(), cartOf([[$product, $quantity]]), cashCents: 100000000);
}

beforeEach(function () {
    $this->reports = app(SalesReportService::class);
    $this->coke = Product::factory()->create(['name' => 'Coke', 'selling_price' => '20.00', 'stock_quantity' => 1000]);
    $this->chips = Product::factory()->create(['name' => 'Chips', 'selling_price' => '10.00', 'stock_quantity' => 1000]);
    $this->from = CarbonImmutable::parse('2026-11-02 00:00:00'); // a Monday
    $this->to = CarbonImmutable::parse('2026-11-15 23:59:59');   // the Sunday two weeks later
});

it('summarises completed sales in range and ignores voided and out-of-range sales', function () {
    sellOn('2026-11-02 09:00', $this->coke, 2);          // 40.00
    sellOn('2026-11-04 15:00', $this->chips, 3);         // 30.00
    $voided = sellOn('2026-11-04 16:00', $this->coke, 5); // voided
    sellOn('2026-12-01 10:00', $this->coke, 1);          // outside range
    app(TransactionService::class)->void($voided, admin(), 'Test');

    expect($this->reports->summary($this->from, $this->to))->toBe([
        'total_sales_cents' => 7000,
        'transaction_count' => 2,
        'average_cents' => 3500,
    ]);
});

it('returns zeros for an empty range', function () {
    expect($this->reports->summary($this->from, $this->to))->toBe([
        'total_sales_cents' => 0,
        'transaction_count' => 0,
        'average_cents' => 0,
    ]);
});

it('lists every day in range, including days with no sales', function () {
    sellOn('2026-11-02 09:00', $this->coke, 1);
    sellOn('2026-11-02 18:00', $this->coke, 1);
    sellOn('2026-11-04 15:00', $this->chips, 1);

    $days = $this->reports->salesByDay($this->from, CarbonImmutable::parse('2026-11-04 23:59:59'));

    expect($days->all())->toBe([
        ['date' => '2026-11-02', 'total_cents' => 4000, 'transactions' => 2],
        ['date' => '2026-11-03', 'total_cents' => 0, 'transactions' => 0],
        ['date' => '2026-11-04', 'total_cents' => 1000, 'transactions' => 1],
    ]);
});

it('rolls days up into weeks and months', function () {
    sellOn('2026-11-02 09:00', $this->coke, 1);  // week of Nov 2
    sellOn('2026-11-08 09:00', $this->coke, 1);  // Sunday, still week of Nov 2
    sellOn('2026-11-09 09:00', $this->chips, 1); // week of Nov 9

    $weeks = $this->reports->salesByPeriod($this->from, $this->to, 'week');
    $months = $this->reports->salesByPeriod($this->from, $this->to, 'month');

    expect($weeks->all())->toBe([
        ['period' => '2026-11-02', 'label' => 'Week of Nov 2', 'total_cents' => 4000, 'transactions' => 2],
        ['period' => '2026-11-09', 'label' => 'Week of Nov 9', 'total_cents' => 1000, 'transactions' => 1],
    ])->and($months->all())->toBe([
        ['period' => '2026-11', 'label' => 'Nov 2026', 'total_cents' => 5000, 'transactions' => 3],
    ]);
});

it('reports sales by product from snapshots, with manual items separate', function () {
    sellOn('2026-11-02 09:00', $this->coke, 2);
    sellOn('2026-11-03 09:00', $this->coke, 1);
    sellOn('2026-11-03 10:00', $this->chips, 1);

    $this->travelTo(CarbonImmutable::parse('2026-11-03 11:00'));
    $cart = new Cart;
    $cart->addManual('Ice', 4, 500);
    checkout(cashier(), $cart, cashCents: 100000);

    $this->coke->update(['name' => 'Coke (renamed later)']);
    $this->coke->delete();

    $rows = $this->reports->salesByProduct($this->from, $this->to);

    expect($rows->all())->toBe([
        ['product_id' => $this->coke->id, 'name' => 'Coke', 'is_manual' => false, 'quantity' => 3, 'total_cents' => 6000],
        ['product_id' => null, 'name' => 'Ice', 'is_manual' => true, 'quantity' => 4, 'total_cents' => 2000],
        ['product_id' => $this->chips->id, 'name' => 'Chips', 'is_manual' => false, 'quantity' => 1, 'total_cents' => 1000],
    ]);
});

it('ranks best sellers by quantity', function () {
    sellOn('2026-11-02 09:00', $this->coke, 2);   // 40.00, qty 2
    sellOn('2026-11-02 10:00', $this->chips, 3);  // 30.00, qty 3

    $best = $this->reports->bestSellers($this->from, $this->to, limit: 1);

    expect($best)->toHaveCount(1)->and($best->first()['name'])->toBe('Chips');
});

it('reports sales by category, counting multi-category products in each', function () {
    $drinks = Category::factory()->create(['name' => 'Drinks']);
    $cold = Category::factory()->create(['name' => 'Cold items']);
    $this->coke->categories()->attach([$drinks->id, $cold->id]);

    sellOn('2026-11-02 09:00', $this->coke, 2);  // 40.00 in Drinks and in Cold items
    sellOn('2026-11-02 10:00', $this->chips, 1); // 10.00 Uncategorized

    $rows = $this->reports->salesByCategory($this->from, $this->to)->keyBy('category');

    expect($rows['Drinks'])->toMatchArray(['quantity' => 2, 'total_cents' => 4000])
        ->and($rows['Cold items'])->toMatchArray(['quantity' => 2, 'total_cents' => 4000])
        ->and($rows['Uncategorized'])->toMatchArray(['quantity' => 1, 'total_cents' => 1000]);
});

it('reports sales by cashier', function () {
    $ana = cashier(['name' => 'Ana']);
    $ben = cashier(['name' => 'Ben']);
    sellOn('2026-11-02 09:00', $this->coke, 2, $ana);
    sellOn('2026-11-02 10:00', $this->coke, 1, $ana);
    sellOn('2026-11-02 11:00', $this->chips, 1, $ben);

    expect($this->reports->salesByCashier($this->from, $this->to)->all())->toBe([
        ['user_id' => $ana->id, 'cashier' => 'Ana', 'transactions' => 2, 'total_cents' => 6000],
        ['user_id' => $ben->id, 'cashier' => 'Ben', 'transactions' => 1, 'total_cents' => 1000],
    ]);
});

it('resolves report ranges from filters with a 30-day default', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 14:00'));

    [$defaultFrom, $defaultTo] = ReportRange::fromFilters(null);
    [$from, $to] = ReportRange::fromFilters(['from' => '2026-11-01', 'to' => '2026-11-07']);
    [$swappedFrom, $swappedTo] = ReportRange::fromFilters(['from' => '2026-11-07', 'to' => '2026-11-01']);

    expect($defaultFrom->toDateTimeString())->toBe('2026-10-18 00:00:00')
        ->and($defaultTo->toDateTimeString())->toBe('2026-11-16 23:59:59')
        ->and($from->toDateTimeString())->toBe('2026-11-01 00:00:00')
        ->and($to->toDateTimeString())->toBe('2026-11-07 23:59:59')
        ->and($swappedFrom->toDateString())->toBe('2026-11-01')
        ->and($swappedTo->toDateString())->toBe('2026-11-07');
});

it('excludes voided sales from every report', function () {
    $drinks = Category::factory()->create(['name' => 'Drinks']);
    $this->chips->categories()->attach($drinks);
    $ben = cashier(['name' => 'Ben']);

    sellOn('2026-11-03 09:00', $this->coke, 1);
    $voided = sellOn('2026-11-03 10:00', $this->chips, 9, $ben);
    app(TransactionService::class)->void($voided, admin(), 'Test');

    $from = CarbonImmutable::parse('2026-11-03 00:00:00');
    $to = CarbonImmutable::parse('2026-11-03 23:59:59');

    expect($this->reports->summary($from, $to))->toBe(['total_sales_cents' => 2000, 'transaction_count' => 1, 'average_cents' => 2000])
        ->and($this->reports->salesByDay($from, $to)->all())->toBe([
            ['date' => '2026-11-03', 'total_cents' => 2000, 'transactions' => 1],
        ])
        ->and($this->reports->salesByPeriod($from, $to, 'week')->all())->toBe([
            ['period' => '2026-11-02', 'label' => 'Week of Nov 2', 'total_cents' => 2000, 'transactions' => 1],
        ])
        ->and($this->reports->salesByProduct($from, $to)->pluck('name')->all())->toBe(['Coke'])
        ->and($this->reports->bestSellers($from, $to)->pluck('name')->all())->toBe(['Coke'])
        ->and($this->reports->salesByCategory($from, $to)->all())->toBe([
            ['category_id' => null, 'category' => 'Uncategorized', 'quantity' => 1, 'total_cents' => 2000],
        ])
        ->and($this->reports->salesByCashier($from, $to)->pluck('cashier')->all())->not->toContain('Ben');
});

it('counts a sale on the first and last second of the range and nothing outside it', function () {
    sellOn('2026-11-01 23:59:59', $this->coke, 1);  // just before
    sellOn('2026-11-02 00:00:00', $this->chips, 1); // first second
    sellOn('2026-11-15 23:59:59', $this->chips, 2); // last second
    sellOn('2026-11-16 00:00:00', $this->coke, 1);  // just after

    $days = $this->reports->salesByDay($this->from, $this->to);

    expect($this->reports->summary($this->from, $this->to))->toMatchArray(['total_sales_cents' => 3000, 'transaction_count' => 2])
        ->and($days)->toHaveCount(14)
        ->and($days->first())->toBe(['date' => '2026-11-02', 'total_cents' => 1000, 'transactions' => 1])
        ->and($days->last())->toBe(['date' => '2026-11-15', 'total_cents' => 2000, 'transactions' => 1])
        ->and($this->reports->salesByProduct($this->from, $this->to)->pluck('quantity', 'name')->all())->toBe(['Chips' => 3])
        ->and($this->reports->salesByCashier($this->from, $this->to)->sum('transactions'))->toBe(2);
});

it('includes a sale made at the end of the day when given the end of day with microseconds', function () {
    sellOn('2026-11-15 23:59:59', $this->coke, 1);

    expect($this->reports->summary($this->from, CarbonImmutable::parse('2026-11-15')->endOfDay()))
        ->toMatchArray(['total_sales_cents' => 2000, 'transaction_count' => 1]);
});

it('reads range bounds given in another timezone as the same instants', function () {
    sellOn('2026-11-02 07:30:00', $this->coke, 1);  // 2026-11-01 23:30 UTC
    sellOn('2026-11-01 23:00:00', $this->chips, 1); // the day before, in Manila

    $from = CarbonImmutable::parse('2026-11-01 16:00:00', 'UTC'); // 2026-11-02 00:00 in Manila
    $to = CarbonImmutable::parse('2026-11-02 15:59:59', 'UTC');   // 2026-11-02 23:59:59 in Manila

    expect($this->reports->salesByDay($from, $to)->all())->toBe([
        ['date' => '2026-11-02', 'total_cents' => 2000, 'transactions' => 1],
    ]);
});

it('uses the net total for summary, daily and cashier figures and gross line totals for product and category', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00'));
    $owner = admin(['name' => 'Owner']);
    checkout($owner, cartOf([[$this->coke, 2]]), cashCents: 100000, discountCents: 550); // 40.00 less 5.50

    expect($this->reports->summary($this->from, $this->to)['total_sales_cents'])->toBe(3450)
        ->and($this->reports->salesByDay($this->from, $this->to)->sum('total_cents'))->toBe(3450)
        ->and($this->reports->salesByPeriod($this->from, $this->to, 'month')->first()['total_cents'])->toBe(3450)
        ->and($this->reports->salesByCashier($this->from, $this->to)->first())->toMatchArray(['cashier' => 'Owner', 'total_cents' => 3450])
        ->and($this->reports->salesByProduct($this->from, $this->to)->first())->toMatchArray(['name' => 'Coke', 'total_cents' => 4000])
        ->and($this->reports->salesByCategory($this->from, $this->to)->first())->toMatchArray(['category' => 'Uncategorized', 'total_cents' => 4000]);
});

it('rounds the average sale to the nearest centavo, half up', function () {
    $odd = Product::factory()->create(['name' => 'Odd', 'selling_price' => '10.01', 'stock_quantity' => 100]);
    sellOn('2026-11-02 09:00', $this->chips, 1); // 10.00
    sellOn('2026-11-02 10:00', $odd, 1);         // 10.01

    expect($this->reports->summary($this->from, $this->to))->toBe([
        'total_sales_cents' => 2001,
        'transaction_count' => 2,
        'average_cents' => 1001,
    ]);
});

it('sums decimal amounts exactly', function () {
    $tenCents = Product::factory()->create(['name' => 'Candy', 'selling_price' => '0.10', 'stock_quantity' => 1000]);

    foreach (range(1, 3) as $hour) {
        sellOn("2026-11-02 0{$hour}:00", $tenCents, 1);
    }

    expect($this->reports->summary($this->from, $this->to)['total_sales_cents'])->toBe(30)
        ->and($this->reports->salesByProduct($this->from, $this->to)->first()['total_cents'])->toBe(30);
});

it('starts weeks on Monday and keeps weeks and months without sales as zero rows', function () {
    sellOn('2026-10-31 09:00', $this->coke, 1);  // Saturday: week of Oct 26, October
    sellOn('2026-12-07 09:00', $this->chips, 1); // Monday: week of Dec 7, December

    $from = CarbonImmutable::parse('2026-10-28 00:00:00'); // a Wednesday
    $to = CarbonImmutable::parse('2026-12-08 23:59:59');

    $weeks = $this->reports->salesByPeriod($from, $to, 'week');
    $months = $this->reports->salesByPeriod($from, $to, 'month');

    expect($weeks->pluck('period')->all())->toBe(['2026-10-26', '2026-11-02', '2026-11-09', '2026-11-16', '2026-11-23', '2026-11-30', '2026-12-07'])
        ->and($weeks->pluck('total_cents')->all())->toBe([2000, 0, 0, 0, 0, 0, 1000])
        ->and($weeks->first()['label'])->toBe('Week of Oct 26')
        ->and($months->all())->toBe([
            ['period' => '2026-10', 'label' => 'Oct 2026', 'total_cents' => 2000, 'transactions' => 1],
            ['period' => '2026-11', 'label' => 'Nov 2026', 'total_cents' => 0, 'transactions' => 0],
            ['period' => '2026-12', 'label' => 'Dec 2026', 'total_cents' => 1000, 'transactions' => 1],
        ]);
});

it('groups by day when asked for daily or an unknown period', function (string $period) {
    sellOn('2026-11-03 09:00', $this->coke, 1);

    $rows = $this->reports->salesByPeriod($this->from, CarbonImmutable::parse('2026-11-03 23:59:59'), $period);

    expect($rows->all())->toBe([
        ['period' => '2026-11-02', 'label' => 'Nov 2', 'total_cents' => 0, 'transactions' => 0],
        ['period' => '2026-11-03', 'label' => 'Nov 3', 'total_cents' => 2000, 'transactions' => 1],
    ]);
})->with(['day', 'year']);

it('keeps differently named manual items apart and combines repeats of the same one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-03 11:00'));

    foreach ([['Ice', 2, 500], ['Ice', 1, 500], ['Load', 1, 5000]] as [$name, $quantity, $price]) {
        $cart = new Cart;
        $cart->addManual($name, $quantity, $price);
        checkout(cashier(), $cart, cashCents: 100000);
    }

    expect($this->reports->salesByProduct($this->from, $this->to)->all())->toBe([
        ['product_id' => null, 'name' => 'Load', 'is_manual' => true, 'quantity' => 1, 'total_cents' => 5000],
        ['product_id' => null, 'name' => 'Ice', 'is_manual' => true, 'quantity' => 3, 'total_cents' => 1500],
    ])->and($this->reports->salesByCategory($this->from, $this->to)->all())->toBe([
        ['category_id' => null, 'category' => 'Uncategorized', 'quantity' => 4, 'total_cents' => 6500],
    ]);
});

it('keeps a deleted product under its category and moves items of a deleted category to Uncategorized', function () {
    $drinks = Category::factory()->create(['name' => 'Drinks']);
    $snacks = Category::factory()->create(['name' => 'Snacks']);
    $this->coke->categories()->attach($drinks);
    $this->chips->categories()->attach($snacks);

    sellOn('2026-11-02 09:00', $this->coke, 1);
    sellOn('2026-11-02 10:00', $this->chips, 1);

    $this->coke->delete();
    $snacks->delete();

    expect($this->reports->salesByCategory($this->from, $this->to)->all())->toBe([
        ['category_id' => $drinks->id, 'category' => 'Drinks', 'quantity' => 1, 'total_cents' => 2000],
        ['category_id' => null, 'category' => 'Uncategorized', 'quantity' => 1, 'total_cents' => 1000],
    ]);
});

it('ignores tampered or malformed filter values and falls back to the default range', function (mixed $value) {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 14:00'));

    [$from, $to] = ReportRange::fromFilters(['from' => $value, 'to' => $value, 'period' => $value]);

    expect($from->toDateTimeString())->toBe('2026-10-18 00:00:00')
        ->and($to->toDateTimeString())->toBe('2026-11-16 23:59:59');
})->with([
    'text' => 'not a date',
    'impossible day' => '2026-02-30',
    'array' => [['2026-11-01']],
    'number' => 20261101,
    'relative phrase' => 'next monday',
    'huge year' => '99999-01-01',
    'date with time' => '2026-11-01 10:00:00',
    'boolean' => true,
    'empty' => '',
]);

it('keeps a valid end date when the start date is malformed', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 14:00'));

    [$from, $to] = ReportRange::fromFilters(['from' => ['x'], 'to' => '2026-11-10']);

    expect($from->toDateString())->toBe('2026-10-18')
        ->and($to->toDateTimeString())->toBe('2026-11-10 23:59:59');
});

it('caps a very long range to the most recent days before its end', function () {
    [$from, $to] = ReportRange::fromFilters(['from' => '1976-01-01', 'to' => '2026-11-16']);

    expect($to->toDateTimeString())->toBe('2026-11-16 23:59:59')
        ->and($from->toDateTimeString())->toBe('2024-11-16 00:00:00')
        ->and((int) $from->diffInDays($to->addSecond()))->toBe(ReportRange::MAX_DAYS);
});

it('keeps a range of exactly the maximum length', function () {
    [$from, $to] = ReportRange::fromFilters(['from' => '2024-11-16', 'to' => '2026-11-16']);

    expect($from->toDateString())->toBe('2024-11-16')
        ->and($to->toDateString())->toBe('2026-11-16');
});

it('matches hand-computed category figures across multi-category, manual, uncategorised and discounted sales', function () {
    $drinks = Category::factory()->create(['name' => 'Drinks']);
    $cold = Category::factory()->create(['name' => 'Cold items']);
    $snacks = Category::factory()->create(['name' => 'Snacks']);
    $toiletries = Category::factory()->create(['name' => 'Toiletries']);
    $juice = Product::factory()->create(['name' => 'Juice', 'selling_price' => '25.00', 'stock_quantity' => 100]);
    $soap = Product::factory()->create(['name' => 'Soap', 'selling_price' => '30.00', 'stock_quantity' => 100]);
    $candle = Product::factory()->create(['name' => 'Candle', 'selling_price' => '15.00', 'stock_quantity' => 100]);
    $this->coke->categories()->attach([$drinks->id, $cold->id]);
    $juice->categories()->attach($drinks);
    $this->chips->categories()->attach($snacks);
    $soap->categories()->attach($toiletries);

    sellOn('2026-11-02 09:00', $this->coke, 2);     // 40.00 in Drinks and Cold items
    sellOn('2026-11-03 09:00', $juice, 3);          // 75.00 in Drinks
    sellOn('2026-11-04 09:00', $this->chips, 4);    // 40.00 in Snacks
    sellOn('2026-11-05 09:00', $candle, 1);         // 15.00 Uncategorized
    $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00'));
    $cart = cartOf([[$soap, 1]]);
    $cart->addManual('Ice', 4, 500);                // 20.00 Uncategorized
    checkout(admin(), $cart, cashCents: 100000, discountCents: 1000); // soap 30.00 gross; the discount is not spread
    $voided = sellOn('2026-11-07 09:00', $juice, 9);
    app(TransactionService::class)->void($voided, admin(), 'Test');
    sellOn('2026-11-16 00:00', $soap, 5);           // after the range
    $juice->delete();

    expect($this->reports->salesByCategory($this->from, $this->to)->all())->toBe([
        ['category_id' => $drinks->id, 'category' => 'Drinks', 'quantity' => 5, 'total_cents' => 11500],
        ['category_id' => $cold->id, 'category' => 'Cold items', 'quantity' => 2, 'total_cents' => 4000],
        ['category_id' => $snacks->id, 'category' => 'Snacks', 'quantity' => 4, 'total_cents' => 4000],
        ['category_id' => null, 'category' => 'Uncategorized', 'quantity' => 5, 'total_cents' => 3500],
        ['category_id' => $toiletries->id, 'category' => 'Toiletries', 'quantity' => 1, 'total_cents' => 3000],
    ]);
});

it('names a product renamed within the range after its latest sale', function () {
    $milk = Product::factory()->create(['name' => 'Milk', 'selling_price' => '30.00', 'stock_quantity' => 100]);
    $zesto = Product::factory()->create(['name' => 'Zesto', 'selling_price' => '12.00', 'stock_quantity' => 100]);

    sellOn('2026-11-02 09:00', $milk, 1);
    sellOn('2026-11-02 09:30', $zesto, 1);
    $milk->update(['name' => 'Whole Milk']);   // MIN() would keep "Milk"
    $zesto->update(['name' => 'Apple Zesto']); // MAX() would keep "Zesto"
    sellOn('2026-11-03 09:00', $milk, 2);
    sellOn('2026-11-03 09:30', $zesto, 2);

    $rows = $this->reports->salesByProduct($this->from, $this->to)->keyBy('product_id');

    expect($rows)->toHaveCount(2)
        ->and($rows[$milk->id])->toBe(['product_id' => $milk->id, 'name' => 'Whole Milk', 'is_manual' => false, 'quantity' => 3, 'total_cents' => 9000])
        ->and($rows[$zesto->id])->toBe(['product_id' => $zesto->id, 'name' => 'Apple Zesto', 'is_manual' => false, 'quantity' => 3, 'total_cents' => 3600]);
});

it('keeps a product\'s name from its last sale in the range, not its current name', function () {
    $milk = Product::factory()->create(['name' => 'Milk', 'selling_price' => '30.00', 'stock_quantity' => 100]);
    sellOn('2026-11-02 09:00', $milk, 1);
    $milk->update(['name' => 'Whole Milk']);
    sellOn('2026-11-20 09:00', $milk, 1); // after the range

    expect($this->reports->salesByProduct($this->from, $this->to)->first()['name'])->toBe('Milk');
});

it('adds the year to daily and weekly labels when the range spans two calendar years', function () {
    sellOn('2025-12-30 09:00', $this->coke, 1);
    sellOn('2026-01-02 09:00', $this->chips, 1);

    $from = CarbonImmutable::parse('2025-12-30 00:00:00');
    $to = CarbonImmutable::parse('2026-01-05 23:59:59');

    expect($this->reports->salesByPeriod($from, $to, 'day')->pluck('label')->all())
        ->toBe(['Dec 30, 2025', 'Dec 31, 2025', 'Jan 1, 2026', 'Jan 2, 2026', 'Jan 3, 2026', 'Jan 4, 2026', 'Jan 5, 2026'])
        ->and($this->reports->salesByPeriod($from, $to, 'week')->pluck('label')->all())
        ->toBe(['Week of Dec 29, 2025', 'Week of Jan 5, 2026'])
        ->and($this->reports->salesByPeriod($from, $to, 'month')->pluck('label')->all())
        ->toBe(['Dec 2025', 'Jan 2026']);
});

it('leaves the year out of daily and weekly labels within one calendar year', function () {
    $from = CarbonImmutable::parse('2026-01-01 00:00:00');
    $to = CarbonImmutable::parse('2026-12-31 23:59:59');

    $days = $this->reports->salesByPeriod($from, $to, 'day')->pluck('label');
    $weeks = $this->reports->salesByPeriod($from, $to, 'week')->pluck('label');

    expect($days->first())->toBe('Jan 1')
        ->and($days->last())->toBe('Dec 31')
        ->and($weeks->first())->toBe('Week of Dec 29')
        ->and($weeks->last())->toBe('Week of Dec 28');
});
