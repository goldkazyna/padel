<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Игроки турнира Мексикано
        Schema::create('mexicano_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->integer('total_points')->default(0);
            $table->integer('rating_before')->nullable();
            $table->integer('rating_after')->nullable();
            $table->timestamps();

            $table->unique(['tournament_id', 'user_id']);
        });

        // Раунды Мексикано
        Schema::create('mexicano_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->integer('round_number');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamps();
        });

        // Матчи Мексикано
        Schema::create('mexicano_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mexicano_round_id')->constrained()->onDelete('cascade');
            $table->foreignId('team1_player1_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('team1_player2_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('team2_player1_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('team2_player2_id')->constrained('users')->onDelete('cascade');
            $table->integer('team1_score')->nullable();
            $table->integer('team2_score')->nullable();
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->timestamps();
        });

        // История пар (кто с кем уже играл)
        Schema::create('mexicano_pair_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->foreignId('player1_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('player2_id')->constrained('users')->onDelete('cascade');
            $table->integer('times_as_partners')->default(0);
            $table->integer('times_as_opponents')->default(0);
            $table->timestamps();

            $table->unique(['tournament_id', 'player1_id', 'player2_id']);
        });

        // Добавляем поле rounds_count в tournaments
        Schema::table('tournaments', function (Blueprint $table) {
            $table->integer('rounds_count')->nullable()->after('groups_count');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('rounds_count');
        });
        
        Schema::dropIfExists('mexicano_pair_history');
        Schema::dropIfExists('mexicano_matches');
        Schema::dropIfExists('mexicano_rounds');
        Schema::dropIfExists('mexicano_players');
    }
};