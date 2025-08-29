<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class KycApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'user_id',
        'cnic',
        'full_name',
        'father_name',
        'date_of_birth',
        'gender',
        'phone_number',
        'email',
        'address',
        'city',
        'province',
        'postal_code',
        'status',
        'risk_score',
        'risk_category',
        'account_tier',
        'sanctions_cleared',
        'pep_cleared',
        'nadra_verified',
        'biometric_verified',
        'consent_given',
        'submitted_at',
        'processed_at',
        'processed_by',
        'rejection_reason',
        'compliance_data'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'sanctions_cleared' => 'boolean',
        'pep_cleared' => 'boolean',
        'nadra_verified' => 'boolean',
        'biometric_verified' => 'boolean',
        'consent_given' => 'boolean',
        'submitted_at' => 'datetime',
        'processed_at' => 'datetime',
        'compliance_data' => 'array'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->application_id)) {
                $model->application_id = 'KYC-' . date('Y') . '-' . strtoupper(Str::random(8));
            }
        });
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(KycVerification::class);
    }

    public function sanctionsScreenings(): HasMany
    {
        return $this->hasMany(SanctionsScreening::class);
    }

    public function auditTrails(): HasMany
    {
        return $this->hasMany(AuditTrail::class, 'model_id')->where('model_type', self::class);
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeHighRisk($query)
    {
        return $query->where('risk_category', 'high');
    }

    // Helper Methods
    public function isCompliant(): bool
    {
        return $this->sanctions_cleared && 
               $this->pep_cleared && 
               $this->nadra_verified && 
               $this->biometric_verified;
    }

    public function canAutoApprove(): bool
    {
        return $this->isCompliant() && 
               $this->risk_score <= 30 && 
               $this->risk_category === 'low';
    }

    public function requiresManualReview(): bool
    {
        return $this->risk_score > 70 || 
               $this->risk_category === 'high' ||
               !$this->isCompliant();
    }

    public function getProgressPercentage(): int
    {
        $steps = [
            'consent_given',
            'nadra_verified',
            'biometric_verified',
            'sanctions_cleared',
            'pep_cleared'
        ];

        $completed = 0;
        foreach ($steps as $step) {
            if ($this->$step) {
                $completed++;
            }
        }

        return (int) (($completed / count($steps)) * 100);
    }

    public function updateRiskScore(): void
    {
        $score = 0;

        // Base risk factors
        if (!$this->nadra_verified) $score += 25;
        if (!$this->biometric_verified) $score += 20;
        if (!$this->sanctions_cleared) $score += 30;
        if (!$this->pep_cleared) $score += 25;

        // Age factor
        $age = now()->diffInYears($this->date_of_birth);
        if ($age < 18) $score += 50; // Underage
        if ($age > 65) $score += 10; // Senior citizen

        // Document verification confidence
        $avgConfidence = $this->documents()
            ->whereNotNull('confidence_score')
            ->avg('confidence_score') ?? 0;
        
        if ($avgConfidence < 70) $score += 15;
        if ($avgConfidence < 50) $score += 25;

        $this->risk_score = min(100, $score);
        
        // Update risk category
        if ($this->risk_score <= 30) {
            $this->risk_category = 'low';
        } elseif ($this->risk_score <= 70) {
            $this->risk_category = 'medium';
        } else {
            $this->risk_category = 'high';
        }

        $this->save();
    }
}