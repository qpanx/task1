# Product Search API

A public, read-only product catalog endpoint built on the repository's existing Laravel 13.35, PHP 8.5 and SQLite stack. Search uses a native SQLite FTS5 index; filtering, ordering, counting and pagination run in SQL. No additional Composer packages, search servers or caches are required.

## Setup

Use the existing `.env` and dependencies when working in this checkout. For a new checkout, install the locked dependencies with `composer install`, copy `.env.example` to `.env`, generate the application key, and create `database/database.sqlite`. Keep `DB_CONNECTION=sqlite`.

```sh
php artisan key:generate --no-interaction
php artisan migrate --no-interaction
php artisan serve --host=127.0.0.1 --port=8000 --no-interaction
```

PHP's PDO SQLite extension must include FTS5. The implementation was tested with PHP's SQLite 3.45.2; the separate `sqlite3` executable can use a different SQLite version. Migrations fail if FTS5 is unavailable or a different database driver is selected. This feature deliberately supports the configured SQLite database, rather than pretending other engines have identical full-text behavior.

The initial repository contained only Laravel's starter tables. The new migrations add `categories` and `products`; there was no existing stock convention, so `products.available` is the catalog availability indicator. Categories are required foreign keys and cannot be deleted while referenced. Prices use `DECIMAL(12,2)` with an Eloquent `decimal:2` cast. SQLite applies numeric affinity rather than enforcing decimal precision: future write endpoints must validate monetary precision and nonnegative prices. Do not use floating-point arithmetic for payment calculations.

Optional demo data (100 products; requires development dependencies for Faker):

```sh
php artisan db:seed --class=ProductSeeder --no-interaction
```

Neither the demo seeder nor the million-product generator runs in the default `DatabaseSeeder` or normal tests. The default starter user seeder is unchanged.

## Endpoint and parameters

`GET /api/products/search`, named route `products.search`. This is the explicitly requested unversioned endpoint; authentication is not required.

| Parameter | Default | Accepted values and behavior |
| --- | --- | --- |
| `q` | Empty | Optional UTF-8 string, at most 200 characters after whitespace normalization; searches name and description. |
| `min_price` | None | Numeric, inclusive, between 0 and 9999999999.99. |
| `max_price` | None | Numeric, inclusive, between 0 and 9999999999.99; must be at least `min_price`. |
| `category_id` | None | Positive integer identifying an existing category. |
| `available` | No restriction | `true`, `false`, `1` or `0`. Only true/1 restricts results to available products; false/0 includes both states. |
| `sort_by` | `created_at` | `price` or `created_at`. |
| `sort_order` | `desc` | `asc` or `desc`. |
| `page` | `1` | Integer from 1 to 2147483647; the upper bound prevents offset overflow. |
| `per_page` | `15` | Integer from 1 to 50. |

Optional numeric, boolean and sort parameters must have valid values when present; blank values are rejected. Unknown parameters are ignored and omitted from pagination links. Prices are numeric filters, not strings to interpolate into SQL. Sort identifiers and directions are allow-listed in both validation and query construction.

Examples (use a category ID present in your database):

```sh
curl --get 'http://localhost:8000/api/products/search' \
  --data-urlencode 'q=laptop' \
  --data-urlencode 'min_price=500' \
  --data-urlencode 'max_price=5000' \
  --data-urlencode 'category_id=2' \
  --data-urlencode 'available=true' \
  --data-urlencode 'sort_by=price' \
  --data-urlencode 'sort_order=asc' \
  --data-urlencode 'page=1' \
  --data-urlencode 'per_page=20'

curl 'http://localhost:8000/api/products/search'
curl 'http://localhost:8000/api/products/search?available=true&sort_by=price&sort_order=asc'
```

### Search semantics

Whitespace is trimmed and collapsed. Words are sequences of Unicode letters/numbers, with combining marks retained. Punctuation separates words. Every word must occur in the name **or** description, in any order; words may span the two fields. For example, `wireless laptop` matches a product named `Laptop` whose description contains `wireless`. All other filters are ANDed with the complete text match.

Each word is quoted as a literal FTS token, then the full expression is passed as a bound parameter. Input cannot introduce SQL, FTS operators, column restrictions, wildcards or phrase syntax. `laptop OR tablet` requires the ordinary word `OR` as well as the other two words.

Matching is case-insensitive using FTS5's Unicode61 tokenizer, with Latin diacritics removed (`cafe` matches `Café`). One-character words are supported, with no stop-word list. Matching uses whole tokens: `lap` does not match `laptop`, and `laptop` does not match `laptops`. There is no stemming, fuzzy matching, prefix matching, relevance sorting or language-specific word segmentation. Languages without word separators and characters introduced after Unicode 6.1 need additional search design before promising equivalent behavior. These choices follow the [SQLite FTS5 tokenizer and query documentation](https://www.sqlite.org/fts5.html).

Missing, empty or whitespace-only `q` returns a listing using the remaining filters. A nonempty query made entirely of punctuation returns an empty result. With no criteria, the default order is `created_at DESC, id DESC`. Every supported sort uses `id` in the same direction as a stable tie-breaker.

### Responses

HTTP 200 uses exactly `data`, `meta` and `links`. Prices are **two-decimal JSON strings**, such as `"1200.00"`, to retain their decimal representation. Timestamps are UTC ISO 8601 strings. Only the seven product fields shown below are selected; category models are not loaded because the response needs only `category_id`.

Illustrative response to an unfiltered request when the catalog has one product:

```json
{
  "data": [
    {
      "id": 1,
      "name": "Gaming Laptop",
      "description": "High-performance gaming laptop",
      "price": "1200.00",
      "category_id": 2,
      "available": true,
      "created_at": "2026-10-12T10:00:00.000000Z"
    }
  ],
  "meta": {"current_page": 1, "per_page": 15, "total": 1, "last_page": 1},
  "links": {
    "first": "http://localhost:8000/api/products/search?page=1",
    "last": "http://localhost:8000/api/products/search?page=1",
    "prev": null,
    "next": null
  }
}
```

Pagination links preserve the validated, normalized filters; booleans may become `1`/`0`. A page beyond the last page returns an empty `data` array with the requested `current_page` and the actual `total`/`last_page`. For an empty result, `total` is 0 and `last_page` is 1. Stable ordering prevents overlapping consecutive pages for a stable dataset; inserts, deletes or sort-value changes between requests can still shift offset-based pages. The count and page queries are separate statements, not a cross-request snapshot.

Invalid input returns HTTP 422 with standard Laravel JSON errors even without an `Accept` header. For `per_page=51`:

```json
{
  "message": "The per page field must be between 1 and 50.",
  "errors": {"per_page": ["The per page field must be between 1 and 50."]}
}
```

An inverted price range adds `errors.min_price` with `The minimum price must not exceed the maximum price.` The response uses a [Laravel paginated resource collection](https://laravel.com/framework/docs/13.x/eloquent-resources#pagination) with customized metadata.

## Database design and operations

`products_fts` is an external-content FTS5 index on `name` and `description`, keyed to `products.id`. The source text stays in `products`. Insert, delete and text/ID update triggers maintain the index transactionally, including bulk inserts and updates that bypass Eloquent events. The migration rebuilds the index for pre-existing products. The query joins matching FTS row IDs to products; no matching IDs or complete catalog are loaded into PHP.

The page query sorts and paginates IDs in a SQL subquery before joining back for response fields. This avoids copying descriptions into a large temporary sort. The outer query restores the requested order over at most 50 products. Nonempty results use two queries: one exact count and one page fetch; category validation adds one existence query when requested. Empty matches require only the count query.

Do not bypass these triggers, directly change FTS shadow tables, or use SQLite `INSERT OR REPLACE` for catalog updates: replacement deletion semantics can leave an external-content index stale. Use regular inserts/updates or Laravel upserts. If triggers were disabled for a controlled import, rebuild the FTS index before serving searches.

The product migration creates these composite B-tree indexes:

| Index columns | Query supported |
| --- | --- |
| `(price, id)` | Price bounds and deterministic price ordering. |
| `(created_at, id)` | Default newest-first listing and date sorting. |
| `(category_id, price, id)` | Category equality with price bounds/order. |
| `(category_id, created_at, id)` | Category equality with newest-first listing. |
| `(available, price, id)` | Available-only listing with price bounds/order. |
| `(available, created_at, id)` | Available-only newest-first listing. |

There is no redundant single-column category or availability index. These indexes support both ascending and reverse scans. They do not promise to satisfy every filter combination and sort simultaneously: category plus availability may use one index and apply the other filter afterwards. Full-text matches can require a temporary sort; a broad keyword and an exact count can remain expensive. Add more composite indexes only after inspecting representative plans and accounting for storage and write costs.

Migrations have matching `down()` methods. Reversing only the FTS migration removes its triggers/index but keeps products. Reversing the table migrations destroys catalog data, so use backups and forward fixes in production. SQLite schema/index builds and the initial FTS rebuild hold write locks; schedule a maintenance window if applying the index migration to a populated catalog. These are transactional migrations, not online index builds.

SQLite is the tested search backend. A future PostgreSQL migration should evaluate a stored/generated `tsvector` plus GIN index; MySQL should evaluate an InnoDB FULLTEXT index with bound `MATCH ... AGAINST`. Both need engine-specific integration tests and decisions about stop words, minimum token lengths and language configuration. There is no unindexed `%keyword%` fallback.

## Tests and formatting

```sh
php artisan test --compact tests/Feature/Http/Controllers/ProductSearchControllerTest.php tests/Feature/ProductSearchIndexTest.php
php artisan test --compact tests/Feature/Console/Commands
php artisan test --compact tests/Unit/Support/ProductSearchTextTest.php
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

`vendor/bin/pest --compact` also runs the complete suite directly. Tests use the actual supported SQLite FTS5 engine, not a LIKE substitute or a mocked query builder. They run migrations against an isolated in-memory database, exercise index triggers and rebuilds, and cover query counts, all filters/sorts, validation, injection-like input, pagination boundaries, generator batches and benchmark reporting. No million-row fixture is used by the test suite.

Verification on October 12, 2026: **109 tests passed, 292 assertions, no skipped tests** using `vendor/bin/pest --compact`. PHP syntax checks, Composer manifest validation and Pint over the changed PHP paths also passed.

If Git metadata is unavailable, Pint's `--dirty` option cannot operate; run `vendor/bin/pint --format agent` with the changed PHP paths instead. No separate static-analysis package is configured in this repository.

## Optional million-product benchmark

The generator appends data and never truncates existing tables. It creates five named benchmark categories and cycles eight plausible product types with varied prices, dates and availability. It needs no Faker dependency. Only one batch of arrays is held in PHP; model hydration and per-product category queries are avoided. SQL triggers maintain FTS during every batch. Batches commit independently, so an interrupted run retains completed batches; rerunning appends another requested count. In production, Laravel requests confirmation or requires `--force`.

Use a dedicated database. The following commands create a temporary SQLite file and do not alter the application's default catalog:

```sh
product_benchmark_db=$(mktemp /tmp/product-search-XXXXXX.sqlite)
DB_CONNECTION=sqlite DB_DATABASE="$product_benchmark_db" php artisan migrate --no-interaction
DB_CONNECTION=sqlite DB_DATABASE="$product_benchmark_db" php artisan products:generate --count=1000000 --batch=1000 --no-interaction
sqlite3 "$product_benchmark_db" 'ANALYZE;'
DB_CONNECTION=sqlite DB_DATABASE="$product_benchmark_db" php artisan products:benchmark --iterations=5 --per-page=20 --deep-page=10000 --explain --no-interaction
```

`--count` accepts 1–10000000 (default 1000000); `--batch` accepts 1–1000 (default 1000). The batch cap keeps parameter counts within the standard modern SQLite limit. Custom SQLite builds must allow at least seven bindings per chosen batch row.

The benchmark is read-only and refuses an empty catalog. `--q` defaults to `laptop`; `--category` defaults to the first category ID. `--iterations` accepts 1–50. Add `--json` to capture a structured report, including SQL, bindings and both count/page plans when `--explain` is present. It measures common keyword search, combined filters, both sorts, early pages, a deep listing page, and a deep keyword page. Every case has one untimed warmup. Timings include pagination counts, page fetches and model hydration, but exclude HTTP transport, validation and JSON serialization. Empty deep pages are reported as such; choose a page inside the result set for a meaningful deep-page comparison.

Before drawing production conclusions, run on representative persistent storage, analyze statistics after bulk loads, inspect the count and page plans, and measure cold-cache behavior and concurrent traffic as well as warm requests. FTS plans should show a virtual-table MATCH index; ordinary sort plans should use the corresponding B-tree. A `SCAN ... VIRTUAL TABLE INDEX ... M2` line denotes an FTS MATCH access, not a full product-text LIKE scan.

Exact totals require counting all qualifying matches. Page-number pagination is intentionally retained: SQL OFFSET must walk/discard prior rows, and sorting broad matches grows more expensive on deep pages. For a future infinite-scroll client, a separate cursor/keyset contract over `(created_at, id)` or `(price, id)` can avoid the increasing OFFSET cost. It would need its own response contract and a decision about exact totals; it does not automatically remove FTS filtering or sorting costs. See [Laravel's pagination tradeoffs](https://laravel.com/framework/docs/13.x/pagination#cursor-vs-offset-pagination).

For public production traffic, configure gateway rate limits and measure SQLite's single-writer concurrency on your deployment. A million-row local run is useful evidence, not a concurrency or latency SLA.

### Measured local results — October 12, 2026

Executed against **1,000,000 products** using PHP 8.5.0 and PDO SQLite 3.45.2, with a database file on `/tmp` **tmpfs (memory-backed storage)**. Statistics were refreshed with `ANALYZE`. Each case used 20 results per page, one warmup and five timed iterations, with no concurrent load. Generation took **44.44 seconds**, with **38.0 MiB peak PHP memory** and batches of 1000. That PHP memory figure excludes SQLite's database file in tmpfs and other operating-system memory.

These measurements use the final implementation, including the SQL subquery that selects page IDs before fetching full product fields:

| Query | Page | Total matches | Median | Min–max |
| --- | ---: | ---: | ---: | ---: |
| `q=laptop` | 1 | 375000 | 196.223 ms | 194.456–205.858 ms |
| `q=laptop&min_price=500&max_price=5000&category_id=1&available=true` | 1 | 89981 | 218.378 ms | 217.945–219.453 ms |
| Price ascending | 1 | 1000000 | 8.557 ms | 8.247–8.674 ms |
| Created date descending | 1 | 1000000 | 8.171 ms | 8.084–8.276 ms |
| Created date descending | 10000 | 1000000 | 10.646 ms | 10.455–10.934 ms |
| `q=laptop` | 10000 | 375000 | 809.587 ms | 808.334–810.943 ms |

All cases returned 20 rows. Page 10000 has OFFSET 199980. The broad keyword intentionally occurs in both names and descriptions. Random generation means availability counts and timings will differ on another run. These are application query timings on warm memory-backed storage, not end-to-end HTTP timings or evidence of production throughput.

Observed `EXPLAIN QUERY PLAN` details included:

```text
Text lookup:      SCAN products_fts VIRTUAL TABLE INDEX 0:M2
Product lookup:   SEARCH products USING INTEGER PRIMARY KEY (rowid=?)
Price page IDs:   SCAN products USING COVERING INDEX products_price_id_index
Date page IDs:    SCAN products USING COVERING INDEX products_created_at_id_index
Page subquery:   CO-ROUTINE matching_products
Broad text sort: USE TEMP B-TREE FOR ORDER BY
```

The outer query also uses a temporary sort to restore ordering over the small returned page. The broad text sort inside the subquery remains the main deep-search cost. Reproduce with `products:benchmark --explain --json` to see complete count/page SQL, bound values and plans on your own database.

Separate EXPLAIN checks on the same million-row database confirmed covering-index use for both category-filtered sorts and both available-only sorts: `products_category_id_price_id_index`, `products_category_id_created_at_id_index`, `products_available_price_id_index` and `products_available_created_at_id_index`.

## Implementation files

Modified: `bootstrap/app.php`, `README.md`. New files:

```text
app/Console/Commands/BenchmarkProductSearch.php
app/Console/Commands/GenerateProducts.php
app/Http/Controllers/ProductSearchController.php
app/Http/Requests/ProductSearchRequest.php
app/Http/Resources/ProductCollection.php
app/Http/Resources/ProductResource.php
app/Models/Category.php
app/Models/Product.php
app/Services/ProductSearchService.php
app/Support/ProductSearchText.php
database/factories/CategoryFactory.php
database/factories/ProductFactory.php
database/migrations/2026_10_12_083809_create_categories_table.php
database/migrations/2026_10_12_083810_create_products_table.php
database/migrations/2026_10_12_083811_create_product_search_index.php
database/seeders/CategorySeeder.php
database/seeders/ProductSeeder.php
routes/api.php
tests/Feature/Console/Commands/BenchmarkProductSearchTest.php
tests/Feature/Console/Commands/GenerateProductsTest.php
tests/Feature/Http/Controllers/ProductSearchControllerTest.php
tests/Feature/ProductSearchIndexTest.php
tests/Unit/Support/ProductSearchTextTest.php
```

Dependencies and pre-existing tests are unchanged. The new migrations were also applied to the local application's SQLite database; benchmark products were generated only in a separate temporary database.
