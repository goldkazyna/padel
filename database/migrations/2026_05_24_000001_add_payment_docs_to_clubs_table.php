<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->string('offer_agreement')->nullable()->after('payment_url');
            $table->string('privacy_policy')->nullable()->after('offer_agreement');
            $table->string('goods_description')->nullable()->after('privacy_policy');
            $table->string('card_payment_description')->nullable()->after('goods_description');
            $table->boolean('online_payment_enabled')->default(false)->after('card_payment_description');
        });
    }

    public function down(): void
    {
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn([
                'offer_agreement',
                'privacy_policy',
                'goods_description',
                'card_payment_description',
                'online_payment_enabled',
            ]);
        });
    }
};
