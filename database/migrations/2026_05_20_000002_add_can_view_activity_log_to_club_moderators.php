<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_moderators', function (Blueprint $table) {
            $table->boolean('can_view_activity_log')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('club_moderators', function (Blueprint $table) {
            $table->dropColumn('can_view_activity_log');
        });
    }
};
