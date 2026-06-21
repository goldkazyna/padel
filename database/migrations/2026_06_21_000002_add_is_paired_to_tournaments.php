<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Парный Americano Flex: пары фиксированы, ротируются соперники и отдых.
// Флаг на type='americano_flex'. Существующие флекс-турниры = is_paired=false.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->boolean('is_paired')->default(false)->after('pairing_mode');
        });
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn('is_paired');
        });
    }
};
