<?php

use App\Enums\UserRole;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;

it('seeds exactly the admin and cashier accounts', function () {
    $this->seed();

    expect(User::query()->count())->toBe(2);

    $admin = User::query()->where('email', 'admin@k6tindahan.test')->firstOrFail();
    $cashier = User::query()->where('email', 'cashier@k6tindahan.test')->firstOrFail();

    expect($admin->name)->toBe('Store Owner')
        ->and($admin->role)->toBe(UserRole::Admin)
        ->and($admin->is_active)->toBeTrue()
        ->and($cashier->name)->toBe('Cashier')
        ->and($cashier->role)->toBe(UserRole::Cashier)
        ->and($cashier->is_active)->toBeTrue();
});

it('stores the seeded passwords hashed', function () {
    $this->seed();

    foreach (['admin@k6tindahan.test', 'cashier@k6tindahan.test'] as $email) {
        $user = User::query()->where('email', $email)->firstOrFail();

        expect($user->password)->not->toBe('password')
            ->and(Hash::check('password', $user->password))->toBeTrue();
    }
});

it('lets both seeded users access the admin panel', function () {
    $this->seed();

    $panel = Filament::getPanel('admin');

    foreach (['admin@k6tindahan.test', 'cashier@k6tindahan.test'] as $email) {
        expect(User::query()->where('email', $email)->firstOrFail()->canAccessPanel($panel))->toBeTrue();
    }
});

it('does not duplicate users when seeded twice', function () {
    $this->seed();
    $this->seed();

    expect(User::query()->count())->toBe(2);
});

it('never modifies existing users when re-seeded', function () {
    $this->seed();

    User::query()->where('email', 'admin@k6tindahan.test')->firstOrFail()
        ->update(['password' => 'new-secret', 'name' => 'Renamed Owner']);
    User::query()->where('email', 'cashier@k6tindahan.test')->firstOrFail()
        ->update(['is_active' => false]);

    $this->seed();

    $admin = User::query()->where('email', 'admin@k6tindahan.test')->firstOrFail();
    $cashier = User::query()->where('email', 'cashier@k6tindahan.test')->firstOrFail();

    expect(User::query()->count())->toBe(2)
        ->and(Hash::check('new-secret', $admin->password))->toBeTrue()
        ->and($admin->name)->toBe('Renamed Owner')
        ->and($cashier->is_active)->toBeFalse();
});

it('creates a persisted admin with the admin helper', function () {
    $user = admin();

    expect($user->exists)->toBeTrue()
        ->and($user->role)->toBe(UserRole::Admin);
});

it('creates a persisted cashier with the cashier helper', function () {
    $user = cashier();

    expect($user->exists)->toBeTrue()
        ->and($user->role)->toBe(UserRole::Cashier)
        ->and(User::factory()->admin()->cashier()->create()->role)->toBe(UserRole::Cashier);
});

it('applies attribute overrides in the helpers', function () {
    expect(cashier(['can_override_price' => true])->can_override_price)->toBeTrue()
        ->and(admin(['name' => 'Boss'])->name)->toBe('Boss');
});
