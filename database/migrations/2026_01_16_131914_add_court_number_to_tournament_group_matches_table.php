<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_group_matches', function (Blueprint $table) {
            $table->unsignedTinyInteger('court_number')->nullable()->after('group_id');
        });
    }

    public function down(): void
    {
        Schema::table('tournament_group_matches', function (Blueprint $table) {
            $table->dropColumn('court_number');
        });
    }
};