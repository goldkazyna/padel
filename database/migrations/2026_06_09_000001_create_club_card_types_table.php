<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_card_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // visits — посещения корта; trainer — занятия с тренером;
            // discount_court — скидка на корт; discount_trainer — скидка на тренера.
            $table->enum('kind', ['visits', 'trainer', 'discount_court', 'discount_trainer']);
            $table->integer('nominal')->nullable();           // число занятий (visits/trainer)
            $table->integer('discount_percent')->nullable();  // % (discount_*)
            $table->integer('price')->nullable();             // стоимость карты, ₸ (клиент покупает)
            $table->date('default_expires_at')->nullable();   // фиксированная дата окончания
            $table->integer('default_validity_days')->nullable(); // или срок N дней с выдачи
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_card_types');
    }
};
