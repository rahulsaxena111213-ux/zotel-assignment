<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nightly rate calendar: one amount per room type, calendar date, and meal plan.
 * Keeps pricing out of room_types and allows yield management per day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_daily_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')
                ->constrained('room_types')
                ->cascadeOnDelete();
            $table->date('rate_date');
            /** @var string room_only|breakfast_included — validated in application layer */
            $table->string('meal_plan', 32);
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique(['room_type_id', 'rate_date', 'meal_plan'], 'room_daily_rates_unique_slot');
            $table->index(['room_type_id', 'rate_date'], 'room_daily_rates_type_date_idx');
            $table->index('rate_date', 'room_daily_rates_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_daily_rates');
    }
};
