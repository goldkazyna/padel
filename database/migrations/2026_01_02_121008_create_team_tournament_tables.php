<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Пары (команды)
        Schema::create('tournament_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->foreignId('player1_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('player2_id')->constrained('users')->onDelete('cascade');
            $table->string('name')->nullable();
            $table->integer('seed')->nullable(); // Сеяный номер
            $table->integer('rating_avg')->nullable(); // Средний рейтинг пары
            $table->integer('rating_before')->nullable();
            $table->integer('rating_after')->nullable();
            $table->timestamps();
        });

        // Группы
        Schema::create('tournament_team_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->timestamps();
        });

        // Таблица группы (standings)
        Schema::create('tournament_team_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('tournament_team_groups')->onDelete('cascade');
            $table->foreignId('team_id')->constrained('tournament_teams')->onDelete('cascade');
            $table->integer('played')->default(0);
            $table->integer('won')->default(0);
            $table->integer('lost')->default(0);
            $table->integer('points_for')->default(0);
            $table->integer('points_against')->default(0);
            $table->integer('points')->default(0);
            $table->timestamps();

            $table->unique(['group_id', 'team_id']);
        });

        // Матчи группового этапа
        Schema::create('tournament_group_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('tournament_team_groups')->onDelete('cascade');
            $table->foreignId('team1_id')->constrained('tournament_teams')->onDelete('cascade');
            $table->foreignId('team2_id')->constrained('tournament_teams')->onDelete('cascade');
            $table->integer('team1_score')->nullable();
            $table->integer('team2_score')->nullable();
            $table->integer('round_number')->default(1);
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamps();
        });

        // Матчи плей-офф
        Schema::create('tournament_playoff_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->string('stage'); // quarter, semi, final
            $table->integer('match_number');
            $table->foreignId('team1_id')->nullable()->constrained('tournament_teams')->onDelete('cascade');
            $table->foreignId('team2_id')->nullable()->constrained('tournament_teams')->onDelete('cascade');
            $table->string('team1_source')->nullable(); // A1, B2 или W1 (winner match 1)
            $table->string('team2_source')->nullable();
            $table->integer('team1_score')->nullable();
            $table->integer('team2_score')->nullable();
            $table->foreignId('winner_id')->nullable()->constrained('tournament_teams')->onDelete('cascade');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_playoff_matches');
        Schema::dropIfExists('tournament_group_matches');
        Schema::dropIfExists('tournament_team_standings');
        Schema::dropIfExists('tournament_team_groups');
        Schema::dropIfExists('tournament_teams');
    }
};