<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Бонус за результат матча стал частью формата Ladder и больше не
     * настраивается — колонка не нужна. Проверка hasColumn нужна потому,
     * что добавляющая миграция могла быть не применена: обе выкатываются
     * одним деплоем.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tournaments', 'escalera_win_bonus')) {
            Schema::table('tournaments', function (Blueprint $table) {
                $table->dropColumn('escalera_win_bonus');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('tournaments', 'escalera_win_bonus')) {
            Schema::table('tournaments', function (Blueprint $table) {
                $table->boolean('escalera_win_bonus')->default(false)->after('escalera_standings_mode');
            });
        }
    }
};
