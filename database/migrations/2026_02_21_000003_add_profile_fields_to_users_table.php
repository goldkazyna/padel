<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('patronymic', 255)->nullable()->after('last_name');
            $table->string('city', 100)->nullable()->after('patronymic');
            $table->unsignedTinyInteger('age')->nullable()->after('gender');
            $table->string('hand', 10)->nullable()->after('age');
            $table->string('position', 10)->nullable()->after('hand');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['patronymic', 'city', 'age', 'hand', 'position']);
        });
    }
};
