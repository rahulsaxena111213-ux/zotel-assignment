<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blocks inventory for a specific room for a half-open interval:
 * occupied nights are check_in_date <= night < check_out_date.
 * Cancelled rows do not block (filtered in queries / soft logic).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')
                ->constrained('rooms')
                ->cascadeOnDelete();
            $table->date('check_in_date');
            $table->date('check_out_date')->comment('Exclusive: guest departs this morning');
            $table->unsignedTinyInteger('guest_count')->default(1);
            /** @var string confirmed|cancelled|no_show */
            $table->string('status', 32)->default('confirmed');
            $table->timestamps();

            $table->index(['room_id', 'status'], 'reservations_room_status_idx');
            $table->index(['check_in_date', 'check_out_date'], 'reservations_stay_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
