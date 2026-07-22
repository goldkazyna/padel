<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_group_members', function (Blueprint $table) {
            // Дата, с которой участник начинает ходить (необязательно).
            // До этой даты он занимает место в группе, но занятия НЕ списываются.
            $table->date('starts_at')->nullable()->after('subscription_ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('club_group_members', function (Blueprint $table) {
            $table->dropColumn('starts_at');
        });
    }
};
