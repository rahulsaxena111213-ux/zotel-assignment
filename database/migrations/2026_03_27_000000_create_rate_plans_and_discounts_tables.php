<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained('room_types')->cascadeOnDelete();
            $table->string('code', 16)->comment('Vendor code: EP, CP, MAP');
            $table->string('name');
            $table->string('meal_plan', 32)->comment('room_only, breakfast_included, all_meals_included');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['room_type_id', 'code'], 'rate_plans_room_type_code_unique');
            $table->index(['room_type_id', 'meal_plan'], 'rate_plans_room_type_meal_plan_idx');
        });

        Schema::create('rate_plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained('rate_plans')->cascadeOnDelete();
            $table->date('rate_date');
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique(['rate_plan_id', 'rate_date'], 'rate_plan_prices_unique');
            $table->index(['rate_plan_id', 'rate_date'], 'rate_plan_prices_plan_date_idx');
            $table->index('rate_date', 'rate_plan_prices_date_idx');
        });

        Schema::create('rate_plan_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_plan_id')->constrained('rate_plans')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->enum('amount_type', ['percent', 'fixed'])->default('percent');
            $table->decimal('amount', 10, 2);
            $table->json('rules')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['rate_plan_id', 'code'], 'rate_plan_discounts_plan_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_plan_discounts');
        Schema::dropIfExists('rate_plan_prices');
        Schema::dropIfExists('rate_plans');
    }
};
