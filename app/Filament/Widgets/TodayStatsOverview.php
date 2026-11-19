<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Services\SalesReportService;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Today's figures for the dashboard. "Today's sales" is net of discounts, the same figure as the
 * Sales Reports total for today.
 */
class TodayStatsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $today = app(SalesReportService::class)->summary(now()->startOfDay(), now()->endOfDay());
        $lowStock = Product::query()->active()->lowStock()->count();

        return [
            Stat::make("Today's sales", Money::format($today['total_sales_cents'])),
            Stat::make('Transactions today', number_format($today['transaction_count'])),
            Stat::make('Average sale', Money::format($today['average_cents'])),
            Stat::make('Products', number_format(Product::query()->count())),
            Stat::make('Low stock', number_format($lowStock))
                ->color($lowStock > 0 ? 'danger' : 'success')
                ->url(ProductResource::getUrl('index', ['filters' => ['low_stock' => ['isActive' => true]]])),
        ];
    }
}
