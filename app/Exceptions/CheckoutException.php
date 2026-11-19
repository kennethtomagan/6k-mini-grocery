<?php

namespace App\Exceptions;

use App\Support\Money;
use RuntimeException;

/**
 * A sale was refused for a reason the cashier can read and act on.
 */
class CheckoutException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function emptyCart(): self
    {
        return new self('empty_cart', 'The cart is empty.');
    }

    public static function invalidLine(): self
    {
        return new self('invalid_line', 'The cart has an invalid quantity or price. Remove the item and add it again.');
    }

    public static function amountTooLarge(): self
    {
        return new self('amount_too_large', 'The sale total or cash received is too large. Check the quantities and amounts.');
    }

    public static function notAllowed(string $what): self
    {
        return new self('not_allowed', "You are not allowed to {$what}.");
    }

    public static function invalidDiscount(): self
    {
        return new self('invalid_discount', 'Discount cannot be more than the subtotal.');
    }

    /** @param list<string> $names */
    public static function productUnavailable(array $names): self
    {
        return new self(
            'product_unavailable',
            'No longer available: '.implode(', ', $names).'. Remove it from the cart.',
            ['names' => $names],
        );
    }

    /** @param array<string, int> $prices cart line key => current catalog price in centavos */
    public static function priceChanged(array $prices): self
    {
        return new self(
            'price_changed',
            'Some prices have changed. The cart was updated — please check the total and try again.',
            ['prices' => $prices],
        );
    }

    /** @param list<array{name: string, requested: int, available: int}> $shortages */
    public static function insufficientStock(array $shortages): self
    {
        $parts = array_map(
            fn (array $shortage): string => "{$shortage['name']} (only {$shortage['available']} left)",
            $shortages,
        );

        return new self('insufficient_stock', 'Not enough stock: '.implode(', ', $parts).'.', ['shortages' => $shortages]);
    }

    public static function insufficientPayment(int $totalCents, int $cashCents): self
    {
        return new self(
            'insufficient_payment',
            'Cash received is less than the total ('.Money::format($totalCents).').',
            ['total_cents' => $totalCents, 'cash_cents' => $cashCents],
        );
    }
}
