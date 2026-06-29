<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            // Дубликаты-черновики создаются без даты — её задают при подготовке.
            $table->dateTime('start_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dateTime('start_date')->nullable(false)->change();
        });
    }
};
