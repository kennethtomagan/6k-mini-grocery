<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only code allowed to change products.stock_quantity.
 * Every change locks the product row and writes one inventory_movements row in the same DB transaction.
 */
final class InventoryService
{
    public function adjust(
        Product $product,
        int $change,
        StockMovementType $type,
        User $user,
        ?string $reason = null,
        ?Transaction $transaction = null,
        bool $allowNegative = false,
    ): InventoryMovement {
        if ($change === 0 && $type !== StockMovementType::Correction) {
            throw new InvalidArgumentException('Stock change cannot be zero.');
        }

        return DB::transaction(function () use ($product, $change, $type, $user, $reason, $transaction, $allowNegative) {
            $locked = Product::withTrashed()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            $before = $locked->stock_quantity;
            $after = $before + $change;

            if ($this->exceedsColumnRange($change) || $this->exceedsColumnRange($after)) {
                throw new InvalidArgumentException('Stock change is outside the supported quantity range.');
            }

            if ($change < 0 && $after < 0 && ! $allowNegative) {
                throw new InsufficientStockException($locked->name, max(0, $before), abs($change));
            }

            $locked->stock_quantity = $after;
            $locked->save();

            $product->setAttribute('stock_quantity', $after);
            $product->syncOriginalAttribute('stock_quantity');

            return InventoryMovement::create([
                'product_id' => $locked->id,
                'user_id' => $user->id,
                'transaction_id' => $transaction?->id,
                'type' => $type,
                'quantity_before' => $before,
                'quantity_change' => $change,
                'quantity_after' => $after,
                'reason' => $reason,
            ]);
        });
    }

    /**
     * A hand-entered adjustment from the admin panel.
     * For Correction, $quantity is the counted quantity on the shelf; otherwise it is the amount to add or remove.
     * A correction to the quantity already on hand records a zero-change movement as proof of the count.
     */
    public function applyManual(
        Product $product,
        StockMovementType $type,
        int $quantity,
        User $user,
        ?string $reason = null,
    ): InventoryMovement {
        if (! $type->isManual()) {
            throw new InvalidArgumentException("{$type->getLabel()} movements cannot be entered by hand.");
        }

        if ($quantity < 0 || ($quantity === 0 && $type !== StockMovementType::Correction)) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($product, $type, $quantity, $user, $reason) {
            $current = Product::withTrashed()->whereKey($product->getKey())->lockForUpdate()->firstOrFail()->stock_quantity;

            $change = $type === StockMovementType::Correction
                ? $quantity - $current
                : $type->sign() * $quantity;

            return $this->adjust($product, $change, $type, $user, $reason);
        });
    }

    /**
     * Whether a quantity falls outside the signed 32-bit integer range of the stock columns.
     */
    private function exceedsColumnRange(int $quantity): bool
    {
        return $quantity > 2147483647 || $quantity < -2147483648;
    }
}
