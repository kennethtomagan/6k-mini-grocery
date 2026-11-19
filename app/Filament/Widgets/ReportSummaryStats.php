<?php

namespace App\Filament\Widgets;

use App\Services\SalesReportService;
use App\Support\Money;
use App\Support\ReportRange;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReportSummaryStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        [$from, $to] = ReportRange::fromFilters($this->pageFilters);
        $summary = app(SalesReportService::class)->summary($from, $to);

        return [
            Stat::make('Total sales', Money::format($summary['total_sales_cents']))
                ->description($from->format('M j, Y').' – '.$to->format('M j, Y')),
            Stat::make('Transactions', number_format($summary['transaction_count'])),
            Stat::make('Average sale', Money::format($summary['average_cents'])),
        ];
    }
}
