<?php

namespace App\Models;

use App\Enums\MealPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomDailyRate extends Model
{
    protected $fillable = [
        'room_type_id',
        'rate_date',
        'meal_plan',
        'amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'amount' => 'decimal:2',
            'meal_plan' => MealPlan::class,
        ];
    }

    /** @return BelongsTo<RoomType, $this> */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
