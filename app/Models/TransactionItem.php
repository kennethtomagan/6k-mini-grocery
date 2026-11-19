<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'transaction_id', 'product_id', 'product_name', 'product_sku', 'product_barcode', 'is_manual', 'quantity',
    'original_price', 'unit_price', 'unit_cost', 'line_total', 'price_overridden', 'price_override_user_id',
])]
class TransactionItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_manual' => 'boolean',
            'quantity' => 'integer',
            'original_price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'price_overridden' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function priceOverrideUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'price_override_user_id');
    }
}
