<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Enums\TransactionStatus;
use App\Exceptions\CheckoutException;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Pos\Cart;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only code that creates sales.
 *
 * Everything happens in one DB transaction: lock the products (ascending id, so two tills can never
 * deadlock), re-check prices and stock against the locked rows, write the transaction and its item
 * snapshots, then deduct stock through InventoryService. Any failure rolls the whole sale back.
 */
final class CheckoutService
{
    /** Largest value of a DECIMAL(10,2) column (item unit prices), in centavos. */
    private const MAX_UNIT_PRICE_CENTS = 9_999_999_999;

    /** Largest value of a DECIMAL(12,2) column (line totals and sale amounts), in centavos. */
    private const MAX_AMOUNT_CENTS = 999_999_999_999;

    /** Length of transaction_items.product_name. */
    private const MAX_NAME_LENGTH = 150;

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Idempotent on $checkoutToken: a double click or a retried request returns the sale that already exists.
     *
     * If two requests with the same token race past the first lookup, the second one either hits the unique
     * index on its insert or, having waited on the first one's row locks, fails a re-check (for example the
     * stock it needed was just sold by the first). Either way its DB transaction rolls back before any stock
     * moves, and the sale the first request committed is returned instead of the error.
     *
     * The lookup is scoped to the cashier, so a token that collides with another cashier's sale never
     * returns that sale: the unique index refuses the insert and the original exception surfaces.
     */
    public function checkout(
        User $cashier,
        Cart $cart,
        int $discountCents,
        int $cashReceivedCents,
        string $checkoutToken,
    ): Transaction {
        if ($existing = $this->findByToken($cashier, $checkoutToken)) {
            return $existing;
        }

        $this->guard($cashier, $cart, $discountCents, $cashReceivedCents);

        try {
            return DB::transaction(
                fn (): Transaction => $this->process($cashier, $cart, $discountCents, $cashReceivedCents, $checkoutToken),
                attempts: 3,
            );
        } catch (CheckoutException|UniqueConstraintViolationException $exception) {
            return $this->findByToken($cashier, $checkoutToken) ?? throw $exception;
        }
    }

    private function findByToken(User $cashier, string $checkoutToken): ?Transaction
    {
        return Transaction::query()
            ->with('items')
            ->where('checkout_token', $checkoutToken)
            ->where('user_id', $cashier->id)
            ->first();
    }

    /**
     * Checks that need no database: permissions (re-checked here, never trusted from the client),
     * line shape and the column ranges, so an oversized amount is refused instead of failing the insert.
     * A deactivated user is refused even when the caller skipped the route middleware; it runs after the
     * token lookup, so a retry of a sale that was already committed still returns it.
     */
    private function guard(User $cashier, Cart $cart, int $discountCents, int $cashReceivedCents): void
    {
        if ($cashier->cannot('access-pos')) {
            throw CheckoutException::notAllowed('make sales');
        }

        if ($cart->isEmpty()) {
            throw CheckoutException::emptyCart();
        }

        if ($discountCents < 0) {
            throw CheckoutException::invalidDiscount();
        }

        if ($discountCents > 0 && $cashier->cannot('apply-discount')) {
            throw CheckoutException::notAllowed('apply discounts');
        }

        if ($cashReceivedCents > self::MAX_AMOUNT_CENTS) {
            throw CheckoutException::amountTooLarge();
        }

        $subtotalCents = 0;

        foreach ($cart->toArray() as $key => $line) {
            if (! $this->isValidLine($key, $line)) {
                throw CheckoutException::invalidLine();
            }

            if ($line['price_overridden'] && $cashier->cannot('override-price')) {
                throw CheckoutException::notAllowed('change prices');
            }

            $subtotalCents += $line['unit_price_cents'] * $line['quantity'];

            if ($subtotalCents > self::MAX_AMOUNT_CENTS) {
                throw CheckoutException::amountTooLarge();
            }
        }
    }

    /**
     * A line must have the exact shape Cart builds, fit the transaction_items columns, and,
     * for a catalog product, sit under its own "p{id}" key so one product cannot appear on two lines.
     * A manual line must sit under a manual key, the "m" plus 8 hex digits that Cart::addManual() builds.
     */
    private function isValidLine(int|string $key, mixed $line): bool
    {
        if (! is_array($line)) {
            return false;
        }

        $quantity = $line['quantity'] ?? null;
        $unitPriceCents = $line['unit_price_cents'] ?? null;
        $name = $line['name'] ?? null;
        $productId = $line['product_id'] ?? null;
        $isManual = $line['is_manual'] ?? null;

        if (! is_int($quantity) || ! is_int($unitPriceCents) || ! is_string($name) || ! is_bool($isManual)
            || ! is_bool($line['price_overridden'] ?? null) || ($line['key'] ?? null) !== $key) {
            return false;
        }

        if ($quantity < 1 || $quantity > Cart::MAX_QUANTITY
            || $unitPriceCents < 0 || $unitPriceCents > self::MAX_UNIT_PRICE_CENTS
            || $unitPriceCents * $quantity > self::MAX_AMOUNT_CENTS) {
            return false;
        }

        $trimmedName = trim($name);

        if ($trimmedName === '' || mb_strlen($trimmedName) > self::MAX_NAME_LENGTH) {
            return false;
        }

        if ($isManual) {
            return $productId === null && is_string($key) && preg_match('/^m[0-9a-f]{8}$/', $key) === 1;
        }

        return is_int($productId) && $key === 'p'.$productId;
    }

    private function process(
        User $cashier,
        Cart $cart,
        int $discountCents,
        int $cashReceivedCents,
        string $checkoutToken,
    ): Transaction {
        $allowNegative = StoreSetting::current()->allow_negative_stock;

        $products = Product::query()
            ->whereIn('id', $cart->productIds())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $unavailable = [];
        $priceChanges = [];
        $shortages = [];

        foreach ($cart->lines() as $line) {
            if ($line['is_manual']) {
                continue;
            }

            $product = $products->get($line['product_id']);

            if ($product === null || ! $product->is_active) {
                $unavailable[] = $line['name'];

                continue;
            }

            $catalogCents = $product->sellingPriceCents();

            if (! $line['price_overridden'] && $line['unit_price_cents'] !== $catalogCents) {
                $priceChanges[$line['key']] = $catalogCents;
            }

            if (! $allowNegative && $product->stock_quantity < $line['quantity']) {
                $shortages[] = [
                    'name' => $product->name,
                    'requested' => $line['quantity'],
                    'available' => max(0, $product->stock_quantity),
                ];
            }
        }

        if ($unavailable !== []) {
            throw CheckoutException::productUnavailable($unavailable);
        }

        if ($priceChanges !== []) {
            throw CheckoutException::priceChanged($priceChanges);
        }

        if ($shortages !== []) {
            throw CheckoutException::insufficientStock($shortages);
        }

        $subtotalCents = $cart->subtotalCents();

        if ($discountCents > $subtotalCents) {
            throw CheckoutException::invalidDiscount();
        }

        $totalCents = $subtotalCents - $discountCents;

        if ($cashReceivedCents < $totalCents) {
            throw CheckoutException::insufficientPayment($totalCents, $cashReceivedCents);
        }

        $transaction = Transaction::create([
            'checkout_token' => $checkoutToken,
            'user_id' => $cashier->id,
            'status' => TransactionStatus::Completed,
            'subtotal' => Money::fromCents($subtotalCents),
            'discount_amount' => Money::fromCents($discountCents),
            'total' => Money::fromCents($totalCents),
            'cash_received' => Money::fromCents($cashReceivedCents),
            'change_amount' => Money::fromCents($cashReceivedCents - $totalCents),
            'item_count' => $cart->itemCount(),
        ]);

        $transaction->update([
            'reference_number' => sprintf('%s-%06d', $transaction->created_at->format('Ymd'), $transaction->id),
        ]);

        foreach ($cart->lines() as $line) {
            $product = $line['is_manual'] ? null : $products->get($line['product_id']);
            $originalCents = $product ? $product->sellingPriceCents() : $line['unit_price_cents'];
            $overridden = $product !== null && $line['unit_price_cents'] !== $originalCents;

            $transaction->items()->create([
                'product_id' => $product?->id,
                'product_name' => $product ? $product->name : trim($line['name']),
                'product_sku' => $product?->sku,
                'product_barcode' => $product?->barcode,
                'is_manual' => $product === null,
                'quantity' => $line['quantity'],
                'original_price' => Money::fromCents($originalCents),
                'unit_price' => Money::fromCents($line['unit_price_cents']),
                'unit_cost' => $product?->cost_price,
                'line_total' => Money::fromCents($line['unit_price_cents'] * $line['quantity']),
                'price_overridden' => $overridden,
                'price_override_user_id' => $overridden ? $cashier->id : null,
            ]);

            if ($product !== null) {
                $this->inventory->adjust(
                    $product,
                    -$line['quantity'],
                    StockMovementType::Sale,
                    $cashier,
                    transaction: $transaction,
                    allowNegative: $allowNegative,
                );
            }
        }

        return $transaction->load('items');
    }
}
