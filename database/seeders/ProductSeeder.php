<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call(CategorySeeder::class);

        Product::factory()->count(100)
            ->recycle(Category::query()->whereIn('name', CategorySeeder::NAMES)->get())
            ->create();
    }
}
