<?php

namespace App\Filament\Resources\InventoryMovements;

use App\Enums\StockMovementType;
use App\Filament\Resources\InventoryMovements\Pages\ListInventoryMovements;
use App\Models\InventoryMovement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class InventoryMovementResource extends Resource
{
    protected static ?string $model = InventoryMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Stock Movements';

    protected static ?string $modelLabel = 'stock movement';

    /**
     * Read-only ledger: there are no create, edit or delete pages and no record actions.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['product', 'user', 'transaction']))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('M j, Y g:i A')->sortable(),
                TextColumn::make('product.name')->label('Product')->searchable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('quantity_before')->label('Before')->numeric(),
                TextColumn::make('quantity_change')
                    ->label('Change')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "+{$state}" : (string) $state)
                    ->color(fn (int $state): string => $state < 0 ? 'danger' : 'success')
                    ->weight('bold'),
                TextColumn::make('quantity_after')->label('After')->numeric(),
                TextColumn::make('user.name')->label('By'),
                TextColumn::make('transaction.reference_number')->label('Sale')->placeholder('—')->toggleable(),
                TextColumn::make('reason')->placeholder('—')->wrap()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->options(StockMovementType::class),
                SelectFilter::make('product')->relationship('product', 'name')->searchable()->preload(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventoryMovements::route('/'),
        ];
    }
}
