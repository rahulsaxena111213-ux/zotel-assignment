<?php

namespace Database\Seeders;

use App\Enums\ReservationStatus;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\RatePlanDiscount;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HotelSeeder extends Seeder
{
    public function run(): void
    {
        $today = CarbonImmutable::today();

        DB::transaction(function () use ($today) {
            Reservation::query()->delete();
            RatePlanDiscount::query()->delete();
            RatePlanPrice::query()->delete();
            RatePlan::query()->delete();
            Room::query()->delete();
            RoomType::query()->delete();

            $standard = RoomType::query()->create([
                'code' => 'standard',
                'name' => 'Standard',
                'max_adults' => 3,
                'description' => 'Comfortable city-view room.',
                'is_active' => true,
            ]);

            $deluxe = RoomType::query()->create([
                'code' => 'deluxe',
                'name' => 'Deluxe',
                'max_adults' => 4,
                'description' => 'Larger room with premium amenities.',
                'is_active' => true,
            ]);

            $this->seedRooms($standard, 'STD', 5);
            $this->seedRooms($deluxe, 'DLX', 5);

            $this->seedRatePlans($standard, $today, 30);
            $this->seedRatePlans($deluxe, $today, 30);

            $this->seedDiscountRules($standard, $deluxe);

            $stdRoom = Room::query()->where('room_type_id', $standard->id)->orderBy('id')->first();
            if ($stdRoom) {
                Reservation::query()->create([
                    'room_id' => $stdRoom->id,
                    'check_in_date' => $today->addDays(3),
                    'check_out_date' => $today->addDays(6),
                    'guest_count' => 2,
                    'status' => ReservationStatus::Confirmed,
                ]);
            }
        });

        Cache::flush();
    }

    private function seedRooms(RoomType $type, string $prefix, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Room::query()->create([
                'room_type_id' => $type->id,
                'code' => sprintf('%s-%02d', $prefix, $i),
                'sort_order' => $i,
                'is_active' => true,
            ]);
        }
    }

    private function seedRatePlans(RoomType $type, CarbonImmutable $start, int $days): void
    {
        $planDefs = match ($type->code) {
            'standard' => [
                ['code' => 'EP', 'name' => 'Room Only', 'meal_plan' => 'room_only', 'addon' => 0.0],
                ['code' => 'CP', 'name' => 'Breakfast Included', 'meal_plan' => 'breakfast_included', 'addon' => 18.0],
            ],
            'deluxe' => [
                ['code' => 'CP', 'name' => 'Breakfast Included', 'meal_plan' => 'breakfast_included', 'addon' => 25.0],
                ['code' => 'MAP', 'name' => 'All Meals Included', 'meal_plan' => 'all_meals_included', 'addon' => 55.0],
            ],
            default => [],
        };

        foreach ($planDefs as $planDef) {
            $plan = RatePlan::query()->create([
                'room_type_id' => $type->id,
                'code' => $planDef['code'],
                'name' => $planDef['name'],
                'meal_plan' => $planDef['meal_plan'],
                'is_active' => true,
            ]);

            for ($d = 0; $d < $days; $d++) {
                $date = $start->addDays($d);
                $isWeekend = $date->isSaturday() || $date->isSunday();

                $base = match ($type->code) {
                    'standard' => $isWeekend ? 120.00 : 95.00,
                    'deluxe' => $isWeekend ? 185.00 : 155.00,
                    default => 100.00,
                };

                $amount = round($base + $planDef['addon'], 2);

                RatePlanPrice::query()->create([
                    'rate_plan_id' => $plan->id,
                    'rate_date' => $date,
                    'amount' => $amount,
                ]);
            }
        }
    }

    private function seedDiscountRules(RoomType $standard, RoomType $deluxe): void
    {
        $ratePlans = RatePlan::query()->whereIn('room_type_id', [$standard->id, $deluxe->id])->get();

        foreach ($ratePlans as $plan) {
            $discountPercent = in_array($plan->code, ['CP', 'MAP'], true) ? 10.00 : 5.00;

            RatePlanDiscount::query()->create([
                'rate_plan_id' => $plan->id,
                'code' => 'early_bird',
                'name' => 'Early bird discount',
                'amount_type' => 'percent',
                'amount' => $discountPercent,
                'rules' => [],
                'is_active' => true,
            ]);
        }
    }
}
