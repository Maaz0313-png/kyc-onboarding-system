<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;

class KycDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'kyc_application_id',
        'document_type',
        'file_path',
        'file_name',
        'file_hash',
        'file_size',
        'mime_type',
        'ocr_data',
        'verification_data',
        'is_verified',
        'confidence_score',
        'virus_scanned',
        'is_encrypted',
        'expires_at',
        'status',
        'rejection_reason'
    ];

    protected $casts = [
        'ocr_data' => 'array',
        'verification_data' => 'array',
        'is_verified' => 'boolean',
        'virus_scanned' => 'boolean',
        'is_encrypted' => 'boolean',
        'expires_at' => 'datetime',
        'confidence_score' => 'decimal:2'
    ];

    // Relationships
    public function kycApplication(): BelongsTo
    {
        return $this->belongsTo(KycApplication::class);
    }

    // Scopes
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopeUnverified($query)
    {
        return $query->where('is_verified', false);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('document_type', $type);
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }

    // Helper Methods
    public function getFileUrl(): ?string
    {
        if (!$this->file_path) {
            return null;
        }

        return Storage::url($this->file_path);
    }

    public function getDecryptedContent(): ?string
    {
        if (!$this->is_encrypted || !$this->file_path) {
            return null;
        }

        try {
            $encryptedContent = Storage::get($this->file_path);
            return Crypt::decrypt($encryptedContent);
        } catch (\Exception $e) {
            \Log::error('Failed to decrypt document: ' . $e->getMessage());
            return null;
        }
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isVirusScanned(): bool
    {
        return $this->virus_scanned;
    }

    public function getFileSize(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function verifyIntegrity(): bool
    {
        if (!$this->file_path || !Storage::exists($this->file_path)) {
            return false;
        }

        $currentHash = hash_file('sha256', Storage::path($this->file_path));
        return $currentHash === $this->file_hash;
    }

    public function extractCNICData(): ?array
    {
        if ($this->document_type !== 'cnic_front' && $this->document_type !== 'cnic_back') {
            return null;
        }

        $ocrData = $this->ocr_data ?? [];

        if ($this->document_type === 'cnic_front') {
            return [
                'cnic_number' => $this->extractCNICNumber($ocrData),
                'name' => $this->extractName($ocrData),
                'father_name' => $this->extractFatherName($ocrData),
                'date_of_birth' => $this->extractDateOfBirth($ocrData),
                'date_of_issue' => $this->extractDateOfIssue($ocrData)
            ];
        }

        if ($this->document_type === 'cnic_back') {
            return [
                'address' => $this->extractAddress($ocrData),
                'date_of_expiry' => $this->extractDateOfExpiry($ocrData)
            ];
        }

        return null;
    }

    private function extractCNICNumber($ocrData): ?string
    {
        $text = implode(' ', $ocrData);

        // Pakistani CNIC format: 12345-1234567-1
        if (preg_match('/(\d{5}-\d{7}-\d)/', $text, $matches)) {
            return $matches[1];
        }

        // Alternative format without dashes: 1234512345671
        if (preg_match('/(\d{13})/', $text, $matches)) {
            $cnic = $matches[1];
            return substr($cnic, 0, 5) . '-' . substr($cnic, 5, 7) . '-' . substr($cnic, 12, 1);
        }

        return null;
    }

    private function extractName($ocrData): ?string
    {
        $text = implode(' ', $ocrData);

        // Look for name pattern after "Name" keyword
        if (preg_match('/Name[:\s]+([A-Z\s]+)/', $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractFatherName($ocrData): ?string
    {
        $text = implode(' ', $ocrData);

        // Look for father's name pattern
        if (preg_match('/Father[:\s]+([A-Z\s]+)/', $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractDateOfBirth($ocrData): ?string
    {
        $text = implode(' ', $ocrData);

        // Look for date patterns
        if (preg_match('/(\d{2}[\/\-\.]\d{2}[\/\-\.]\d{4})/', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractDateOfIssue($ocrData): ?string
    {
        $text = implode(' ', $ocrData);

        if (preg_match('/Issue[:\s]+(\d{2}[\/\-\.]\d{2}[\/\-\.]\d{4})/', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function extractAddress($ocrData): ?string
    {
        $text = implode(' ', $ocrData);

        if (preg_match('/Address[:\s]+(.+)/', $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractDateOfExpiry($ocrData): ?string
    {
        $text = implode(' ', $ocrData);

        if (preg_match('/Expiry[:\s]+(\d{2}[\/\-\.]\d{2}[\/\-\.]\d{4})/', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function markAsVerified(array $verificationData = []): void
    {
        $this->update([
            'is_verified' => true,
            'verification_data' => $verificationData,
            'status' => 'verified'
        ]);
    }

    public function markAsRejected(string $reason): void
    {
        $this->update([
            'is_verified' => false,
            'status' => 'rejected',
            'rejection_reason' => $reason
        ]);
    }
}