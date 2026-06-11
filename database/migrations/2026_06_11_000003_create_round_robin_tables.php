<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Тип турнира round_robin в enum.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE tournaments MODIFY COLUMN type ENUM('classic','americano','mexicano','team','king_of_court','bali_koc','americano_flex','round_robin') NOT NULL DEFAULT 'classic'");
        }

        // Сохранённое расписание круга (массив раундов из пар индексов игроков), JSON.
        Schema::table('tournaments', function (Blueprint $table) {
            $table->json('round_robin_schedule')->nullable()->after('verified_only');
        });

        // Игроки турнира Round Robin — статистика.
        Schema::create('round_robin_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->default(0);          // порядок в расписании
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->integer('points_for')->default(0);        // выигранные геймы
            $table->integer('points_against')->default(0);    // проигранные геймы
            $table->integer('rating_before')->nullable();
            $table->integer('rating_after')->nullable();
            $table->timestamps();

            $table->unique(['tournament_id', 'user_id']);
        });

        // Раунды
        Schema::create('round_robin_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->integer('round_number');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamps();

            $table->unique(['tournament_id', 'round_number']);
            $table->index(['tournament_id', 'status']);
        });

        // Матчи раунда (4 игрока на корт)
        Schema::create('round_robin_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_robin_round_id')->constrained('round_robin_rounds')->cascadeOnDelete();
            $table->integer('court_number');
            $table->foreignId('team1_player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team1_player2_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team2_player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('team2_player2_id')->constrained('users')->cascadeOnDelete();
            $table->integer('team1_score')->nullable();
            $table->integer('team2_score')->nullable();
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->timestamps();

            $table->index('round_robin_round_id', 'rr_matches_round_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('round_robin_matches');
        Schema::dropIfExists('round_robin_rounds');
        Schema::dropIfExists('round_robin_players');
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('round_robin_schedule');
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE tournaments MODIFY COLUMN type ENUM('classic','americano','mexicano','team','king_of_court','bali_koc','americano_flex') NOT NULL DEFAULT 'classic'");
        }
    }
};
