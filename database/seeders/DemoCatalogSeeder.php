<?php

namespace Database\Seeders;

use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

/**
 * Demo catalog for a sari-sari store: 14 products, two of them at or below their low-stock threshold.
 *
 * Run by hand with `php artisan db:seed --class=DemoCatalogSeeder`; it is never called from DatabaseSeeder.
 * Re-running is safe. Products are looked up with trashed ones included, so a soft-deleted demo product is
 * skipped instead of being duplicated or hitting the unique barcode index, and it is not restored. Opening
 * stock is recorded only for products created by the current run, through InventoryService.
 */
class DemoCatalogSeeder extends Seeder
{
    public function run(InventoryService $inventory): void
    {
        $admin = User::query()->where('role', UserRole::Admin)->where('is_active', true)->firstOrFail();

        $catalog = [
            'Drinks' => [
                ['Coke Mismo 295ml', '4801981118502', 20.00, 16.50, 48],
                ['Royal Mismo 295ml', '4801981118519', 20.00, 16.50, 24],
                ['C2 Apple 230ml', '4800016052019', 18.00, 14.00, 24],
                ['Nature\'s Spring 500ml', '4800049720015', 15.00, 10.00, 36],
            ],
            'Snacks' => [
                ['Skyflakes Crackers 25g', '4800016644801', 8.00, 6.50, 60],
                ['Piattos Cheese 40g', '4800016022012', 20.00, 16.00, 30],
                ['Boy Bawang Garlic 100g', '4800194111157', 25.00, 20.00, 4],
            ],
            'Noodles & Canned' => [
                ['Lucky Me Pancit Canton Original', '4807770270017', 18.00, 14.50, 72],
                ['Century Tuna Flakes in Oil 155g', '0748485800017', 42.00, 36.00, 24],
                ['Argentina Corned Beef 150g', '4800249005011', 45.00, 39.00, 3],
            ],
            'Household' => [
                ['Surf Powder Sachet 65g', '4800888141026', 12.00, 9.50, 50],
                ['Safeguard Bar 60g', '4902430453318', 28.00, 23.00, 20],
            ],
        ];

        foreach ($catalog as $categoryName => $products) {
            $category = Category::query()->firstOrCreate(['name' => $categoryName]);

            foreach ($products as [$name, $barcode, $price, $cost, $stock]) {
                $product = Product::withTrashed()->firstOrCreate(
                    ['barcode' => $barcode],
                    ['name' => $name, 'selling_price' => $price, 'cost_price' => $cost, 'low_stock_threshold' => 5, 'is_active' => true],
                );

                if ($product->trashed()) {
                    continue;
                }

                $product->categories()->syncWithoutDetaching([$category->id]);

                $this->recordOpeningStock($inventory, $product, $stock, $admin);
            }
        }

        /** Products without a barcode or category are valid too. */
        foreach ([['Ice candy', 5.00, 40], ['Yelo (ice, per plastic)', 5.00, 30]] as [$name, $price, $stock]) {
            $product = Product::withTrashed()->firstOrCreate(
                ['name' => $name],
                ['selling_price' => $price, 'low_stock_threshold' => 5, 'is_active' => true],
            );

            if ($product->trashed()) {
                continue;
            }

            $this->recordOpeningStock($inventory, $product, $stock, $admin);
        }
    }

    /**
     * Only a product created in this run gets opening stock, and only when the stock is above zero, because
     * InventoryService::adjust() rejects a zero change.
     */
    private function recordOpeningStock(InventoryService $inventory, Product $product, int $stock, User $admin): void
    {
        if ($product->wasRecentlyCreated && $stock > 0) {
            $inventory->adjust($product, $stock, StockMovementType::Initial, $admin, 'Demo opening stock');
        }
    }
}
