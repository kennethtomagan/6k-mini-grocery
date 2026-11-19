<?php

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Pos\Cart;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/**
 * Create a persisted admin user.
 *
 * @param  array<string, mixed>  $attributes
 */
function admin(array $attributes = []): User
{
    return User::factory()->admin()->create($attributes);
}

/**
 * Create a persisted cashier user.
 *
 * @param  array<string, mixed>  $attributes
 */
function cashier(array $attributes = []): User
{
    return User::factory()->cashier()->create($attributes);
}

/**
 * Build a cart of catalog products.
 *
 * @param  list<array{0: Product, 1: int}>  $items
 */
function cartOf(array $items): Cart
{
    $cart = new Cart;

    foreach ($items as [$product, $quantity]) {
        $cart->addProduct($product, $quantity);
    }

    return $cart;
}

/**
 * Run a checkout through the real CheckoutService. A fresh token is used unless one is given.
 */
function checkout(User $user, Cart $cart, int $cashCents, int $discountCents = 0, ?string $token = null): Transaction
{
    return app(CheckoutService::class)->checkout($user, $cart, $discountCents, $cashCents, $token ?? (string) Str::uuid());
}
