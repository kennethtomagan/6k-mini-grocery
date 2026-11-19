<?php

namespace App\Filament\Widgets;

use App\Services\SalesReportService;
use App\Support\ReportRange;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class BestSellersTable extends TableWidget
{
    public static function canView(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Best sellers (last 30 days)')
            ->description('Before sale discounts.')
            ->records(function (): array {
                [$from, $to] = ReportRange::fromFilters(null);

                return app(SalesReportService::class)->bestSellers($from, $to, 10)
                    ->map(fn (array $row): array => [...$row, 'total' => $row['total_cents'] / 100])
                    ->all();
            })
            ->columns([
                TextColumn::make('name')->label('Product'),
                TextColumn::make('quantity')->label('Qty sold')->numeric(),
                TextColumn::make('total')->label('Sales')->money('PHP'),
            ])
            ->paginated(false);
    }
}
