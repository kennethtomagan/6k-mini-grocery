<?php

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->cashier = User::factory()->cashier()->create();

    $this->makeTransaction = fn (TransactionStatus $status): Transaction => Transaction::query()->create([
        'checkout_token' => (string) Str::uuid(),
        'user_id' => $this->cashier->id,
        'status' => $status,
        'subtotal' => '20.00',
        'total' => '20.00',
        'cash_received' => '20.00',
        'change_amount' => '0.00',
        'item_count' => 1,
    ]);
});

it('reports whether a transaction is voided', function () {
    expect(($this->makeTransaction)(TransactionStatus::Completed)->isVoided())->toBeFalse()
        ->and(($this->makeTransaction)(TransactionStatus::Voided)->isVoided())->toBeTrue();
});

it('resolves the cashier who made the sale', function () {
    expect(($this->makeTransaction)(TransactionStatus::Completed)->cashier->is($this->cashier))->toBeTrue();
});
