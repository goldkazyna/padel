<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ручной порядок мест на корте в раунде Ladder.
     *
     * Обычно места считаются от очков, а при полном равенстве выше идёт
     * игрок с большим рейтингом. Организатору бывает нужно решить ничью
     * иначе — договорились на корте, сыграли тай-брейк, учли личную встречу.
     * Здесь лежат четыре id в нужном порядке; null — считаем как обычно.
     */
    public function up(): void
    {
        Schema::table('escalera_round_courts', function (Blueprint $table) {
            $table->json('manual_rank')->nullable()->after('player4_id');
        });
    }

    public function down(): void
    {
        Schema::table('escalera_round_courts', function (Blueprint $table) {
            $table->dropColumn('manual_rank');
        });
    }
};
