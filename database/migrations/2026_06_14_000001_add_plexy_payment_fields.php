<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ключи Plexy на каждый клуб (у разных клубов — разные).
        Schema::table('clubs', function (Blueprint $table) {
            $table->text('plexy_api_key')->nullable()->after('online_payment_enabled');
            $table->string('plexy_merchant_id')->nullable()->after('plexy_api_key');
            $table->text('plexy_webhook_secret')->nullable()->after('plexy_merchant_id');
        });

        // Статус онлайн-оплаты брони (помимо уже существующего is_paid).
        Schema::table('court_bookings', function (Blueprint $table) {
            // pending | paid | failed | cancelled | refunded
            $table->string('payment_status')->nullable()->after('is_paid');
            // id платёжной ссылки / транзакции в Plexy
            $table->string('payment_id')->nullable()->after('payment_status');
            $table->timestamp('paid_at')->nullable()->after('payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn(['plexy_api_key', 'plexy_merchant_id', 'plexy_webhook_secret']);
        });
        Schema::table('court_bookings', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'payment_id', 'paid_at']);
        });
    }
};
