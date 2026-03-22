<?php

namespace Database\Seeders;

use App\Enums\MealPlan;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomDailyRate;
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
            RoomDailyRate::query()->delete();
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
                'max_adults' => 3,
                'description' => 'Larger room with premium amenities.',
                'is_active' => true,
            ]);

            $this->seedRooms($standard, 'STD', 5);
            $this->seedRooms($deluxe, 'DLX', 5);

            $this->seedRatesForWindow($standard, $today, 30);
            $this->seedRatesForWindow($deluxe, $today, 30);

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

    private function seedRatesForWindow(RoomType $type, CarbonImmutable $start, int $days): void
    {
        for ($d = 0; $d < $days; $d++) {
            $date = $start->addDays($d);
            $isWeekend = $date->isSaturday() || $date->isSunday();

            $roomOnly = match ($type->code) {
                'standard' => $isWeekend ? 120.00 : 95.00,
                'deluxe' => $isWeekend ? 185.00 : 155.00,
                default => 100.00,
            };

            $breakfastExtra = match ($type->code) {
                'standard' => 18.00,
                'deluxe' => 25.00,
                default => 15.00,
            };

            RoomDailyRate::query()->create([
                'room_type_id' => $type->id,
                'rate_date' => $date,
                'meal_plan' => MealPlan::RoomOnly->value,
                'amount' => $roomOnly,
            ]);

            RoomDailyRate::query()->create([
                'room_type_id' => $type->id,
                'rate_date' => $date,
                'meal_plan' => MealPlan::BreakfastIncluded->value,
                'amount' => round($roomOnly + $breakfastExtra, 2),
            ]);
        }
    }
}
