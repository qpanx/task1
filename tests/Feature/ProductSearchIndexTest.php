<?php

use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->use(LazilyRefreshDatabase::class);

test('keeps search synchronized after bulk updates that bypass model events', function () {
    $product = Product::factory()->create(['name' => 'Laptop', 'description' => 'Portable computer']);

    DB::table('products')->where('id', $product->id)->update(['name' => 'Travel Workstation', 'description' => 'Wireless tablet']);

    $this->getJson(route('products.search', ['q' => 'laptop']))->assertOk()->assertJsonPath('data', []);
    $this->getJson(route('products.search', ['q' => 'wireless tablet']))->assertOk()->assertJsonPath('data.*.id', [$product->id]);
});

test('removes deleted products from the search index', function () {
    $product = Product::factory()->create(['name' => 'Laptop', 'description' => 'Portable computer']);

    DB::table('products')->where('id', $product->id)->delete();

    $this->getJson(route('products.search', ['q' => 'laptop']))->assertOk()->assertJsonPath('meta.total', 0);
    expect(DB::select('SELECT rowid FROM products_fts WHERE products_fts MATCH ?', ['"laptop"']))->toBe([]);
});

test('can roll back and rebuild the text index without losing existing products', function () {
    Product::query()->count();
    $migration = require database_path('migrations/2026_10_12_083811_create_product_search_index.php');
    $migration->down();
    $product = Product::factory()->create(['name' => 'Laptop', 'description' => 'Portable computer']);

    $migration->up();

    $this->getJson(route('products.search', ['q' => 'laptop']))->assertOk()->assertJsonPath('data.*.id', [$product->id]);
    $this->assertModelExists($product);
});

test('rolls back search index changes together with the product transaction', function () {
    DB::beginTransaction();
    Product::factory()->create(['name' => 'Laptop', 'description' => 'Portable computer']);
    DB::rollBack();

    $this->getJson(route('products.search', ['q' => 'laptop']))->assertOk()->assertJsonPath('meta.total', 0);
    expect(DB::select('SELECT rowid FROM products_fts WHERE products_fts MATCH ?', ['"laptop"']))->toBe([]);
});
