<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_moderators', function (Blueprint $table) {
            $table->boolean('tournaments_full_access')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('club_moderators', function (Blueprint $table) {
            $table->dropColumn('tournaments_full_access');
        });
    }
};
