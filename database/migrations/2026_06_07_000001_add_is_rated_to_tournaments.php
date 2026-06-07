<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Рейтинговый ли турнир. По умолчанию true — все существующие
            // турниры остаются рейтинговыми, поведение не меняется.
            $table->boolean('is_rated')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('is_rated');
        });
    }
};
