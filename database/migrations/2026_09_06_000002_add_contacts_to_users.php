<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Необязательные контакты игрока: WhatsApp, Telegram, Instagram.
 *
 * Телефон в системе — это логин, менять его нельзя. WhatsApp хранится
 * отдельно: у части людей он на другом номере, а списываться с партнёром
 * по игре чаще всего хотят именно там.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp', 20)->nullable()->after('phone');
            $table->string('telegram_username', 64)->nullable()->after('whatsapp');
            $table->string('instagram', 64)->nullable()->after('telegram_username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['whatsapp', 'telegram_username', 'instagram']);
        });
    }
};
