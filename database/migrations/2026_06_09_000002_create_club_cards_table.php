<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_card_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_client_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->integer('balance')->nullable();          // остаток занятий (счётчики)
            $table->integer('initial_balance')->nullable();  // стартовый остаток (для X / Y)
            $table->date('expires_at')->nullable();          // null = бессрочная
            $table->enum('status', ['active', 'archived'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_cards');
    }
};
