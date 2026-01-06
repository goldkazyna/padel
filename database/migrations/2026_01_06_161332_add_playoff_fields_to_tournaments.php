<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->boolean('has_playoff')->default(false)->after('groups_count');
            $table->enum('playoff_type', ['final_only', 'semifinal_final'])->nullable()->after('has_playoff');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['has_playoff', 'playoff_type']);
        });
    }
};