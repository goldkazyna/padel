<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') { return; }
        DB::statement("ALTER TABLE challenges MODIFY COLUMN status ENUM('open', 'ready', 'in_progress', 'pending_confirmation', 'completed', 'cancelled') DEFAULT 'open'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') { return; }
        DB::statement("ALTER TABLE challenges MODIFY COLUMN status ENUM('open', 'ready', 'in_progress', 'completed', 'cancelled') DEFAULT 'open'");
    }
};
