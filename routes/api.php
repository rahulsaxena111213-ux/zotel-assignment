<?php

use App\Http\Controllers\Api\V1\HotelSearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/hotels/search', HotelSearchController::class);
});
