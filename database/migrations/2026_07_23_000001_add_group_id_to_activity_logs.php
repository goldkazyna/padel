<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            // Привязка записи журнала к группе (для «Журнала групп»).
            // БЕЗ внешнего ключа: история должна оставаться даже после удаления группы.
            $table->unsignedBigInteger('group_id')->nullable()->after('subject_id');
            $table->index(['club_id', 'group_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['club_id', 'group_id', 'created_at']);
            $table->dropColumn('group_id');
        });
    }
};
