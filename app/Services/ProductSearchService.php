<?php

namespace App\Services;

use App\Models\Product;
use App\Support\ProductSearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

class ProductSearchService
{
    private const array COLUMNS = [
        'products.id', 'products.name', 'products.description', 'products.price',
        'products.category_id', 'products.available', 'products.created_at',
    ];

    /**
     * @param  array{q?: string|null, min_price?: int|float|numeric-string, max_price?: int|float|numeric-string, category_id?: int|string, available?: bool|int|string, sort_by?: string, sort_order?: string, page?: int|string, per_page?: int|string}  $criteria
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $criteria, int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        $total = $this->query($criteria)->toBase()->getCountForPagination();
        $products = $total > 0
            ? $this->pageQuery($criteria, $perPage, $page)->get()
            : (new Product)->newCollection();

        return new LengthAwarePaginator($products, $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);
    }

    /**
     * Paginate IDs inside SQL before fetching descriptions, keeping temporary sorts narrow.
     *
     * @param  array{q?: string|null, min_price?: int|float|numeric-string, max_price?: int|float|numeric-string, category_id?: int|string, available?: bool|int|string, sort_by?: string, sort_order?: string, page?: int|string, per_page?: int|string}  $criteria
     * @return Builder<Product>
     */
    public function pageQuery(array $criteria, int $perPage, int $page): Builder
    {
        $matchingIds = $this->query($criteria)->select('products.id')->forPage($page, $perPage);
        $sortBy = $criteria['sort_by'] ?? 'created_at';
        $sortOrder = $criteria['sort_order'] ?? 'desc';

        return Product::query()->select(self::COLUMNS)
            ->joinSub($matchingIds, 'matching_products', 'matching_products.id', '=', 'products.id')
            ->orderBy('products.'.$sortBy, $sortOrder)
            ->orderBy('products.id', $sortOrder);
    }

    /**
     * @param  array{q?: string|null, min_price?: int|float|numeric-string, max_price?: int|float|numeric-string, category_id?: int|string, available?: bool|int|string, sort_by?: string, sort_order?: string, page?: int|string, per_page?: int|string}  $criteria
     * @return Builder<Product>
     */
    public function query(array $criteria): Builder
    {
        $sortBy = $criteria['sort_by'] ?? 'created_at';
        $sortOrder = $criteria['sort_order'] ?? 'desc';

        if (! in_array($sortBy, ['price', 'created_at'], true) || ! in_array($sortOrder, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Unsupported product sort.');
        }

        $query = Product::query()->select(self::COLUMNS);

        $search = ProductSearchText::normalize($criteria['q'] ?? '');

        if ($search !== '') {
            $expression = ProductSearchText::expression($search);

            if ($expression === '') {
                $query->whereRaw('0 = 1');
            } else {
                $query->join('products_fts', 'products_fts.rowid', '=', 'products.id')
                    ->whereRaw('products_fts MATCH ?', [$expression]);
            }
        }

        if (isset($criteria['min_price'])) {
            $query->where('products.price', '>=', $criteria['min_price']);
        }

        if (isset($criteria['max_price'])) {
            $query->where('products.price', '<=', $criteria['max_price']);
        }

        if (isset($criteria['category_id'])) {
            $query->where('products.category_id', $criteria['category_id']);
        }

        if (in_array($criteria['available'] ?? false, [true, 1, '1', 'true'], true)) {
            $query->where('products.available', true);
        }

        return $query->orderBy('products.'.$sortBy, $sortOrder)->orderBy('products.id', $sortOrder);
    }
}
