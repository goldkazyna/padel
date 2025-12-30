<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->enum('type', ['classic', 'americano', 'mexicano'])->default('classic')->after('club_id');
            $table->integer('points_to_win')->default(16)->after('price'); // 16, 24, 32
            $table->integer('groups_count')->default(1)->after('points_to_win'); // 1, 2, 4
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['type', 'points_to_win', 'groups_count']);
        });
    }
};