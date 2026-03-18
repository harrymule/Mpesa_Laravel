<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->string('status')->nullable()->index();
            $table->string('phone_number')->nullable();
            $table->string('amount')->nullable();
            $table->string('reference')->nullable();
            $table->string('callback_url')->nullable();
            $table->string('merchant_request_id')->nullable()->index();
            $table->string('checkout_request_id')->nullable()->index();
            $table->string('conversation_id')->nullable()->index();
            $table->string('originator_conversation_id')->nullable()->index();
            $table->string('transaction_id')->nullable()->index();
            $table->string('result_code')->nullable();
            $table->string('result_desc')->nullable();
            $table->integer('callback_attempts')->default(0);
            $table->string('last_callback_code')->nullable();
            $table->boolean('callback_success')->default(false);
            $table->timestamp('last_callback_attempt')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('callback_payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_transactions');
    }
};
