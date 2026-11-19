<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LowStockTable extends TableWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Low stock')
            ->query(Product::query()->active()->lowStock())
            ->columns([
                TextColumn::make('name')->label('Product'),
                TextColumn::make('stock_quantity')->label('In stock')->numeric()->badge()->color('danger'),
                TextColumn::make('low_stock_threshold')->label('Warn at')->numeric(),
            ])
            ->defaultSort('stock_quantity')
            ->paginated([5, 10, 25])
            ->emptyStateHeading('Nothing is running low');
    }
}
