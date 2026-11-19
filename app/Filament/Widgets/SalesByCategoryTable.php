<?php

namespace App\Filament\Widgets;

use App\Services\SalesReportService;
use App\Support\ReportRange;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

class SalesByCategoryTable extends TableWidget
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Sales by category')
            ->description('Before sale discounts. A product in several categories is counted in each one.')
            ->records(function (): array {
                [$from, $to] = ReportRange::fromFilters($this->pageFilters);

                return app(SalesReportService::class)->salesByCategory($from, $to)
                    ->map(fn (array $row): array => [...$row, 'total' => $row['total_cents'] / 100])
                    ->all();
            })
            ->columns([
                TextColumn::make('category'),
                TextColumn::make('quantity')->label('Qty sold')->numeric(),
                TextColumn::make('total')->label('Sales')->money('PHP'),
            ])
            ->paginated(false);
    }
}
