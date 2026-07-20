<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_group_members', function (Blueprint $table) {
            // Дата ухода из группы (мягкое удаление: status=inactive + left_at).
            // Записи сохраняются для отчётов/истории/выручки.
            $table->timestamp('left_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('club_group_members', function (Blueprint $table) {
            $table->dropColumn('left_at');
        });
    }
};
