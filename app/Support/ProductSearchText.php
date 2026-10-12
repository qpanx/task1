<?php

namespace App\Support;

use Illuminate\Support\Str;

class ProductSearchText
{
    public static function normalize(string $query): string
    {
        return Str::squish($query);
    }

    /**
     * Quote every word so user input cannot introduce FTS operators or column filters.
     * An empty expression represents a nonempty query with no searchable words.
     */
    public static function expression(string $query): string
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}]*/u', $query, $matches);

        return implode(' AND ', array_map(
            fn (string $word): string => '"'.$word.'"',
            array_unique($matches[0]),
        ));
    }
}
