<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RatePlan extends Model
{
    protected $fillable = [
        'room_type_id',
        'code',
        'name',
        'meal_plan',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(RatePlanPrice::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(RatePlanDiscount::class);
    }
}
