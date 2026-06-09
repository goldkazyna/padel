<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->foreignId('club_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('court_booking_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('amount');          // сколько списано (часов/занятий)
            $table->integer('balance_after');   // остаток после списания
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_card_transactions');
    }
};
