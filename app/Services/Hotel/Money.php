<?php

namespace App\Services\Hotel;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Internal monetary math with no float accumulation; round for API at boundaries only.
 */
final class Money
{
    private const INTERNAL_SCALE = 8;

    public static function of(string|int|float $amount): BigDecimal
    {
        return BigDecimal::of((string) $amount);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero();
    }

    public static function sum(BigDecimal ...$parts): BigDecimal
    {
        $total = self::zero();
        foreach ($parts as $part) {
            $total = $total->plus($part);
        }

        return $total;
    }

    public static function mul(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->multipliedBy($b);
    }

    /** @param  numeric-string  $percent */
    public static function percentOf(BigDecimal $base, string $percent): BigDecimal
    {
        return $base
            ->multipliedBy(BigDecimal::of($percent))
            ->dividedBy('100', self::INTERNAL_SCALE, RoundingMode::HALF_UP);
    }

    /** Invoice-style line: discount amount rounded to minor units, then subtracted. */
    public static function applyPercentLine(BigDecimal $running, string $percent): array
    {
        $rawDiscount = self::percentOf($running, $percent);
        $discountLine = $rawDiscount->toScale(2, RoundingMode::HALF_UP);
        $after = $running->minus($discountLine);

        return [$discountLine, $after];
    }

    public static function toResponseMoney(BigDecimal $value): string
    {
        return $value->toScale(2, RoundingMode::HALF_UP)->__toString();
    }
}
