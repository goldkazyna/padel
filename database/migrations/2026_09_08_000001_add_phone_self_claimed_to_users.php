<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отметка «номер вписал сам, кодом не подтверждал».
 *
 * Аккаунт через Google или Apple заводится без телефона, и первый номер
 * человек вбивает руками в профиле — проверить его нечем. Такой номер
 * должен уступать: если настоящий владелец войдёт по коду из СМС, номер
 * переходит к нему.
 *
 * Отличать нужно именно этот случай. Незаполненный phone_verified_at сам по
 * себе ни о чём не говорит: так же выглядят игроки, которых завёл клуб в CRM
 * по номеру, и старые учётки до появления этой колонки. У них номер отбирать
 * нельзя — иначе человек войдёт по СМС в пустой новый аккаунт, а рейтинг и
 * история останутся в старом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('phone_self_claimed')->default(false)->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone_self_claimed');
        });
    }
};
