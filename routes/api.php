<?php

use App\Http\Controllers\ProductSearchController;
use Illuminate\Support\Facades\Route;

Route::get('/products/search', ProductSearchController::class)->name('products.search');
