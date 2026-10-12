<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Services\ProductSearchService;
use App\Support\ProductSearchText;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

#[Signature('products:benchmark {--q=laptop : Common search term} {--category= : Category for the combined filter case} {--iterations=5 : Timed runs per case (1-50)} {--per-page=20 : Page size (1-50)} {--deep-page=10000 : Deep page to measure} {--explain : Include page and count query plans} {--json : Output a JSON report}')]
#[Description('Measure product pagination queries and optionally inspect SQLite query plans')]
class BenchmarkProductSearch extends Command
{
    public function handle(ProductSearchService $search): int
    {
        $validator = Validator::make($this->options(), [
            'q' => ['bail', 'required', 'string', 'encoding:UTF-8', 'max:200'],
            'category' => ['bail', 'nullable', 'integer', 'min:1', Rule::exists(Category::class, 'id')],
            'iterations' => ['required', 'integer', 'between:1,50'],
            'per-page' => ['required', 'integer', 'between:1,50'],
            'deep-page' => ['required', 'integer', 'between:1,2147483647'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->error('Product search benchmarks require SQLite with FTS5.');

            return self::FAILURE;
        }

        $productCount = Product::query()->count();

        if ($productCount === 0) {
            $this->error('No products to benchmark. Run products:generate on a dedicated benchmark database first.');

            return self::FAILURE;
        }

        $term = ProductSearchText::normalize($this->option('q'));
        $categoryId = $this->option('category') ?? Category::query()->orderBy('id')->value('id');
        $iterations = (int) $this->option('iterations');
        $perPage = (int) $this->option('per-page');
        $deepPage = (int) $this->option('deep-page');
        $cases = [
            ['name' => 'common_keyword', 'criteria' => ['q' => $term], 'page' => 1],
            ['name' => 'multiple_filters', 'criteria' => ['q' => $term, 'min_price' => 500, 'max_price' => 5000, 'category_id' => $categoryId, 'available' => true], 'page' => 1],
            ['name' => 'price_sort', 'criteria' => ['sort_by' => 'price', 'sort_order' => 'asc'], 'page' => 1],
            ['name' => 'created_at_sort', 'criteria' => [], 'page' => 1],
            ['name' => 'deep_listing', 'criteria' => [], 'page' => $deepPage],
            ['name' => 'deep_keyword', 'criteria' => ['q' => $term], 'page' => $deepPage],
        ];
        $results = [];
        DB::connection()->disableQueryLog();

        foreach ($cases as $case) {
            $query = $search->query($case['criteria']);
            $search->paginate($case['criteria'], $perPage, $case['page']);
            $times = [];

            for ($iteration = 0; $iteration < $iterations; $iteration++) {
                $startedAt = hrtime(true);
                $page = $search->paginate($case['criteria'], $perPage, $case['page']);
                $times[] = (hrtime(true) - $startedAt) / 1e6;
            }

            sort($times, SORT_NUMERIC);
            $result = [
                ...$case,
                'returned' => $page->count(),
                'total' => $page->total(),
                'min_ms' => round($times[0], 3),
                'median_ms' => round(($times[intdiv($iterations - 1, 2)] + $times[intdiv($iterations, 2)]) / 2, 3),
                'max_ms' => round($times[$iterations - 1], 3),
            ];

            if ($this->option('explain')) {
                $result['queries'] = $this->queryPlans($query, $search->pageQuery($case['criteria'], $perPage, $case['page']));
            }

            $results[] = $result;
        }

        $report = [
            'sqlite_version' => DB::selectOne('SELECT sqlite_version() AS version')->version,
            'product_count' => $productCount,
            'iterations' => $iterations,
            'warmup_runs_per_case' => 1,
            'per_page' => $perPage,
            'timing_scope' => 'Paginator count and page queries plus model hydration; excludes HTTP and JSON serialization.',
            'results' => $results,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('SQLite '.$report['sqlite_version'].'; '.$productCount.' products; '.$iterations.' timed runs after one warmup per case.');
        $this->line($report['timing_scope']);
        $this->table(['Case', 'Page', 'Returned', 'Total', 'Median ms', 'Min ms', 'Max ms'], array_map(
            fn (array $result): array => [
                $result['name'], $result['page'], $result['returned'], $result['total'],
                $result['median_ms'], $result['min_ms'], $result['max_ms'],
            ],
            $results,
        ));

        if ($this->option('explain')) {
            foreach ($results as $result) {
                $this->line($result['name'].': '.json_encode($result['queries'], JSON_THROW_ON_ERROR));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  Builder<Product>  $query
     * @param  Builder<Product>  $pageQuery
     * @return array<string, array{sql: string, bindings: array<mixed>, plan: array<object>}>
     */
    private function queryPlans(Builder $query, Builder $pageQuery): array
    {
        $queries = [
            'count' => (clone $query)->toBase()->reorder()->select([])->selectRaw('count(*) as aggregate'),
            'page' => $pageQuery->toBase(),
        ];
        $plans = [];

        foreach ($queries as $name => $builder) {
            $plans[$name] = [
                'sql' => $builder->toSql(),
                'bindings' => $builder->getBindings(),
                'plan' => DB::select('EXPLAIN QUERY PLAN '.$builder->toSql(), $builder->getBindings()),
            ];
        }

        return $plans;
    }
}
