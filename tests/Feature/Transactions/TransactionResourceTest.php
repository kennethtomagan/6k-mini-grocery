<?php

use App\Enums\StockMovementType;
use App\Enums\TransactionStatus;
use App\Filament\Resources\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Transactions\Pages\ViewTransaction;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\TransactionService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 100]);
});

it('shows cashiers only their own sales', function () {
    $ana = cashier();
    $ben = cashier();
    $own = checkout($ana, cartOf([[$this->product, 1]]), cashCents: 1000);
    $other = checkout($ben, cartOf([[$this->product, 1]]), cashCents: 1000);

    Livewire::actingAs($ana)->test(ListTransactions::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::actingAs(admin())->test(ListTransactions::class)
        ->assertCanSeeTableRecords([$own, $other]);

    $this->actingAs($ana)->get("/admin/transactions/{$own->id}")->assertOk();
    $this->actingAs($ana)->get("/admin/transactions/{$other->id}")->assertNotFound();
});

it('finds a sale by its transaction number', function () {
    $cashier = cashier();
    $first = checkout($cashier, cartOf([[$this->product, 1]]), cashCents: 1000);
    $second = checkout($cashier, cartOf([[$this->product, 1]]), cashCents: 1000);

    Livewire::actingAs(admin())->test(ListTransactions::class)
        ->searchTable($first->reference_number)
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$second]);
});

it('shows sale details including overrides and manual items', function () {
    $trusted = cashier(['name' => 'Ana Reyes', 'can_override_price' => true]);
    $regular = Product::factory()->create(['name' => 'Kopiko Brown', 'selling_price' => '12.00', 'stock_quantity' => 10]);
    $cart = cartOf([[$this->product, 2], [$regular, 1]]);
    $cart->overridePrice('p'.$this->product->id, 800);
    $cart->addManual('Ice (plastic)', 1, 500);
    $sale = checkout($trusted, $cart, cashCents: 5000);

    $this->actingAs(admin(['name' => 'Store Owner']))->get("/admin/transactions/{$sale->id}")
        ->assertOk()
        ->assertSee($sale->reference_number)
        ->assertSeeInOrder([
            $this->product->name, '2', '₱10.00', '₱8.00', '₱16.00', 'Price changed by', 'Ana Reyes',
            'Kopiko Brown', '₱12.00', '₱12.00', 'Price changed by', '—',
            'Ice (plastic)', '₱5.00', '₱5.00', 'Price changed by', '—',
        ])
        ->assertSeeInOrder(['Subtotal', '₱33.00', 'Discount', '₱0.00', 'Total', '₱33.00', 'Cash received', '₱50.00', 'Change', '₱17.00']);
});

it('voids a sale from the list as admin', function () {
    $sale = checkout(cashier(), cartOf([[$this->product, 5]]), cashCents: 5000);

    Livewire::actingAs(admin())->test(ListTransactions::class)
        ->callTableAction('void', $sale, data: ['reason' => 'Wrong item scanned'])
        ->assertHasNoTableActionErrors();

    expect($sale->fresh()->status)->toBe(TransactionStatus::Voided)
        ->and($this->product->fresh()->stock_quantity)->toBe(100);
});

it('hides the void action from cashiers', function () {
    $cashier = cashier();
    $sale = checkout($cashier, cartOf([[$this->product, 1]]), cashCents: 1000);

    Livewire::actingAs($cashier)->test(ListTransactions::class)
        ->assertTableActionHidden('void', $sale);
});

it('serves the sales history at the URL the POS header links to', function () {
    expect(TransactionResource::getUrl('index'))->toBe(url('/admin/transactions'));

    $this->actingAs(cashier())->get('/admin/transactions')->assertOk();
    $this->actingAs(admin())->get('/admin/transactions')->assertOk();
});

it('sends guests to the login page', function () {
    $sale = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);

    $this->get('/admin/transactions')->assertRedirect('/admin/login');
    $this->get("/admin/transactions/{$sale->id}")->assertRedirect('/admin/login');
});

it('lets an admin open any cashier\'s sale', function () {
    $sale = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);

    $this->actingAs(admin())->get("/admin/transactions/{$sale->id}")->assertOk();
});

it('refuses another cashier\'s sale on the view page at the Livewire level too', function () {
    $sale = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);

    expect(fn () => Livewire::actingAs(cashier())->test(ViewTransaction::class, ['record' => $sale->getRouteKey()]))
        ->toThrow(ModelNotFoundException::class);
});

it('has no create, edit or delete pages or actions', function () {
    $sale = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);

    expect(array_keys(TransactionResource::getPages()))->toBe(['index', 'view']);

    $this->actingAs(admin());

    expect(TransactionResource::canCreate())->toBeFalse()
        ->and(TransactionResource::canEdit($sale))->toBeFalse()
        ->and(TransactionResource::canDelete($sale))->toBeFalse();

    Livewire::actingAs(admin())->test(ListTransactions::class)
        ->assertActionDoesNotExist(TestAction::make('edit')->table($sale))
        ->assertActionDoesNotExist(TestAction::make('delete')->table($sale))
        ->assertActionDoesNotExist('create');

    Livewire::actingAs(admin())->test(ViewTransaction::class, ['record' => $sale->getRouteKey()])
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete');
});

it('voids a sale from its detail page and then shows who voided it and why', function () {
    $admin = admin(['name' => 'Owner Admin']);
    $sale = checkout(cashier(), cartOf([[$this->product, 3]]), cashCents: 3000);

    Livewire::actingAs($admin)->test(ViewTransaction::class, ['record' => $sale->getRouteKey()])
        ->callAction('void', data: ['reason' => 'Customer returned it'])
        ->assertHasNoFormErrors()
        ->assertNotified('Sale voided and stock restored')
        ->assertActionHidden('void');

    expect($sale->fresh()->status)->toBe(TransactionStatus::Voided)
        ->and($this->product->fresh()->stock_quantity)->toBe(100);

    $this->actingAs($admin)->get("/admin/transactions/{$sale->id}")
        ->assertOk()
        ->assertSee('Voided')
        ->assertSee('Customer returned it')
        ->assertSee('Owner Admin');
});

it('hides the void action on a sale that is already voided', function () {
    $admin = admin();
    $sale = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);
    app(TransactionService::class)->void($sale, $admin, 'Already done');

    Livewire::actingAs($admin)->test(ListTransactions::class)
        ->assertActionHidden(TestAction::make('void')->table($sale));

    Livewire::actingAs($admin)->test(ViewTransaction::class, ['record' => $sale->getRouteKey()])
        ->assertActionHidden('void');
});

it('hides the void action from a cashier on their own sale\'s detail page', function () {
    $cashier = cashier();
    $sale = checkout($cashier, cartOf([[$this->product, 1]]), cashCents: 1000);

    Livewire::actingAs($cashier)->test(ViewTransaction::class, ['record' => $sale->getRouteKey()])
        ->assertActionHidden('void');
});

it('does not restore stock again when the sale was voided while the modal was open', function () {
    $admin = admin();
    $sale = checkout(cashier(), cartOf([[$this->product, 4]]), cashCents: 4000);

    $component = Livewire::actingAs($admin)->test(ListTransactions::class)
        ->mountAction(TestAction::make('void')->table($sale));

    app(TransactionService::class)->void($sale, admin(), 'Other admin was faster');

    $component->setActionData(['reason' => 'Late click'])->callMountedAction();

    expect($this->product->fresh()->stock_quantity)->toBe(100)
        ->and(InventoryMovement::query()->where('type', StockMovementType::VoidReturn)->count())->toBe(1)
        ->and($sale->fresh()->void_reason)->toBe('Other admin was faster');
});

it('validates the void reason', function (mixed $reason, string $rule) {
    $sale = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);

    Livewire::actingAs(admin())->test(ListTransactions::class)
        ->callAction(TestAction::make('void')->table($sale), data: ['reason' => $reason])
        ->assertHasFormErrors(['reason' => $rule]);

    expect($sale->fresh()->status)->toBe(TransactionStatus::Completed)
        ->and($this->product->fresh()->stock_quantity)->toBe(99);
})->with([
    'missing' => [null, 'required'],
    'blank' => ['', 'required'],
    'whitespace only' => ["   \n ", 'required'],
    'too long' => [str_repeat('a', 256), 'max'],
    'array' => [['Wrong item'], 'string'],
]);

it('refuses a void reason made only of non-breaking spaces in the modal', function () {
    $sale = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);

    Livewire::actingAs(admin())->test(ListTransactions::class)
        ->callAction(TestAction::make('void')->table($sale), data: ['reason' => "\u{00A0}\u{00A0}"])
        ->assertHasFormErrors(['reason' => 'required']);

    expect($sale->fresh()->status)->toBe(TransactionStatus::Completed);
});

it('trims the void reason typed in the modal', function () {
    $sale = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);

    Livewire::actingAs(admin())->test(ListTransactions::class)
        ->callAction(TestAction::make('void')->table($sale), data: ['reason' => '  '.str_repeat('a', 255).'  '])
        ->assertHasNoFormErrors();

    expect($sale->fresh()->void_reason)->toBe(str_repeat('a', 255));
});

it('shows a notification instead of an error when restoring stock fails', function () {
    $admin = admin();
    $sale = checkout(cashier(), cartOf([[$this->product, 5]]), cashCents: 5000);
    app(InventoryService::class)->adjust($this->product, 2147483550, StockMovementType::Restock, $admin);

    Livewire::actingAs($admin)->test(ListTransactions::class)
        ->callAction(TestAction::make('void')->table($sale), data: ['reason' => 'Overflow'])
        ->assertNotified('Sale was not voided');

    expect($sale->fresh()->status)->toBe(TransactionStatus::Completed)
        ->and($this->product->fresh()->stock_quantity)->toBe(2147483645);
});

it('filters by cashier for admins only', function () {
    $ana = cashier();
    $ben = cashier();
    $anaSale = checkout($ana, cartOf([[$this->product, 1]]), cashCents: 1000);
    $benSale = checkout($ben, cartOf([[$this->product, 1]]), cashCents: 1000);

    Livewire::actingAs(admin())->test(ListTransactions::class)
        ->assertTableFilterVisible('user_id')
        ->filterTable('user_id', $ana->id)
        ->assertCanSeeTableRecords([$anaSale])
        ->assertCanNotSeeTableRecords([$benSale]);

    Livewire::actingAs($ana)->test(ListTransactions::class)
        ->assertTableFilterHidden('user_id');
});

it('keeps a cashier scoped to their own sales even with a tampered cashier filter', function () {
    $ana = cashier();
    $ben = cashier();
    $anaSale = checkout($ana, cartOf([[$this->product, 1]]), cashCents: 1000);
    $benSale = checkout($ben, cartOf([[$this->product, 1]]), cashCents: 1000);

    Livewire::actingAs($ana)->test(ListTransactions::class)
        ->set('tableFilters.user_id.value', $ben->id)
        ->assertSuccessful()
        ->assertCanNotSeeTableRecords([$benSale]);

    Livewire::actingAs($ana)->test(ListTransactions::class)
        ->searchTable($benSale->reference_number)
        ->assertCanNotSeeTableRecords([$benSale, $anaSale]);
});

it('filters by status', function () {
    $completed = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);
    $voided = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);
    app(TransactionService::class)->void($voided, admin(), 'Test');

    Livewire::actingAs(admin())->test(ListTransactions::class)
        ->filterTable('status', TransactionStatus::Voided)
        ->assertCanSeeTableRecords([$voided])
        ->assertCanNotSeeTableRecords([$completed]);
});

it('filters by an inclusive date range', function () {
    $cashier = cashier();
    $this->travelTo(Carbon::parse('2026-11-01 23:59:00'));
    $before = checkout($cashier, cartOf([[$this->product, 1]]), cashCents: 1000);
    $this->travelTo(Carbon::parse('2026-11-02 00:00:00'));
    $firstDay = checkout($cashier, cartOf([[$this->product, 1]]), cashCents: 1000);
    $this->travelTo(Carbon::parse('2026-11-03 23:59:59'));
    $lastDay = checkout($cashier, cartOf([[$this->product, 1]]), cashCents: 1000);
    $this->travelTo(Carbon::parse('2026-11-04 00:00:00'));
    $after = checkout($cashier, cartOf([[$this->product, 1]]), cashCents: 1000);
    $this->travelBack();

    Livewire::actingAs(admin())->test(ListTransactions::class)
        ->filterTable('date', ['from' => '2026-11-02', 'until' => '2026-11-03'])
        ->assertCanSeeTableRecords([$firstDay, $lastDay])
        ->assertCanNotSeeTableRecords([$before, $after]);
});

it('ignores malformed date filter input instead of failing', function (mixed $value) {
    $sale = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);

    Livewire::actingAs(admin())->test(ListTransactions::class)
        ->filterTable('date', ['from' => $value, 'until' => $value])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$sale]);
})->with([
    'text' => 'not a date',
    'impossible day' => '2026-02-30',
    'array' => [['2026-11-01']],
    'number' => 20261101,
    'relative phrase' => 'next monday',
    'huge year' => '99999-01-01',
]);

it('does not fail on tampered filter input sent over Livewire', function (string $path, mixed $value) {
    $sale = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);

    Livewire::actingAs(admin())->test(ListTransactions::class)
        ->set("tableFilters.{$path}", $value)
        ->assertSuccessful();

    Livewire::actingAs(admin())->test(ListTransactions::class)
        ->set("tableDeferredFilters.{$path}", $value)
        ->call('applyTableFilters')
        ->assertSuccessful();
})->with([
    'unknown status' => ['status.value', 'refunded'],
    'array status' => ['status.value', ['voided']],
    'nested array status' => ['status.value', [['voided']]],
    'array cashier' => ['user_id.value', ['1']],
    'scalar status state' => ['status', 'voided'],
    'scalar date state' => ['date', '2026-11-01'],
]);

it('does not fail on tampered filter input in the URL', function (string $query) {
    checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);

    $this->actingAs(admin())->get("/admin/transactions?{$query}")->assertOk();
})->with([
    'array status' => 'filters[status][value][]=voided',
    'array cashier' => 'filters[user_id][value][]=1',
    'array date' => 'filters[date][from][]=2026-11-01',
    'scalar status state' => 'filters[status]=voided',
    'unknown status' => 'filters[status][value]=refunded',
]);

it('ignores a filters value in the URL that is not a set of filters', function (string $query) {
    $sale = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);

    $this->actingAs(admin())->get("/admin/transactions?{$query}")
        ->assertOk()
        ->assertSee($sale->reference_number);
})->with([
    'text' => 'filters=x',
    'number' => 'filters=123',
    'null' => 'filters=null',
    'quoted text' => 'filters="abc"',
    'empty' => 'filters=',
]);

it('lets a cashier open the list with a filters value that is not a set of filters', function () {
    $cashier = cashier();
    $own = checkout($cashier, cartOf([[$this->product, 1]]), cashCents: 1000);

    $this->actingAs($cashier)->get('/admin/transactions?filters=x')
        ->assertOk()
        ->assertSee($own->reference_number);
});

it('applies a status filter from the URL', function () {
    $completed = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);
    $voided = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);
    app(TransactionService::class)->void($voided, admin(), 'Test');

    Livewire::withQueryParams(['filters' => ['status' => ['value' => 'voided']]])
        ->actingAs(admin())
        ->test(ListTransactions::class)
        ->assertCanSeeTableRecords([$voided])
        ->assertCanNotSeeTableRecords([$completed]);

    $this->actingAs(admin())->get('/admin/transactions?filters[status][value]=voided')
        ->assertOk()
        ->assertSee($voided->reference_number)
        ->assertDontSee($completed->reference_number);
});

it('applies a date filter from the URL', function () {
    $cashier = cashier();
    $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));
    $older = checkout($cashier, cartOf([[$this->product, 1]]), cashCents: 1000);
    $this->travelTo(Carbon::parse('2026-11-05 12:00:00'));
    $newer = checkout($cashier, cartOf([[$this->product, 1]]), cashCents: 1000);
    $this->travelBack();

    Livewire::withQueryParams(['filters' => ['date' => ['from' => '2026-11-03', 'until' => null]]])
        ->actingAs(admin())
        ->test(ListTransactions::class)
        ->assertCanSeeTableRecords([$newer])
        ->assertCanNotSeeTableRecords([$older]);
});

it('lists sales without per-row queries', function () {
    $admin = admin();
    $countQueries = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($admin)->test(ListTransactions::class);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);
    checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);
    $small = $countQueries();

    foreach (range(1, 6) as $ignored) {
        $sale = checkout(cashier(), cartOf([[$this->product, 1]]), cashCents: 1000);
    }
    app(TransactionService::class)->void($sale, $admin, 'Mixed statuses');
    $large = $countQueries();

    expect($large)->toBe($small);
});

it('shows a sale\'s detail page without per-item queries', function () {
    /** The first request also resolves the Store Settings navigation URL (one query). Filament's NavigationManager is scoped: it is mounted once per container scope (once per test here, once per request in production), so the first request in a test pays for it and a warm-up request runs first. */
    $admin = admin();
    $countQueries = function (int $saleId) use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get("/admin/transactions/{$saleId}")->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $overridingSale = function (array $products) use ($admin): int {
        $cart = cartOf(array_map(fn (Product $product): array => [$product, 1], $products));

        foreach ($products as $product) {
            $cart->overridePrice('p'.$product->id, 500);
        }

        $cart->addManual('Ice', 1, 500);
        $sale = checkout(cashier(['can_override_price' => true]), $cart, cashCents: 100000);
        app(TransactionService::class)->void($sale, $admin, 'Query count');

        return $sale->id;
    };

    $countQueries($overridingSale([$this->product]));

    $small = $countQueries($overridingSale([$this->product]));
    $large = $countQueries($overridingSale(Product::factory()->count(6)->create(['selling_price' => '10.00', 'stock_quantity' => 10])->all()));

    expect($large)->toBe($small);
});
