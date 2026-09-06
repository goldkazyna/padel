<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Когда с игрока последний раз списывали рейтинг за простой.
 *
 * Колонка last_played_at уже есть с 2025 года, но её заполняли только
 * поединки (EloService) — на проде она была у 14 человек из трёх тысяч.
 * Ночная команда пересчитывает её всем.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('rating_decayed_at')->nullable()->after('last_played_at');
            // Ночная команда выбирает кандидатов по этим двум полям.
            $table->index(['last_played_at', 'rating_decayed_at'], 'users_decay_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_decay_idx');
            $table->dropColumn('rating_decayed_at');
        });
    }
};
