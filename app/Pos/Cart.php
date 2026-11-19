<?php

namespace App\Pos;

use App\Models\Product;

/**
 * The POS cart. Pure PHP: no database, no session, no Livewire.
 * Lines are keyed "p{productId}" for catalog products and "m{random}" for manual items.
 * All prices are integer centavos.
 *
 * @phpstan-type CartLine array{
 *     key: string,
 *     product_id: int|null,
 *     name: string,
 *     unit_price_cents: int,
 *     original_price_cents: int,
 *     quantity: int,
 *     is_manual: bool,
 *     price_overridden: bool
 * }
 */
final class Cart
{
    /** The largest quantity one cart line may hold. The POS quantity box and CheckoutService enforce it. */
    public const MAX_QUANTITY = 9999;

    /** @param array<string, CartLine> $lines */
    public function __construct(private array $lines = []) {}

    /** @param array<string, CartLine> $lines */
    public static function fromArray(array $lines): self
    {
        return new self($lines);
    }

    /** @return array<string, CartLine> */
    public function toArray(): array
    {
        return $this->lines;
    }

    /** @return list<CartLine> */
    public function lines(): array
    {
        return array_values($this->lines);
    }

    /** @return CartLine|null */
    public function line(string $key): ?array
    {
        return $this->lines[$key] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function addProduct(Product $product, int $quantity = 1): string
    {
        $key = 'p'.$product->id;

        if (isset($this->lines[$key])) {
            $this->lines[$key]['quantity'] += $quantity;

            return $key;
        }

        $cents = $product->sellingPriceCents();

        $this->lines[$key] = [
            'key' => $key,
            'product_id' => $product->id,
            'name' => $product->name,
            'unit_price_cents' => $cents,
            'original_price_cents' => $cents,
            'quantity' => $quantity,
            'is_manual' => false,
            'price_overridden' => false,
        ];

        return $key;
    }

    public function addManual(string $name, int $quantity, int $unitPriceCents): string
    {
        $key = 'm'.bin2hex(random_bytes(4));

        $this->lines[$key] = [
            'key' => $key,
            'product_id' => null,
            'name' => $name,
            'unit_price_cents' => $unitPriceCents,
            'original_price_cents' => $unitPriceCents,
            'quantity' => $quantity,
            'is_manual' => true,
            'price_overridden' => false,
        ];

        return $key;
    }

    public function setQuantity(string $key, int $quantity): void
    {
        if (! isset($this->lines[$key])) {
            return;
        }

        if ($quantity <= 0) {
            unset($this->lines[$key]);

            return;
        }

        $this->lines[$key]['quantity'] = $quantity;
    }

    public function increment(string $key): void
    {
        $this->setQuantity($key, ($this->lines[$key]['quantity'] ?? 0) + 1);
    }

    public function decrement(string $key): void
    {
        $this->setQuantity($key, ($this->lines[$key]['quantity'] ?? 0) - 1);
    }

    public function remove(string $key): void
    {
        unset($this->lines[$key]);
    }

    public function overridePrice(string $key, int $unitPriceCents): void
    {
        if (! isset($this->lines[$key]) || $this->lines[$key]['is_manual']) {
            return;
        }

        $this->lines[$key]['unit_price_cents'] = $unitPriceCents;
        $this->lines[$key]['price_overridden'] = $unitPriceCents !== $this->lines[$key]['original_price_cents'];
    }

    public function resetPrice(string $key): void
    {
        if (isset($this->lines[$key])) {
            $this->overridePrice($key, $this->lines[$key]['original_price_cents']);
        }
    }

    /**
     * The catalog price changed since the line was added. An active override keeps its charged price.
     */
    public function syncPrice(string $key, int $catalogPriceCents): void
    {
        if (! isset($this->lines[$key])) {
            return;
        }

        $this->lines[$key]['original_price_cents'] = $catalogPriceCents;

        if (! $this->lines[$key]['price_overridden']) {
            $this->lines[$key]['unit_price_cents'] = $catalogPriceCents;
        }
    }

    public function quantityOf(int $productId): int
    {
        return $this->lines['p'.$productId]['quantity'] ?? 0;
    }

    /** @return list<int> */
    public function productIds(): array
    {
        return array_values(array_filter(array_column($this->lines, 'product_id')));
    }

    public function subtotalCents(): int
    {
        $sum = 0;

        foreach ($this->lines as $line) {
            $sum += $line['unit_price_cents'] * $line['quantity'];
        }

        return $sum;
    }

    public function itemCount(): int
    {
        return array_sum(array_column($this->lines, 'quantity'));
    }
}
