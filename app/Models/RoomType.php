<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'max_adults',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /** @return HasMany<RoomDailyRate, $this> */
    public function dailyRates(): HasMany
    {
        return $this->hasMany(RoomDailyRate::class);
    }

    /** @return HasMany<RatePlan, $this> */
    public function ratePlans(): HasMany
    {
        return $this->hasMany(RatePlan::class);
    }
}
