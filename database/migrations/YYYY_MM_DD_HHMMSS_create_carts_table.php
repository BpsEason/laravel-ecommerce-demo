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
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade'); // 登入用戶的購物車
            $table->string('session_id')->nullable()->unique(); // 未登入用戶的購物車，通過 Session ID 識別
            $table->timestamps();

            // 確保每個用戶只有一個購物車，或者每個 Session ID 只有一個購物車
            $table->unique(['user_id']);
            $table->unique(['session_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};