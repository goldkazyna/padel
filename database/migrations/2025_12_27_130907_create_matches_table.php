<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->foreignId('player1_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('player2_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('winner_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('score')->nullable(); // "6:4, 6:3"
            $table->integer('player1_rating_before')->nullable();
            $table->integer('player2_rating_before')->nullable();
            $table->integer('player1_rating_after')->nullable();
            $table->integer('player2_rating_after')->nullable();
            $table->integer('rating_change')->nullable(); // сколько очков передано
            $table->enum('status', ['scheduled', 'completed', 'cancelled'])->default('scheduled');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};