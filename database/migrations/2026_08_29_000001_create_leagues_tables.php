<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Лига — серия турниров с общей таблицей.
 *
 * Сами результаты нигде не дублируются: сводная таблица считается из этапов
 * на лету, иначе после первой же правки счёта задним числом она разошлась бы
 * с турнирами.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('leagues')) {
            Schema::create('leagues', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('club_id');
                $table->unsignedBigInteger('creator_id')->nullable();

                $table->string('name');
                $table->text('description')->nullable();
                $table->string('cover')->nullable();

                $table->string('status')->default('draft');
                // Формат этапов. Пока только Americano Flex, поле на вырост.
                $table->string('format')->default('americano_flex');
                $table->unsignedSmallInteger('stages_planned')->default(8);

                $table->dateTime('start_date')->nullable();
                $table->dateTime('end_date')->nullable();

                $table->decimal('min_level', 4, 2)->nullable();
                $table->decimal('max_level', 4, 2)->nullable();
                $table->unsignedSmallInteger('max_players')->nullable();
                $table->unsignedInteger('price')->nullable();
                $table->boolean('is_rated')->default(true);

                $table->timestamps();
                $table->index(['club_id', 'status']);
            });
        }

        if (!Schema::hasTable('league_players')) {
            Schema::create('league_players', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('league_id');
                $table->unsignedBigInteger('user_id');
                $table->string('status')->default('registered');
                $table->timestamp('joined_at')->nullable();
                $table->timestamp('left_at')->nullable();
                $table->timestamps();

                $table->unique(['league_id', 'user_id']);
                $table->index('league_id');
            });
        }

        if (!Schema::hasColumn('tournaments', 'league_id')) {
            Schema::table('tournaments', function (Blueprint $table) {
                $table->unsignedBigInteger('league_id')->nullable()->after('club_id');
                $table->unsignedSmallInteger('league_stage')->nullable()->after('league_id');
                $table->index('league_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tournaments', 'league_id')) {
            Schema::table('tournaments', function (Blueprint $table) {
                $table->dropIndex(['league_id']);
                $table->dropColumn(['league_id', 'league_stage']);
            });
        }

        Schema::dropIfExists('league_players');
        Schema::dropIfExists('leagues');
    }
};
