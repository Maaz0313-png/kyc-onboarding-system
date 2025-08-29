<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'kyc_application_id',
        'verification_type',
        'provider',
        'transaction_id',
        'status',
        'request_data',
        'response_data',
        'match_score',
        'nadra_session_id',
        'cnic_verified',
        'biometric_matched',
        'nadra_response',
        'error_code',
        'error_message',
        'retry_count',
        'verified_at'
    ];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
        'nadra_response' => 'array',
        'cnic_verified' => 'boolean',
        'biometric_matched' => 'boolean',
        'match_score' => 'decimal:2',
        'verified_at' => 'datetime'
    ];

    // Relationships
    public function kycApplication(): BelongsTo
    {
        return $this->belongsTo(KycApplication::class);
    }

    // Scopes
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('verification_type', $type);
    }

    public function scopeByProvider($query, $provider)
    {
        return $query->where('provider', $provider);
    }

    // Helper Methods
    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function hasTimedOut(): bool
    {
        return $this->status === 'timeout';
    }

    public function canRetry(): bool
    {
        return $this->retry_count < 3 && ($this->isFailed() || $this->hasTimedOut());
    }

    public function incrementRetryCount(): void
    {
        $this->increment('retry_count');
    }

    public function markAsSuccessful(array $responseData = [], float $matchScore = null): void
    {
        $updateData = [
            'status' => 'success',
            'response_data' => $responseData,
            'verified_at' => now()
        ];

        if ($matchScore !== null) {
            $updateData['match_score'] = $matchScore;
        }

        // Update specific fields based on verification type
        if ($this->verification_type === 'nadra_verisys') {
            $updateData['cnic_verified'] = true;
            $updateData['nadra_response'] = $responseData;
        }

        if ($this->verification_type === 'biometric') {
            $updateData['biometric_matched'] = true;
        }

        $this->update($updateData);
    }

    public function markAsFailed(string $errorCode = null, string $errorMessage = null): void
    {
        $this->update([
            'status' => 'failed',
            'error_code' => $errorCode,
            'error_message' => $errorMessage
        ]);
    }

    public function markAsTimeout(): void
    {
        $this->update([
            'status' => 'timeout',
            'error_message' => 'Verification request timed out'
        ]);
    }

    public function getVerificationResult(): array
    {
        return [
            'type' => $this->verification_type,
            'provider' => $this->provider,
            'status' => $this->status,
            'match_score' => $this->match_score,
            'verified_at' => $this->verified_at,
            'cnic_verified' => $this->cnic_verified,
            'biometric_matched' => $this->biometric_matched,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message
        ];
    }

    public function getNadraVerificationDetails(): ?array
    {
        if ($this->verification_type !== 'nadra_verisys' || !$this->nadra_response) {
            return null;
        }

        return [
            'session_id' => $this->nadra_session_id,
            'cnic_verified' => $this->cnic_verified,
            'match_score' => $this->match_score,
            'response' => $this->nadra_response,
            'verified_at' => $this->verified_at
        ];
    }

    public function getBiometricVerificationDetails(): ?array
    {
        if ($this->verification_type !== 'biometric' || !$this->response_data) {
            return null;
        }

        return [
            'biometric_matched' => $this->biometric_matched,
            'match_score' => $this->match_score,
            'liveness_check' => $this->response_data['liveness_check'] ?? false,
            'face_match' => $this->response_data['face_match'] ?? false,
            'verified_at' => $this->verified_at
        ];
    }

    public function isHighConfidence(): bool
    {
        return $this->match_score && $this->match_score >= 85.0;
    }

    public function isMediumConfidence(): bool
    {
        return $this->match_score && $this->match_score >= 70.0 && $this->match_score < 85.0;
    }

    public function isLowConfidence(): bool
    {
        return $this->match_score && $this->match_score < 70.0;
    }

    public function getConfidenceLevel(): string
    {
        if ($this->isHighConfidence()) {
            return 'high';
        }

        if ($this->isMediumConfidence()) {
            return 'medium';
        }

        return 'low';
    }

    public function shouldTriggerManualReview(): bool
    {
        return $this->isFailed() ||
            $this->isLowConfidence() ||
            $this->retry_count >= 2;
    }
}