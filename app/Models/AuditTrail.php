<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditTrail extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent'
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array'
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable()
    {
        return $this->morphTo('model');
    }

    // Scopes
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByModel($query, $modelType, $modelId = null)
    {
        $query = $query->where('model_type', $modelType);

        if ($modelId) {
            $query->where('model_id', $modelId);
        }

        return $query;
    }

    public function scopeByAction($query, $action)
    {
        return $query->where('action', '=', $action);
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // Helper Methods
    public static function log(
        string $action,
        Model $model,
        ?User $user = null,
        array $oldValues = [],
        array $newValues = [],
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): self {
        return self::create([
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'model_type' => get_class($model),
            'model_id' => $model->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ipAddress ?? request()->ip(),
            'user_agent' => $userAgent ?? request()->userAgent()
        ]);
    }

    public static function logKycAction(
        string $action,
        KycApplication $kycApplication,
        ?User $user = null,
        array $additionalData = []
    ): self {
        return self::log(
            $action,
            $kycApplication,
            $user,
            [],
            array_merge([
                'application_id' => $kycApplication->application_id,
                'cnic' => $kycApplication->cnic,
                'status' => $kycApplication->status,
                'risk_score' => $kycApplication->risk_score
            ], $additionalData)
        );
    }

    public static function logDocumentAction(
        string $action,
        KycDocument $document,
        ?User $user = null,
        array $additionalData = []
    ): self {
        return self::log(
            $action,
            $document,
            $user,
            [],
            array_merge([
                'document_type' => $document->document_type,
                'file_name' => $document->file_name,
                'status' => $document->status,
                'is_verified' => $document->is_verified
            ], $additionalData)
        );
    }

    public static function logVerificationAction(
        string $action,
        KycVerification $verification,
        ?User $user = null,
        array $additionalData = []
    ): self {
        return self::log(
            $action,
            $verification,
            $user,
            [],
            array_merge([
                'verification_type' => $verification->verification_type,
                'provider' => $verification->provider,
                'status' => $verification->status,
                'match_score' => $verification->match_score
            ], $additionalData)
        );
    }

    public static function logScreeningAction(
        string $action,
        SanctionsScreening $screening,
        ?User $user = null,
        array $additionalData = []
    ): self {
        return self::log(
            $action,
            $screening,
            $user,
            [],
            array_merge([
                'screening_type' => $screening->screening_type,
                'status' => $screening->status,
                'risk_level' => $screening->risk_level,
                'match_count' => $screening->match_count
            ], $additionalData)
        );
    }

    public function getChanges(): array
    {
        $changes = [];

        if ($this->old_values && $this->new_values) {
            foreach ($this->new_values as $key => $newValue) {
                $oldValue = $this->old_values[$key] ?? null;

                if ($oldValue !== $newValue) {
                    $changes[$key] = [
                        'old' => $oldValue,
                        'new' => $newValue
                    ];
                }
            }
        }

        return $changes;
    }

    public function getFormattedAction(): string
    {
        $actions = [
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Deleted',
            'verified' => 'Verified',
            'rejected' => 'Rejected',
            'approved' => 'Approved',
            'submitted' => 'Submitted',
            'processed' => 'Processed',
            'uploaded' => 'Uploaded',
            'downloaded' => 'Downloaded',
            'viewed' => 'Viewed',
            'screened' => 'Screened',
            'reported' => 'Reported'
        ];

        return $actions[$this->action] ?? ucfirst($this->action);
    }

    public function getModelName(): string
    {
        $modelNames = [
            KycApplication::class => 'KYC Application',
            KycDocument::class => 'KYC Document',
            KycVerification::class => 'KYC Verification',
            SanctionsScreening::class => 'Sanctions Screening',
            User::class => 'User'
        ];

        return $modelNames[$this->model_type] ?? class_basename($this->model_type);
    }

    public function getDescription(): string
    {
        $modelName = $this->getModelName();
        $action = $this->getFormattedAction();

        return "{$action} {$modelName}";
    }

    public function isSecuritySensitive(): bool
    {
        $sensitiveActions = [
            'deleted',
            'rejected',
            'approved',
            'escalated',
            'reported'
        ];

        return in_array($this->action, $sensitiveActions);
    }

    public function getComplianceData(): array
    {
        return [
            'timestamp' => $this->created_at->toISOString(),
            'user_id' => $this->user_id,
            'action' => $this->action,
            'model' => $this->getModelName(),
            'model_id' => $this->model_id,
            'ip_address' => $this->ip_address,
            'changes' => $this->getChanges()
        ];
    }
}