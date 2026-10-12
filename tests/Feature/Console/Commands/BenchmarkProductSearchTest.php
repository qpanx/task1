<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;

pest()->use(LazilyRefreshDatabase::class);

test('reports measured pagination results and bound query plans without changing data', function () {
    $category = Category::factory()->create();
    Product::factory()->for($category)->create(['name' => 'Laptop', 'description' => 'Portable computer', 'price' => '1000.00']);
    Product::factory()->for($category)->unavailable()->create(['name' => 'Laptop', 'description' => 'Portable computer', 'price' => '2000.00']);
    Product::factory()->create(['name' => 'Keyboard', 'description' => 'Mechanical keys']);

    $exitCode = Artisan::call('products:benchmark', [
        '--iterations' => 1, '--deep-page' => 2, '--per-page' => 1, '--category' => $category->id,
        '--explain' => true, '--json' => true, '--no-interaction' => true,
    ]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0);
    expect($report['product_count'])->toBe(3);
    expect($report['results'])->toHaveCount(6);
    expect(array_column($report['results'], 'total'))->toBe([2, 1, 3, 3, 3, 2]);
    expect(array_column($report['results'], 'returned'))->toBe([1, 1, 1, 1, 1, 1]);
    expect($report['results'][0]['median_ms'])->toBeNumeric()->toBeGreaterThanOrEqual(0);
    expect($report['results'][0]['queries']['page']['bindings'])->toContain('"laptop"');
    expect($report['results'][0]['queries']['count']['plan'])->not->toBeEmpty();
    $this->assertDatabaseCount('products', 3);
});

test('prints a readable benchmark table without requiring JSON output', function () {
    Product::factory()->create(['name' => 'Laptop', 'description' => 'Portable computer']);

    $this->artisan('products:benchmark', ['--iterations' => 1, '--deep-page' => 1, '--explain' => true])
        ->expectsOutputToContain('1 products; 1 timed runs')
        ->expectsOutputToContain('common_keyword:')
        ->assertSuccessful();
});

test('rejects a benchmark on an empty catalog', function () {
    $this->artisan('products:benchmark')->expectsOutputToContain('No products to benchmark.')->assertFailed();
});

test('rejects invalid benchmark parameters', function (array $options) {
    $this->artisan('products:benchmark', $options)->assertFailed();
})->with([
    'no runs' => [['--iterations' => 0]],
    'too many runs' => [['--iterations' => 51]],
    'zero page' => [['--deep-page' => 0]],
    'too many results' => [['--per-page' => 51]],
    'nonexistent category' => [['--category' => 99999]],
    'long query' => [['--q' => str_repeat('x', 201)]],
    'malformed UTF-8' => [['--q' => "\xFF"]],
]);
