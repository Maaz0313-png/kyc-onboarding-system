<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_id')->unique();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Personal Information (SBP Required)
            $table->string('cnic')->unique();
            $table->string('full_name');
            $table->string('father_name');
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female', 'other']);
            $table->string('phone_number');
            $table->string('email');
            $table->text('address');
            $table->string('city');
            $table->string('province');
            $table->string('postal_code');
            
            // KYC Status & Risk Assessment
            $table->enum('status', ['pending', 'in_progress', 'approved', 'rejected', 'under_review'])->default('pending');
            $table->integer('risk_score')->default(0); // 0-100 scale
            $table->enum('risk_category', ['low', 'medium', 'high'])->default('medium');
            $table->enum('account_tier', ['basic', 'standard', 'premium'])->nullable();
            
            // Compliance Flags
            $table->boolean('sanctions_cleared')->default(false);
            $table->boolean('pep_cleared')->default(false);
            $table->boolean('nadra_verified')->default(false);
            $table->boolean('biometric_verified')->default(false);
            $table->boolean('consent_given')->default(false);
            
            // Processing Information
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('processed_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('compliance_data')->nullable();
            
            $table->timestamps();
            
            $table->index(['status', 'created_at']);
            $table->index(['risk_category', 'risk_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_applications');
    }
};