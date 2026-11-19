<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TransactionStatus: string implements HasColor, HasLabel
{
    case Completed = 'completed';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::Voided => 'Voided',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Voided => 'danger',
        };
    }
}
