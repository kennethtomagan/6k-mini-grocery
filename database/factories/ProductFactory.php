<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Factories bypass $fillable, so stock_quantity can be set here; app code must use InventoryService.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(3, true)),
            'sku' => null,
            'barcode' => fake()->unique()->ean13(),
            'selling_price' => fake()->randomFloat(2, 5, 200),
            'cost_price' => null,
            'stock_quantity' => 50,
            'low_stock_threshold' => 5,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the product is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
