<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('americano_flex_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->integer('round_number');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('in_progress');
            $table->timestamps();
            $table->index(['tournament_id', 'round_number']);
        });

        Schema::create('americano_flex_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('americano_flex_round_id')->constrained()->cascadeOnDelete();
            $table->integer('court_number');
            $table->foreignId('team1_player1_id')->constrained('users');
            $table->foreignId('team1_player2_id')->constrained('users');
            $table->foreignId('team2_player1_id')->constrained('users');
            $table->foreignId('team2_player2_id')->constrained('users');
            $table->integer('team1_score')->nullable();
            $table->integer('team2_score')->nullable();
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->timestamps();
            $table->index('americano_flex_round_id');
        });

        Schema::create('americano_flex_byes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('americano_flex_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
            $table->unique(['americano_flex_round_id', 'user_id']);
        });

        Schema::create('americano_flex_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->integer('total_points')->default(0);
            $table->integer('matches_played')->default(0);
            $table->integer('bye_count')->default(0);
            $table->integer('bye_streak')->default(0);
            $table->integer('rating_before')->nullable();
            $table->integer('rating_after')->nullable();
            $table->timestamps();
            $table->unique(['tournament_id', 'user_id']);
        });

        Schema::create('americano_flex_pair_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player1_id')->constrained('users');
            $table->foreignId('player2_id')->constrained('users');
            $table->integer('times_as_partners')->default(0);
            $table->integer('times_as_opponents')->default(0);
            $table->timestamps();
            $table->unique(['tournament_id', 'player1_id', 'player2_id'], 'flex_pair_history_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('americano_flex_pair_history');
        Schema::dropIfExists('americano_flex_players');
        Schema::dropIfExists('americano_flex_byes');
        Schema::dropIfExists('americano_flex_matches');
        Schema::dropIfExists('americano_flex_rounds');
    }
};
