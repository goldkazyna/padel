<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отчёт по оплаченным броням после закрытия смены.
 *
 * Клуб включает галочку — и при закрытии смены бот присылает PDF за этот день:
 * владельцу не нужно заходить в CRM, чтобы увидеть, чем закончился день.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->boolean('shift_report_enabled')->default(false)->after('telegram_chat_ids');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('shift_report_enabled');
        });
    }
};
