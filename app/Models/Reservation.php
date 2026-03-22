<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    protected $fillable = [
        'room_id',
        'check_in_date',
        'check_out_date',
        'guest_count',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'status' => ReservationStatus::class,
        ];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Half-open interval overlap with stay [checkIn, checkOut).
     *
     * @param  Builder<Reservation>  $query
     */
    public function scopeOverlappingStay(Builder $query, CarbonInterface $checkIn, CarbonInterface $checkOut): void
    {
        $query->where('check_in_date', '<', $checkOut)
            ->where('check_out_date', '>', $checkIn);
    }

    /**
     * @param  Builder<Reservation>  $query
     */
    public function scopeBlockingInventory(Builder $query): void
    {
        $query->where('status', ReservationStatus::Confirmed);
    }
}
