<?php

namespace App\Filament\Widgets;

use App\Services\SalesReportService;
use App\Support\ReportRange;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** On the dashboard (no filters) this shows the last 30 days by day; on Sales Reports it follows the filters. */
class SalesTrendChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Sales';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    /**
     * @return array{datasets: array<int, array{label: string, data: array<int, float>}>, labels: array<int, string>}
     */
    protected function getData(): array
    {
        [$from, $to] = ReportRange::fromFilters($this->pageFilters);
        $period = in_array($this->pageFilters['period'] ?? 'day', ['day', 'week', 'month'], true)
            ? ($this->pageFilters['period'] ?? 'day')
            : 'day';

        $rows = app(SalesReportService::class)->salesByPeriod($from, $to, $period);

        return [
            'datasets' => [[
                'label' => 'Sales (₱)',
                'data' => $rows->map(fn (array $row): float => $row['total_cents'] / 100)->all(),
            ]],
            'labels' => $rows->pluck('label')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
