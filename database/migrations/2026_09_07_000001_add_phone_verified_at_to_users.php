<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Когда номер телефона был подтверждён кодом (или получен от телеграма).
 *
 * До этого телефон можно было вписать руками в профиле — так в базе
 * появлялись чужие и опечатанные номера, неотличимые от проверенных.
 *
 * Заливка: подтверждёнными считаем тех, у кого номер пришёл от телеграма,
 * и тех, кто вообще не мог войти иначе как по СМС (нет ни telegram_id, ни
 * apple/google-аккаунта). Остальным ставим null — они пройдут проверку при
 * следующей смене номера.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
        });

        DB::table('users')
            ->whereNotNull('phone')
            ->where(function ($q) {
                $q->whereNotNull('telegram_id')
                  ->orWhere(function ($q2) {
                      $q2->whereNull('telegram_id')
                         ->whereNull('google_id')
                         ->where('email', 'not like', '%privaterelay.appleid.com');
                  });
            })
            ->update(['phone_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone_verified_at');
        });
    }
};
