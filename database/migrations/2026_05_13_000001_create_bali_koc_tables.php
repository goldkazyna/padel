<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Пары турнира «Король Корта (Bali Format)» — фиксированные на весь турнир.
        // Турнирные очки начисляются по корту: победитель корта K из N кортов
        // получает (N+2−K) очков; раунд 1 всегда 1/0.
        Schema::create('bali_koc_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player2_id')->constrained('users')->cascadeOnDelete();
            $table->integer('points')->default(0);
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->integer('games_for')->default(0);
            $table->integer('games_against')->default(0);
            // Рейтинги обоих игроков сохраняем независимо — у каждого свой ELO.
            $table->integer('player1_rating_before')->nullable();
            $table->integer('player1_rating_after')->nullable();
            $table->integer('player2_rating_before')->nullable();
            $table->integer('player2_rating_after')->nullable();
            $table->timestamps();

            $table->index('tournament_id');
        });

        Schema::create('bali_koc_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->integer('round_number');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamps();

            $table->unique(['tournament_id', 'round_number']);
            $table->index(['tournament_id', 'status']);
        });

        Schema::create('bali_koc_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bali_koc_round_id')->constrained('bali_koc_rounds')->cascadeOnDelete();
            $table->integer('court_number');
            $table->foreignId('pair1_id')->constrained('bali_koc_pairs')->cascadeOnDelete();
            $table->foreignId('pair2_id')->constrained('bali_koc_pairs')->cascadeOnDelete();
            $table->integer('pair1_games')->nullable();
            $table->integer('pair2_games')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamps();

            $table->index('bali_koc_round_id', 'bali_koc_matches_round_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bali_koc_matches');
        Schema::dropIfExists('bali_koc_rounds');
        Schema::dropIfExists('bali_koc_pairs');
    }
};
