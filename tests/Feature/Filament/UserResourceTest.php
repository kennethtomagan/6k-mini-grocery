<?php

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;

it('lets only admins open the create and edit pages', function () {
    $target = cashier();

    $this->actingAs(cashier())->get('/admin/users/create')->assertForbidden();
    $this->actingAs(cashier())->get("/admin/users/{$target->getRouteKey()}/edit")->assertForbidden();
    $this->actingAs(admin())->get('/admin/users/create')->assertOk();
    $this->actingAs(admin())->get("/admin/users/{$target->getRouteKey()}/edit")->assertOk();
});

it('shows the users navigation item to admins only', function () {
    $this->actingAs(admin());
    expect(UserResource::canAccess())->toBeTrue();

    $this->actingAs(cashier());
    expect(UserResource::canAccess())->toBeFalse();
});

it('lists users for an admin', function () {
    $users = collect([cashier(), cashier()]);

    Livewire::actingAs(admin())
        ->test(ListUsers::class)
        ->assertCanSeeTableRecords($users);
});

it('offers no delete action in the table or on the edit page', function () {
    $target = cashier();

    Livewire::actingAs(admin())
        ->test(ListUsers::class)
        ->assertTableActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('delete');

    Livewire::actingAs(admin())
        ->test(EditUser::class, ['record' => $target->getRouteKey()])
        ->assertActionDoesNotExist(DeleteAction::class);
});

it('lets an admin demote and deactivate another admin', function () {
    $other = admin();

    Livewire::actingAs(admin())
        ->test(EditUser::class, ['record' => $other->getRouteKey()])
        ->fillForm(['role' => UserRole::Cashier->value, 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($other->fresh()->role)->toBe(UserRole::Cashier)
        ->and($other->fresh()->is_active)->toBeFalse();
});

it('rejects a duplicate email', function () {
    $existing = cashier();

    Livewire::actingAs(admin())
        ->test(CreateUser::class)
        ->fillForm(['name' => 'Dup', 'email' => $existing->email, 'password' => 'long-enough'])
        ->call('create')
        ->assertHasFormErrors(['email' => 'unique']);
});

it('requires a password of at least 8 characters on create', function () {
    Livewire::actingAs(admin())
        ->test(CreateUser::class)
        ->fillForm(['name' => 'Short', 'email' => 'short@example.com', 'password' => 'short'])
        ->call('create')
        ->assertHasFormErrors(['password' => 'min']);

    Livewire::actingAs(admin())
        ->test(CreateUser::class)
        ->fillForm(['name' => 'None', 'email' => 'none@example.com', 'password' => null])
        ->call('create')
        ->assertHasFormErrors(['password' => 'required']);
});

it('shows the permission toggles only for the cashier role', function () {
    Livewire::actingAs(admin())
        ->test(CreateUser::class)
        ->fillForm(['role' => UserRole::Cashier->value])
        ->assertFormFieldIsVisible('can_override_price')
        ->assertFormFieldIsVisible('can_apply_discount')
        ->fillForm(['role' => UserRole::Admin->value])
        ->assertFormFieldIsHidden('can_override_price')
        ->assertFormFieldIsHidden('can_apply_discount');
});

it('does not restore cashier permissions after a promote then demote round trip', function () {
    $user = cashier(['can_override_price' => true, 'can_apply_discount' => true]);

    Livewire::actingAs(admin())
        ->test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm(['role' => UserRole::Admin->value])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::actingAs(admin())
        ->test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm(['role' => UserRole::Cashier->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $user = $user->fresh();

    expect($user->role)->toBe(UserRole::Cashier)
        ->and($user->can('override-price'))->toBeFalse()
        ->and($user->can('apply-discount'))->toBeFalse();
});

it('rejects a tampered non-string password with a validation error', function () {
    $user = cashier();

    Livewire::actingAs(admin())
        ->test(EditUser::class, ['record' => $user->getRouteKey()])
        ->set('data.password', ['x'])
        ->call('save')
        ->assertHasFormErrors(['password']);
});

it('requires at least 8 characters for a new password on edit', function () {
    $user = cashier();

    Livewire::actingAs(admin())
        ->test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm(['password' => 'short'])
        ->call('save')
        ->assertHasFormErrors(['password' => 'min']);
});

it('forbids cashiers from the create and edit components', function () {
    $target = cashier();

    Livewire::actingAs(cashier())->test(CreateUser::class)->assertForbidden();
    Livewire::actingAs(cashier())
        ->test(EditUser::class, ['record' => $target->getRouteKey()])
        ->assertForbidden();
});

it('never loads the password hash into the edit form', function () {
    $user = cashier();

    Livewire::actingAs(admin())
        ->test(EditUser::class, ['record' => $user->getRouteKey()])
        ->assertSet('data.password', null)
        ->assertDontSee($user->password, escape: false);
});

it('rejects a password longer than bcrypt can use', function () {
    Livewire::actingAs(admin())
        ->test(CreateUser::class)
        ->fillForm([
            'name' => 'Long Password',
            'email' => 'long@example.com',
            'password' => str_repeat('a', 73),
            'role' => UserRole::Cashier->value,
        ])
        ->call('create')
        ->assertHasFormErrors(['password' => 'max']);
});

it('shows admins as having both pos powers in the list', function () {
    $owner = admin();
    $plain = cashier();
    $trusted = cashier(['can_override_price' => true, 'can_apply_discount' => true]);

    Livewire::actingAs($owner)
        ->test(ListUsers::class)
        ->assertTableColumnStateSet('can_override_price', true, $owner)
        ->assertTableColumnStateSet('can_apply_discount', true, $owner)
        ->assertTableColumnStateSet('can_override_price', false, $plain)
        ->assertTableColumnStateSet('can_apply_discount', false, $plain)
        ->assertTableColumnStateSet('can_override_price', true, $trusted)
        ->assertTableColumnStateSet('can_apply_discount', true, $trusted);
});
