<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->unsignedInteger('moderation_minutes')->nullable()->after('moderation_hours');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', fn (Blueprint $t) => $t->dropColumn('moderation_minutes'));
    }
};
