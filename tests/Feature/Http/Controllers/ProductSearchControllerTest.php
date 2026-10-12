<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

pest()->use(LazilyRefreshDatabase::class);

test('searches names and descriptions with native case-insensitive token matching', function (string $query, string $name, string $description) {
    $matching = Product::factory()->create(['name' => $name, 'description' => $description]);
    Product::factory()->create(['name' => 'Desk Chair', 'description' => 'Ergonomic fabric seating']);

    $this->getJson(route('products.search', ['q' => $query]))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$matching->id]);
})->with([
    'name' => ['laptop', 'Gaming Laptop', 'Portable computer'],
    'description with nonmatching name' => ['laptop', 'Travel Workstation', 'A powerful laptop'],
    'mixed case' => ['LaPtOp', 'GAMING LAPTOP', 'Portable computer'],
    'diacritics' => ['cafe', 'Café Machine', 'Espresso maker'],
    'short token' => ['x', 'Model X', 'Portable computer'],
    'Arabic' => ['شاشة', 'شاشة محمولة', 'Portable monitor'],
]);

test('requires every word across either field regardless of order', function () {
    $matching = Product::factory()->create(['name' => 'Laptop', 'description' => 'Wireless portable computer']);
    Product::factory()->create(['name' => 'Laptop', 'description' => 'Wired computer']);
    Product::factory()->create(['name' => 'Headphones', 'description' => 'Wireless audio']);

    $this->getJson(route('products.search', ['q' => " \tWireless\u{00A0}  laptop\n "]))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$matching->id]);
});

test('lists products when the search term is absent or empty', function (array $parameters) {
    $product = Product::factory()->create();

    $this->getJson(route('products.search', $parameters))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$product->id]);
})->with([
    'missing' => [[]],
    'empty' => [['q' => '']],
    'whitespace' => [['q' => '   ']],
]);

test('applies inclusive price boundaries', function (array $filters, array $positions) {
    $products = Product::factory()->count(5)->sequence(
        ['price' => '0.00'], ['price' => '99.99'], ['price' => '100.00'],
        ['price' => '500.00'], ['price' => '500.01'],
    )->create();

    $this->getJson(route('products.search', [...$filters, 'sort_by' => 'price', 'sort_order' => 'asc']))
        ->assertOk()
        ->assertJsonPath('data.*.id', array_map(fn (int $position): int => $products[$position]->id, $positions));
})->with([
    'minimum' => [['min_price' => '100'], [2, 3, 4]],
    'maximum' => [['max_price' => '500'], [0, 1, 2, 3]],
    'combined' => [['min_price' => '100', 'max_price' => '500'], [2, 3]],
    'equal bounds' => [['min_price' => '100', 'max_price' => '100'], [2]],
    'zero' => [['min_price' => '0', 'max_price' => '0'], [0]],
    'empty query still filters' => [['q' => '', 'min_price' => '500'], [3, 4]],
]);

test('filters by an existing category', function () {
    $category = Category::factory()->create();
    $matching = Product::factory()->for($category)->create();
    Product::factory()->create();

    $this->getJson(route('products.search', ['category_id' => $category->id]))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$matching->id]);
});

test('limits availability only when requested', function (string $available, array $expectedAvailability) {
    Product::factory()->create(['created_at' => '2026-10-12 10:00:00']);
    Product::factory()->unavailable()->create(['created_at' => '2026-10-12 10:00:00']);

    $this->getJson(route('products.search', ['available' => $available]))->assertOk()
        ->assertJsonPath('data.*.available', $expectedAvailability);
})->with([
    'true' => ['true', [true]],
    'one' => ['1', [true]],
    'false' => ['false', [false, true]],
    'zero' => ['0', [false, true]],
]);

test('keeps name and description matches inside all combined filters', function () {
    $category = Category::factory()->create();
    $otherCategory = Category::factory()->create();
    $factory = Product::factory()->for($category)->state([
        'name' => 'Travel Workstation', 'description' => 'Wireless laptop', 'price' => '1000.00',
    ]);
    $descriptionMatch = $factory->create();
    $nameMatch = $factory->create(['name' => 'Wireless Laptop', 'description' => 'Portable computer']);
    $factory->create(['price' => '499.99']);
    $factory->create(['price' => '5000.01']);
    $factory->create(['category_id' => $otherCategory->id]);
    $factory->unavailable()->create();
    $factory->create(['description' => 'Wired computer']);

    $this->getJson(route('products.search', [
        'q' => 'wireless laptop', 'min_price' => '500', 'max_price' => '5000',
        'category_id' => $category->id, 'available' => 'true', 'sort_by' => 'price', 'sort_order' => 'asc',
    ]))->assertOk()->assertJsonPath('data.*.id', [$descriptionMatch->id, $nameMatch->id]);
});

test('sorts deterministically using the primary key for ties', function (array $sort, array $positions) {
    $products = Product::factory()->count(4)->sequence(
        ['price' => '100.00', 'created_at' => '2026-10-10 10:00:00'],
        ['price' => '200.00', 'created_at' => '2026-10-11 10:00:00'],
        ['price' => '100.00', 'created_at' => '2026-10-10 10:00:00'],
        ['price' => '50.00', 'created_at' => '2026-10-12 10:00:00'],
    )->create();

    $this->getJson(route('products.search', $sort))
        ->assertOk()
        ->assertJsonPath('data.*.id', array_map(fn (int $position): int => $products[$position]->id, $positions));
})->with([
    'default newest' => [[], [3, 1, 2, 0]],
    'price ascending' => [['sort_by' => 'price', 'sort_order' => 'asc'], [3, 0, 2, 1]],
    'price descending' => [['sort_by' => 'price', 'sort_order' => 'desc'], [1, 2, 0, 3]],
    'date ascending' => [['sort_by' => 'created_at', 'sort_order' => 'asc'], [0, 2, 1, 3]],
    'date descending' => [['sort_by' => 'created_at', 'sort_order' => 'desc'], [3, 1, 2, 0]],
]);

test('supports default custom and maximum page sizes', function (array $parameters, int $perPage, int $lastPage) {
    Product::factory()->count(51)->for(Category::factory())->create();

    $this->getJson(route('products.search', $parameters))
        ->assertOk()
        ->assertJsonCount($perPage, 'data')
        ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => $perPage, 'total' => 51, 'last_page' => $lastPage]);
})->with([
    'default' => [[], 15, 4],
    'custom' => [['per_page' => 20], 20, 3],
    'maximum' => [['per_page' => 50], 50, 2],
]);

test('preserves validated filters and does not duplicate tied products across pages', function () {
    $products = Product::factory()->count(3)->for(Category::factory())->create([
        'name' => 'Laptop', 'price' => '100.00', 'created_at' => '2026-10-12 10:00:00',
    ]);
    $filters = ['q' => 'laptop', 'available' => 'true', 'per_page' => 2, 'sort_by' => 'price', 'sort_order' => 'asc', 'ignored' => 'value'];

    $first = $this->getJson(route('products.search', $filters));
    $first->assertOk()->assertJsonPath('data.*.id', [$products[0]->id, $products[1]->id])->assertJsonPath('links.prev', null);
    parse_str(parse_url($first->json('links.next'), PHP_URL_QUERY), $nextParameters);
    expect($nextParameters)->toBe([
        'q' => 'laptop', 'available' => '1', 'sort_by' => 'price', 'sort_order' => 'asc', 'per_page' => '2', 'page' => '2',
    ]);

    $this->getJson($first->json('links.next'))->assertOk()
        ->assertJsonPath('data.*.id', [$products[2]->id])
        ->assertJsonPath('meta.current_page', 2)->assertJsonPath('links.next', null);

    $this->getJson(route('products.search', [...$filters, 'page' => 3]))->assertOk()
        ->assertJsonPath('data', [])->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
});

test('returns only documented product and pagination fields', function () {
    $product = Product::factory()->create([
        'name' => 'Gaming Laptop', 'description' => 'High-performance gaming laptop',
        'price' => '1200.00', 'created_at' => '2026-10-12 10:00:00',
    ]);

    $this->getJson(route('products.search'))->assertOk()->assertExactJson([
        'data' => [[
            'id' => $product->id, 'name' => 'Gaming Laptop', 'description' => 'High-performance gaming laptop',
            'price' => '1200.00', 'category_id' => $product->category_id,
            'available' => true, 'created_at' => '2026-10-12T10:00:00.000000Z',
        ]],
        'meta' => ['current_page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1],
        'links' => ['first' => route('products.search', ['page' => 1]), 'last' => route('products.search', ['page' => 1]), 'prev' => null, 'next' => null],
    ]);
});

test('returns an empty paginated result for unmatched or punctuation-only searches', function (string $query) {
    Product::factory()->create(['name' => 'Laptop', 'description' => 'Portable computer']);

    $this->getJson(route('products.search', ['q' => $query]))->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1])
        ->assertJsonPath('links.next', null)->assertJsonPath('links.prev', null);
})->with(['unmatched' => 'toaster', 'no substring matching' => 'lap', 'no stemming' => 'laptops', 'punctuation only' => '*%()']);

test('treats SQL and FTS injection-like searches as literal words', function (string $query) {
    Product::factory()->create(['name' => 'Laptop', 'description' => 'Portable computer']);

    $this->getJson(route('products.search', ['q' => $query]))->assertOk()->assertJsonPath('data', []);

    $this->assertDatabaseCount('products', 1);
})->with(["' OR 1=1 --", 'laptop OR chair', 'name:laptop', 'laptop" OR "*', "laptop'); DROP TABLE products; --"]);

test('returns 422 with a field message for invalid query parameters', function (string $field, mixed $value, string $message) {
    $this->getJson(route('products.search', [$field => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);
})->with([
    'query array' => ['q', ['laptop'], 'The q field must be a string.'],
    'malformed UTF-8' => ['q', "\xFF", 'The q field must be encoded in UTF-8.'],
    'query too long' => ['q', str_repeat('x', 201), 'The q field must not be greater than 200 characters.'],
    'negative minimum' => ['min_price', -1, 'The min price field must be at least 0.'],
    'negative maximum' => ['max_price', -1, 'The max price field must be at least 0.'],
    'nonnumeric minimum' => ['min_price', 'cheap', 'The min price field must be a number.'],
    'nonnumeric maximum' => ['max_price', 'cheap', 'The max price field must be a number.'],
    'oversized price' => ['max_price', '1e100', 'The max price field must not be greater than 9999999999.99.'],
    'empty minimum' => ['min_price', '', 'The min price field is required.'],
    'category string' => ['category_id', 'electronics', 'The category id field must be an integer.'],
    'category fractional' => ['category_id', '1.5', 'The category id field must be an integer.'],
    'category negative' => ['category_id', -1, 'The category id field must be at least 1.'],
    'category nonexistent' => ['category_id', 99999, 'The selected category id is invalid.'],
    'invalid boolean' => ['available', 'yes', 'The available field must be true or false.'],
    'boolean array' => ['available', ['true'], 'The available field must be true or false.'],
    'unsupported sort' => ['sort_by', 'name', 'The selected sort by is invalid.'],
    'sort injection' => ['sort_by', 'price; DROP TABLE products', 'The selected sort by is invalid.'],
    'unsupported direction' => ['sort_order', 'up', 'The selected sort order is invalid.'],
    'direction injection' => ['sort_order', 'desc; DROP TABLE products', 'The selected sort order is invalid.'],
    'empty sort' => ['sort_by', '', 'The sort by field is required.'],
    'zero page' => ['page', 0, 'The page field must be at least 1.'],
    'negative page' => ['page', -1, 'The page field must be at least 1.'],
    'fractional page' => ['page', '1.5', 'The page field must be an integer.'],
    'nonnumeric page' => ['page', 'first', 'The page field must be an integer.'],
    'overflow page' => ['page', '2147483648', 'The page field must not be greater than 2147483647.'],
    'too many per page' => ['per_page', 51, 'The per page field must be between 1 and 50.'],
    'zero per page' => ['per_page', 0, 'The per page field must be between 1 and 50.'],
    'negative per page' => ['per_page', -1, 'The per page field must be between 1 and 50.'],
    'fractional per page' => ['per_page', '1.5', 'The per page field must be an integer.'],
]);

test('returns 422 when the minimum price exceeds the maximum price even without an Accept header', function () {
    $this->get(route('products.search', ['min_price' => '500', 'max_price' => '100']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['min_price' => 'The minimum price must not exceed the maximum price.']);
});

test('uses a constant number of queries without loading categories for each result', function () {
    Product::factory()->count(50)->create();
    $this->expectsDatabaseQueryCount(2);

    $this->getJson(route('products.search', ['per_page' => 50]))->assertOk()->assertJsonCount(50, 'data');
});
