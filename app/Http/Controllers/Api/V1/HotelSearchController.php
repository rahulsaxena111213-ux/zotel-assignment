<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MealPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\SearchHotelsRequest;
use App\Services\SearchService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class HotelSearchController extends Controller
{
    public function __invoke(SearchHotelsRequest $request, SearchService $search): JsonResponse
    {
        $payload = $search->search(
            CarbonImmutable::parse($request->validated('check_in_date'))->startOfDay(),
            CarbonImmutable::parse($request->validated('check_out_date'))->startOfDay(),
            (int) $request->validated('guest_count'),
            $request->has('meal_plan') ? $request->enum('meal_plan', MealPlan::class) : null,
            null,
            $request->boolean('debug'),
        );

        return response()->json($payload);
    }
}
