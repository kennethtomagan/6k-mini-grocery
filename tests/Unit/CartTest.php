<?php

use App\Models\Product;
use App\Pos\Cart;

function cartProduct(int $id, string $price, string $name = 'Item'): Product
{
    return Product::factory()->make(['id' => $id, 'name' => $name, 'selling_price' => $price]);
}

it('adds a product and increases quantity when added again', function () {
    $cart = new Cart;
    $coke = cartProduct(1, '25.00', 'Coke');

    $key = $cart->addProduct($coke);
    $cart->addProduct($coke);

    expect($key)->toBe('p1')
        ->and($cart->lines())->toHaveCount(1)
        ->and($cart->line('p1'))->toMatchArray([
            'key' => 'p1',
            'product_id' => 1,
            'name' => 'Coke',
            'unit_price_cents' => 2500,
            'original_price_cents' => 2500,
            'quantity' => 2,
            'is_manual' => false,
            'price_overridden' => false,
        ])
        ->and($cart->subtotalCents())->toBe(5000)
        ->and($cart->itemCount())->toBe(2)
        ->and($cart->quantityOf(1))->toBe(2);
});

it('adds manual items that are never merged', function () {
    $cart = new Cart;

    $first = $cart->addManual('Ice', 2, 500);
    $second = $cart->addManual('Ice', 1, 500);

    expect($first)->not->toBe($second)
        ->and($cart->lines())->toHaveCount(2)
        ->and($cart->line($first))->toMatchArray(['product_id' => null, 'is_manual' => true, 'quantity' => 2])
        ->and($cart->subtotalCents())->toBe(1500)
        ->and($cart->productIds())->toBe([]);
});

it('increments, decrements and removes lines', function () {
    $cart = new Cart;
    $cart->addProduct(cartProduct(1, '10.00'));

    $cart->increment('p1');
    $cart->increment('p1');
    $cart->decrement('p1');
    expect($cart->line('p1')['quantity'])->toBe(2);

    $cart->setQuantity('p1', 7);
    expect($cart->line('p1')['quantity'])->toBe(7);

    $cart->setQuantity('p1', 0);
    expect($cart->isEmpty())->toBeTrue();

    $cart->addProduct(cartProduct(2, '10.00'));
    $cart->decrement('p2');
    expect($cart->isEmpty())->toBeTrue();

    $cart->addProduct(cartProduct(3, '10.00'));
    $cart->remove('p3');
    expect($cart->isEmpty())->toBeTrue();
});

it('overrides and resets a price without losing the original', function () {
    $cart = new Cart;
    $cart->addProduct(cartProduct(1, '25.00'));

    $cart->overridePrice('p1', 2000);
    expect($cart->line('p1'))->toMatchArray([
        'unit_price_cents' => 2000, 'original_price_cents' => 2500, 'price_overridden' => true,
    ]);

    $cart->overridePrice('p1', 2500);
    expect($cart->line('p1')['price_overridden'])->toBeFalse();

    $cart->overridePrice('p1', 1000);
    $cart->resetPrice('p1');
    expect($cart->line('p1'))->toMatchArray(['unit_price_cents' => 2500, 'price_overridden' => false]);
});

it('syncs a changed catalog price but keeps an override', function () {
    $cart = new Cart;
    $cart->addProduct(cartProduct(1, '25.00'));
    $cart->addProduct(cartProduct(2, '10.00'));
    $cart->overridePrice('p2', 800);

    $cart->syncPrice('p1', 2700);
    $cart->syncPrice('p2', 1200);

    expect($cart->line('p1'))->toMatchArray(['unit_price_cents' => 2700, 'original_price_cents' => 2700])
        ->and($cart->line('p2'))->toMatchArray(['unit_price_cents' => 800, 'original_price_cents' => 1200, 'price_overridden' => true]);
});

it('round-trips through an array', function () {
    $cart = new Cart;
    $cart->addProduct(cartProduct(1, '12.50'), 3);
    $cart->addManual('Load', 1, 5000);

    $copy = Cart::fromArray($cart->toArray());

    expect($copy->toArray())->toBe($cart->toArray())
        ->and($copy->subtotalCents())->toBe(8750)
        ->and($copy->productIds())->toBe([1]);
});

it('ignores unknown keys', function () {
    $cart = new Cart;

    $cart->increment('p99');
    $cart->remove('p99');
    $cart->overridePrice('p99', 100);

    expect($cart->isEmpty())->toBeTrue()->and($cart->line('p99'))->toBeNull();
});
