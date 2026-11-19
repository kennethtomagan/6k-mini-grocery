<?php

use App\Enums\StockMovementType;
use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

it('allows a product with no categories and with several categories', function () {
    $plain = Product::factory()->create();
    $tagged = Product::factory()->create();
    $tagged->categories()->attach(Category::factory()->count(2)->create());

    expect($plain->categories)->toHaveCount(0)
        ->and($tagged->categories)->toHaveCount(2);
});

it('stores blank barcodes and skus as null so many products can have none', function () {
    $a = Product::factory()->create(['barcode' => '', 'sku' => '   ']);
    $b = Product::factory()->create(['barcode' => null, 'sku' => null]);

    expect($a->fresh()->barcode)->toBeNull()
        ->and($a->fresh()->sku)->toBeNull()
        ->and($b->fresh()->barcode)->toBeNull();
});

it('rejects a duplicate barcode at the database level', function () {
    Product::factory()->create(['barcode' => '4800016644801']);

    Product::factory()->create(['barcode' => '4800016644801']);
})->throws(UniqueConstraintViolationException::class);

it('trims barcodes', function () {
    $product = Product::factory()->create(['barcode' => " 4800016644801\n"]);

    expect($product->barcode)->toBe('4800016644801');
});

it('does not mass-assign stock quantity', function () {
    $product = Product::factory()->create(['stock_quantity' => 10]);

    $product->fill(['stock_quantity' => 999]);

    expect($product->stock_quantity)->toBe(10);
});

it('finds low-stock products', function () {
    $low = Product::factory()->create(['stock_quantity' => 5, 'low_stock_threshold' => 5]);
    $fine = Product::factory()->create(['stock_quantity' => 6, 'low_stock_threshold' => 5]);

    expect(Product::lowStock()->pluck('id')->all())->toBe([$low->id])
        ->and($low->isLowStock())->toBeTrue()
        ->and($fine->isLowStock())->toBeFalse();
});

it('searches by name, sku and barcode', function () {
    $coke = Product::factory()->create(['name' => 'Coke Mismo', 'sku' => 'BEV-001', 'barcode' => '4801981118502']);
    Product::factory()->create(['name' => 'Skyflakes', 'sku' => 'BIS-001', 'barcode' => '4800016644801']);

    expect(Product::search('mismo')->pluck('id')->all())->toBe([$coke->id])
        ->and(Product::search('BEV')->pluck('id')->all())->toBe([$coke->id])
        ->and(Product::search('48019811')->pluck('id')->all())->toBe([$coke->id])
        ->and(Product::search('')->count())->toBe(2);
});

it('removes pivot rows when a category is deleted but keeps the product', function () {
    $product = Product::factory()->create();
    $category = Category::factory()->create();
    $product->categories()->attach($category);

    expect($product->categories()->count())->toBe(1);

    $category->delete();

    expect($product->fresh())->not->toBeNull()
        ->and($product->categories()->count())->toBe(0);
});

it('always has one store settings row', function () {
    expect(StoreSetting::current()->store_name)->toBe('My Sari-Sari Store')
        ->and(StoreSetting::current()->allow_negative_stock)->toBeFalse()
        ->and(StoreSetting::count())->toBe(1);
});

it('keeps transaction items and movements intact when a product is soft-deleted', function () {
    $cashier = User::factory()->cashier()->create();
    $product = Product::factory()->create(['name' => 'Coke Mismo']);

    $transaction = Transaction::query()->create([
        'checkout_token' => (string) Str::uuid(),
        'user_id' => $cashier->id,
        'status' => TransactionStatus::Completed,
        'subtotal' => '20.00',
        'total' => '20.00',
        'cash_received' => '50.00',
        'change_amount' => '30.00',
        'item_count' => 1,
    ]);
    $item = TransactionItem::query()->create([
        'transaction_id' => $transaction->id,
        'product_id' => $product->id,
        'product_name' => 'Coke Mismo',
        'quantity' => 1,
        'original_price' => '20.00',
        'unit_price' => '20.00',
        'line_total' => '20.00',
    ]);
    $movement = InventoryMovement::query()->create([
        'product_id' => $product->id,
        'user_id' => $cashier->id,
        'transaction_id' => $transaction->id,
        'type' => StockMovementType::Sale,
        'quantity_before' => 50,
        'quantity_change' => -1,
        'quantity_after' => 49,
    ]);

    $product->delete();

    expect(Product::find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull()
        ->and($item->fresh())->not->toBeNull()
        ->and($movement->fresh())->not->toBeNull()
        ->and($item->fresh()->product->is($product))->toBeTrue()
        ->and($movement->fresh()->product->is($product))->toBeTrue()
        ->and($item->fresh()->product_name)->toBe('Coke Mismo')
        ->and($transaction->items)->toHaveCount(1)
        ->and($transaction->inventoryMovements)->toHaveCount(1)
        ->and($transaction->fresh()->status)->toBe(TransactionStatus::Completed)
        ->and($movement->fresh()->type)->toBe(StockMovementType::Sale)
        ->and($movement->fresh()->updated_at)->toBeNull();
});

it('keeps the active filter when combined with search', function () {
    $active = Product::factory()->create(['sku' => 'BEV-001']);
    Product::factory()->inactive()->create(['sku' => 'BEV-002']);

    expect(Product::active()->search('BEV')->pluck('id')->all())->toBe([$active->id]);
});

it('treats like wildcards in search terms literally', function () {
    $promo = Product::factory()->create(['name' => 'Promo 50% off', 'barcode' => '1111111111111']);
    Product::factory()->create(['name' => 'Skyflakes', 'barcode' => '2222222222222']);

    expect(Product::search('%')->pluck('id')->all())->toBe([$promo->id])
        ->and(Product::search('_')->count())->toBe(0);
});

it('keeps a barcode of zero', function () {
    $product = Product::factory()->create(['barcode' => '0']);

    expect($product->fresh()->barcode)->toBe('0');
});

it('converts the selling price to cents', function () {
    $product = Product::factory()->create(['selling_price' => '12.50']);

    expect($product->fresh()->sellingPriceCents())->toBe(1250);
});

it('builds the image url only when an image exists', function () {
    $without = Product::factory()->create();
    $with = Product::factory()->create(['image_path' => 'products/a.jpg']);

    expect($without->imageUrl())->toBeNull()
        ->and($with->imageUrl())->toBe(Storage::disk('public')->url('products/a.jpg'));
});
