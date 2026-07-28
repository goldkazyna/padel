<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sessions в club_group_enrollments был UNSIGNED — из-за этого нельзя было
 * записать компенсирующий пакет на −остаток (обнуление при удалении участника).
 * Делаем колонку знаковой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_group_enrollments', function (Blueprint $table) {
            $table->integer('sessions')->change();
        });
    }

    public function down(): void
    {
        Schema::table('club_group_enrollments', function (Blueprint $table) {
            $table->unsignedInteger('sessions')->change();
        });
    }
};
