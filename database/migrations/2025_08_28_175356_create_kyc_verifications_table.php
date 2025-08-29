<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_application_id')->constrained()->onDelete('cascade');
            
            // Verification Type & Provider
            $table->enum('verification_type', ['nadra_verisys', 'pak_id', 'biometric', 'liveness_check', 'address_verification']);
            $table->string('provider'); // NADRA, Verisys, etc.
            $table->string('transaction_id')->nullable(); // External verification ID
            
            // Verification Results
            $table->enum('status', ['pending', 'success', 'failed', 'timeout'])->default('pending');
            $table->json('request_data')->nullable(); // Sent data
            $table->json('response_data')->nullable(); // Received response
            $table->decimal('match_score', 5, 2)->nullable(); // Matching percentage
            
            // NADRA Specific Fields
            $table->string('nadra_session_id')->nullable();
            $table->boolean('cnic_verified')->default(false);
            $table->boolean('biometric_matched')->default(false);
            $table->json('nadra_response')->nullable();
            
            // Error Handling
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            
            // Timestamps
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            
            $table->index(['kyc_application_id', 'verification_type']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_verifications');
    }
};