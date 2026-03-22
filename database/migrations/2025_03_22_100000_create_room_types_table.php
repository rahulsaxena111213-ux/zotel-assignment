<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog of sellable room categories (e.g. Standard, Deluxe).
 * Pricing and policies attach to room_type; physical units live in `rooms`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique()->comment('Stable API key: standard, deluxe');
            $table->string('name');
            $table->unsignedTinyInteger('max_adults')->default(3);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_types');
    }
};
