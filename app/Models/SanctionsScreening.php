<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SanctionsScreening extends Model
{
    use HasFactory;

    protected $fillable = [
        'kyc_application_id',
        'screening_type',
        'screening_provider',
        'reference_id',
        'status',
        'search_criteria',
        'matches_found',
        'match_count',
        'highest_match_score',
        'risk_level',
        'risk_notes',
        'requires_manual_review',
        'reviewed_by',
        'reviewed_at',
        'final_decision',
        'review_comments',
        'reported_to_fmu',
        'fmu_reference',
        'reported_at'
    ];

    protected $casts = [
        'search_criteria' => 'array',
        'matches_found' => 'array',
        'highest_match_score' => 'decimal:2',
        'requires_manual_review' => 'boolean',
        'reviewed_at' => 'datetime',
        'reported_to_fmu' => 'boolean',
        'reported_at' => 'datetime'
    ];

    // Relationships
    public function kycApplication(): BelongsTo
    {
        return $this->belongsTo(KycApplication::class);
    }

    // Scopes
    public function scopeClear($query)
    {
        return $query->where('status', 'clear');
    }

    public function scopeMatchFound($query)
    {
        return $query->where('status', 'match_found');
    }

    public function scopeUnderReview($query)
    {
        return $query->where('status', 'under_review');
    }

    public function scopeHighRisk($query)
    {
        return $query->where('risk_level', 'high');
    }

    public function scopeCriticalRisk($query)
    {
        return $query->where('risk_level', 'critical');
    }

    public function scopeRequiresReview($query)
    {
        return $query->where('requires_manual_review', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('screening_type', $type);
    }

    public function scopeReportedToFmu($query)
    {
        return $query->where('reported_to_fmu', true);
    }

    // Helper Methods
    public function isClear(): bool
    {
        return $this->status === 'clear';
    }

    public function hasMatches(): bool
    {
        return $this->status === 'match_found' && $this->match_count > 0;
    }

    public function isUnderReview(): bool
    {
        return $this->status === 'under_review';
    }

    public function isFalsePositive(): bool
    {
        return $this->status === 'false_positive';
    }

    public function isHighRisk(): bool
    {
        return in_array($this->risk_level, ['high', 'critical']);
    }

    public function isCriticalRisk(): bool
    {
        return $this->risk_level === 'critical';
    }

    public function requiresManualReview(): bool
    {
        return $this->requires_manual_review;
    }

    public function isReviewed(): bool
    {
        return !is_null($this->reviewed_by) && !is_null($this->reviewed_at);
    }

    public function isApproved(): bool
    {
        return $this->final_decision === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->final_decision === 'rejected';
    }

    public function isEscalated(): bool
    {
        return $this->final_decision === 'escalated';
    }

    public function isReportedToFmu(): bool
    {
        return $this->reported_to_fmu;
    }

    public function markAsClear(): void
    {
        $this->update([
            'status' => 'clear',
            'risk_level' => 'low',
            'requires_manual_review' => false
        ]);
    }

    public function markAsMatchFound(array $matches, float $highestScore): void
    {
        $riskLevel = $this->calculateRiskLevel($highestScore);

        $this->update([
            'status' => 'match_found',
            'matches_found' => $matches,
            'match_count' => count($matches),
            'highest_match_score' => $highestScore,
            'risk_level' => $riskLevel,
            'requires_manual_review' => $riskLevel !== 'low'
        ]);
    }

    public function markAsFalsePositive(string $reviewedBy, string $comments = null): void
    {
        $this->update([
            'status' => 'false_positive',
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
            'final_decision' => 'approved',
            'review_comments' => $comments,
            'requires_manual_review' => false
        ]);
    }

    public function markAsUnderReview(): void
    {
        $this->update([
            'status' => 'under_review',
            'requires_manual_review' => true
        ]);
    }

    public function approve(string $reviewedBy, string $comments = null): void
    {
        $this->update([
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
            'final_decision' => 'approved',
            'review_comments' => $comments,
            'requires_manual_review' => false
        ]);
    }

    public function reject(string $reviewedBy, string $comments): void
    {
        $this->update([
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
            'final_decision' => 'rejected',
            'review_comments' => $comments,
            'requires_manual_review' => false
        ]);
    }

    public function escalate(string $reviewedBy, string $comments): void
    {
        $this->update([
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => now(),
            'final_decision' => 'escalated',
            'review_comments' => $comments,
            'requires_manual_review' => true,
            'risk_level' => 'critical'
        ]);
    }

    public function reportToFmu(string $fmuReference): void
    {
        $this->update([
            'reported_to_fmu' => true,
            'fmu_reference' => $fmuReference,
            'reported_at' => now()
        ]);
    }

    private function calculateRiskLevel(float $matchScore): string
    {
        if ($matchScore >= 90) {
            return 'critical';
        }

        if ($matchScore >= 75) {
            return 'high';
        }

        if ($matchScore >= 50) {
            return 'medium';
        }

        return 'low';
    }

    public function getScreeningResult(): array
    {
        return [
            'type' => $this->screening_type,
            'provider' => $this->screening_provider,
            'status' => $this->status,
            'risk_level' => $this->risk_level,
            'match_count' => $this->match_count,
            'highest_match_score' => $this->highest_match_score,
            'requires_manual_review' => $this->requires_manual_review,
            'final_decision' => $this->final_decision,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at,
            'reported_to_fmu' => $this->reported_to_fmu
        ];
    }

    public function getMatchDetails(): array
    {
        return [
            'matches' => $this->matches_found ?? [],
            'match_count' => $this->match_count,
            'highest_score' => $this->highest_match_score,
            'search_criteria' => $this->search_criteria
        ];
    }

    public function shouldReportToFmu(): bool
    {
        return $this->hasMatches() &&
            $this->isHighRisk() &&
            !$this->isReportedToFmu() &&
            !$this->isFalsePositive();
    }

    public function getComplianceStatus(): string
    {
        if ($this->isClear() || $this->isFalsePositive()) {
            return 'compliant';
        }

        if ($this->isRejected()) {
            return 'non_compliant';
        }

        if ($this->requiresManualReview() || $this->isUnderReview()) {
            return 'pending_review';
        }

        if ($this->isApproved()) {
            return 'compliant_with_conditions';
        }

        return 'unknown';
    }
}