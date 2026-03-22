<?php

namespace App\Services;

use App\Enums\MealPlan;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomDailyRate;
use App\Models\RoomType;
use App\Services\Hotel\CurrencyPresenter;
use App\Services\Hotel\DiscountPipeline;
use App\Services\Hotel\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

class SearchService
{
    public function __construct(
        private readonly DiscountPipeline $discounts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function search(
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        int $guestCount,
        MealPlan $mealPlan,
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
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $busyRoomIds = Reservation::query()
            ->blockingInventory()
            ->overlappingStay($checkIn, $checkOut)
            ->pluck('room_id')
            ->unique()
            ->all();

        $roomOnlyMatrix = $this->loadRoomOnlyRatesMatrix(
            $roomTypes->pluck('id')->all(),
            $checkIn,
            $lastNight,
        );

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
                $roomOnlyMatrix,
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
                'meal_plan' => $mealPlan->value,
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
        MealPlan $mealPlan,
        int $nights,
        CarbonImmutable $checkIn,
        CarbonImmutable $bookingDate,
        array $busyRoomIds,
        array $roomOnlyMatrix,
        bool $debug,
    ): array {
        $maxPerRoom = max(1, (int) $type->max_adults);
        $requiredRooms = (int) ceil($guestCount / $maxPerRoom);

        $availableRooms = Room::query()
            ->where('room_type_id', $type->id)
            ->where('is_active', true)
            ->whereNotIn('id', $busyRoomIds)
            ->count();

        $ratesComplete = $nights > 0 && $this->ratesCompleteForStay($roomOnlyMatrix, $type->id, $checkIn, $nights);

        $status = $this->resolveInventoryStatus(
            $nights,
            $ratesComplete,
            $requiredRooms,
            $availableRooms,
        );

        $bookable = $status === 'available';

        $roomOnlyStayTotalBd = null;
        if ($ratesComplete) {
            $oneRoomStay = $this->sumNightlyRates($roomOnlyMatrix, $type->id, $checkIn, $nights);
            $roomOnlyStayTotalBd = $oneRoomStay->multipliedBy((string) $requiredRooms);
        }

        $mealAddonPerRoomNight = $this->mealPlanAddonPerRoomPerNight($mealPlan, $type->code);
        $mealPlanStayTotalBd = Money::zero();
        if ($roomOnlyStayTotalBd !== null) {
            $mealPlanStayTotalBd = $mealAddonPerRoomNight
                ->multipliedBy((string) $requiredRooms)
                ->multipliedBy((string) max(0, $nights));
        }

        $preDiscountBd = $roomOnlyStayTotalBd !== null
            ? $roomOnlyStayTotalBd->plus($mealPlanStayTotalBd)
            : null;

        $averagePreDiscountPerNight = null;
        if ($preDiscountBd !== null && $nights > 0) {
            $averagePreDiscountPerNight = Money::toResponseMoney(
                $preDiscountBd->dividedBy((string) $nights, 8, RoundingMode::HALF_UP)
            );
        }

        $discountPayload = null;
        $finalStayTotalPrice = null;

        if ($preDiscountBd !== null && ! in_array($status, ['rates_unavailable', 'invalid_stay_length'], true)) {
            $discountPayload = $this->discounts->applyToSubtotal(
                $preDiscountBd,
                $nights,
                $checkIn,
                $bookingDate,
            );
            $finalStayTotalPrice = $discountPayload['final_stay_total_price'];
        }

        $finalAveragePricePerNight = null;
        if ($finalStayTotalPrice !== null && $nights > 0) {
            $finalAveragePricePerNight = Money::toResponseMoney(
                Money::of($finalStayTotalPrice)->dividedBy((string) $nights, 8, RoundingMode::HALF_UP)
            );
        }

        $availability = [
            'bookable' => $bookable,
            'status' => $status,
        ];
        if ($status === 'partial_available') {
            $availability['short_by_rooms'] = max(0, $requiredRooms - $availableRooms);
        }

        $discountsResponse = null;
        if ($discountPayload !== null) {
            $discountsResponse = [
                'discount_stay_total_price' => $discountPayload['discount_stay_total_price'],
            ];
            if ($debug) {
                $discountsResponse['lines'] = $discountPayload['lines'];
            }
        }

        return [
            'room_type_code' => $type->code,
            'room_type_name' => $type->name,
            'inventory' => [
                'required_rooms' => $requiredRooms,
                'available_rooms' => $availableRooms,
            ],
            'availability' => $availability,
            'pricing' => [
                'meal_plan_type' => $mealPlan->value,
                'room_only_stay_total_price' => $roomOnlyStayTotalBd !== null
                    ? Money::toResponseMoney($roomOnlyStayTotalBd)
                    : null,
                'meal_plan_price_total' => $roomOnlyStayTotalBd !== null
                    ? Money::toResponseMoney($mealPlanStayTotalBd)
                    : null,
                'pre_discount_stay_total_price' => $preDiscountBd !== null
                    ? Money::toResponseMoney($preDiscountBd)
                    : null,
                'average_pre_discount_stay_total_price_per_night' => $averagePreDiscountPerNight,
            ],
            'discounts' => $discountsResponse,
            'final_stay_total_price' => $finalStayTotalPrice,
            'final_average_price_per_night' => $finalAveragePricePerNight,
        ];
    }

    /**
     * @return 'available'|'sold_out'|'partial_available'|'rates_unavailable'|'invalid_stay_length'
     */
    private function resolveInventoryStatus(
        int $nights,
        bool $ratesComplete,
        int $requiredRooms,
        int $availableRooms,
    ): string {
        if ($nights <= 0) {
            return 'invalid_stay_length';
        }

        if (! $ratesComplete) {
            return 'rates_unavailable';
        }

        if ($availableRooms >= $requiredRooms) {
            return 'available';
        }

        if ($availableRooms === 0) {
            return 'sold_out';
        }

        return 'partial_available';
    }

    private function mealPlanAddonPerRoomPerNight(MealPlan $mealPlan, string $roomTypeCode): BigDecimal
    {
        $addons = config('hotel.meal_plan_addons_per_room_per_night', []);
        $slice = $addons[$mealPlan->value] ?? null;

        if (! is_array($slice)) {
            return Money::zero();
        }

        $raw = $slice[$roomTypeCode] ?? $slice['default'] ?? '0';

        return Money::of((string) $raw);
    }

    /**
     * @param  list<int>  $roomTypeIds
     * @return array<int, array<string, string>>
     */
    private function loadRoomOnlyRatesMatrix(
        array $roomTypeIds,
        CarbonImmutable $firstNight,
        CarbonImmutable $lastNight,
    ): array {
        if ($roomTypeIds === [] || $firstNight->gt($lastNight)) {
            return [];
        }

        $ttl = max(0, (int) config('hotel.search_cache_ttl_seconds', 0));
        $cacheKey = sprintf(
            'hotel:rates:room_only:%s:%s:%s',
            $firstNight->toDateString(),
            $lastNight->toDateString(),
            implode('-', $roomTypeIds),
        );

        $rows = $ttl > 0
            ? Cache::remember($cacheKey, $ttl, fn () => $this->fetchRoomOnlyRateRows($roomTypeIds, $firstNight, $lastNight))
            : $this->fetchRoomOnlyRateRows($roomTypeIds, $firstNight, $lastNight);

        $matrix = [];
        foreach ($rows as $row) {
            $tid = (int) $row['room_type_id'];
            $date = $row['rate_date'];
            $matrix[$tid][$date] = $row['amount'];
        }

        return $matrix;
    }

    /**
     * @param  list<int>  $roomTypeIds
     * @return list<array{room_type_id: int, rate_date: string, amount: string}>
     */
    private function fetchRoomOnlyRateRows(
        array $roomTypeIds,
        CarbonImmutable $firstNight,
        CarbonImmutable $lastNight,
    ): array {
        return RoomDailyRate::query()
            ->whereIn('room_type_id', $roomTypeIds)
            ->where('meal_plan', MealPlan::RoomOnly->value)
            ->whereDate('rate_date', '>=', $firstNight->startOfDay())
            ->whereDate('rate_date', '<=', $lastNight->startOfDay())
            ->orderBy('room_type_id')
            ->orderBy('rate_date')
            ->get(['room_type_id', 'rate_date', 'amount'])
            ->map(fn (RoomDailyRate $r) => [
                'room_type_id' => (int) $r->room_type_id,
                'rate_date' => $r->rate_date->toDateString(),
                'amount' => (string) $r->amount,
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, string>>  $matrix
     */
    private function ratesCompleteForStay(array $matrix, int $roomTypeId, CarbonImmutable $checkIn, int $nights): bool
    {
        if ($nights <= 0) {
            return false;
        }

        $cursor = $checkIn;
        for ($i = 0; $i < $nights; $i++) {
            $key = $cursor->toDateString();
            if (! isset($matrix[$roomTypeId][$key])) {
                return false;
            }
            $cursor = $cursor->addDay();
        }

        return true;
    }

    /**
     * @param  array<int, array<string, string>>  $matrix
     */
    private function sumNightlyRates(array $matrix, int $roomTypeId, CarbonImmutable $checkIn, int $nights): BigDecimal
    {
        $sum = Money::zero();
        $cursor = $checkIn;
        for ($i = 0; $i < $nights; $i++) {
            $sum = $sum->plus(Money::of($matrix[$roomTypeId][$cursor->toDateString()]));
            $cursor = $cursor->addDay();
        }

        return $sum;
    }
}
