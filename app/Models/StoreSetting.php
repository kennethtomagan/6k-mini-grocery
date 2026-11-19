<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_name', 'store_address', 'store_phone', 'receipt_footer', 'receipt_paper_width', 'allow_negative_stock'])]
class StoreSetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'receipt_paper_width' => 'integer',
            'allow_negative_stock' => 'boolean',
        ];
    }

    /** The single settings row (lowest id). The migration inserts it; the fallback recreates it, with the migration's default footer, if the table was truncated. */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? self::query()->create(['receipt_footer' => 'Salamat po! Balik kayo.'])->refresh();
    }
}
