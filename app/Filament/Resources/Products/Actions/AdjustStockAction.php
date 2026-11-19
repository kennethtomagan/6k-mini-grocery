<?php

namespace App\Filament\Resources\Products\Actions;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Services\InventoryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;

class AdjustStockAction
{
    public const MAX_QUANTITY = 1000000;

    /**
     * Admin-only manual stock change. Livewire skips TrimStrings, so the reason is trimmed here,
     * and the type is limited to the manual options so a tampered system-only type is rejected.
     */
    public static function make(): Action
    {
        return Action::make('adjustStock')
            ->label('Adjust stock')
            ->icon(Heroicon::OutlinedArrowsUpDown)
            ->color('gray')
            ->visible(fn (Product $record): bool => ! $record->trashed() && auth()->user()->can('adjustStock', $record))
            ->authorize(fn (Product $record): bool => auth()->user()->can('adjustStock', $record))
            ->modalHeading(fn (Product $record): string => "Adjust stock — {$record->name}")
            ->modalDescription(fn (Product $record): string => "Current stock: {$record->stock_quantity}")
            ->modalSubmitActionLabel('Save adjustment')
            ->schema([
                Select::make('type')
                    ->label('What happened?')
                    ->options(StockMovementType::manualOptions())
                    ->default(StockMovementType::Restock->value)
                    ->required()
                    ->in(array_keys(StockMovementType::manualOptions()))
                    ->live(),
                TextInput::make('quantity')
                    ->label(fn (Get $get): string => $get('type') === StockMovementType::Correction->value
                        ? 'Counted quantity on the shelf'
                        : 'Quantity')
                    ->required()
                    ->integer()
                    ->minValue(fn (Get $get): int => $get('type') === StockMovementType::Correction->value ? 0 : 1)
                    ->maxValue(self::MAX_QUANTITY),
                Textarea::make('reason')
                    ->label('Reason / notes')
                    ->rows(2)
                    ->trim()
                    ->string()
                    ->maxLength(255)
                    ->required(fn (Get $get): bool => $get('type') !== StockMovementType::Restock->value),
            ])
            ->action(function (Product $record, array $data): void {
                abort_unless(auth()->user()->can('adjustStock', $record), 403);

                try {
                    $movement = app(InventoryService::class)->applyManual(
                        $record,
                        StockMovementType::from($data['type']),
                        (int) $data['quantity'],
                        auth()->user(),
                        filled($data['reason'] ?? null) ? $data['reason'] : null,
                    );
                } catch (InsufficientStockException|InvalidArgumentException $exception) {
                    Notification::make()->danger()->title('Stock was not changed')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title("Stock is now {$movement->quantity_after}")->send();
            });
    }
}
