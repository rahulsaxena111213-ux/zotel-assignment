<?php

namespace App\Enums;

enum MealPlan: string
{
    case RoomOnly = 'room_only';
    case BreakfastIncluded = 'breakfast_included';

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
