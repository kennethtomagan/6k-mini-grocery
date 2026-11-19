<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ReportSummaryStats;
use App\Filament\Widgets\SalesByCashierTable;
use App\Filament\Widgets\SalesByCategoryTable;
use App\Filament\Widgets\SalesByProductTable;
use App\Filament\Widgets\SalesTrendChart;
use App\Support\ReportRange;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Filter state comes from the URL, the session and Livewire requests and is never validated. Before Filament
 * fills the form or hands the filters to the widgets, a date that is not a real Y-m-d date becomes empty, an
 * unknown grouping becomes daily and unknown keys are dropped. Only public Livewire hooks are used.
 */
class SalesReports extends BaseDashboard
{
    use HasFiltersForm;

    /**
     * @var array<string, string>
     */
    public const PERIODS = ['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly'];

    protected static string $routePath = 'sales-reports';

    protected static ?string $title = 'Sales Reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    public function mount(): void
    {
        $this->filters = self::repairedFilters($this->filters);
    }

    public function updated(string $property): void
    {
        if ($property === 'filters' || str_starts_with($property, 'filters.')) {
            $this->filters = self::repairedFilters($this->filters);
        }
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->columnSpanFull()->schema([
                DatePicker::make('from')->default(now()->subDays(29)->toDateString())->maxDate(now()),
                DatePicker::make('to')->default(now()->toDateString())->maxDate(now()),
                Select::make('period')
                    ->label('Group chart by')
                    ->options(self::PERIODS)
                    ->default('day')
                    ->selectablePlaceholder(false),
            ]),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            ReportSummaryStats::class,
            SalesTrendChart::class,
            SalesByProductTable::class,
            SalesByCategoryTable::class,
            SalesByCashierTable::class,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $filters
     * @return array{from?: string|null, to?: string|null, period?: string}|null
     */
    private static function repairedFilters(?array $filters): ?array
    {
        if ($filters === null) {
            return null;
        }

        $repaired = [];

        foreach (['from', 'to'] as $key) {
            if (array_key_exists($key, $filters)) {
                $repaired[$key] = ReportRange::parseDate($filters[$key])?->toDateString();
            }
        }

        if (array_key_exists('period', $filters)) {
            $period = $filters['period'];
            $repaired['period'] = is_string($period) && array_key_exists($period, self::PERIODS) ? $period : 'day';
        }

        return $repaired;
    }
}
