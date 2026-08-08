<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('court_booking_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_booking_id')->constrained('court_bookings')->cascadeOnDelete();
            // Позиция справочника. Позицию могли удалить — строка живёт дальше
            // со снимком названия и цены.
            $table->foreignId('club_inventory_item_id')->nullable()
                  ->constrained('club_inventory_items')->nullOnDelete();
            $table->string('name');                        // снимок названия
            $table->integer('price');                      // снимок цены за единицу, целые тенге
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();

            $table->index('club_inventory_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('court_booking_inventory_items');
    }
};
