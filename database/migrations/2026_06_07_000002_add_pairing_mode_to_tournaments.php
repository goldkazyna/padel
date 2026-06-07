<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Только для type=team. self — пары регистрируются сами (как сейчас);
            // admin — игроки записываются поодиночке, пары собирает админ.
            $table->string('pairing_mode')->default('self')->after('teams_advance');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('pairing_mode');
        });
    }
};
