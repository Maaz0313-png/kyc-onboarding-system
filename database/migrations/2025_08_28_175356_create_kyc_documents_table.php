<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_application_id')->constrained()->onDelete('cascade');
            
            // Document Information
            $table->enum('document_type', ['cnic_front', 'cnic_back', 'selfie', 'utility_bill', 'bank_statement', 'salary_certificate']);
            $table->string('file_path');
            $table->string('file_name');
            $table->string('file_hash'); // For integrity verification
            $table->integer('file_size');
            $table->string('mime_type');
            
            // OCR & Verification Data
            $table->json('ocr_data')->nullable(); // Extracted text from documents
            $table->json('verification_data')->nullable(); // NADRA/verification response
            $table->boolean('is_verified')->default(false);
            $table->decimal('confidence_score', 5, 2)->nullable(); // OCR confidence
            
            // Security & Compliance
            $table->boolean('virus_scanned')->default(false);
            $table->boolean('is_encrypted')->default(true);
            $table->timestamp('expires_at')->nullable(); // Document retention policy
            
            // Processing Status
            $table->enum('status', ['uploaded', 'processing', 'verified', 'rejected'])->default('uploaded');
            $table->text('rejection_reason')->nullable();
            
            $table->timestamps();
            
            $table->index(['kyc_application_id', 'document_type']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
    }
};