<?php

use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoCatalogSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('seeds the demo catalog with opening stock', function () {
    $this->seed(DemoCatalogSeeder::class);

    expect(Product::count())->toBe(14)
        ->and(Product::lowStock()->count())->toBe(2)
        ->and(Category::count())->toBe(4)
        ->and(InventoryMovement::count())->toBe(14)
        ->and(InventoryMovement::where('type', StockMovementType::Initial)->count())->toBe(14);

    Product::all()->each(function (Product $product) {
        expect($product->inventoryMovements()->count())->toBe(1)
            ->and((int) $product->inventoryMovements()->sum('quantity_change'))->toBe($product->stock_quantity);
    });
});

it('creates products without a barcode or category', function () {
    $this->seed(DemoCatalogSeeder::class);

    $products = Product::whereNull('barcode')->get();

    expect($products)->toHaveCount(2)
        ->and($products->every(fn (Product $product) => $product->categories()->count() === 0))->toBeTrue();
});

it('is idempotent when run twice', function () {
    $this->seed(DemoCatalogSeeder::class);
    $stock = Product::orderBy('id')->pluck('stock_quantity', 'id')->all();

    $this->seed(DemoCatalogSeeder::class);

    expect(Product::count())->toBe(14)
        ->and(Category::count())->toBe(4)
        ->and(InventoryMovement::count())->toBe(14)
        ->and(Product::orderBy('id')->pluck('stock_quantity', 'id')->all())->toBe($stock);
});

it('neither fails nor duplicates when a demo product was soft-deleted', function (string $column, string $value) {
    $this->seed(DemoCatalogSeeder::class);
    Product::where($column, $value)->firstOrFail()->delete();

    $this->seed(DemoCatalogSeeder::class);

    expect(Product::withTrashed()->count())->toBe(14)
        ->and(Product::count())->toBe(13)
        ->and(InventoryMovement::count())->toBe(14);
})->with([
    'barcode product' => ['barcode', '4801981118502'],
    'barcode-less product' => ['name', 'Ice candy'],
]);

it('does not re-attach a soft-deleted demo product to its category', function () {
    $this->seed(DemoCatalogSeeder::class);
    $product = Product::where('barcode', '4801981118502')->firstOrFail();
    $product->categories()->detach();
    $product->delete();

    $this->seed(DemoCatalogSeeder::class);

    expect($product->categories()->count())->toBe(0);
});

it('attributes the opening stock to an active admin even when an inactive admin was seeded first', function () {
    User::query()->where('role', UserRole::Admin)->update(['is_active' => false]);
    $activeAdmin = admin();

    $this->seed(DemoCatalogSeeder::class);

    expect(InventoryMovement::where('user_id', $activeAdmin->id)->count())->toBe(14);
});
