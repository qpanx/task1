<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public const array NAMES = ['Computers', 'Audio', 'Accessories', 'Home', 'Outdoors'];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::NAMES as $name) {
            Category::query()->firstOrCreate(['name' => $name]);
        }
    }
}
