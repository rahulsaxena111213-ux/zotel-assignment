<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatePlanPrice extends Model
{
    protected $fillable = [
        'rate_plan_id',
        'rate_date',
        'amount',
    ];

    protected $casts = [
        'rate_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }
}
