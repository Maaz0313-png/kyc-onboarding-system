<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sanctions_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_application_id')->constrained()->onDelete('cascade');
            
            // Screening Information
            $table->enum('screening_type', ['un_sanctions', 'tfs_regime', 'pep_check', 'local_proscribed', 'ofac', 'eu_sanctions']);
            $table->string('screening_provider')->default('internal'); // internal, WorldCheck, etc.
            $table->string('reference_id')->nullable(); // External screening reference
            
            // Screening Results
            $table->enum('status', ['pending', 'clear', 'match_found', 'false_positive', 'under_review'])->default('pending');
            $table->json('search_criteria'); // Name, CNIC, DOB used for screening
            $table->json('matches_found')->nullable(); // Any matches discovered
            $table->integer('match_count')->default(0);
            $table->decimal('highest_match_score', 5, 2)->default(0);
            
            // Risk Assessment
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->text('risk_notes')->nullable();
            $table->boolean('requires_manual_review')->default(false);
            
            // Compliance Officer Review
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->enum('final_decision', ['approved', 'rejected', 'escalated'])->nullable();
            $table->text('review_comments')->nullable();
            
            // goAML Reporting
            $table->boolean('reported_to_fmu')->default(false);
            $table->string('fmu_reference')->nullable();
            $table->timestamp('reported_at')->nullable();
            
            $table->timestamps();
            
            $table->index(['kyc_application_id', 'screening_type']);
            $table->index(['status', 'risk_level']);
            $table->index(['requires_manual_review', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanctions_screenings');
    }
};