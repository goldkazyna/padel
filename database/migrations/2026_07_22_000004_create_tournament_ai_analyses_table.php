<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('content');           // структурированный разбор (headline/summary/factors/...)
            $table->string('model')->nullable(); // какая модель сгенерировала
            $table->string('lang', 5)->default('ru');
            $table->timestamps();

            $table->unique(['tournament_id', 'user_id']); // один разбор на игрока/турнир
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_ai_analyses');
    }
};
