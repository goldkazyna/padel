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
     *
     * `MODIFY` — синтаксис MySQL, поэтому на других драйверах пропускаем:
     * тесты гоняются на SQLite, где третья миграция и так создаёт колонку
     * сразу с нужным умолчанием.
     */
    public function up(): void
    {
        $this->setDefault(1);
    }

    public function down(): void
    {
        $this->setDefault(0);
    }

    private function setDefault(int $default): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        if (!Schema::hasColumn('tournaments', 'escalera_win_bonus')) {
            return;
        }

        DB::statement("ALTER TABLE `tournaments` MODIFY `escalera_win_bonus` TINYINT(1) NOT NULL DEFAULT {$default}");
    }
};
