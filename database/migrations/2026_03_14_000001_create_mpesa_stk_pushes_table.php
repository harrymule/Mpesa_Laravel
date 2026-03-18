<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_stk_pushes', function (Blueprint $table) {
            $table->id();
            $table->string('phone_number')->nullable();
            $table->string('amount')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('merchant_request_id')->nullable()->index();
            $table->string('tracking_id')->nullable()->index();
            $table->string('checkout_request_id')->nullable()->index();
            $table->string('transaction_code')->nullable();
            $table->string('internal_comment')->nullable();
            $table->text('response_description')->nullable();
            $table->text('customer_message')->nullable();
            $table->string('response_code')->nullable();
            $table->string('user_action')->nullable();
            $table->string('callback_url')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_stk_pushes');
    }
};
