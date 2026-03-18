<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stk_push_id')->nullable()->index();
            $table->string('tracking_id')->nullable()->index();
            $table->string('transaction_type')->nullable();
            $table->string('trans_id')->nullable()->unique();
            $table->string('trans_time')->nullable();
            $table->string('trans_amount')->nullable();
            $table->string('business_short_code')->nullable();
            $table->string('bill_ref_number')->nullable();
            $table->string('invoice_number')->nullable();
            $table->string('org_account_balance')->nullable();
            $table->string('third_party_trans_id')->nullable();
            $table->string('msisdn')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('status')->default('NEW');
            $table->string('path')->nullable();
            $table->integer('callback_attempts')->default(0);
            $table->string('last_callback_code')->nullable();
            $table->boolean('callback_success')->default(false);
            $table->string('callback_url')->nullable();
            $table->timestamp('last_callback_attempt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_payments');
    }
};
