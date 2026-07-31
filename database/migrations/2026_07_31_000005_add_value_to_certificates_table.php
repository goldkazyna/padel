<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('value_type', 20)->default('amount')->after('client_id'); // amount | hours
            $table->unsignedInteger('amount')->nullable()->after('value_type');       // сумма, ₸
            $table->unsignedSmallInteger('hours')->nullable()->after('amount');        // бесплатные часы
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['value_type', 'amount', 'hours']);
        });
    }
};
