<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кто привязал карту клиенту.
 *
 * Привязка карты — это продажа, и в отчёте о выручке рядом с ней должен стоять
 * сотрудник. Раньше это нигде не сохранялось: у выпущенных до сих пор карт
 * останется пусто, у новых будет автор.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_cards', function (Blueprint $table) {
            $table->unsignedBigInteger('issued_by')->nullable()->after('club_client_id');
        });
    }

    public function down(): void
    {
        Schema::table('club_cards', function (Blueprint $table) {
            $table->dropColumn('issued_by');
        });
    }
};
