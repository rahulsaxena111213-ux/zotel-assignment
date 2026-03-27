<?php

use App\Enums\MealPlan;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\RoomDailyRate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $existingPlanners = [];

            /** @var RoomDailyRate[] $rates */
            $rates = RoomDailyRate::query()->orderBy('room_type_id')->orderBy('rate_date')->get();

            foreach ($rates as $rate) {
                $roomTypeId = $rate->room_type_id;
                $planCode = match ($rate->meal_plan) {
                    MealPlan::RoomOnly->value => 'EP',
                    MealPlan::BreakfastIncluded->value => 'CP',
                    default => strtoupper($rate->meal_plan),
                };

                if (! isset($existingPlanners[$roomTypeId][$planCode])) {
                    $existingPlanners[$roomTypeId][$planCode] = RatePlan::query()->firstOrCreate([
                        'room_type_id' => $roomTypeId,
                        'code' => $planCode,
                    ], [
                        'name' => $planCode === 'EP' ? 'Room Only (EP)' : ($planCode === 'CP' ? 'Breakfast Included (CP)' : strtoupper($planCode)),
                        'meal_plan' => $rate->meal_plan,
                        'is_active' => true,
                    ]);
                }

                /** @var RatePlan $plan */
                $plan = $existingPlanners[$roomTypeId][$planCode];

                RatePlanPrice::query()->updateOrCreate([
                    'rate_plan_id' => $plan->id,
                    'rate_date' => $rate->rate_date,
                ], ['amount' => $rate->amount]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table('rate_plan_prices')->delete();
            DB::table('rate_plans')->delete();
        });
    }
};
