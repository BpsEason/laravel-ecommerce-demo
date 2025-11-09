<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->string('transaction_id')->unique()->nullable(); // 第三方支付平台的交易 ID
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('method'); // stripe, mock_gateway, paypal, etc.
            $table->string('status')->default('pending'); // pending, completed, failed, refunded
            $table->json('meta')->nullable(); // 儲存支付平台回傳的原始資料
            $table->timestamps();

            $table->index('order_id');
            $table->index('transaction_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};