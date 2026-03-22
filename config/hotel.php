<?php

return [

    'default_currency' => strtoupper((string) env('HOTEL_CURRENCY', 'USD')),

    /*
    |--------------------------------------------------------------------------
    | Currency metadata (ISO 4217 code → display)
    |--------------------------------------------------------------------------
    */
    'currency_definitions' => [
        'USD' => ['symbol' => '$', 'decimals' => 2],
        'INR' => ['symbol' => '₹', 'decimals' => 2],
        'EUR' => ['symbol' => '€', 'decimals' => 2],
        'GBP' => ['symbol' => '£', 'decimals' => 2],
    ],

    'max_adults_per_room' => 3,

    'max_guests_per_search' => (int) env('HOTEL_MAX_GUESTS', 30),

    /*
    |--------------------------------------------------------------------------
    | Meal-plan add-ons (per room, per night) — extensible by meal plan key
    |--------------------------------------------------------------------------
    |
    | Keys must align with App\Enums\MealPlan values. `room_only` has no add-on.
    | Future plans (e.g. half_board): add a key and optional enum case.
    |
    */
    'meal_plan_addons_per_room_per_night' => [
        'room_only' => null,
        'breakfast_included' => [
            'standard' => '18.00',
            'deluxe' => '25.00',
            'default' => '15.00',
        ],
    ],

    'discounts' => [
        'long_stay' => [
            'min_nights' => 3,
            'percent' => '10',
        ],
        'last_minute' => [
            'days_before_check_in' => 2,
            'percent' => '15',
        ],
    ],

    'search_cache_ttl_seconds' => (int) env('HOTEL_SEARCH_CACHE_TTL', 60),

];
