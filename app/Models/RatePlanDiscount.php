<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatePlanDiscount extends Model
{
    protected $table = 'rate_plan_discounts';

    protected $fillable = [
        'rate_plan_id',
        'code',
        'name',
        'amount_type',
        'amount',
        'rules',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'rules' => 'array',
        'is_active' => 'boolean',
    ];

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    public function isApplicable(CarbonImmutable $checkIn, CarbonImmutable $bookingDate): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $rules = $this->rules ?? [];

        if (isset($rules['early_bird_days_before'])) {
            $leadDays = $bookingDate->diffInDays($checkIn, false);
            return $leadDays >= (int) $rules['early_bird_days_before'];
        }

        // no rule means always apply
        return true;
    }
}
