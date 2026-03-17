<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenge_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->tinyInteger('position'); // 1-4 (1,2 = team A; 3,4 = team B)
            $table->enum('status', ['confirmed', 'invited', 'declined'])->default('confirmed');
            $table->integer('rating_before')->nullable();
            $table->integer('rating_after')->nullable();
            $table->integer('rating_change')->nullable();
            $table->timestamps();

            $table->unique(['challenge_id', 'position']);
            $table->unique(['challenge_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_players');
    }
};
