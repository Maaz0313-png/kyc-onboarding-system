<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'email_verified_at',
        'phone_verified_at',
        'is_active',
        'last_login_at'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean'
        ];
    }

    // Relationships
    public function kycApplications(): HasMany
    {
        return $this->hasMany(KycApplication::class);
    }

    public function currentKycApplication(): HasOne
    {
        return $this->hasOne(KycApplication::class)->latest();
    }

    public function approvedKycApplication(): HasOne
    {
        return $this->hasOne(KycApplication::class)->where('status', 'approved');
    }

    public function auditTrails(): HasMany
    {
        return $this->hasMany(AuditTrail::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVerified($query)
    {
        return $query->whereNotNull('email_verified_at');
    }

    public function scopePhoneVerified($query)
    {
        return $query->whereNotNull('phone_verified_at');
    }

    public function scopeKycApproved($query)
    {
        return $query->whereHas('kycApplications', function ($q) {
            $q->where('status', 'approved');
        });
    }

    // Helper Methods
    public function isEmailVerified(): bool
    {
        return !is_null($this->email_verified_at);
    }

    public function isPhoneVerified(): bool
    {
        return !is_null($this->phone_verified_at);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function hasKycApplication(): bool
    {
        return $this->kycApplications()->exists();
    }

    public function hasApprovedKyc(): bool
    {
        return $this->kycApplications()->where('status', 'approved')->exists();
    }

    public function hasPendingKyc(): bool
    {
        return $this->kycApplications()->whereIn('status', ['pending', 'in_progress', 'under_review'])->exists();
    }

    public function getKycStatus(): string
    {
        $latestApplication = $this->currentKycApplication;

        if (!$latestApplication) {
            return 'not_started';
        }

        return $latestApplication->status;
    }

    public function getKycProgressPercentage(): int
    {
        $latestApplication = $this->currentKycApplication;

        if (!$latestApplication) {
            return 0;
        }

        return $latestApplication->getProgressPercentage();
    }

    public function canStartKyc(): bool
    {
        return $this->isActive() &&
            $this->isEmailVerified() &&
            $this->isPhoneVerified() &&
            !$this->hasPendingKyc() &&
            !$this->hasApprovedKyc();
    }

    public function canAccessAccount(): bool
    {
        return $this->isActive() && $this->hasApprovedKyc();
    }

    public function getAccountTier(): ?string
    {
        $approvedApplication = $this->approvedKycApplication;

        return $approvedApplication?->account_tier;
    }

    public function getRiskCategory(): ?string
    {
        $approvedApplication = $this->approvedKycApplication;

        return $approvedApplication?->risk_category;
    }

    public function getRiskScore(): int
    {
        $approvedApplication = $this->approvedKycApplication;

        return $approvedApplication?->risk_score ?? 0;
    }

    public function markEmailAsVerified(): void
    {
        $this->update(['email_verified_at' => now()]);
    }

    public function markPhoneAsVerified(): void
    {
        $this->update(['phone_verified_at' => now()]);
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    public function updateLastLogin(): void
    {
        $this->update(['last_login_at' => now()]);
    }

    public function isComplianceOfficer(): bool
    {
        return $this->hasRole('compliance_officer');
    }

    public function isKycOfficer(): bool
    {
        return $this->hasRole('kyc_officer');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function canReviewKyc(): bool
    {
        return $this->isKycOfficer() || $this->isComplianceOfficer() || $this->isAdmin();
    }

    public function canApproveKyc(): bool
    {
        return $this->isComplianceOfficer() || $this->isAdmin();
    }

    public function canAccessComplianceData(): bool
    {
        return $this->isComplianceOfficer() || $this->isAdmin();
    }
}