<?php

use App\Enums\StockMovementType;
use App\Enums\TransactionStatus;
use App\Exceptions\CheckoutException;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Pos\Cart;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('completes a sale: transaction, items, stock and change', function () {
    $cashier = cashier();
    $coke = Product::factory()->create(['name' => 'Coke', 'selling_price' => '25.00', 'cost_price' => '20.00', 'stock_quantity' => 10]);
    $chips = Product::factory()->create(['name' => 'Chips', 'selling_price' => '12.50', 'stock_quantity' => 5]);

    $transaction = checkout($cashier, cartOf([[$coke, 2], [$chips, 1]]), cashCents: 10000);

    expect($transaction->status)->toBe(TransactionStatus::Completed)
        ->and($transaction->user_id)->toBe($cashier->id)
        ->and($transaction->reference_number)->toMatch('/^\d{8}-\d{6}$/')
        ->and($transaction->subtotal)->toBe('62.50')
        ->and($transaction->discount_amount)->toBe('0.00')
        ->and($transaction->total)->toBe('62.50')
        ->and($transaction->cash_received)->toBe('100.00')
        ->and($transaction->change_amount)->toBe('37.50')
        ->and($transaction->item_count)->toBe(3)
        ->and($transaction->items)->toHaveCount(2)
        ->and($coke->fresh()->stock_quantity)->toBe(8)
        ->and($chips->fresh()->stock_quantity)->toBe(4);

    $item = $transaction->items->firstWhere('product_id', $coke->id);

    expect($item->product_name)->toBe('Coke')
        ->and($item->product_barcode)->toBe($coke->barcode)
        ->and($item->quantity)->toBe(2)
        ->and($item->original_price)->toBe('25.00')
        ->and($item->unit_price)->toBe('25.00')
        ->and($item->unit_cost)->toBe('20.00')
        ->and($item->line_total)->toBe('50.00')
        ->and($item->is_manual)->toBeFalse()
        ->and($item->price_overridden)->toBeFalse();

    $movement = InventoryMovement::query()->where('product_id', $coke->id)->sole();

    expect($movement->type)->toBe(StockMovementType::Sale)
        ->and($movement->quantity_before)->toBe(10)
        ->and($movement->quantity_change)->toBe(-2)
        ->and($movement->quantity_after)->toBe(8)
        ->and($movement->transaction_id)->toBe($transaction->id)
        ->and($movement->user_id)->toBe($cashier->id);
});

it('sells manual items without touching inventory', function () {
    $cart = new Cart;
    $cart->addManual('Ice (plastic)', 3, 500);

    $transaction = checkout(cashier(), $cart, cashCents: 2000);
    $item = $transaction->items->sole();

    expect($item->is_manual)->toBeTrue()
        ->and($item->product_id)->toBeNull()
        ->and($item->product_name)->toBe('Ice (plastic)')
        ->and($item->unit_price)->toBe('5.00')
        ->and($item->line_total)->toBe('15.00')
        ->and($transaction->change_amount)->toBe('5.00')
        ->and(InventoryMovement::count())->toBe(0)
        ->and(Product::count())->toBe(0);
});

it('records a price override and leaves the catalog price alone', function () {
    $cashier = cashier(['can_override_price' => true]);
    $product = Product::factory()->create(['selling_price' => '25.00', 'stock_quantity' => 5]);
    $cart = cartOf([[$product, 2]]);
    $cart->overridePrice('p'.$product->id, 2000);

    $item = checkout($cashier, $cart, cashCents: 4000)->items->sole();

    expect($item->original_price)->toBe('25.00')
        ->and($item->unit_price)->toBe('20.00')
        ->and($item->line_total)->toBe('40.00')
        ->and($item->price_overridden)->toBeTrue()
        ->and($item->price_override_user_id)->toBe($cashier->id)
        ->and($product->fresh()->selling_price)->toBe('25.00');
});

it('refuses a price override from a cashier without permission', function () {
    $product = Product::factory()->create(['selling_price' => '25.00', 'stock_quantity' => 5]);
    $cart = cartOf([[$product, 1]]);
    $cart->overridePrice('p'.$product->id, 100);

    expect(fn () => checkout(cashier(), $cart, cashCents: 10000))
        ->toThrow(CheckoutException::class, 'not allowed to change prices');

    expect(Transaction::count())->toBe(0)->and($product->fresh()->stock_quantity)->toBe(5);
});

it('refuses a cart whose line was tampered to a different price without the override flag', function () {
    $product = Product::factory()->create(['selling_price' => '25.00', 'stock_quantity' => 5]);
    $lines = cartOf([[$product, 1]])->toArray();
    $lines['p'.$product->id]['unit_price_cents'] = 100;

    try {
        checkout(cashier(), Cart::fromArray($lines), cashCents: 10000);
        $this->fail('Expected a CheckoutException.');
    } catch (CheckoutException $exception) {
        expect($exception->reason)->toBe('price_changed')
            ->and($exception->context['prices'])->toBe(['p'.$product->id => 2500]);
    }

    expect(Transaction::count())->toBe(0);
});

it('applies a discount only with permission and never above the subtotal', function () {
    $product = Product::factory()->create(['selling_price' => '100.00', 'stock_quantity' => 5]);

    expect(fn () => checkout(cashier(), cartOf([[$product, 1]]), cashCents: 10000, discountCents: 1000))
        ->toThrow(CheckoutException::class, 'not allowed to apply discounts');

    $allowed = cashier(['can_apply_discount' => true]);

    expect(fn () => checkout($allowed, cartOf([[$product, 1]]), cashCents: 10000, discountCents: 10001))
        ->toThrow(CheckoutException::class, 'Discount cannot be more than the subtotal');

    $transaction = checkout($allowed, cartOf([[$product, 1]]), cashCents: 10000, discountCents: 1000);

    expect($transaction->subtotal)->toBe('100.00')
        ->and($transaction->discount_amount)->toBe('10.00')
        ->and($transaction->total)->toBe('90.00')
        ->and($transaction->change_amount)->toBe('10.00');
});

it('refuses a sale with insufficient stock and changes nothing', function () {
    $short = Product::factory()->create(['name' => 'Short', 'selling_price' => '10.00', 'stock_quantity' => 1]);
    $fine = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 9]);

    try {
        checkout(cashier(), cartOf([[$fine, 2], [$short, 3]]), cashCents: 10000);
        $this->fail('Expected a CheckoutException.');
    } catch (CheckoutException $exception) {
        expect($exception->reason)->toBe('insufficient_stock')
            ->and($exception->context['shortages'])->toBe([['name' => 'Short', 'requested' => 3, 'available' => 1]]);
    }

    expect(Transaction::count())->toBe(0)
        ->and(TransactionItem::count())->toBe(0)
        ->and(InventoryMovement::count())->toBe(0)
        ->and($short->fresh()->stock_quantity)->toBe(1)
        ->and($fine->fresh()->stock_quantity)->toBe(9);
});

it('allows overselling when the store setting permits negative stock', function () {
    StoreSetting::current()->update(['allow_negative_stock' => true]);
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 1]);

    checkout(cashier(), cartOf([[$product, 3]]), cashCents: 3000);

    expect($product->fresh()->stock_quantity)->toBe(-2);
});

it('returns the same transaction for a repeated checkout token', function () {
    $cashier = cashier();
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $token = (string) Str::uuid();

    $first = checkout($cashier, cartOf([[$product, 2]]), cashCents: 2000, token: $token);
    $second = checkout($cashier, cartOf([[$product, 2]]), cashCents: 2000, token: $token);

    expect($second->id)->toBe($first->id)
        ->and(Transaction::count())->toBe(1)
        ->and($product->fresh()->stock_quantity)->toBe(8);
});

it('refuses payment below the total', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);

    expect(fn () => checkout(cashier(), cartOf([[$product, 2]]), cashCents: 1999))
        ->toThrow(CheckoutException::class, 'Cash received is less than the total');

    expect(Transaction::count())->toBe(0)->and($product->fresh()->stock_quantity)->toBe(10);
});

it('accepts exact cash with zero change', function () {
    $product = Product::factory()->create(['selling_price' => '19.99', 'stock_quantity' => 10]);

    $transaction = checkout(cashier(), cartOf([[$product, 3]]), cashCents: 5997);

    expect($transaction->total)->toBe('59.97')->and($transaction->change_amount)->toBe('0.00');
});

it('refuses an empty cart', function () {
    expect(fn () => checkout(cashier(), new Cart, cashCents: 1000))->toThrow(CheckoutException::class, 'cart is empty');
});

it('refuses the sale and reports new prices when a catalog price changed', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);
    $cart = cartOf([[$product, 1]]);
    $product->update(['selling_price' => '12.00']);

    try {
        checkout(cashier(), $cart, cashCents: 5000);
        $this->fail('Expected a CheckoutException.');
    } catch (CheckoutException $exception) {
        expect($exception->reason)->toBe('price_changed')
            ->and($exception->context['prices'])->toBe(['p'.$product->id => 1200]);
    }
});

it('refuses products that were deactivated or deleted after being added', function () {
    $inactive = Product::factory()->create(['name' => 'Gone A', 'selling_price' => '10.00']);
    $deleted = Product::factory()->create(['name' => 'Gone B', 'selling_price' => '10.00']);
    $cart = cartOf([[$inactive, 1], [$deleted, 1]]);
    $inactive->update(['is_active' => false]);
    $deleted->delete();

    try {
        checkout(cashier(), $cart, cashCents: 5000);
        $this->fail('Expected a CheckoutException.');
    } catch (CheckoutException $exception) {
        expect($exception->reason)->toBe('product_unavailable')
            ->and($exception->context['names'])->toBe(['Gone A', 'Gone B']);
    }
});

it('keeps item history accurate after the product is edited or deleted', function () {
    $product = Product::factory()->create(['name' => 'Old name', 'selling_price' => '10.00', 'stock_quantity' => 5]);
    $transaction = checkout(cashier(), cartOf([[$product, 1]]), cashCents: 1000);

    $product->update(['name' => 'New name', 'selling_price' => '99.00']);
    $product->delete();

    $item = $transaction->fresh()->items->sole();

    expect($item->product_name)->toBe('Old name')
        ->and($item->unit_price)->toBe('10.00')
        ->and($item->product)->not->toBeNull();
});

/*
 * Additions to the plan's tests: server-side permission re-checks, money bounds,
 * manual-item rules, idempotency under a race, locking, atomicity and snapshots.
 */

it('refuses a tampered override flag from a cashier without permission', function () {
    $product = Product::factory()->create(['selling_price' => '25.00', 'stock_quantity' => 5]);
    $lines = cartOf([[$product, 1]])->toArray();
    $lines['p'.$product->id]['unit_price_cents'] = 100;
    $lines['p'.$product->id]['price_overridden'] = true;

    expect(fn () => checkout(cashier(), Cart::fromArray($lines), cashCents: 10000))
        ->toThrow(CheckoutException::class, 'not allowed to change prices');

    expect(Transaction::count())->toBe(0)->and($product->fresh()->stock_quantity)->toBe(5);
});

it('refuses a tampered discount from a cashier whose permission was revoked', function () {
    $cashier = cashier(['can_apply_discount' => true]);
    $product = Product::factory()->create(['selling_price' => '100.00', 'stock_quantity' => 5]);
    $cashier->update(['can_apply_discount' => false]);

    expect(fn () => checkout($cashier, cartOf([[$product, 1]]), cashCents: 10000, discountCents: 1))
        ->toThrow(CheckoutException::class, 'not allowed to apply discounts');

    expect(Transaction::count())->toBe(0);
});

it('refuses overrides and discounts from a deactivated user', function () {
    $user = admin();
    $product = Product::factory()->create(['selling_price' => '25.00', 'stock_quantity' => 5]);
    $cart = cartOf([[$product, 1]]);
    $cart->overridePrice('p'.$product->id, 2000);
    $user->update(['is_active' => false]);

    expect(fn () => checkout($user, $cart, cashCents: 10000))
        ->toThrow(CheckoutException::class, 'not allowed to make sales')
        ->and(fn () => checkout($user, cartOf([[$product, 1]]), cashCents: 10000, discountCents: 100))
        ->toThrow(CheckoutException::class, 'not allowed to make sales');

    expect(Transaction::count())->toBe(0)->and($product->fresh()->stock_quantity)->toBe(5);
});

it('refuses a plain sale from a deactivated cashier and writes nothing', function () {
    $cashier = cashier(['is_active' => false]);
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 5]);

    try {
        checkout($cashier, cartOf([[$product, 1]]), cashCents: 1000);
        $this->fail('Expected a CheckoutException.');
    } catch (CheckoutException $exception) {
        expect($exception->reason)->toBe('not_allowed')
            ->and($exception->getMessage())->toBe('You are not allowed to make sales.');
    }

    expect(Transaction::count())->toBe(0)
        ->and(TransactionItem::count())->toBe(0)
        ->and(InventoryMovement::count())->toBe(0)
        ->and($product->fresh()->stock_quantity)->toBe(5);
});

it('still returns a committed sale by token after the cashier is deactivated', function () {
    $cashier = cashier();
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 5]);
    $token = (string) Str::uuid();
    $sale = checkout($cashier, cartOf([[$product, 1]]), cashCents: 1000, token: $token);

    $cashier->update(['is_active' => false]);
    $retry = checkout($cashier, cartOf([[$product, 1]]), cashCents: 1000, token: $token);

    expect($retry->id)->toBe($sale->id)
        ->and(Transaction::count())->toBe(1)
        ->and($product->fresh()->stock_quantity)->toBe(4);
});

it('never returns another cashier\'s sale for a colliding token', function () {
    $ana = cashier();
    $ben = cashier();
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 5]);
    $token = (string) Str::uuid();
    checkout($ana, cartOf([[$product, 1]]), cashCents: 1000, token: $token);

    expect(fn () => checkout($ben, cartOf([[$product, 2]]), cashCents: 2000, token: $token))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Transaction::count())->toBe(1)
        ->and(Transaction::query()->sole()->user_id)->toBe($ana->id)
        ->and(InventoryMovement::count())->toBe(1)
        ->and($product->fresh()->stock_quantity)->toBe(4);
});

it('lists every short item when several products lack stock', function () {
    $soap = Product::factory()->create(['name' => 'Soap', 'selling_price' => '10.00', 'stock_quantity' => 1]);
    $fine = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 9]);
    $rice = Product::factory()->create(['name' => 'Rice', 'selling_price' => '10.00', 'stock_quantity' => 0]);

    try {
        checkout(cashier(), cartOf([[$soap, 2], [$fine, 1], [$rice, 4]]), cashCents: 10000);
        $this->fail('Expected a CheckoutException.');
    } catch (CheckoutException $exception) {
        expect($exception->reason)->toBe('insufficient_stock')
            ->and($exception->context['shortages'])->toBe([
                ['name' => 'Soap', 'requested' => 2, 'available' => 1],
                ['name' => 'Rice', 'requested' => 4, 'available' => 0],
            ])
            ->and($exception->getMessage())->toBe('Not enough stock: Soap (only 1 left), Rice (only 0 left).');
    }

    expect(Transaction::count())->toBe(0)->and(InventoryMovement::count())->toBe(0);
});

it('lets an admin override prices and apply discounts without the cashier flags', function () {
    $owner = admin();
    $product = Product::factory()->create(['selling_price' => '25.00', 'stock_quantity' => 5]);
    $cart = cartOf([[$product, 1]]);
    $cart->overridePrice('p'.$product->id, 2000);

    $transaction = checkout($owner, $cart, cashCents: 2000, discountCents: 500);

    expect($transaction->total)->toBe('15.00')
        ->and($transaction->items->sole()->price_override_user_id)->toBe($owner->id);
});

it('does not record an override when the charged price equals the catalog price', function () {
    $product = Product::factory()->create(['selling_price' => '25.00', 'stock_quantity' => 5]);
    $lines = cartOf([[$product, 1]])->toArray();
    $lines['p'.$product->id]['price_overridden'] = true;

    $item = checkout(admin(), Cart::fromArray($lines), cashCents: 2500)->items->sole();

    expect($item->price_overridden)->toBeFalse()
        ->and($item->price_override_user_id)->toBeNull()
        ->and($item->original_price)->toBe('25.00');
});

it('keeps an override after a catalog price change and records the current catalog price', function () {
    $product = Product::factory()->create(['selling_price' => '25.00', 'stock_quantity' => 5]);
    $cart = cartOf([[$product, 1]]);
    $cart->overridePrice('p'.$product->id, 2000);
    $product->update(['selling_price' => '27.00']);

    $item = checkout(admin(), $cart, cashCents: 2000)->items->sole();

    expect($item->original_price)->toBe('27.00')
        ->and($item->unit_price)->toBe('20.00')
        ->and($item->price_overridden)->toBeTrue();
});

it('refuses lines outside the supported quantity and price range', function (int $quantity, int $unitPriceCents) {
    $cart = new Cart;
    $cart->addManual('Big item', $quantity, $unitPriceCents);

    try {
        checkout(admin(), $cart, cashCents: 999_999_999_999);
        $this->fail('Expected a CheckoutException.');
    } catch (CheckoutException $exception) {
        expect($exception->reason)->toBe('invalid_line');
    }

    expect(Transaction::count())->toBe(0);
})->with(fn (): array => [
    'zero quantity' => [0, 100],
    'negative quantity' => [-1, 100],
    'quantity above the cart maximum' => [Cart::MAX_QUANTITY + 1, 100],
    'negative price' => [1, -1],
    'unit price above DECIMAL(10,2)' => [1, 10_000_000_000],
    'line total above DECIMAL(12,2)' => [101, 9_999_999_999],
]);

it('accepts lines at the edge of the supported range', function () {
    $cart = new Cart;
    $cart->addManual('Big item', 100, 9_999_999_999);

    $transaction = checkout(admin(), $cart, cashCents: 999_999_999_900);
    $item = $transaction->items->sole();

    expect($item->unit_price)->toBe('99999999.99')
        ->and($item->quantity)->toBe(100)
        ->and($item->line_total)->toBe('9999999999.00')
        ->and($transaction->change_amount)->toBe('0.00');

    $edge = new Cart;
    $edge->addManual('Many', Cart::MAX_QUANTITY, 1);

    expect(checkout(admin(), $edge, cashCents: 9999)->item_count)->toBe(Cart::MAX_QUANTITY);
});

it('refuses a subtotal above the DECIMAL(12,2) range', function () {
    $cart = new Cart;
    $cart->addManual('Half', 60, 9_999_999_999);
    $cart->addManual('Other half', 60, 9_999_999_999);

    try {
        checkout(admin(), $cart, cashCents: 999_999_999_999);
        $this->fail('Expected a CheckoutException.');
    } catch (CheckoutException $exception) {
        expect($exception->reason)->toBe('amount_too_large');
    }

    expect(Transaction::count())->toBe(0);
});

it('refuses cash above the DECIMAL(12,2) range', function () {
    $cart = new Cart;
    $cart->addManual('Ice', 1, 500);

    try {
        checkout(cashier(), $cart, cashCents: 1_000_000_000_000);
        $this->fail('Expected a CheckoutException.');
    } catch (CheckoutException $exception) {
        expect($exception->reason)->toBe('amount_too_large');
    }

    expect(Transaction::count())->toBe(0);
});

it('refuses malformed cart lines', function (Closure $tamper) {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 5]);
    $lines = cartOf([[$product, 1]])->toArray();
    $key = 'p'.$product->id;
    $lines = $tamper($lines, $key, $product);

    try {
        checkout(admin(), Cart::fromArray($lines), cashCents: 100000);
        $this->fail('Expected a CheckoutException.');
    } catch (CheckoutException $exception) {
        expect($exception->reason)->toBe('invalid_line');
    }

    expect(Transaction::count())->toBe(0)->and($product->fresh()->stock_quantity)->toBe(5);
})->with([
    'string quantity' => function (array $lines, string $key): array {
        $lines[$key]['quantity'] = '2';

        return $lines;
    },
    'float price' => function (array $lines, string $key): array {
        $lines[$key]['unit_price_cents'] = 1000.0;

        return $lines;
    },
    'missing quantity' => function (array $lines, string $key): array {
        unset($lines[$key]['quantity']);

        return $lines;
    },
    'array name' => function (array $lines, string $key): array {
        $lines[$key]['name'] = ['x'];

        return $lines;
    },
    'second line for the same product' => function (array $lines, string $key): array {
        $lines['x'.$key] = [...$lines[$key], 'key' => 'x'.$key];

        return $lines;
    },
    'manual line pointing at a product' => function (array $lines, string $key, Product $product): array {
        $lines['m0000abcd'] = [...$lines[$key], 'key' => 'm0000abcd', 'is_manual' => true, 'product_id' => $product->id];
        unset($lines[$key]);

        return $lines;
    },
    'manual line under a catalog key' => function (array $lines, string $key): array {
        $lines[$key] = [...$lines[$key], 'is_manual' => true, 'product_id' => null];

        return $lines;
    },
    'manual line under a key that is not a manual key' => function (array $lines, string $key): array {
        $lines['x1'] = [...$lines[$key], 'key' => 'x1', 'is_manual' => true, 'product_id' => null];
        unset($lines[$key]);

        return $lines;
    },
]);

it('stores a trimmed manual item name', function () {
    $cart = new Cart;
    $cart->addManual('  Ice (plastic)  ', 1, 500);

    expect(checkout(cashier(), $cart, cashCents: 500)->items->sole()->product_name)->toBe('Ice (plastic)');
});

it('refuses a manual item with a blank or overlong name', function (string $name) {
    $cart = new Cart;
    $cart->addManual($name, 1, 500);

    try {
        checkout(cashier(), $cart, cashCents: 500);
        $this->fail('Expected a CheckoutException.');
    } catch (CheckoutException $exception) {
        expect($exception->reason)->toBe('invalid_line');
    }

    expect(Transaction::count())->toBe(0);
})->with([
    'blank' => ['   '],
    '151 characters' => [str_repeat('a', 151)],
    '151 multibyte characters' => [str_repeat('ñ', 151)],
]);

it('accepts a manual item name of exactly 150 multibyte characters', function () {
    $cart = new Cart;
    $cart->addManual(str_repeat('ñ', 150), 1, 500);

    expect(checkout(cashier(), $cart, cashCents: 500)->items->sole()->product_name)->toBe(str_repeat('ñ', 150));
});

it('returns the original sale for a duplicate token even if the cart changed or stock ran out', function () {
    $cashier = cashier();
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 2]);
    $token = (string) Str::uuid();

    $first = checkout($cashier, cartOf([[$product, 2]]), cashCents: 2000, token: $token);
    $retry = checkout($cashier, cartOf([[$product, 2]]), cashCents: 2000, token: $token);
    $different = checkout($cashier, cartOf([[$product, 1]]), cashCents: 1000, token: $token);

    expect($retry->id)->toBe($first->id)
        ->and($different->id)->toBe($first->id)
        ->and($different->total)->toBe('20.00')
        ->and($different->items)->toHaveCount(1)
        ->and(Transaction::count())->toBe(1)
        ->and(InventoryMovement::count())->toBe(1)
        ->and($product->fresh()->stock_quantity)->toBe(0);
});

/**
 * Simulates a second request with the same token that passed its duplicate check just before
 * the first request committed: the first sale is written the moment the second request's
 * duplicate lookup returns empty.
 *
 * @return array{0: Transaction, 1: Transaction} the racing sale and the second request's result
 */
function raceCheckoutWithSameToken(Product $product, int $quantity): array
{
    $cashier = cashier();
    $token = (string) Str::uuid();
    $racer = null;

    DB::listen(function (QueryExecuted $query) use (&$racer, $cashier, $product, $quantity, $token): void {
        if ($racer === null && str_contains($query->sql, 'from `transactions`') && str_contains($query->sql, '`checkout_token`')) {
            $racer = false;
            $racer = checkout($cashier, cartOf([[$product, $quantity]]), cashCents: 100000, token: $token);
        }
    });

    $second = checkout($cashier, cartOf([[$product, $quantity]]), cashCents: 100000, token: $token);

    return [$racer, $second];
}

it('returns the racing sale when two requests with one token collide on the unique index', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 10]);

    [$racer, $second] = raceCheckoutWithSameToken($product, 2);

    expect($second->id)->toBe($racer->id)
        ->and(Transaction::count())->toBe(1)
        ->and(TransactionItem::count())->toBe(1)
        ->and(InventoryMovement::count())->toBe(1)
        ->and($product->fresh()->stock_quantity)->toBe(8);
});

it('returns the racing sale when the race used up the last of the stock', function () {
    $product = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 2]);

    [$racer, $second] = raceCheckoutWithSameToken($product, 2);

    expect($second->id)->toBe($racer->id)
        ->and(Transaction::count())->toBe(1)
        ->and(InventoryMovement::count())->toBe(1)
        ->and($product->fresh()->stock_quantity)->toBe(0);
});

it('locks the cart products in ascending id order', function () {
    $first = Product::factory()->create(['selling_price' => '10.00']);
    $second = Product::factory()->create(['selling_price' => '10.00']);
    $locks = [];

    DB::listen(function (QueryExecuted $query) use (&$locks): void {
        if (str_contains($query->sql, 'from `products`') && str_contains($query->sql, 'for update')) {
            $locks[] = $query->sql;
        }
    });

    checkout(cashier(), cartOf([[$second, 1], [$first, 1]]), cashCents: 2000);

    expect($locks[0])->toContain('where `id` in (?, ?)')
        ->toContain('order by `id` asc');
});

it('writes nothing when a later step of the sale fails', function () {
    $first = Product::factory()->create(['selling_price' => '10.00', 'stock_quantity' => 5]);
    $breaks = Product::factory()->create(['name' => 'Breaks', 'selling_price' => '10.00', 'stock_quantity' => 5]);

    TransactionItem::creating(function (TransactionItem $item): void {
        if ($item->product_name === 'Breaks') {
            throw new RuntimeException('Simulated failure.');
        }
    });

    expect(fn () => checkout(cashier(), cartOf([[$first, 1], [$breaks, 1]]), cashCents: 2000))
        ->toThrow(RuntimeException::class, 'Simulated failure.');

    expect(Transaction::count())->toBe(0)
        ->and(TransactionItem::count())->toBe(0)
        ->and(InventoryMovement::count())->toBe(0)
        ->and($first->fresh()->stock_quantity)->toBe(5)
        ->and($breaks->fresh()->stock_quantity)->toBe(5);
});

it('builds the reference number from the sale date and id', function () {
    $this->travelTo(Carbon::parse('2026-11-17 09:30:00', 'Asia/Manila'));
    $cart = new Cart;
    $cart->addManual('Ice', 1, 500);

    $transaction = checkout(cashier(), $cart, cashCents: 500);

    expect($transaction->fresh()->reference_number)
        ->toBe(sprintf('20261117-%06d', $transaction->id));
});

it('stores exact decimal amounts that satisfy the totals invariants', function () {
    $owner = admin();
    $coke = Product::factory()->create(['selling_price' => '12.50', 'stock_quantity' => 10]);
    $soap = Product::factory()->create(['selling_price' => '25.00', 'stock_quantity' => 10]);
    $cart = cartOf([[$coke, 3], [$soap, 1]]);
    $cart->overridePrice('p'.$soap->id, 1999);
    $cart->addManual('Ice', 2, 500);

    $transaction = checkout($owner, $cart, cashCents: 10000, discountCents: 249);

    $row = DB::table('transactions')->where('id', $transaction->id)->first();
    $items = DB::table('transaction_items')->where('transaction_id', $transaction->id)->orderBy('id')->get();

    expect($row->subtotal)->toBe('67.49')
        ->and($row->discount_amount)->toBe('2.49')
        ->and($row->total)->toBe('65.00')
        ->and($row->cash_received)->toBe('100.00')
        ->and($row->change_amount)->toBe('35.00')
        ->and((int) $row->item_count)->toBe(6)
        ->and($items->pluck('line_total')->all())->toBe(['37.50', '19.99', '10.00'])
        ->and($items->pluck('unit_price')->all())->toBe(['12.50', '19.99', '5.00'])
        ->and($items->pluck('original_price')->all())->toBe(['12.50', '25.00', '5.00'])
        ->and(DB::table('transaction_items')->where('transaction_id', $transaction->id)->sum('line_total'))->toEqual('67.49');
});

it('keeps every snapshot field after the product is edited and deleted', function () {
    $user = cashier(['can_override_price' => true]);
    $product = Product::factory()->create([
        'name' => 'Safeguard', 'sku' => 'SG-90', 'barcode' => '4800888123456',
        'selling_price' => '45.00', 'cost_price' => '38.25', 'stock_quantity' => 5,
    ]);
    $cart = cartOf([[$product, 2]]);
    $cart->overridePrice('p'.$product->id, 4000);
    $transaction = checkout($user, $cart, cashCents: 8000);

    $product->update([
        'name' => 'Safeguard Pure White', 'sku' => 'SG-91', 'barcode' => '4800888999999',
        'selling_price' => '50.00', 'cost_price' => '41.00',
    ]);
    $product->delete();

    $item = TransactionItem::query()->where('transaction_id', $transaction->id)->sole();

    expect($item->product_id)->toBe($product->id)
        ->and($item->product_name)->toBe('Safeguard')
        ->and($item->product_sku)->toBe('SG-90')
        ->and($item->product_barcode)->toBe('4800888123456')
        ->and($item->original_price)->toBe('45.00')
        ->and($item->unit_price)->toBe('40.00')
        ->and($item->unit_cost)->toBe('38.25')
        ->and($item->line_total)->toBe('80.00')
        ->and($item->price_overridden)->toBeTrue()
        ->and($item->price_override_user_id)->toBe($user->id);
});
