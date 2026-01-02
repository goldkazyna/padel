<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE tournaments MODIFY COLUMN type ENUM('classic', 'americano', 'mexicano', 'team') DEFAULT 'classic'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tournaments MODIFY COLUMN type ENUM('classic', 'americano', 'mexicano') DEFAULT 'classic'");
    }
};