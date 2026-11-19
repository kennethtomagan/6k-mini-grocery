<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum StockMovementType: string implements HasLabel
{
    case Initial = 'initial';
    case Restock = 'restock';
    case Sale = 'sale';
    case VoidReturn = 'void_return';
    case Damaged = 'damaged';
    case Expired = 'expired';
    case Lost = 'lost';
    case Correction = 'correction';

    public function getLabel(): string
    {
        return match ($this) {
            self::Initial => 'Opening stock',
            self::Restock => 'Restock',
            self::Sale => 'Sale',
            self::VoidReturn => 'Void return',
            self::Damaged => 'Damaged',
            self::Expired => 'Expired',
            self::Lost => 'Lost / missing',
            self::Correction => 'Stock count correction',
        };
    }

    /** +1 adds stock, -1 removes stock, 0 means "set to a counted quantity". */
    public function sign(): int
    {
        return match ($this) {
            self::Initial, self::Restock, self::VoidReturn => 1,
            self::Sale, self::Damaged, self::Expired, self::Lost => -1,
            self::Correction => 0,
        };
    }

    /** Types an admin may choose by hand. Sale, VoidReturn and Initial are system-only. */
    public function isManual(): bool
    {
        return in_array($this, [self::Restock, self::Damaged, self::Expired, self::Lost, self::Correction], true);
    }

    /** @return array<string, string> */
    public static function manualOptions(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            if ($case->isManual()) {
                $options[$case->value] = $case->getLabel();
            }
        }

        return $options;
    }
}
