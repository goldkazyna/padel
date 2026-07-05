<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Игроки турнира «Just Padel It» — стат
        Schema::create('just_padel_it_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('total_points')->default(0);
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->integer('points_for')->default(0);
            $table->integer('points_against')->default(0);
            $table->integer('rating_before')->nullable();
            $table->integer('rating_after')->nullable();
            $table->timestamps();

            $table->unique(['tournament_id', 'user_id']);
        });

        // Раунды
        Schema::create('just_padel_it_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->integer('round_number');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamps();

            $table->unique(['tournament_id', 'round_number']);
            $table->index(['tournament_id', 'status']);
        });

        // Матчи раунда (4 игрока на корт)
        Schema::create('just_padel_it_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('just_padel_it_round_id')->constrained('just_padel_it_rounds')->cascadeOnDelete();
            $table->integer('court_number');
            $table->foreignId('team1_player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team1_player2_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team2_player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team2_player2_id')->constrained('users')->cascadeOnDelete();
            $table->integer('team1_score')->nullable();
            $table->integer('team2_score')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamps();

            $table->index('just_padel_it_round_id', 'jpi_matches_round_idx');
        });

        // Фиксированные пары для «Just Padel It» (is_paired). Создаются админом
        // после набора, до старта. Аналог kingofcourt_pairs.
        Schema::create('just_padel_it_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player2_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('tournament_id', 'jpi_pairs_tournament_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('just_padel_it_pairs');
        Schema::dropIfExists('just_padel_it_matches');
        Schema::dropIfExists('just_padel_it_rounds');
        Schema::dropIfExists('just_padel_it_players');
    }
};
