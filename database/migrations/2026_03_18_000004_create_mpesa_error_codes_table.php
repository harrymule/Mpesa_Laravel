<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mpesa_error_codes', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('daraja')->index();
            $table->string('journey')->nullable()->index();
            $table->string('error_stage')->default('request')->index();
            $table->string('signature')->unique();
            $table->string('code')->nullable()->index();
            $table->string('error_key')->default('mpesa_request_failed')->index();
            $table->integer('http_status')->nullable()->index();
            $table->string('title')->nullable();
            $table->text('message')->nullable();
            $table->text('possible_cause')->nullable();
            $table->text('mitigation')->nullable();
            $table->boolean('is_known')->default(false)->index();
            $table->unsignedInteger('occurrences')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('sample_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_error_codes');
    }
};
