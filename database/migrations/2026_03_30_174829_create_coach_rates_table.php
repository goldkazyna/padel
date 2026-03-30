<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('coach_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_coach_id')->constrained('club_coaches')->cascadeOnDelete();
            $table->tinyInteger('hours'); // 1, 2, 3, 4...
            $table->decimal('rate', 10, 2);
            $table->timestamps();
            $table->unique(['club_coach_id', 'hours']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coach_rates');
    }
};
