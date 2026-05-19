<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE tournaments MODIFY COLUMN type ENUM('classic','americano','mexicano','team','king_of_court','bali_koc','americano_flex') NOT NULL DEFAULT 'classic'");
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE tournaments MODIFY COLUMN type ENUM('classic','americano','mexicano','team','king_of_court','bali_koc') NOT NULL DEFAULT 'classic'");
        }
    }
};
