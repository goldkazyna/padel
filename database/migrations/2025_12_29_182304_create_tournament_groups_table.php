<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->string('name'); // "Группа A", "Группа B"
            $table->timestamps();
        });

        // Привязка игрока к группе
        Schema::create('tournament_group_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_group_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->integer('total_points')->default(0); // Набранные очки за турнир
            $table->timestamps();

            $table->unique(['tournament_group_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_group_players');
        Schema::dropIfExists('tournament_groups');
    }
};