<?php

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only sales figures. Every method counts completed sales only and reads item snapshots, so
 * later product edits never change past numbers. All amounts are returned as integer centavos.
 *
 * Sales totals (summary, by day/period, by cashier) are net of the sale-level discount: they add up
 * transactions.total. Product and category figures add up item line totals, which are before that discount.
 *
 * Both bounds are inclusive to the second: a sale is counted when created_at >= $from and
 * created_at < the second after $to. Bounds are read in the app timezone, the timezone of the MySQL session.
 */
final class SalesReportService
{
    /** @return array{total_sales_cents: int, transaction_count: int, average_cents: int} */
    public function summary(CarbonInterface $from, CarbonInterface $to): array
    {
        $row = $this->transactions($from, $to)
            ->selectRaw('COALESCE(SUM(total), 0) as total_sales, COUNT(*) as transaction_count')
            ->first();

        $total = Money::toCents($row->total_sales);
        $count = (int) $row->transaction_count;

        return [
            'total_sales_cents' => $total,
            'transaction_count' => $count,
            'average_cents' => $count > 0 ? (int) round($total / $count) : 0,
        ];
    }

    /** @return Collection<int, array{date: string, total_cents: int, transactions: int}> one row per day, zero-filled */
    public function salesByDay(CarbonInterface $from, CarbonInterface $to): Collection
    {
        [$start, $end] = $this->bounds($from, $to);

        $rows = $this->transactions($from, $to)
            ->selectRaw('DATE(created_at) as day, SUM(total) as total, COUNT(*) as transactions')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $days = collect();

        foreach (CarbonPeriod::create($start->toDateString(), '1 day', $end->subSecond()->toDateString()) as $date) {
            $key = $date->toDateString();
            $row = $rows->get($key);

            $days->push([
                'date' => $key,
                'total_cents' => $row ? Money::toCents($row->total) : 0,
                'transactions' => $row ? (int) $row->transactions : 0,
            ]);
        }

        return $days;
    }

    /**
     * Weeks and months without sales are kept as zero rows. A week is labelled by its Monday and a month by
     * its first day, even when the range starts later; an unknown period groups by day. When the range
     * spans more than one calendar year, daily and weekly labels include the year.
     *
     * @param  'day'|'week'|'month'  $period  weeks start on Monday
     * @return Collection<int, array{period: string, label: string, total_cents: int, transactions: int}>
     */
    public function salesByPeriod(CarbonInterface $from, CarbonInterface $to, string $period): Collection
    {
        [$start, $end] = $this->bounds($from, $to);
        $dayFormat = $start->year === $end->subSecond()->year ? 'M j' : 'M j, Y';

        return $this->salesByDay($from, $to)
            ->groupBy(fn (array $day): string => match ($period) {
                'week' => CarbonImmutable::parse($day['date'])->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
                'month' => substr($day['date'], 0, 7),
                default => $day['date'],
            })
            ->map(fn (Collection $days, string $key): array => [
                'period' => $key,
                'label' => match ($period) {
                    'week' => 'Week of '.CarbonImmutable::parse($key)->format($dayFormat),
                    'month' => CarbonImmutable::parse($key.'-01')->format('M Y'),
                    default => CarbonImmutable::parse($key)->format($dayFormat),
                },
                'total_cents' => $days->sum('total_cents'),
                'transactions' => $days->sum('transactions'),
            ])
            ->values();
    }

    /**
     * Catalog items are grouped by product and named from the snapshot on their latest item in the range (the
     * highest item id), so a product renamed within the range shows its newer name. Manual items have no
     * product_id, so they are grouped by the name typed at the till.
     *
     * @return Collection<int, array{product_id: int|null, name: string, is_manual: bool, quantity: int, total_cents: int}>
     */
    public function salesByProduct(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $sold = $this->items($from, $to)
            ->selectRaw('transaction_items.product_id')
            ->selectRaw('CASE WHEN transaction_items.product_id IS NULL THEN transaction_items.product_name END as manual_name')
            ->selectRaw('MAX(transaction_items.id) as latest_item_id')
            ->selectRaw('MAX(transaction_items.is_manual) as is_manual')
            ->selectRaw('SUM(transaction_items.quantity) as quantity')
            ->selectRaw('SUM(transaction_items.line_total) as total')
            ->groupBy('transaction_items.product_id', 'manual_name');

        return DB::query()
            ->fromSub($sold, 'sold')
            ->select('sold.product_id', 'sold.is_manual', 'sold.quantity', 'sold.total')
            ->selectSub(
                DB::table('transaction_items as latest')->select('latest.product_name')->whereColumn('latest.id', 'sold.latest_item_id'),
                'name',
            )
            ->orderByDesc('sold.total')
            ->orderBy('name')
            ->get()
            ->map(fn (object $row): array => [
                'product_id' => $row->product_id === null ? null : (int) $row->product_id,
                'name' => $row->name,
                'is_manual' => (bool) $row->is_manual,
                'quantity' => (int) $row->quantity,
                'total_cents' => Money::toCents($row->total),
            ]);
    }

    /** @return Collection<int, array{product_id: int|null, name: string, is_manual: bool, quantity: int, total_cents: int}> */
    public function bestSellers(CarbonInterface $from, CarbonInterface $to, int $limit = 10): Collection
    {
        return $this->salesByProduct($from, $to)->sortByDesc('quantity')->take($limit)->values();
    }

    /**
     * A product in several categories is counted in each, so the rows can add up to more than total sales.
     * Categories are the product's current categories, not those at the time of sale. Manual items and
     * products without a category are counted under "Uncategorized".
     *
     * @return Collection<int, array{category_id: int|null, category: string, quantity: int, total_cents: int}>
     */
    public function salesByCategory(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $soldPerProduct = $this->items($from, $to)
            ->selectRaw('transaction_items.product_id')
            ->selectRaw('SUM(transaction_items.quantity) as quantity')
            ->selectRaw('SUM(transaction_items.line_total) as total')
            ->groupBy('transaction_items.product_id');

        return DB::query()
            ->fromSub($soldPerProduct, 'sold')
            ->leftJoin('category_product', 'category_product.product_id', '=', 'sold.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'category_product.category_id')
            ->selectRaw("categories.id as category_id, COALESCE(categories.name, 'Uncategorized') as category")
            ->selectRaw('SUM(sold.quantity) as quantity')
            ->selectRaw('SUM(sold.total) as total')
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('total')
            ->orderBy('category')
            ->get()
            ->map(fn (object $row): array => [
                'category_id' => $row->category_id === null ? null : (int) $row->category_id,
                'category' => $row->category,
                'quantity' => (int) $row->quantity,
                'total_cents' => Money::toCents($row->total),
            ]);
    }

    /** @return Collection<int, array{user_id: int, cashier: string, transactions: int, total_cents: int}> */
    public function salesByCashier(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->transactions($from, $to, 'transactions.')
            ->join('users', 'users.id', '=', 'transactions.user_id')
            ->selectRaw('users.id as user_id, users.name as cashier, COUNT(*) as transactions, SUM(transactions.total) as total')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total')
            ->orderBy('cashier')
            ->get()
            ->map(fn (object $row): array => [
                'user_id' => (int) $row->user_id,
                'cashier' => $row->cashier,
                'transactions' => (int) $row->transactions,
                'total_cents' => Money::toCents($row->total),
            ]);
    }

    /**
     * Completed sales in range. The status equality and the created_at range use the (status, created_at) index.
     */
    private function transactions(CarbonInterface $from, CarbonInterface $to, string $prefix = ''): Builder
    {
        [$start, $end] = $this->bounds($from, $to);

        return DB::table('transactions')
            ->where($prefix.'status', TransactionStatus::Completed->value)
            ->where($prefix.'created_at', '>=', $start)
            ->where($prefix.'created_at', '<', $end);
    }

    /**
     * Items of completed sales in range, reached from the transactions (status, created_at) index.
     */
    private function items(CarbonInterface $from, CarbonInterface $to): Builder
    {
        [$start, $end] = $this->bounds($from, $to);

        return DB::table('transactions')
            ->join('transaction_items', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->where('transactions.status', TransactionStatus::Completed->value)
            ->where('transactions.created_at', '>=', $start)
            ->where('transactions.created_at', '<', $end);
    }

    /**
     * Query bindings are written without a timezone, so both bounds are moved into the app timezone first.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} inclusive start, exclusive end (the second after $to)
     */
    private function bounds(CarbonInterface $from, CarbonInterface $to): array
    {
        $timezone = config('app.timezone');

        return [
            CarbonImmutable::instance($from)->setTimezone($timezone)->startOfSecond(),
            CarbonImmutable::instance($to)->setTimezone($timezone)->startOfSecond()->addSecond(),
        ];
    }
}
