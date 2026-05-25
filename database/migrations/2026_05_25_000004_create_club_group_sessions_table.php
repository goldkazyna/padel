<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_group_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('club_groups')->cascadeOnDelete();
            $table->foreignId('court_id')->constrained('courts')->cascadeOnDelete();
            $table->foreignId('court_booking_id')->nullable()->constrained('court_bookings')->nullOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('coach_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['planned', 'held', 'cancelled'])->default('planned');
            $table->timestamp('held_at')->nullable();
            $table->foreignId('conducted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'date']);
            $table->index(['court_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_group_sessions');
    }
};
