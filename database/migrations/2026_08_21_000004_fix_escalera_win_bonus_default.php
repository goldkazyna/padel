<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Привести значение по умолчанию к «включено».
     *
     * Колонку заводили дважды: первая миграция создавала её с default false
     * (когда бонус ещё был настройкой), третья — с default true, но только
     * если колонки нет. Если drop-миграция между ними не применялась, колонка
     * оставалась с прежним default, и новые турниры создавались без бонуса.
     *
     * Значения существующих строк не трогаем: у сыгранных турниров бонус
     * должен остаться выключенным.
     */
    public function up(): void
    {
        if (Schema::hasColumn('tournaments', 'escalera_win_bonus')) {
            DB::statement('ALTER TABLE `tournaments` MODIFY `escalera_win_bonus` TINYINT(1) NOT NULL DEFAULT 1');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tournaments', 'escalera_win_bonus')) {
            DB::statement('ALTER TABLE `tournaments` MODIFY `escalera_win_bonus` TINYINT(1) NOT NULL DEFAULT 0');
        }
    }
};
