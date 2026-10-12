<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductSearchRequest;
use App\Http\Resources\ProductCollection;
use App\Services\ProductSearchService;

class ProductSearchController extends Controller
{
    public function __invoke(ProductSearchRequest $request, ProductSearchService $search): ProductCollection
    {
        $products = $search->paginate(
            $request->validated(),
            perPage: $request->integer('per_page', 15),
            page: $request->integer('page', 1),
        )->appends($request->safe()->except('page'));

        return new ProductCollection($products);
    }
}
