<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Переписка WhatsApp, приходящая вебхуком от Whapi.Cloud.
 *
 * Пока это «мягкая» интеграция: сообщения только принимаются и хранятся,
 * ничего не отправляется. Поэтому таблица одна и максимально простая —
 * лента сообщений, которую CRM группирует по номеру и дате.
 *
 * `payload` держим целиком: у Whapi десятки типов сообщений, и разбирать
 * их все сразу незачем — а когда понадобится картинка или геометка, данные
 * уже будут лежать.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_messages')) {
            return;
        }

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('club_id')->nullable()->constrained()->nullOnDelete();

            // Идентификатор сообщения в WhatsApp: вебхук приходит повторно
            // при ретраях, и второй раз запись создаваться не должна.
            $table->string('wa_message_id')->unique();
            $table->string('channel_id')->nullable();

            // Чат = собеседник. Номер держим отдельно от chat_id: по нему
            // сообщения связываются с карточкой клиента.
            $table->string('chat_id')->index();
            $table->string('phone', 32)->index();
            $table->string('author_name')->nullable();

            $table->boolean('from_me')->default(false);
            $table->string('type', 32)->default('text');
            $table->text('body')->nullable();
            $table->json('payload')->nullable();

            $table->timestamp('sent_at')->index();
            $table->timestamps();

            $table->index(['club_id', 'phone', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
