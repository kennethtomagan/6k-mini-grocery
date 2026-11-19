<?php

use App\Enums\StockMovementType;
use App\Enums\TransactionStatus;
use App\Enums\UserRole;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

it('gives each stock movement type its stock direction', function (StockMovementType $type, int $expected) {
    expect($type->sign())->toBe($expected);
})->with([
    [StockMovementType::Initial, 1],
    [StockMovementType::Restock, 1],
    [StockMovementType::VoidReturn, 1],
    [StockMovementType::Sale, -1],
    [StockMovementType::Damaged, -1],
    [StockMovementType::Expired, -1],
    [StockMovementType::Lost, -1],
    [StockMovementType::Correction, 0],
]);

it('flags only admin-selectable types as manual', function () {
    $manual = array_filter(StockMovementType::cases(), fn (StockMovementType $type) => $type->isManual());

    expect(array_map(fn (StockMovementType $type) => $type->value, array_values($manual)))
        ->toBe(['restock', 'damaged', 'expired', 'lost', 'correction']);
});

it('lists manual options keyed by value in order', function () {
    expect(StockMovementType::manualOptions())->toBe([
        'restock' => 'Restock',
        'damaged' => 'Damaged',
        'expired' => 'Expired',
        'lost' => 'Lost / missing',
        'correction' => 'Stock count correction',
    ]);
});

it('exposes user role values and labels', function () {
    expect(UserRole::from('admin'))->toBe(UserRole::Admin)
        ->and(UserRole::Cashier->value)->toBe('cashier')
        ->and(UserRole::Admin->getLabel())->toBe('Admin')
        ->and(UserRole::Cashier->getLabel())->toBe('Cashier')
        ->and(UserRole::Admin)->toBeInstanceOf(HasLabel::class);
});

it('exposes transaction status labels and colors', function () {
    expect(TransactionStatus::Completed->getLabel())->toBe('Completed')
        ->and(TransactionStatus::Voided->getLabel())->toBe('Voided')
        ->and(TransactionStatus::Completed->getColor())->toBe('success')
        ->and(TransactionStatus::Voided->getColor())->toBe('danger')
        ->and(TransactionStatus::Completed)->toBeInstanceOf(HasColor::class)
        ->and(TransactionStatus::Completed)->toBeInstanceOf(HasLabel::class);
});
