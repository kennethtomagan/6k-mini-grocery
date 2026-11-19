<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BestSellersTable;
use App\Filament\Widgets\LowStockTable;
use App\Filament\Widgets\SalesTrendChart;
use App\Filament\Widgets\TodayStatsOverview;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;

/**
 * Everyone who can open the panel lands here; the figures widgets hide themselves from cashiers.
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            AccountWidget::class,
            TodayStatsOverview::class,
            SalesTrendChart::class,
            BestSellersTable::class,
            LowStockTable::class,
        ];
    }
}
