<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сколько миллисекунд занял разбор.
 *
 * Нужно не ради статистики: по прошлым разборам экран показывает, сколько
 * ещё ждать. Без этого «идёт разбор» превращается в белый экран без конца.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_analyses', function (Blueprint $table) {
            $table->unsignedInteger('duration_ms')->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_analyses', function (Blueprint $table) {
            $table->dropColumn('duration_ms');
        });
    }
};
