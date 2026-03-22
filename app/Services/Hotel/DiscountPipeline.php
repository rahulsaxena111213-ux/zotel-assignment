<?php

namespace App\Services\Hotel;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/**
 * Sequential percentage discounts on a running BigDecimal balance.
 * Each line amount is rounded to 2 dp before subtracting (invoice-style).
 */
class DiscountPipeline
{
    /**
     * @return array{
     *     lines: list<array{
     *         code: string,
     *         label: string,
     *         percent: float,
     *         applied_to_total_price: string,
     *         discount_total_price: string,
     *         subtotal_total_price_after: string
     *     }>,
     *     discount_stay_total_price: string,
     *     final_stay_total_price: string
     * }
     */
    public function applyToSubtotal(
        BigDecimal $subtotal,
        int $nights,
        CarbonImmutable $checkIn,
        CarbonImmutable $bookingDate,
    ): array {
        $lines = [];
        $running = $subtotal;
        $totalDiscount = Money::zero();

        $longCfg = config('hotel.discounts.long_stay', []);
        $longThreshold = (int) ($longCfg['min_nights'] ?? 3);
        $longPercent = (string) ($longCfg['percent'] ?? '0');

        if ($nights > $longThreshold && BigDecimal::of($longPercent)->isPositive()) {
            $appliedTo = $running;
            [$discountLine, $running] = Money::applyPercentLine($running, $longPercent);
            $totalDiscount = $totalDiscount->plus($discountLine);
            $lines[] = [
                'code' => 'long_stay',
                'label' => 'Long stay',
                'percent' => (float) $longPercent,
                'applied_to_total_price' => Money::toResponseMoney($appliedTo),
                'discount_total_price' => Money::toResponseMoney($discountLine),
                'subtotal_total_price_after' => Money::toResponseMoney($running),
            ];
        }

        $lmCfg = config('hotel.discounts.last_minute', []);
        $lmWindow = (int) ($lmCfg['days_before_check_in'] ?? 2);
        $lmPercent = (string) ($lmCfg['percent'] ?? '0');
        $leadDays = $bookingDate->diffInDays($checkIn, false);
        $isLastMinute = $checkIn->greaterThanOrEqualTo($bookingDate)
            && $leadDays >= 0
            && $leadDays <= $lmWindow;

        if ($isLastMinute && BigDecimal::of($lmPercent)->isPositive()) {
            $appliedTo = $running;
            [$discountLine, $running] = Money::applyPercentLine($running, $lmPercent);
            $totalDiscount = $totalDiscount->plus($discountLine);
            $lines[] = [
                'code' => 'last_minute',
                'label' => 'Last minute',
                'percent' => (float) $lmPercent,
                'applied_to_total_price' => Money::toResponseMoney($appliedTo),
                'discount_total_price' => Money::toResponseMoney($discountLine),
                'subtotal_total_price_after' => Money::toResponseMoney($running),
            ];
        }

        return [
            'lines' => $lines,
            'discount_stay_total_price' => Money::toResponseMoney($totalDiscount),
            'final_stay_total_price' => Money::toResponseMoney($running),
        ];
    }
}
