<?php

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->inventory = app(InventoryService::class);
    $this->admin = admin();
});

it('restocks and records a complete movement', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $movement = $this->inventory->applyManual($product, StockMovementType::Restock, 24, $this->admin, 'Delivery from Puregold');

    expect($product->fresh()->stock_quantity)->toBe(34)
        ->and($movement->product_id)->toBe($product->id)
        ->and($movement->user_id)->toBe($this->admin->id)
        ->and($movement->type)->toBe(StockMovementType::Restock)
        ->and($movement->quantity_before)->toBe(10)
        ->and($movement->quantity_change)->toBe(24)
        ->and($movement->quantity_after)->toBe(34)
        ->and($movement->reason)->toBe('Delivery from Puregold')
        ->and($movement->created_at)->not->toBeNull();
});

it('deducts damaged, expired and lost stock', function (StockMovementType $type) {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $movement = $this->inventory->applyManual($product, $type, 3, $this->admin, 'Write-off');

    expect($product->fresh()->stock_quantity)->toBe(7)
        ->and($movement->quantity_change)->toBe(-3);
})->with([StockMovementType::Damaged, StockMovementType::Expired, StockMovementType::Lost]);

it('refuses to write off more than is in stock and records nothing', function () {
    $product = Product::factory()->create(['stock_quantity' => 2]);

    expect(fn () => $this->inventory->applyManual($product, StockMovementType::Damaged, 3, $this->admin, 'Dropped'))
        ->toThrow(InsufficientStockException::class);

    expect($product->fresh()->stock_quantity)->toBe(2)
        ->and(InventoryMovement::count())->toBe(0);
});

it('sets stock to a counted quantity and records the difference', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $down = $this->inventory->applyManual($product, StockMovementType::Correction, 7, $this->admin, 'Monthly count');
    $up = $this->inventory->applyManual($product, StockMovementType::Correction, 9, $this->admin, 'Found two more');
    $same = $this->inventory->applyManual($product, StockMovementType::Correction, 9, $this->admin, 'Recount');

    expect($down->quantity_change)->toBe(-3)
        ->and($up->quantity_change)->toBe(2)
        ->and($same->quantity_change)->toBe(0)
        ->and($same->quantity_before)->toBe(9)
        ->and($same->quantity_after)->toBe(9)
        ->and($product->fresh()->stock_quantity)->toBe(9);
});

it('corrects negative stock back to a real count', function () {
    $product = Product::factory()->create(['stock_quantity' => -4]);

    $movement = $this->inventory->applyManual($product, StockMovementType::Correction, 6, $this->admin, 'Count after overselling');

    expect($movement->quantity_change)->toBe(10)
        ->and($product->fresh()->stock_quantity)->toBe(6);
});

it('rejects system-only types and zero quantities from manual adjustments', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    expect(fn () => $this->inventory->applyManual($product, StockMovementType::Sale, 1, $this->admin))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->inventory->applyManual($product, StockMovementType::Restock, 0, $this->admin))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->inventory->applyManual($product, StockMovementType::Restock, -5, $this->admin))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects initial and void-return types from manual adjustments', function (StockMovementType $type) {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    expect(fn () => $this->inventory->applyManual($product, $type, 1, $this->admin))
        ->toThrow(InvalidArgumentException::class);

    expect($product->fresh()->stock_quantity)->toBe(10)
        ->and(InventoryMovement::count())->toBe(0);
})->with([StockMovementType::Initial, StockMovementType::VoidReturn]);

it('rejects a zero change for non-correction types', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    expect(fn () => $this->inventory->adjust($product, 0, StockMovementType::Sale, $this->admin))
        ->toThrow(InvalidArgumentException::class);
});

it('lets stock go negative only when the caller allows it', function () {
    $product = Product::factory()->create(['stock_quantity' => 1]);

    expect(fn () => $this->inventory->adjust($product, -2, StockMovementType::Sale, $this->admin))
        ->toThrow(InsufficientStockException::class);

    $movement = $this->inventory->adjust($product, -2, StockMovementType::Sale, $this->admin, allowNegative: true);

    expect($movement->quantity_after)->toBe(-1)
        ->and($product->fresh()->stock_quantity)->toBe(-1);
});

it('reports the available and requested quantities when stock is short', function () {
    $product = Product::factory()->create(['name' => 'Skyflakes', 'stock_quantity' => 2]);

    try {
        $this->inventory->adjust($product, -5, StockMovementType::Sale, $this->admin);
    } catch (InsufficientStockException $exception) {
        expect($exception->productName)->toBe('Skyflakes')
            ->and($exception->available)->toBe(2)
            ->and($exception->requested)->toBe(5);

        return;
    }

    $this->fail('Expected InsufficientStockException.');
});

it('keeps the passed-in product instance in sync', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $this->inventory->adjust($product, -4, StockMovementType::Sale, $this->admin);

    expect($product->stock_quantity)->toBe(6)
        ->and($product->isDirty())->toBeFalse();
});

it('works from a stale product instance by locking and re-reading the row', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);
    $stale = Product::find($product->id);

    $this->inventory->adjust($product, -4, StockMovementType::Sale, $this->admin);
    $movement = $this->inventory->adjust($stale, -1, StockMovementType::Sale, $this->admin);

    expect($movement->quantity_before)->toBe(6)
        ->and($movement->quantity_after)->toBe(5)
        ->and($product->fresh()->stock_quantity)->toBe(5);
});

it('adjusts soft-deleted products', function () {
    $product = Product::factory()->create(['stock_quantity' => 3]);
    $product->delete();

    $movement = $this->inventory->applyManual($product, StockMovementType::Restock, 2, $this->admin);

    expect($movement->quantity_after)->toBe(5);
});

it('keeps stock equal to the sum of its movements', function () {
    $product = Product::factory()->create(['stock_quantity' => 0]);

    $this->inventory->adjust($product, 20, StockMovementType::Initial, $this->admin);
    $this->inventory->applyManual($product, StockMovementType::Restock, 12, $this->admin);
    $this->inventory->adjust($product, -5, StockMovementType::Sale, $this->admin);
    $this->inventory->applyManual($product, StockMovementType::Expired, 2, $this->admin, 'Past date');
    $this->inventory->applyManual($product, StockMovementType::Correction, 24, $this->admin, 'Count');

    expect($product->fresh()->stock_quantity)->toBe(24)
        ->and((int) InventoryMovement::where('product_id', $product->id)->sum('quantity_change'))->toBe(24);

    InventoryMovement::where('product_id', $product->id)->get()->each(function (InventoryMovement $movement) {
        expect($movement->quantity_before + $movement->quantity_change)->toBe($movement->quantity_after);
    });
});

it('rejects changes that would overflow the 32-bit stock column and writes nothing', function (int $stock, int $change) {
    $product = Product::factory()->create(['stock_quantity' => $stock]);

    expect(fn () => $this->inventory->adjust($product, $change, StockMovementType::Restock, $this->admin, allowNegative: true))
        ->toThrow(InvalidArgumentException::class);

    expect($product->fresh()->stock_quantity)->toBe($stock)
        ->and(InventoryMovement::count())->toBe(0);
})->with([
    'above max' => [2147483000, 1000000],
    'change itself above max' => [0, 2147483648],
    'below min' => [-2147483000, -1000000],
]);

it('locks the product row for update', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    DB::enableQueryLog();
    $this->inventory->adjust($product, 1, StockMovementType::Restock, $this->admin);

    $locked = collect(DB::getQueryLog())->contains(
        fn (array $query): bool => str_contains($query['query'], 'from `products`') && str_ends_with(trim($query['query']), 'for update')
    );

    expect($locked)->toBeTrue();
});

it('rolls back the stock change when the movement cannot be written', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    InventoryMovement::creating(function () {
        throw new RuntimeException('ledger write failed');
    });

    expect(fn () => $this->inventory->adjust($product, 5, StockMovementType::Restock, $this->admin))
        ->toThrow(RuntimeException::class);

    expect($product->fresh()->stock_quantity)->toBe(10)
        ->and(InventoryMovement::count())->toBe(0);
});
