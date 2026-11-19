<?php

use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('lets active users into the panel and blocks inactive users', function () {
    $this->actingAs(cashier())->get('/admin')->assertOk();
    $this->actingAs(cashier(['is_active' => false]))->get('/admin')->assertForbidden();
});

it('grants price override and discount by role or flag', function () {
    expect(admin()->can('override-price'))->toBeTrue()
        ->and(admin()->can('apply-discount'))->toBeTrue()
        ->and(cashier()->can('override-price'))->toBeFalse()
        ->and(cashier()->can('apply-discount'))->toBeFalse()
        ->and(cashier(['can_override_price' => true])->can('override-price'))->toBeTrue()
        ->and(cashier(['can_apply_discount' => true])->can('apply-discount'))->toBeTrue()
        ->and(cashier(['can_override_price' => true, 'is_active' => false])->can('override-price'))->toBeFalse();
});

it('reserves catalog, stock and reports for admins', function () {
    $product = Product::factory()->create();

    expect(admin()->can('update', $product))->toBeTrue()
        ->and(admin()->can('adjustStock', $product))->toBeTrue()
        ->and(admin()->can('view-reports'))->toBeTrue()
        ->and(cashier()->can('viewAny', Product::class))->toBeFalse()
        ->and(cashier()->can('adjustStock', $product))->toBeFalse()
        ->and(cashier()->can('view-reports'))->toBeFalse();
});

it('lets cashiers view only their own transactions and never void', function () {
    $owner = cashier();
    $other = cashier();
    $sale = new Transaction(['user_id' => $owner->id, 'status' => TransactionStatus::Completed]);

    expect($owner->can('view', $sale))->toBeTrue()
        ->and($other->can('view', $sale))->toBeFalse()
        ->and(admin()->can('view', $sale))->toBeTrue()
        ->and($owner->can('void', $sale))->toBeFalse()
        ->and(admin()->can('void', $sale))->toBeTrue();
});

it('does not allow voiding twice', function () {
    $sale = new Transaction(['user_id' => cashier()->id, 'status' => TransactionStatus::Voided]);

    expect(admin()->can('void', $sale))->toBeFalse();
});

it('never allows deleting users, transactions or movements, even for admins', function () {
    $admin = admin();

    expect($admin->can('delete', cashier()))->toBeFalse()
        ->and($admin->can('delete', new Transaction))->toBeFalse()
        ->and($admin->can('update', new Transaction))->toBeFalse()
        ->and($admin->can('delete', new InventoryMovement))->toBeFalse()
        ->and($admin->can('update', new InventoryMovement))->toBeFalse()
        ->and($admin->can('forceDelete', Product::factory()->create()))->toBeFalse();
});

it('keeps cashiers out of user management', function () {
    $this->actingAs(cashier())->get('/admin/users')->assertForbidden();
    $this->actingAs(admin())->get('/admin/users')->assertOk();
});

it('creates a cashier with a hashed password', function () {
    Livewire::actingAs(admin())
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'Ana Reyes',
            'email' => 'ana@example.com',
            'password' => 'secret-pass',
            'role' => UserRole::Cashier->value,
            'is_active' => true,
            'can_override_price' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'ana@example.com')->sole();

    expect($user->role)->toBe(UserRole::Cashier)
        ->and($user->can_override_price)->toBeTrue()
        ->and($user->password)->not->toBe('secret-pass')
        ->and(Hash::check('secret-pass', $user->password))->toBeTrue();
});

it('keeps the old password when the field is left blank on edit', function () {
    $user = cashier(['password' => 'original-pass']);

    Livewire::actingAs(admin())
        ->test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm(['name' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($user->fresh()->name)->toBe('Renamed')
        ->and(Hash::check('original-pass', $user->fresh()->password))->toBeTrue();
});

it('does not let an admin demote or deactivate their own account', function () {
    $admin = admin();

    Livewire::actingAs($admin)
        ->test(EditUser::class, ['record' => $admin->getRouteKey()])
        ->set('data.role', UserRole::Cashier->value)
        ->set('data.is_active', false)
        ->set('data.name', 'Renamed')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($admin->fresh()->name)->toBe('Renamed')
        ->and($admin->fresh()->role)->toBe(UserRole::Admin)
        ->and($admin->fresh()->is_active)->toBeTrue();
});
