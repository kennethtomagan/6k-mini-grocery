<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Enums\TransactionStatus;
use App\Exceptions\TransactionAlreadyVoidedException;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TransactionService
{
    /**
     * The length of the transactions.void_reason column.
     */
    public const MAX_REASON_LENGTH = 255;

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Voids a completed sale. The transaction and its items are kept exactly as they were; stock comes
     * back through new "void_return" movements linked to the transaction.
     *
     * The sale row is locked and re-read before anything else, so a second or racing void sees the first
     * one's result and is refused. Products are then locked one by one in ascending id order, the same
     * order CheckoutService uses, so a void and a checkout cannot deadlock each other.
     *
     * @throws InvalidArgumentException when the reason is empty or too long, or a restored quantity is out of range
     * @throws TransactionAlreadyVoidedException
     * @throws AuthorizationException
     */
    public function void(Transaction $transaction, User $user, string $reason): Transaction
    {
        $reason = Str::trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to void a sale.');
        }

        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException('The reason may not be longer than '.self::MAX_REASON_LENGTH.' characters.');
        }

        return DB::transaction(function () use ($transaction, $user, $reason): Transaction {
            $locked = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isVoided()) {
                throw new TransactionAlreadyVoidedException($locked->reference_number);
            }

            if ($user->cannot('void', $locked)) {
                throw new AuthorizationException('You are not allowed to void sales.');
            }

            $items = $locked->items()->whereNotNull('product_id')->orderBy('product_id')->get();

            foreach ($items as $item) {
                $product = Product::withTrashed()->find($item->product_id);

                if ($product !== null) {
                    $this->inventory->adjust(
                        $product,
                        $item->quantity,
                        StockMovementType::VoidReturn,
                        $user,
                        "Void of sale {$locked->reference_number}",
                        $locked,
                    );
                }
            }

            $locked->update([
                'status' => TransactionStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $user->id,
                'void_reason' => $reason,
            ]);

            return $locked->load('items');
        }, attempts: 3);
    }
}
