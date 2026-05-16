<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE tournament_participants MODIFY status ENUM('pending','registered','confirmed','cancelled','reserve','waiting') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE tournament_teams MODIFY status ENUM('pending','approved','rejected','waiting') NOT NULL DEFAULT 'pending'");
        }
        // На SQLite enum хранится как TEXT — расширение значений не требуется.
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE tournament_participants MODIFY status ENUM('pending','registered','confirmed','cancelled','reserve') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE tournament_teams MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        }
    }
};
