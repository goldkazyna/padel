<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coach_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('club_id')->constrained('clubs')->onDelete('cascade');
            // Настенное время Алматы — так же, как tournaments.start_date.
            $table->dateTime('starts_at');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedInteger('price')->default(0);
            $table->unsignedTinyInteger('capacity')->default(4);
            $table->text('description')->nullable();
            $table->enum('status', ['planned', 'completed', 'cancelled'])->default('planned');
            $table->timestamps();

            // Список игрока сортируется по времени начала.
            $table->index('starts_at');
            $table->index(['coach_id', 'starts_at']);
        });

        Schema::create('training_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_id')->constrained('trainings')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            // Отметки об отправленных напоминаниях — чтобы пуш не ушёл дважды.
            $table->dateTime('reminded_1d_at')->nullable();
            $table->dateTime('reminded_2h_at')->nullable();
            $table->dateTime('reminded_1h_at')->nullable();
            $table->timestamps();

            // Двойной тап по «Записаться» не должен создавать вторую запись.
            $table->unique(['training_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_participants');
        Schema::dropIfExists('trainings');
    }
};
