<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_group_members', function (Blueprint $table) {
            // Комментарий администратора по участнику (необязательно).
            $table->text('note')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('club_group_members', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
