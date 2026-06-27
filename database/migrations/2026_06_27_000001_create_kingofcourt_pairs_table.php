<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Фиксированные пары для «Король корта» (is_paired). Создаются админом
        // после набора, до старта. Аналог bali_koc_pairs, но счёт/очки — как в КК.
        Schema::create('kingofcourt_pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player2_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('tournament_id', 'koc_pairs_tournament_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kingofcourt_pairs');
    }
};
