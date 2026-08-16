<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * До появления выбора пары в парном «Just Padel It» собирал только админ,
     * а в поле pairing_mode лежало значение по умолчанию — 'self'. Теперь это
     * поле для JPI работает, поэтому существующим турнирам проставляем 'admin':
     * иначе у открытых на запись турниров молча сменился бы способ регистрации.
     */
    public function up(): void
    {
        DB::table('tournaments')
            ->where('type', 'just_padel_it')
            ->where('is_paired', true)
            ->update(['pairing_mode' => 'admin']);
    }

    public function down(): void
    {
        DB::table('tournaments')
            ->where('type', 'just_padel_it')
            ->where('is_paired', true)
            ->update(['pairing_mode' => 'self']);
    }
};
