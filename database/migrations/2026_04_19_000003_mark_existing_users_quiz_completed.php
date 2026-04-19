<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Существующие юзеры на момент добавления опросника не должны его проходить.
        DB::table('users')->update(['quiz_completed' => true]);
    }

    public function down(): void
    {
        // ничего не откатываем
    }
};
