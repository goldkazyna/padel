<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        DB::statement("ALTER TABLE tournaments MODIFY COLUMN type ENUM('classic', 'americano', 'mexicano', 'team', 'king_of_court') DEFAULT 'classic'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        DB::statement("ALTER TABLE tournaments MODIFY COLUMN type ENUM('classic', 'americano', 'mexicano', 'team') DEFAULT 'classic'");
    }
};
