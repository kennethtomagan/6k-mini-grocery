<?php

use App\Enums\UserRole;
use App\Models\User;

it('defaults a factory user to an active cashier without extra permissions', function () {
    $user = User::factory()->create()->fresh();

    expect($user->role)->toBe(UserRole::Cashier)
        ->and($user->is_active)->toBeTrue()
        ->and($user->can_override_price)->toBeFalse()
        ->and($user->can_apply_discount)->toBeFalse();
});

it('tells admins and cashiers apart', function () {
    expect(User::factory()->admin()->make()->isAdmin())->toBeTrue()
        ->and(User::factory()->cashier()->make()->isAdmin())->toBeFalse();
});

it('only lets active users access the admin panel', function () {
    $panel = filament()->getPanel('admin');

    expect(User::factory()->make()->canAccessPanel($panel))->toBeTrue()
        ->and(User::factory()->inactive()->make()->canAccessPanel($panel))->toBeFalse();
});

it('lets an active user open the admin panel over HTTP', function () {
    $this->actingAs(User::factory()->create())->get('/admin')->assertOk();
});

it('forbids an inactive user from the admin panel over HTTP', function () {
    $this->actingAs(User::factory()->inactive()->create())->get('/admin')->assertForbidden();
});

it('hides the password and remember token when serialised', function () {
    $array = User::factory()->create()->toArray();

    expect($array)->not->toHaveKeys(['password', 'remember_token']);
});

it('clears the cashier permission flags when saving a non-cashier', function () {
    $admin = admin(['can_override_price' => true, 'can_apply_discount' => true])->fresh();

    expect($admin->can_override_price)->toBeFalse()
        ->and($admin->can_apply_discount)->toBeFalse();
});

it('keeps the permission flags on a cashier', function () {
    $cashier = cashier(['can_override_price' => true, 'can_apply_discount' => true])->fresh();

    expect($cashier->can_override_price)->toBeTrue()
        ->and($cashier->can_apply_discount)->toBeTrue();
});

it('leaves the flags alone when the role is not loaded', function () {
    $cashier = cashier(['can_override_price' => true]);

    $partial = User::query()->select(['id', 'name'])->find($cashier->id);
    $partial->name = 'Renamed';
    $partial->save();

    expect($cashier->fresh()->can_override_price)->toBeTrue();
});

it('keeps the flags of a user created without an explicit role, which defaults to cashier', function () {
    $user = User::query()->create([
        'name' => 'No Role',
        'email' => 'norole@example.com',
        'password' => 'password',
        'can_override_price' => true,
        'can_apply_discount' => true,
    ])->fresh();

    expect($user->role)->toBe(UserRole::Cashier)
        ->and($user->can_override_price)->toBeTrue()
        ->and($user->can_apply_discount)->toBeTrue();
});
