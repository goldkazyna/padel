<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('court_booking_coaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_booking_id')->constrained('court_bookings')->cascadeOnDelete();
            // Тренер (users.id) — как в court_bookings.coach_id.
            $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('coach_price', 12, 2)->nullable(); // цена этого тренера
            $table->boolean('coach_paid')->default(false);      // выплачено ли ему
            $table->timestamps();

            $table->unique(['court_booking_id', 'coach_id']);
            $table->index('coach_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('court_booking_coaches');
    }
};
