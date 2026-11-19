<?php

use App\Enums\TransactionStatus;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Policies\TransactionItemPolicy;
use Illuminate\Support\Facades\Gate;

it('lets only admins manage categories', function () {
    $category = Category::factory()->create();
    $admin = admin();
    $cashier = cashier();

    expect($admin->can('viewAny', Category::class))->toBeTrue()
        ->and($admin->can('create', Category::class))->toBeTrue()
        ->and($admin->can('update', $category))->toBeTrue()
        ->and($admin->can('delete', $category))->toBeTrue()
        ->and($cashier->can('viewAny', Category::class))->toBeFalse()
        ->and($cashier->can('create', Category::class))->toBeFalse()
        ->and($cashier->can('update', $category))->toBeFalse()
        ->and($cashier->can('delete', $category))->toBeFalse();
});

it('lets admins edit store settings but never create or delete them', function () {
    $settings = StoreSetting::current();
    $admin = admin();

    expect($admin->can('viewAny', StoreSetting::class))->toBeTrue()
        ->and($admin->can('update', $settings))->toBeTrue()
        ->and($admin->can('create', StoreSetting::class))->toBeFalse()
        ->and($admin->can('delete', $settings))->toBeFalse()
        ->and($admin->can('deleteAny', StoreSetting::class))->toBeFalse()
        ->and(cashier()->can('update', $settings))->toBeFalse();
});

it('keeps the inventory ledger read-only for everyone', function () {
    $movement = new InventoryMovement;
    $admin = admin();

    expect($admin->can('viewAny', InventoryMovement::class))->toBeTrue()
        ->and($admin->can('view', $movement))->toBeTrue()
        ->and($admin->can('create', InventoryMovement::class))->toBeFalse()
        ->and($admin->can('update', $movement))->toBeFalse()
        ->and($admin->can('delete', $movement))->toBeFalse()
        ->and($admin->can('restore', $movement))->toBeFalse()
        ->and(cashier()->can('viewAny', InventoryMovement::class))->toBeFalse();
});

it('limits transaction listing to active users and blocks creation and force deletion', function () {
    $admin = admin();

    expect(cashier()->can('viewAny', Transaction::class))->toBeTrue()
        ->and(cashier(['is_active' => false])->can('viewAny', Transaction::class))->toBeFalse()
        ->and($admin->can('create', Transaction::class))->toBeFalse()
        ->and($admin->can('forceDelete', new Transaction(['status' => TransactionStatus::Completed])))->toBeFalse();
});

it('lets admins manage users but never delete them', function () {
    $admin = admin();
    $other = cashier();

    expect($admin->can('viewAny', User::class))->toBeTrue()
        ->and($admin->can('create', User::class))->toBeTrue()
        ->and($admin->can('update', $other))->toBeTrue()
        ->and($admin->can('delete', $other))->toBeFalse()
        ->and($admin->can('delete', $admin))->toBeFalse()
        ->and(cashier()->can('viewAny', User::class))->toBeFalse()
        ->and(cashier()->can('update', $other))->toBeFalse();
});

it('grants the access-pos gate to active users only', function () {
    expect(cashier()->can('access-pos'))->toBeTrue()
        ->and(admin()->can('access-pos'))->toBeTrue()
        ->and(cashier(['is_active' => false])->can('access-pos'))->toBeFalse()
        ->and(admin(['is_active' => false])->can('access-pos'))->toBeFalse();
});

it('lets admins restore soft-deleted products but never force delete them', function () {
    $product = Product::factory()->create();
    $product->delete();
    $admin = admin();

    expect($admin->can('restore', $product))->toBeTrue()
        ->and($admin->can('forceDelete', $product))->toBeFalse();
});

it('allows a transaction owner to view it even when user_id was assigned as a string', function () {
    $owner = cashier();
    $sale = new Transaction(['user_id' => (string) $owner->id, 'status' => TransactionStatus::Completed]);

    expect($owner->can('view', $sale))->toBeTrue();
});

it('compares transaction owner ids strictly as strings', function (string $suffix, string $prefix, bool $expected) {
    $owner = cashier();
    $sale = new Transaction(['user_id' => $prefix.$owner->id.$suffix, 'status' => TransactionStatus::Completed]);

    expect($owner->can('view', $sale))->toBe($expected);
})->with([
    'exact string id' => ['', '', true],
    'decimal suffix' => ['.9', '', false],
    'leading zero' => ['', '0', false],
    'leading space' => ['', ' ', false],
]);

it('denies viewing an unsaved transaction item without a transaction', function () {
    expect(admin()->can('view', new TransactionItem))->toBeFalse()
        ->and(cashier()->can('view', new TransactionItem))->toBeFalse();
});

it('lets a cashier view items of only their own transaction', function () {
    $owner = cashier();
    $item = (new TransactionItem)->setRelation(
        'transaction',
        new Transaction(['user_id' => $owner->id, 'status' => TransactionStatus::Completed]),
    );

    expect($owner->can('view', $item))->toBeTrue()
        ->and(cashier()->can('view', $item))->toBeFalse()
        ->and(admin()->can('view', $item))->toBeTrue();
});

it('does not let an unsaved user view a transaction without an owner', function () {
    $sale = new Transaction(['status' => TransactionStatus::Completed]);

    expect((new User)->can('view', $sale))->toBeFalse();
});

it('discovers a policy for transaction items', function () {
    expect(Gate::getPolicyFor(TransactionItem::class))->toBeInstanceOf(TransactionItemPolicy::class);
});

it('does not let an inactive admin void a sale', function () {
    $sale = new Transaction(['user_id' => 1, 'status' => TransactionStatus::Completed]);

    expect(admin(['is_active' => false])->can('void', $sale))->toBeFalse()
        ->and(admin()->can('void', $sale))->toBeTrue();
});

it('denies gates to inactive users even with flags or admin role', function () {
    expect(cashier(['can_apply_discount' => true, 'is_active' => false])->can('apply-discount'))->toBeFalse()
        ->and(admin(['is_active' => false])->can('view-reports'))->toBeFalse();
});

/**
 * Expected policy outcomes keyed by "Model ability role".
 *
 * @return array<string, array{0: class-string, 1: string, 2: string, 3: bool}>
 */
function policyMatrixDataset(): array
{
    $adminOnly = [
        'viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'delete' => true,
        'deleteAny' => true, 'restore' => true, 'restoreAny' => true, 'forceDelete' => false,
        'forceDeleteAny' => false, 'replicate' => true, 'reorder' => true,
    ];
    $denyAll = array_fill_keys(array_keys($adminOnly), false);

    $matrix = [
        Product::class => $adminOnly + ['adjustStock' => true],
        Category::class => $adminOnly,
        User::class => array_merge($adminOnly, ['delete' => false, 'deleteAny' => false]),
        StoreSetting::class => array_merge($adminOnly, ['create' => false, 'delete' => false, 'deleteAny' => false, 'replicate' => false]),
        InventoryMovement::class => array_merge($denyAll, ['viewAny' => true, 'view' => true]),
        Transaction::class => array_merge($denyAll, ['viewAny' => true, 'view' => true, 'void' => true]),
        TransactionItem::class => array_merge($denyAll, ['viewAny' => true, 'view' => true]),
    ];

    $dataset = [];

    foreach ($matrix as $model => $abilities) {
        foreach ($abilities as $ability => $adminExpected) {
            $cashierExpected = match (true) {
                in_array($model, [Transaction::class, TransactionItem::class], true) => $ability === 'viewAny',
                default => false,
            };

            $name = class_basename($model);
            $dataset["$name $ability admin"] = [$model, $ability, 'admin', $adminExpected];
            $dataset["$name $ability cashier"] = [$model, $ability, 'cashier', $cashierExpected];
        }
    }

    return $dataset;
}

/**
 * Build the argument a policy ability expects: a record or the model class.
 */
function policyMatrixSubject(string $model, string $ability): mixed
{
    $classAbilities = ['viewAny', 'create', 'deleteAny', 'restoreAny', 'forceDeleteAny', 'reorder'];

    if (in_array($ability, $classAbilities, true)) {
        return $model;
    }

    return match ($model) {
        Product::class => Product::factory()->create(),
        Category::class => Category::factory()->create(),
        User::class => cashier(),
        StoreSetting::class => StoreSetting::current(),
        InventoryMovement::class => new InventoryMovement,
        Transaction::class => new Transaction(['status' => TransactionStatus::Completed]),
        TransactionItem::class => (new TransactionItem)->setRelation(
            'transaction',
            new Transaction(['status' => TransactionStatus::Completed]),
        ),
    };
}

it('enforces the full policy matrix', function (string $model, string $ability, string $role, bool $expected) {
    $user = $role === 'admin' ? admin() : cashier();

    expect(method_exists(Gate::getPolicyFor($model), $ability))->toBeTrue()
        ->and($user->can($ability, policyMatrixSubject($model, $ability)))->toBe($expected);
})->with(policyMatrixDataset());
