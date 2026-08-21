<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Бонус за результат матча в Ladder не настраивается — он часть формата.
     * Но таблица считается на лету, поэтому без отметки в турнире итоги уже
     * сыгранных пересчитались бы задним числом.
     *
     * Отсюда техническая колонка: у новых турниров она включена значением по
     * умолчанию, а всем существующим сразу проставляется false — их таблицы
     * остаются такими, какими их видели участники. В формах колонки нет.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('tournaments', 'escalera_win_bonus')) {
            Schema::table('tournaments', function (Blueprint $table) {
                $table->boolean('escalera_win_bonus')->default(true)->after('escalera_standings_mode');
            });
        }

        // Колонка добавляется со значением по умолчанию, поэтому существующие
        // строки получили true — возвращаем их к прежнему поведению.
        DB::table('tournaments')->update(['escalera_win_bonus' => false]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('tournaments', 'escalera_win_bonus')) {
            Schema::table('tournaments', function (Blueprint $table) {
                $table->dropColumn('escalera_win_bonus');
            });
        }
    }
};
