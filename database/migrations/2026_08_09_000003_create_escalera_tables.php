<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Участник турнира. start_court нужен для награды «Восхождение»,
        // wins — для первого тай-брейка итоговой таблицы.
        Schema::create('escalera_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('total_points')->default(0);
            $table->unsignedSmallInteger('start_court')->nullable();
            $table->unsignedSmallInteger('current_court')->nullable();
            $table->integer('wins')->default(0);
            $table->integer('rating_before')->nullable();
            $table->integer('rating_after')->nullable();
            $table->timestamps();

            $table->unique(['tournament_id', 'user_id']);
        });

        Schema::create('escalera_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->integer('round_number');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamps();

            $table->unique(['tournament_id', 'round_number']);
        });

        // Корт в раунде. Порядок игроков — это посадка, от неё строится
        // очерёдность трёх матчей.
        Schema::create('escalera_round_courts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escalera_round_id')->constrained('escalera_rounds')->cascadeOnDelete();
            $table->unsignedSmallInteger('court_number');
            $table->foreignId('player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player2_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player3_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player4_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['escalera_round_id', 'court_number'], 'esc_round_court_unique');
        });

        // Короткий матч: три на корт за раунд.
        Schema::create('escalera_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escalera_round_court_id')->constrained('escalera_round_courts')->cascadeOnDelete();
            $table->unsignedTinyInteger('match_number'); // 1..3
            $table->foreignId('team1_player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team1_player2_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team2_player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team2_player2_id')->constrained('users')->cascadeOnDelete();
            $table->integer('team1_score')->nullable();
            $table->integer('team2_score')->nullable();
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->timestamps();

            $table->unique(['escalera_round_court_id', 'match_number'], 'esc_court_match_unique');
        });

        // Результат игрока за раунд: место на корте, позиция в общем строю, баллы.
        // Нужен для истории движения и для колонки «изменение позиции».
        Schema::create('escalera_round_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escalera_round_id')->constrained('escalera_rounds')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('court_number');
            $table->unsignedTinyInteger('place_on_court'); // 1..4
            $table->unsignedSmallInteger('overall_position');
            $table->integer('points');
            $table->timestamps();

            $table->unique(['escalera_round_id', 'user_id'], 'esc_round_result_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escalera_round_results');
        Schema::dropIfExists('escalera_matches');
        Schema::dropIfExists('escalera_round_courts');
        Schema::dropIfExists('escalera_rounds');
        Schema::dropIfExists('escalera_players');
    }
};
