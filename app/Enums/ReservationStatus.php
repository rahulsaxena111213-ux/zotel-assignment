<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function blocksInventory(): bool
    {
        return $this === self::Confirmed;
    }
}
