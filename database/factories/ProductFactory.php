<?php

namespace Database\Factories;

use App\Models\Category;
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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Portable Laptop', 'Wireless Headphones', 'Gaming Keyboard', 'Office Monitor']),
            'description' => fake()->sentence(12),
            'price' => fake()->randomFloat(2, 1, 5000),
            'category_id' => Category::factory(),
            'available' => true,
        ];
    }

    public function unavailable(): static
    {
        return $this->state(fn (array $attributes): array => ['available' => false]);
    }
}
