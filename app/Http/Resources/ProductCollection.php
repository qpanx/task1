<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ProductCollection extends ResourceCollection
{
    /**
     * @param  array{current_page: int, per_page: int, total: int, last_page: int}  $paginated
     * @param  array{links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array<string, mixed>}  $default
     * @return array{links: array{first: string|null, last: string|null, prev: string|null, next: string|null}, meta: array{current_page: int, per_page: int, total: int, last_page: int}}
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'links' => $default['links'],
            'meta' => [
                'current_page' => $paginated['current_page'],
                'per_page' => $paginated['per_page'],
                'total' => $paginated['total'],
                'last_page' => $paginated['last_page'],
            ],
        ];
    }
}
