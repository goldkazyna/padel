<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->onDelete('cascade');
            $table->integer('round_no');
            $table->json('pair_a')->nullable();
            $table->json('pair_b')->nullable();
            $table->integer('score_a')->nullable();
            $table->integer('score_b')->nullable();
            $table->integer('tiebreak_a')->nullable();
            $table->integer('tiebreak_b')->nullable();
            $table->boolean('is_played')->default(false);
            $table->timestamps();

            $table->index(['game_id', 'round_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_rounds');
    }
};
