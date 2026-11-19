<?php

use App\Enums\StockMovementType;
use App\Enums\TransactionStatus;
use App\Exceptions\TransactionAlreadyVoidedException;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Pos\Cart;
use App\Services\InventoryService;
use App\Services\TransactionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->service = app(TransactionService::class);
});

it('voids a sale and puts the stock back with its own movements', function () {
    $admin = admin();
    $coke = Product::factory()->create(['selling_price' => '25.00', 'stock_quantity' => 10]);
    $chips = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 5]);
    $sale = checkout(cashier(), cartOf([[$coke, 3], [$chips, 2]]), cashCents: 10000);

    $voided = $this->service->void($sale, $admin, 'Customer changed their mind');

    expect($voided->status)->toBe(TransactionStatus::Voided)
        ->and($voided->voided_by)->toBe($admin->id)
        ->and($voided->voided_at)->not->toBeNull()
        ->and($voided->void_reason)->toBe('Customer changed their mind')
        ->and($voided->total)->toBe('95.00')
        ->and($voided->items)->toHaveCount(2)
        ->and($coke->fresh()->stock_quantity)->toBe(10)
        ->and($chips->fresh()->stock_quantity)->toBe(5);

    $returns = InventoryMovement::query()->where('type', StockMovementType::VoidReturn)->orderBy('product_id')->get();

    expect($returns)->toHaveCount(2)
        ->and($returns[0]->product_id)->toBe($coke->id)
        ->and($returns[0]->quantity_before)->toBe(7)
        ->and($returns[0]->quantity_change)->toBe(3)
        ->and($returns[0]->quantity_after)->toBe(10)
        ->and($returns[0]->transaction_id)->toBe($sale->id)
        ->and($returns[0]->user_id)->toBe($admin->id)
        ->and($returns[0]->reason)->toContain($sale->reference_number);

    expect(InventoryMovement::query()->where('type', StockMovementType::Sale)->count())->toBe(2);
});

it('does not create stock movements for manual items when voiding', function () {
    $cart = new Cart;
    $cart->addManual('Ice', 2, 500);
    $sale = checkout(cashier(), $cart, cashCents: 1000);

    $this->service->void($sale, admin(), 'Test');

    expect(InventoryMovement::count())->toBe(0)
        ->and($sale->fresh()->status)->toBe(TransactionStatus::Voided);
});

it('refuses to void twice and restores stock only once', function () {
    $admin = admin();
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $sale = checkout(cashier(), cartOf([[$product, 4]]), cashCents: 4000);

    $this->service->void($sale, $admin, 'First');

    expect(fn () => $this->service->void($sale, $admin, 'Second'))
        ->toThrow(TransactionAlreadyVoidedException::class);

    expect($product->fresh()->stock_quantity)->toBe(10)
        ->and($sale->fresh()->void_reason)->toBe('First');
});

it('does not let a cashier void', function () {
    $cashier = cashier();
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $sale = checkout($cashier, cartOf([[$product, 1]]), cashCents: 1000);

    expect(fn () => $this->service->void($sale, $cashier, 'Oops'))->toThrow(AuthorizationException::class);

    expect($sale->fresh()->status)->toBe(TransactionStatus::Completed)
        ->and($product->fresh()->stock_quantity)->toBe(9);
});

it('requires a reason', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $sale = checkout(cashier(), cartOf([[$product, 1]]), cashCents: 1000);

    expect(fn () => $this->service->void($sale, admin(), '   '))->toThrow(InvalidArgumentException::class);
});

it('restores stock even when the product was deleted after the sale', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $sale = checkout(cashier(), cartOf([[$product, 2]]), cashCents: 2000);
    $product->delete();

    $this->service->void($sale, admin(), 'Deleted product');

    expect(Product::withTrashed()->find($product->id)->stock_quantity)->toBe(10);
});

it('keeps stock equal to the movement ledger after a sale and a void', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 0]);
    app(InventoryService::class)->adjust($product, 10, StockMovementType::Initial, admin());
    $sale = checkout(cashier(), cartOf([[$product, 3]]), cashCents: 3000);
    checkout(cashier(), cartOf([[$product, 2]]), cashCents: 2000);

    $this->service->void($sale, admin(), 'Wrong item');

    expect($product->fresh()->stock_quantity)->toBe(8)
        ->and((int) InventoryMovement::query()->where('product_id', $product->id)->sum('quantity_change'))->toBe(8);
});

it('writes exactly one void return per catalog line and none for manual lines', function () {
    $coke = Product::factory()->create(['selling_price' => '25.00', 'stock_quantity' => 10]);
    $chips = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $cart = cartOf([[$coke, 2], [$chips, 1]]);
    $cart->addManual('Ice', 3, 500);
    $sale = checkout(cashier(), $cart, cashCents: 10000);

    $this->service->void($sale, admin(), 'Mixed sale');

    $returns = InventoryMovement::query()
        ->where('transaction_id', $sale->id)
        ->where('type', StockMovementType::VoidReturn)
        ->orderBy('product_id')
        ->get();

    expect($returns->pluck('product_id')->all())->toBe([$coke->id, $chips->id])
        ->and($returns->pluck('quantity_change')->all())->toBe([2, 1])
        ->and(InventoryMovement::query()->whereNull('product_id')->count())->toBe(0);
});

it('leaves the sale items and the original sale movements untouched', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $cart = cartOf([[$product, 2]]);
    $cart->addManual('Ice', 1, 500);
    $sale = checkout(cashier(), $cart, cashCents: 5000);

    $itemsBefore = TransactionItem::query()->where('transaction_id', $sale->id)->orderBy('id')->get()->toArray();
    $movementsBefore = InventoryMovement::query()->where('type', StockMovementType::Sale)->orderBy('id')->get()->toArray();
    $saleBefore = $sale->fresh()->only(['reference_number', 'user_id', 'subtotal', 'discount_amount', 'total', 'cash_received', 'change_amount', 'item_count', 'created_at']);

    $this->service->void($sale, admin(), 'Audit check');

    expect(TransactionItem::query()->where('transaction_id', $sale->id)->orderBy('id')->get()->toArray())->toBe($itemsBefore)
        ->and(InventoryMovement::query()->where('type', StockMovementType::Sale)->orderBy('id')->get()->toArray())->toBe($movementsBefore)
        ->and($sale->fresh()->only(array_keys($saleBefore)))->toEqual($saleBefore);
});

it('refuses a reason made only of non-breaking spaces', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $sale = checkout(cashier(), cartOf([[$product, 1]]), cashCents: 1000);

    expect(fn () => $this->service->void($sale, admin(), "\u{00A0} \u{00A0}"))->toThrow(InvalidArgumentException::class);

    expect($sale->fresh()->status)->toBe(TransactionStatus::Completed);
});

it('refuses an inactive admin', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $sale = checkout(cashier(), cartOf([[$product, 1]]), cashCents: 1000);

    expect(fn () => $this->service->void($sale, admin(['is_active' => false]), 'Former owner'))
        ->toThrow(AuthorizationException::class);

    expect($sale->fresh()->status)->toBe(TransactionStatus::Completed)
        ->and($product->fresh()->stock_quantity)->toBe(9);
});

it('stores the reason trimmed', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $sale = checkout(cashier(), cartOf([[$product, 1]]), cashCents: 1000);

    $this->service->void($sale, admin(), "  Wrong item scanned \n");

    expect($sale->fresh()->void_reason)->toBe('Wrong item scanned');
});

it('accepts a reason of exactly 255 characters, counting multibyte characters once', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $sale = checkout(cashier(), cartOf([[$product, 1]]), cashCents: 1000);
    $reason = str_repeat('ñ', 255);

    $this->service->void($sale, admin(), "  {$reason}  ");

    expect($sale->fresh()->void_reason)->toBe($reason);
});

it('rejects a reason longer than 255 characters and changes nothing', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $sale = checkout(cashier(), cartOf([[$product, 1]]), cashCents: 1000);

    expect(fn () => $this->service->void($sale, admin(), str_repeat('a', 256)))
        ->toThrow(InvalidArgumentException::class);

    expect($sale->fresh()->status)->toBe(TransactionStatus::Completed)
        ->and($product->fresh()->stock_quantity)->toBe(9)
        ->and(InventoryMovement::query()->where('type', StockMovementType::VoidReturn)->count())->toBe(0);
});

it('refuses a second admin whose copy of the sale was loaded before the first void', function () {
    $first = admin();
    $second = admin();
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $sale = checkout(cashier(), cartOf([[$product, 4]]), cashCents: 4000);
    $firstCopy = Transaction::query()->findOrFail($sale->id);
    $secondCopy = Transaction::query()->findOrFail($sale->id);

    $this->service->void($firstCopy, $first, 'First admin');

    expect($secondCopy->isVoided())->toBeFalse()
        ->and(fn () => $this->service->void($secondCopy, $second, 'Second admin'))
        ->toThrow(TransactionAlreadyVoidedException::class);

    $fresh = $sale->fresh();

    expect($product->fresh()->stock_quantity)->toBe(10)
        ->and(InventoryMovement::query()->where('type', StockMovementType::VoidReturn)->count())->toBe(1)
        ->and($fresh->voided_by)->toBe($first->id)
        ->and($fresh->void_reason)->toBe('First admin');
});

it('locks the sale row first, then the products in ascending id order', function () {
    $lower = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $higher = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $sale = checkout(cashier(), cartOf([[$higher, 1], [$lower, 1]]), cashCents: 2000);
    $admin = admin();
    $locks = [];

    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains($query->sql, 'for update')) {
            $table = str_contains($query->sql, 'from `transactions`') ? 'transactions' : 'products';
            $locks[] = [$table, $query->bindings[0]];
        }
    });

    $this->service->void($sale, $admin, 'Lock order');

    expect($locks[0])->toBe(['transactions', $sale->id])
        ->and(array_slice($locks, 1))->toBe([['products', $lower->id], ['products', $higher->id]]);
});

it('rolls back the whole void when restoring one line fails', function () {
    $admin = admin();
    $first = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $overflowing = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $sale = checkout(cashier(), cartOf([[$first, 2], [$overflowing, 5]]), cashCents: 10000);
    app(InventoryService::class)->adjust($overflowing, 2147483640, StockMovementType::Restock, $admin);

    expect(fn () => $this->service->void($sale, $admin, 'Overflow'))->toThrow(InvalidArgumentException::class);

    $fresh = $sale->fresh();

    expect($fresh->status)->toBe(TransactionStatus::Completed)
        ->and($fresh->voided_at)->toBeNull()
        ->and($fresh->voided_by)->toBeNull()
        ->and($fresh->void_reason)->toBeNull()
        ->and($first->fresh()->stock_quantity)->toBe(8)
        ->and($overflowing->fresh()->stock_quantity)->toBe(2147483645)
        ->and(InventoryMovement::query()->where('type', StockMovementType::VoidReturn)->count())->toBe(0);
});

it('refuses a cashier voiding another cashier\'s sale', function () {
    $owner = cashier();
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $sale = checkout($owner, cartOf([[$product, 1]]), cashCents: 1000);

    expect(fn () => $this->service->void($sale, cashier(), 'Not mine'))->toThrow(AuthorizationException::class);

    expect($sale->fresh()->status)->toBe(TransactionStatus::Completed)
        ->and(InventoryMovement::query()->where('type', StockMovementType::VoidReturn)->count())->toBe(0);
});
