<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Счёт клиенту: админ выставляет произвольную сумму, клиент платит
        // по ссылке Plexy. К объектам CRM намеренно не привязано — платить
        // можно за что угодно, назначение живёт в описании.
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Клиент необязателен: счёт можно выставить и разовому гостю.
            $table->foreignId('club_client_id')->nullable()
                ->constrained('club_clients')->nullOnDelete();

            // Сумма в тенге. В Plexy уходит в тиынах (×100) — как в бронях.
            $table->decimal('amount', 10, 2);
            $table->string('description', 255);
            $table->string('client_name')->nullable();
            $table->string('client_phone', 32)->nullable();

            // pending | paid | expired | cancelled
            $table->string('status')->default('pending');
            $table->string('plexy_link_id')->nullable();
            $table->text('plexy_url')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['club_id', 'status']);
            // По нему вебхук находит счёт: merchantReference = "paylink-{id}".
            $table->index('plexy_link_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
