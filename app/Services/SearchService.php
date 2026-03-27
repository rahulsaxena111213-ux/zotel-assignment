<?php

namespace App\Services;

use App\Enums\MealPlan;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\RatePlanDiscount;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\Hotel\CurrencyPresenter;
use App\Services\Hotel\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

class SearchService
{
    public function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function search(
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        int $guestCount,
        ?MealPlan $mealPlan = null,
        ?CarbonInterface $bookingDate = null,
        bool $debug = false,
    ): array {
        $checkIn = CarbonImmutable::instance($checkIn)->startOfDay();
        $checkOut = CarbonImmutable::instance($checkOut)->startOfDay();
        $bookingDate = $bookingDate
            ? CarbonImmutable::instance($bookingDate)->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $nights = (int) $checkIn->diffInDays($checkOut);
        $lastNight = $checkOut->subDay();

        $currency = CurrencyPresenter::forApp();

        $roomTypes = RoomType::query()
            ->with(['ratePlans.prices', 'ratePlans.discounts'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $busyRoomIds = Reservation::query()
            ->blockingInventory()
            ->overlappingStay($checkIn, $checkOut)
            ->pluck('room_id')
            ->unique()
            ->all();

        $results = [];
        foreach ($roomTypes as $type) {
            $results[] = $this->buildRoomTypeResult(
                $type,
                $guestCount,
                $mealPlan,
                $nights,
                $checkIn,
                $bookingDate,
                $busyRoomIds,
                $debug,
            );
        }

        return [
            'meta' => [
                'currency' => $currency,
                'amounts_are_decimal_strings' => true,
                'debug' => $debug,
                'check_in_date' => $checkIn->toDateString(),
                'check_out_date' => $checkOut->toDateString(),
                'nights' => $nights,
                'guest_count' => $guestCount,
                'booking_date' => $bookingDate->toDateString(),
            ],
            'results' => $results,
        ];
    }

    /**
     * @param  array<int, array<string, string>>  $roomOnlyMatrix
     * @param  list<int|string>  $busyRoomIds
     * @return array<string, mixed>
     */
    private function buildRoomTypeResult(
        RoomType $type,
        int $guestCount,
        ?MealPlan $mealPlan,
        int $nights,
        CarbonImmutable $checkIn,
        CarbonImmutable $bookingDate,
        array $busyRoomIds,
        bool $debug,
    ): array {
        $maxPerRoom = max(1, (int) $type->max_adults);
        $requiredRooms = (int) ceil($guestCount / $maxPerRoom);

        $availableRooms = Room::query()
            ->where('room_type_id', $type->id)
            ->where('is_active', true)
            ->whereNotIn('id', $busyRoomIds)
            ->count();

        $ratePlans = $type->ratePlans
            ->where('is_active', true)
            ->when($mealPlan !== null, function ($collection) use ($mealPlan) {
                return $collection->where('code', $mealPlan->toRatePlanCode());
            });

        $order = ['EP', 'CP', 'MAP'];
        $ratePlans = $ratePlans->sortBy(fn ($plan) => array_search($plan->code, $order, true) ?? 99)->values();

        $planResults = [];
        foreach ($ratePlans as $plan) {
            $planMatrix = $this->loadRatePlanMatrix($plan->id, $checkIn, $checkIn->addDays(max(0, $nights - 1)));
            $planRatesComplete = $nights > 0 && $this->ratesCompleteForStay($planMatrix, $checkIn, $nights);

            $planRoomTotal = null;
            if ($planRatesComplete) {
                $oneRoomStay = $this->sumNightlyRates($planMatrix, $checkIn, $nights);
                $planRoomTotal = $oneRoomStay->multipliedBy((string) $requiredRooms);
            }

            $planDiscountPayload = null;
            $planFinalTotal = null;
            $planDiscountPercentage = 0.0;

            if ($planRoomTotal !== null && $nights > 0) {
                $planDiscountPayload = $this->applyRatePlanDiscounts($planRoomTotal, $plan, $nights, $checkIn, $bookingDate);
                $planFinalTotal = $planDiscountPayload['final_stay_total_price'];
                $planDiscountPercentage = $planDiscountPayload['discount_percentage'] ?? 0.0;
            }

            $planResults[] = [
                'rate_plan_code' => $plan->code,
                'rate_plan_name' => $plan->name,
                'meal_plan' => $plan->meal_plan,
                'rate_plan_available' => $planRatesComplete,
                'base_price' => $planRoomTotal !== null ? Money::toResponseMoney($planRoomTotal) : null,
                'discount_percentage' => $planDiscountPercentage,
                'discount_amount' => $planDiscountPayload !== null ? $planDiscountPayload['discount_stay_total_price'] : null,
                'final_price' => $planFinalTotal,
                'price_per_night' => $planFinalTotal !== null && $nights > 0 ? Money::toResponseMoney(Money::of($planFinalTotal)->dividedBy((string) $nights, 8, RoundingMode::HALF_UP)) : null,
                'discounts' => $planDiscountPayload !== null ? [
                    'discount_stay_total_price' => $planDiscountPayload['discount_stay_total_price'],
                    'lines' => $debug ? $planDiscountPayload['lines'] : null,
                ] : null,
            ];
        }

        $ratesComplete = count($planResults) > 0 && collect($planResults)->contains(fn ($it) => $it['rate_plan_available'] === true);

        $status = $this->resolveInventoryStatus(
            $nights,
            $ratesComplete,
            $requiredRooms,
            $availableRooms,
        );

        $availability = [
            'bookable' => $status === 'available',
            'status' => $status,
        ];
        if ($status === 'partial_available') {
            $availability['short_by_rooms'] = max(0, $requiredRooms - $availableRooms);
        }

        return [
            'room_type_code' => $type->code,
            'room_type_name' => $type->name,
            'inventory' => [
                'required_rooms' => $requiredRooms,
                'available_rooms' => $availableRooms,
            ],
            'availability' => $availability,
            'rate_plans' => $planResults,
            'pricing' => [
                'rate_plans_count' => count($planResults),
            ],
        ];
    }

    /**
     * @return 'available'|'sold_out'|'partial_available'|'invalid_stay_length'
     */
    private function resolveInventoryStatus(
        int $nights,
        bool $hasAvailableRatePlan,
        int $requiredRooms,
        int $availableRooms,
    ): string {
        if ($nights <= 0) {
            return 'invalid_stay_length';
        }

        if (! $hasAvailableRatePlan) {
            return 'sold_out';
        }

        if ($availableRooms >= $requiredRooms) {
            return 'available';
        }

        if ($availableRooms === 0) {
            return 'sold_out';
        }

        return 'partial_available';
    }

    /**
     * @param  int  $ratePlanId
     * @return array<string, string>
     */
    private function loadRatePlanMatrix(int $ratePlanId, CarbonImmutable $firstNight, CarbonImmutable $lastNight): array
    {
        if ($firstNight->gt($lastNight)) {
            return [];
        }

        $ttl = max(0, (int) config('hotel.search_cache_ttl_seconds', 0));
        $cacheKey = sprintf(
            'hotel:rates:rate_plan:%s:%s:%s',
            $ratePlanId,
            $firstNight->toDateString(),
            $lastNight->toDateString(),
        );

        $rows = $ttl > 0
            ? Cache::remember($cacheKey, $ttl, fn () => $this->fetchRatePlanRows($ratePlanId, $firstNight, $lastNight))
            : $this->fetchRatePlanRows($ratePlanId, $firstNight, $lastNight);

        $matrix = [];
        foreach ($rows as $row) {
            $matrix[$row['rate_date']] = $row['amount'];
        }

        return $matrix;
    }

    /**
     * @return list<array{rate_plan_id: int, rate_date: string, amount: string}>
     */
    private function fetchRatePlanRows(int $ratePlanId, CarbonImmutable $firstNight, CarbonImmutable $lastNight): array
    {
        return RatePlanPrice::query()
            ->where('rate_plan_id', $ratePlanId)
            ->whereDate('rate_date', '>=', $firstNight->startOfDay())
            ->whereDate('rate_date', '<=', $lastNight->startOfDay())
            ->orderBy('rate_date')
            ->get(['rate_plan_id', 'rate_date', 'amount'])
            ->map(fn (RatePlanPrice $r) => [
                'rate_plan_id' => (int) $r->rate_plan_id,
                'rate_date' => $r->rate_date->toDateString(),
                'amount' => (string) $r->amount,
            ])
            ->all();
    }

    private function applyRatePlanDiscounts(
        BigDecimal $subtotal,
        RatePlan $plan,
        int $nights,
        CarbonImmutable $checkIn,
        CarbonImmutable $bookingDate,
    ): array {
        $lines = [];
        $running = $subtotal;
        $totalDiscount = Money::zero();

        $discounts = $plan->discounts()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $totalPercent = 0.0;

        foreach ($discounts as $discount) {
            if (! $discount->isApplicable($checkIn, $bookingDate)) {
                continue;
            }

            if ($discount->amount_type === 'percent') {
                [$discountLine, $running] = Money::applyPercentLine($running, (string) $discount->amount);
                $totalPercent += (float) $discount->amount;
            } else {
                $discountLine = BigDecimal::of((string) $discount->amount)->toScale(2, RoundingMode::HALF_UP);
                $running = $running->minus($discountLine);
            }

            $totalDiscount = $totalDiscount->plus($discountLine);

            $lines[] = [
                'code' => $discount->code,
                'label' => $discount->name,
                'percent' => $discount->amount_type === 'percent' ? (float) $discount->amount : null,
                'applied_to_total_price' => Money::toResponseMoney($running->plus($discountLine)),
                'discount_total_price' => Money::toResponseMoney($discountLine),
                'subtotal_total_price_after' => Money::toResponseMoney($running),
            ];
        }

        return [
            'lines' => $lines,
            'discount_stay_total_price' => Money::toResponseMoney($totalDiscount),
            'discount_percentage' => $totalPercent,
            'final_stay_total_price' => Money::toResponseMoney($running),
        ];
    }

    /**
     * @param  array<string, string>  $matrix
     */
    private function ratesCompleteForStay(array $matrix, CarbonImmutable $checkIn, int $nights): bool
    {
        if ($nights <= 0) {
            return false;
        }

        $cursor = $checkIn;
        for ($i = 0; $i < $nights; $i++) {
            $key = $cursor->toDateString();
            if (! isset($matrix[$key])) {
                return false;
            }
            $cursor = $cursor->addDay();
        }

        return true;
    }

    /**
     * @param  array<string, string>  $matrix
     */
    private function sumNightlyRates(array $matrix, CarbonImmutable $checkIn, int $nights): BigDecimal
    {
        $sum = Money::zero();
        $cursor = $checkIn;

        for ($i = 0; $i < $nights; $i++) {
            $sum = $sum->plus(Money::of($matrix[$cursor->toDateString()]));
            $cursor = $cursor->addDay();
        }

        return $sum;
    }
}
