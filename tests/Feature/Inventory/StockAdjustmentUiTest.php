<?php

use App\Enums\StockMovementType;
use App\Filament\Resources\InventoryMovements\Pages\ListInventoryMovements;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\InventoryService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('records opening stock entered on the create form', function () {
    $admin = admin();

    Livewire::actingAs($admin)
        ->test(CreateProduct::class)
        ->fillForm([
            'name' => 'Lucky Me Pancit Canton',
            'selling_price' => 18,
            'low_stock_threshold' => 10,
            'is_active' => true,
            'initial_stock' => 48,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->where('name', 'Lucky Me Pancit Canton')->sole();
    $movement = InventoryMovement::query()->where('product_id', $product->id)->sole();

    expect($product->stock_quantity)->toBe(48)
        ->and($movement->type)->toBe(StockMovementType::Initial)
        ->and($movement->quantity_before)->toBe(0)
        ->and($movement->quantity_after)->toBe(48)
        ->and($movement->user_id)->toBe($admin->id);
});

it('creates no product when recording the opening stock fails', function () {
    InventoryMovement::creating(function (): void {
        throw new RuntimeException('Movement could not be written.');
    });

    $page = Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm(['name' => 'Half made', 'selling_price' => 5, 'low_stock_threshold' => 0, 'initial_stock' => 12]);

    expect(fn () => $page->call('create'))->toThrow(RuntimeException::class, 'Movement could not be written.');

    expect(Product::where('name', 'Half made')->exists())->toBeFalse()
        ->and(InventoryMovement::count())->toBe(0);
});

it('creates no movement when opening stock is zero', function () {
    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm(['name' => 'Empty shelf', 'selling_price' => 5, 'low_stock_threshold' => 0, 'initial_stock' => 0])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(InventoryMovement::count())->toBe(0)
        ->and(Product::where('name', 'Empty shelf')->sole()->stock_quantity)->toBe(0);
});

it('validates opening stock', function (mixed $value) {
    Livewire::actingAs(admin())
        ->test(CreateProduct::class)
        ->fillForm(['name' => 'Bad stock', 'selling_price' => 5, 'low_stock_threshold' => 0, 'initial_stock' => $value])
        ->call('create')
        ->assertHasFormErrors(['initial_stock']);

    expect(Product::count())->toBe(0);
})->with([-1, 1.5, 'abc', 2147483648, 1000001]);

it('adjusts stock from the product table', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->callAction(TestAction::make('adjustStock')->table($product), data: [
            'type' => StockMovementType::Expired->value,
            'quantity' => 4,
            'reason' => 'Past best-before date',
        ])
        ->assertHasNoFormErrors();

    expect($product->fresh()->stock_quantity)->toBe(6)
        ->and(InventoryMovement::query()->where('product_id', $product->id)->sole()->reason)->toBe('Past best-before date');
});

it('restocks without a reason', function () {
    $product = Product::factory()->create(['stock_quantity' => 1]);

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->callAction(TestAction::make('adjustStock')->table($product), data: [
            'type' => StockMovementType::Restock->value,
            'quantity' => 5,
        ])
        ->assertHasNoFormErrors();

    expect($product->fresh()->stock_quantity)->toBe(6);
});

it('sets the counted quantity for a stock count correction, including zero', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->callAction(TestAction::make('adjustStock')->table($product), data: [
            'type' => StockMovementType::Correction->value,
            'quantity' => 0,
            'reason' => 'Shelf is empty',
        ])
        ->assertHasNoFormErrors();

    $movement = InventoryMovement::query()->sole();

    expect($product->fresh()->stock_quantity)->toBe(0)
        ->and($movement->quantity_change)->toBe(-10);
});

it('adjusts stock from the edit page and refreshes the displayed stock', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    Livewire::actingAs(admin())
        ->test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->callAction('adjustStock', data: [
            'type' => StockMovementType::Restock->value,
            'quantity' => 5,
        ])
        ->assertHasNoFormErrors()
        ->assertSchemaStateSet(['stock_quantity' => 15]);

    expect($product->fresh()->stock_quantity)->toBe(15);
});

it('rejects invalid adjust-stock input and changes nothing', function (array $data, string $field) {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->callAction(TestAction::make('adjustStock')->table($product), data: $data + [
            'type' => StockMovementType::Restock->value,
            'quantity' => 1,
            'reason' => 'ok',
        ])
        ->assertHasFormErrors([$field]);

    expect($product->fresh()->stock_quantity)->toBe(10)
        ->and(InventoryMovement::count())->toBe(0);
})->with([
    'zero quantity' => [['quantity' => 0], 'quantity'],
    'negative quantity' => [['quantity' => -3], 'quantity'],
    'fractional quantity' => [['quantity' => 1.5], 'quantity'],
    'text quantity' => [['quantity' => 'abc'], 'quantity'],
    'huge quantity' => [['quantity' => 2147483647], 'quantity'],
    'missing quantity' => [['quantity' => null], 'quantity'],
    'tampered sale type' => [['type' => 'sale'], 'type'],
    'tampered void_return type' => [['type' => 'void_return'], 'type'],
    'tampered initial type' => [['type' => 'initial'], 'type'],
    'missing type' => [['type' => null], 'type'],
    'reason too long' => [['reason' => str_repeat('a', 256)], 'reason'],
    'array reason' => [['reason' => ['x']], 'reason'],
    'write-off without reason' => [['type' => 'damaged', 'reason' => ''], 'reason'],
]);

it('rejects a counted quantity that is negative', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->callAction(TestAction::make('adjustStock')->table($product), data: [
            'type' => StockMovementType::Correction->value,
            'quantity' => -1,
            'reason' => 'x',
        ])
        ->assertHasFormErrors(['quantity']);

    expect($product->fresh()->stock_quantity)->toBe(10);
});

it('keeps a whitespace-only reason from satisfying a required reason', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->callAction(TestAction::make('adjustStock')->table($product), data: [
            'type' => StockMovementType::Lost->value,
            'quantity' => 1,
            'reason' => '   ',
        ])
        ->assertHasFormErrors(['reason']);

    expect(InventoryMovement::count())->toBe(0);
});

it('does not write off more than the stock on hand and tells the admin', function () {
    $product = Product::factory()->create(['stock_quantity' => 2]);

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->callAction(TestAction::make('adjustStock')->table($product), data: [
            'type' => StockMovementType::Damaged->value,
            'quantity' => 3,
            'reason' => 'Dropped',
        ])
        ->assertNotified('Stock was not changed');

    expect($product->fresh()->stock_quantity)->toBe(2)
        ->and(InventoryMovement::count())->toBe(0);
});

it('hides the adjust-stock action for trashed products', function () {
    $product = Product::factory()->create();
    $product->delete();

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->filterTable('trashed', true)
        ->assertActionHidden(TestAction::make('adjustStock')->table($product));
});

it('keeps cashiers out of product and movement pages and the adjust action', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    Livewire::actingAs(cashier())->test(ListProducts::class)->assertForbidden();
    Livewire::actingAs(cashier())->test(EditProduct::class, ['record' => $product->getRouteKey()])->assertForbidden();
    Livewire::actingAs(cashier())->test(ListInventoryMovements::class)->assertForbidden();

    expect($product->fresh()->stock_quantity)->toBe(10)
        ->and(InventoryMovement::count())->toBe(0);
});

it('shows stock movements to admins only', function () {
    $this->actingAs(admin())->get('/admin/inventory-movements')->assertOk();
    $this->actingAs(cashier())->get('/admin/inventory-movements')->assertForbidden();
});

it('lists movements read-only without per-row queries', function () {
    $admin = admin();
    $inventory = app(InventoryService::class);

    $products = Product::factory()->count(6)->create(['stock_quantity' => 0]);
    foreach ($products as $product) {
        $inventory->adjust($product, 5, StockMovementType::Initial, $admin);
    }

    $countQueries = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($admin)->test(ListInventoryMovements::class);

        return count(DB::getQueryLog());
    };

    $movements = InventoryMovement::query()->latest('id')->get();
    Livewire::actingAs($admin)->test(ListInventoryMovements::class)
        ->assertCanSeeTableRecords($movements)
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');

    expect(ListInventoryMovements::getResource()::canCreate())->toBeFalse();

    $small = $countQueries();
    InventoryMovement::query()->delete();
    foreach ($products as $product) {
        $inventory->adjust($product->fresh(), 1, StockMovementType::Restock, $admin);
        $inventory->adjust($product->fresh(), 1, StockMovementType::Restock, $admin);
    }
    $large = $countQueries();

    expect($large)->toBe($small);
});

it('exposes no create, edit or delete pages for movements', function () {
    $pages = array_keys(ListInventoryMovements::getResource()::getPages());

    expect($pages)->toBe(['index']);
});

it('shows movements of a soft-deleted product in the list', function () {
    $admin = admin();
    $product = Product::factory()->create(['stock_quantity' => 0]);
    $movement = app(InventoryService::class)->adjust($product, 5, StockMovementType::Initial, $admin);
    $product->delete();

    Livewire::actingAs($admin)
        ->test(ListInventoryMovements::class)
        ->assertCanSeeTableRecords([$movement])
        ->assertSee($product->name);
});

it('shows a notification and leaves stock unchanged when a restock would overflow', function () {
    $product = Product::factory()->create(['stock_quantity' => 2147483000]);

    Livewire::actingAs(admin())
        ->test(ListProducts::class)
        ->callAction(TestAction::make('adjustStock')->table($product), data: [
            'type' => StockMovementType::Restock->value,
            'quantity' => 1000000,
        ])
        ->assertNotified('Stock was not changed');

    expect($product->fresh()->stock_quantity)->toBe(2147483000)
        ->and(InventoryMovement::count())->toBe(0);
});
