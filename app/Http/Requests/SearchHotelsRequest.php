<?php

namespace App\Http\Requests;

use App\Enums\MealPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchHotelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxGuests = (int) config('hotel.max_guests_per_search', 30);

        return [
            'check_in_date' => ['required', 'date', 'after_or_equal:today'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'guest_count' => ['required', 'integer', 'min:1', 'max:'.$maxGuests],
            'meal_plan' => ['sometimes', 'nullable', 'string', Rule::enum(MealPlan::class)],
            'debug' => ['sometimes', 'boolean'],
        ];
    }
}
