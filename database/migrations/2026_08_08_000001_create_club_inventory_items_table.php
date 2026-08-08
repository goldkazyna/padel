<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Цена в целых тенге, без копеек — как в остальных денежных полях проекта.
            $table->integer('price');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // По этой паре строится список раздела.
            $table->index(['club_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_inventory_items');
    }
};
