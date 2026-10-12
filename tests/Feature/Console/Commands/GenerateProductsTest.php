<?php

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

pest()->use(LazilyRefreshDatabase::class);

test('generates the exact requested count across partial batches and indexes bulk inserts', function () {
    $existing = Product::factory()->create(['name' => 'Desk Chair', 'description' => 'Ergonomic seating']);

    $this->artisan('products:generate', ['--count' => 17, '--batch' => 3])
        ->expectsOutputToContain('Generated 17 products')
        ->assertSuccessful();

    $this->assertDatabaseCount('products', 18);
    $this->assertModelExists($existing);
    expect(Category::query()->where('name', 'like', 'Benchmark %')->count())->toBe(5);
    $this->getJson(route('products.search', ['q' => 'laptop']))->assertOk()->assertJsonPath('meta.total', 7);
});

test('rejects invalid generator bounds without appending any products', function (array $options) {
    $this->artisan('products:generate', $options)->assertFailed();

    $this->assertDatabaseCount('products', 0);
    $this->assertDatabaseCount('categories', 0);
})->with([
    'zero count' => [['--count' => 0]],
    'negative count' => [['--count' => -1]],
    'fractional count' => [['--count' => '1.5']],
    'nonnumeric count' => [['--count' => 'many']],
    'excessive count' => [['--count' => 10000001]],
    'zero batch' => [['--batch' => 0]],
    'excessive batch' => [['--batch' => 1001]],
]);

test('requires explicit force before adding benchmark data in production', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->artisan('products:generate', ['--count' => 1])
        ->expectsConfirmation('Are you sure you want to run this command?', false)
        ->assertFailed();

    $this->assertDatabaseCount('products', 0);
});

test('supports the production force option and reuses benchmark categories on repeated runs', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->artisan('products:generate', ['--count' => 2, '--force' => true])->assertSuccessful();
    $this->artisan('products:generate', ['--count' => 3, '--force' => true])->assertSuccessful();

    $this->assertDatabaseCount('products', 5);
    $this->assertDatabaseCount('categories', 5);
});

test('provides a small optional demo seeder with valid related categories', function () {
    $this->seed(ProductSeeder::class);

    $this->assertDatabaseCount('products', 100);
    $this->assertDatabaseCount('categories', 5);
    $this->getJson(route('products.search'))->assertOk()->assertJsonPath('meta.total', 100);
});
