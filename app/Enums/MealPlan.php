<?php

namespace App\Enums;

enum MealPlan: string
{
    case RoomOnly = 'room_only';
    case BreakfastIncluded = 'breakfast_included';
    case AllMealsIncluded = 'all_meals_included';
    case EP = 'EP';
    case CP = 'CP';
    case MAP = 'MAP';

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }

    public function toRatePlanCode(): string
    {
        return match ($this) {
            self::RoomOnly, self::EP => 'EP',
            self::BreakfastIncluded, self::CP => 'CP',
            self::AllMealsIncluded, self::MAP => 'MAP',
        };
    }

    public function toBaseMealPlan(): string
    {
        return match ($this) {
            self::RoomOnly, self::EP => self::RoomOnly->value,
            self::BreakfastIncluded, self::CP => self::BreakfastIncluded->value,
            self::AllMealsIncluded, self::MAP => self::AllMealsIncluded->value,
        };
    }
}
