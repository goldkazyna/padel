<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') { return; }
        DB::statement("ALTER TABLE tournament_participants MODIFY COLUMN status ENUM('pending', 'registered', 'confirmed', 'cancelled') DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') { return; }
        DB::statement("ALTER TABLE tournament_participants MODIFY COLUMN status ENUM('registered', 'confirmed', 'cancelled') DEFAULT 'registered'");
    }
};