<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Раунды
        Schema::create('americano_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_group_id')->constrained()->onDelete('cascade');
            $table->integer('round_number');
            $table->enum('status', ['pending', 'in_progress', 'completed'])->default('pending');
            $table->timestamps();
        });

        // Матчи 2х2
        Schema::create('americano_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('americano_round_id')->constrained()->onDelete('cascade');
            $table->foreignId('team1_player1_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('team1_player2_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('team2_player1_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('team2_player2_id')->constrained('users')->onDelete('cascade');
            $table->integer('team1_score')->nullable();
            $table->integer('team2_score')->nullable();
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('americano_matches');
        Schema::dropIfExists('americano_rounds');
    }
};