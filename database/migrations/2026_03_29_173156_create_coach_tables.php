<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_coaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('specialization')->nullable();
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'user_id']);
        });

        Schema::create('coach_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_coach_id')->constrained('club_coaches')->cascadeOnDelete();
            $table->tinyInteger('day_of_week'); // 1-7
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
        });

        Schema::create('coach_schedule_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_coach_id')->constrained('club_coaches')->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->boolean('is_available')->default(false);
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::table('court_bookings', function (Blueprint $table) {
            $table->foreignId('coach_id')->nullable()->after('comment')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('court_bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coach_id');
        });

        Schema::dropIfExists('coach_schedule_overrides');
        Schema::dropIfExists('coach_schedules');
        Schema::dropIfExists('club_coaches');
    }
};
