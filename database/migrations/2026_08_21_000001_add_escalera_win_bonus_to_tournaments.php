<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ladder: начислять ли бонус за результат матча (победа +2, ничья +1)
     * поверх суммы забитых очков. Настройка только для новых турниров —
     * у существующих остаётся false, чтобы их таблицы не пересчитались.
     */
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->boolean('escalera_win_bonus')->default(false)->after('escalera_standings_mode');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('escalera_win_bonus');
        });
    }
};
