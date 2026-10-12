<?php

use App\Support\ProductSearchText;

test('normalizes surrounding and repeated Unicode whitespace', function () {
    expect(ProductSearchText::normalize(" \tGaming\u{00A0}  Laptop\n "))->toBe('Gaming Laptop');
});

test('builds literal AND searches without exposing the FTS query language', function (string $input, string $expected) {
    expect(ProductSearchText::expression($input))->toBe($expected);
})->with([
    'words across fields' => ['gaming laptop', '"gaming" AND "laptop"'],
    'duplicates' => ['laptop laptop', '"laptop"'],
    'operators' => ['laptop OR tablet', '"laptop" AND "OR" AND "tablet"'],
    'column filters and quotes' => ['name:"laptop"*', '"name" AND "laptop"'],
    'SQL-like input' => ["' OR 1=1 --", '"OR" AND "1"'],
    'punctuation' => ['USB-C', '"USB" AND "C"'],
    'Unicode words' => ['Café شاشة', '"Café" AND "شاشة"'],
    'combining accent' => ["cafe\u{0301}", "\"cafe\u{0301}\""],
    'no words' => ['*%"()-', ''],
]);
