<?php

namespace App\Services;

use App\Models\KycApplication;
use App\Models\SanctionsScreening;
use App\Models\AuditTrail;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

class SanctionsScreeningService
{
    private Client $client;
    private array $sanctionsLists;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 30]);
        $this->loadSanctionsLists();
    }

    /**
     * Perform comprehensive sanctions screening
     */
    public function performScreening(KycApplication $kycApplication): array
    {
        $screeningResults = [];

        // UN Sanctions List
        $screeningResults['un_sanctions'] = $this->screenAgainstUNSanctions($kycApplication);

        // TFS (Targeted Financial Sanctions) Regime
        $screeningResults['tfs_regime'] = $this->screenAgainstTFS($kycApplication);

        // PEP (Politically Exposed Persons) Check
        $screeningResults['pep_check'] = $this->screenAgainstPEP($kycApplication);

        // Local Proscribed Entities
        $screeningResults['local_proscribed'] = $this->screenAgainstLocalProscribed($kycApplication);

        // OFAC Sanctions
        $screeningResults['ofac'] = $this->screenAgainstOFAC($kycApplication);

        // Update overall compliance status
        $this->updateComplianceStatus($kycApplication, $screeningResults);

        return $screeningResults;
    }

    /**
     * Screen against UN Sanctions List
     */
    public function screenAgainstUNSanctions(KycApplication $kycApplication): SanctionsScreening
    {
        $screening = SanctionsScreening::create([
            'kyc_application_id' => $kycApplication->id,
            'screening_type' => 'un_sanctions',
            'screening_provider' => 'internal',
            'status' => 'pending',
            'search_criteria' => [
                'full_name' => $kycApplication->full_name,
                'father_name' => $kycApplication->father_name,
                'cnic' => $kycApplication->cnic,
                'date_of_birth' => $kycApplication->date_of_birth?->format('Y-m-d')
            ]
        ]);

        try {
            $matches = $this->searchInSanctionsList(
                $this->sanctionsLists['un_sanctions'] ?? [],
                $kycApplication
            );

            if (empty($matches)) {
                $screening->markAsClear();
            } else {
                $highestScore = max(array_column($matches, 'match_score'));
                $screening->markAsMatchFound($matches, $highestScore);

                // Report to FMU if required
                if ($screening->shouldReportToFmu()) {
                    $this->reportToFMU($screening);
                }
            }

            AuditTrail::logScreeningAction('screened', $screening, null, [
                'screening_type' => 'un_sanctions',
                'matches_count' => count($matches)
            ]);

        } catch (\Exception $e) {
            Log::error('UN Sanctions Screening Error', [
                'kyc_application_id' => $kycApplication->id,
                'error' => $e->getMessage()
            ]);

            $screening->update([
                'status' => 'under_review',
                'risk_notes' => 'Screening failed due to technical error',
                'requires_manual_review' => true
            ]);
        }

        return $screening;
    }

    /**
     * Screen against TFS (Targeted Financial Sanctions) Regime
     */
    public function screenAgainstTFS(KycApplication $kycApplication): SanctionsScreening
    {
        $screening = SanctionsScreening::create([
            'kyc_application_id' => $kycApplication->id,
            'screening_type' => 'tfs_regime',
            'screening_provider' => 'internal',
            'status' => 'pending',
            'search_criteria' => [
                'full_name' => $kycApplication->full_name,
                'father_name' => $kycApplication->father_name,
                'cnic' => $kycApplication->cnic
            ]
        ]);

        try {
            $matches = $this->searchInSanctionsList(
                $this->sanctionsLists['tfs_regime'] ?? [],
                $kycApplication
            );

            if (empty($matches)) {
                $screening->markAsClear();
            } else {
                $highestScore = max(array_column($matches, 'match_score'));
                $screening->markAsMatchFound($matches, $highestScore);

                if ($screening->shouldReportToFmu()) {
                    $this->reportToFMU($screening);
                }
            }

        } catch (\Exception $e) {
            Log::error('TFS Screening Error', [
                'kyc_application_id' => $kycApplication->id,
                'error' => $e->getMessage()
            ]);

            $screening->markAsUnderReview();
        }

        return $screening;
    }

    /**
     * Screen against PEP (Politically Exposed Persons) List
     */
    public function screenAgainstPEP(KycApplication $kycApplication): SanctionsScreening
    {
        $screening = SanctionsScreening::create([
            'kyc_application_id' => $kycApplication->id,
            'screening_type' => 'pep_check',
            'screening_provider' => 'internal',
            'status' => 'pending',
            'search_criteria' => [
                'full_name' => $kycApplication->full_name,
                'father_name' => $kycApplication->father_name,
                'cnic' => $kycApplication->cnic
            ]
        ]);

        try {
            $matches = $this->searchInSanctionsList(
                $this->sanctionsLists['pep_list'] ?? [],
                $kycApplication
            );

            if (empty($matches)) {
                $screening->markAsClear();
                $kycApplication->update(['pep_cleared' => true]);
            } else {
                $highestScore = max(array_column($matches, 'match_score'));
                $screening->markAsMatchFound($matches, $highestScore);

                // PEP matches require enhanced due diligence
                $screening->update([
                    'requires_manual_review' => true,
                    'risk_notes' => 'PEP match found - Enhanced Due Diligence required'
                ]);
            }

        } catch (\Exception $e) {
            Log::error('PEP Screening Error', [
                'kyc_application_id' => $kycApplication->id,
                'error' => $e->getMessage()
            ]);

            $screening->markAsUnderReview();
        }

        return $screening;
    }

    /**
     * Screen against Local Proscribed Entities
     */
    public function screenAgainstLocalProscribed(KycApplication $kycApplication): SanctionsScreening
    {
        $screening = SanctionsScreening::create([
            'kyc_application_id' => $kycApplication->id,
            'screening_type' => 'local_proscribed',
            'screening_provider' => 'internal',
            'status' => 'pending',
            'search_criteria' => [
                'full_name' => $kycApplication->full_name,
                'father_name' => $kycApplication->father_name,
                'cnic' => $kycApplication->cnic,
                'address' => $kycApplication->address
            ]
        ]);

        try {
            $matches = $this->searchInSanctionsList(
                $this->sanctionsLists['local_proscribed'] ?? [],
                $kycApplication
            );

            if (empty($matches)) {
                $screening->markAsClear();
            } else {
                $highestScore = max(array_column($matches, 'match_score'));
                $screening->markAsMatchFound($matches, $highestScore);

                // Local proscribed matches are critical
                $screening->update(['risk_level' => 'critical']);

                if ($screening->shouldReportToFmu()) {
                    $this->reportToFMU($screening);
                }
            }

        } catch (\Exception $e) {
            Log::error('Local Proscribed Screening Error', [
                'kyc_application_id' => $kycApplication->id,
                'error' => $e->getMessage()
            ]);

            $screening->markAsUnderReview();
        }

        return $screening;
    }

    /**
     * Screen against OFAC Sanctions
     */
    public function screenAgainstOFAC(KycApplication $kycApplication): SanctionsScreening
    {
        $screening = SanctionsScreening::create([
            'kyc_application_id' => $kycApplication->id,
            'screening_type' => 'ofac',
            'screening_provider' => 'internal',
            'status' => 'pending',
            'search_criteria' => [
                'full_name' => $kycApplication->full_name,
                'father_name' => $kycApplication->father_name,
                'date_of_birth' => $kycApplication->date_of_birth?->format('Y-m-d')
            ]
        ]);

        try {
            $matches = $this->searchInSanctionsList(
                $this->sanctionsLists['ofac'] ?? [],
                $kycApplication
            );

            if (empty($matches)) {
                $screening->markAsClear();
            } else {
                $highestScore = max(array_column($matches, 'match_score'));
                $screening->markAsMatchFound($matches, $highestScore);
            }

        } catch (\Exception $e) {
            Log::error('OFAC Screening Error', [
                'kyc_application_id' => $kycApplication->id,
                'error' => $e->getMessage()
            ]);

            $screening->markAsUnderReview();
        }

        return $screening;
    }

    /**
     * Search in sanctions list using fuzzy matching
     */
    private function searchInSanctionsList(array $sanctionsList, KycApplication $kycApplication): array
    {
        $matches = [];
        $searchName = strtolower($kycApplication->full_name);
        $searchFatherName = strtolower($kycApplication->father_name);

        foreach ($sanctionsList as $entry) {
            $entryName = strtolower($entry['name'] ?? '');
            $entryAliases = array_map('strtolower', $entry['aliases'] ?? []);

            // Name matching
            $nameScore = $this->calculateSimilarity($searchName, $entryName);

            // Check aliases
            $aliasScore = 0;
            foreach ($entryAliases as $alias) {
                $aliasScore = max($aliasScore, $this->calculateSimilarity($searchName, $alias));
            }

            $maxScore = max($nameScore, $aliasScore);

            // Father name matching (if available)
            if (!empty($entry['father_name'])) {
                $fatherScore = $this->calculateSimilarity($searchFatherName, strtolower($entry['father_name']));
                $maxScore = ($maxScore + $fatherScore) / 2;
            }

            // Date of birth matching (if available)
            if (!empty($entry['date_of_birth'])) {
                $dobMatch = $kycApplication->date_of_birth->format('Y-m-d') === $entry['date_of_birth'];
                if ($dobMatch) {
                    $maxScore += 10; // Bonus for exact DOB match
                }
            }

            // Consider it a match if score is above threshold
            if ($maxScore >= 70) {
                $matches[] = [
                    'entry' => $entry,
                    'match_score' => min(100, $maxScore),
                    'match_type' => $nameScore > $aliasScore ? 'name' : 'alias',
                    'matched_field' => $nameScore > $aliasScore ? $entryName : 'alias'
                ];
            }
        }

        // Sort by match score descending
        usort($matches, function ($a, $b) {
            return $b['match_score'] <=> $a['match_score'];
        });

        return $matches;
    }

    /**
     * Calculate similarity between two strings using Levenshtein distance
     */
    private function calculateSimilarity(string $str1, string $str2): float
    {
        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen === 0)
            return 100;

        $distance = levenshtein($str1, $str2);
        return (1 - ($distance / $maxLen)) * 100;
    }

    /**
     * Load sanctions lists from storage
     */
    private function loadSanctionsLists(): void
    {
        $this->sanctionsLists = [
            'un_sanctions' => $this->loadListFromFile('sanctions/un_sanctions.json'),
            'tfs_regime' => $this->loadListFromFile('sanctions/tfs_regime.json'),
            'pep_list' => $this->loadListFromFile('sanctions/pep_list.json'),
            'local_proscribed' => $this->loadListFromFile('sanctions/local_proscribed.json'),
            'ofac' => $this->loadListFromFile('sanctions/ofac.json')
        ];
    }

    /**
     * Load sanctions list from file
     */
    private function loadListFromFile(string $filePath): array
    {
        try {
            // Validate file path to prevent directory traversal
            if (strpos($filePath, '..') !== false || !str_starts_with($filePath, 'sanctions/')) {
                throw new \InvalidArgumentException('Invalid file path');
            }
            
            if (Storage::exists($filePath)) {
                $content = Storage::get($filePath);
                return json_decode($content, true) ?? [];
            }
        } catch (\Exception $e) {
            Log::warning("Failed to load sanctions list: {$filePath}", [
                'error' => $e->getMessage()
            ]);
        }

        return [];
    }

    /**
     * Update overall compliance status
     */
    private function updateComplianceStatus(KycApplication $kycApplication, array $screeningResults): void
    {
        $sanctionsCleared = true;
        $pepCleared = true;

        foreach ($screeningResults as $type => $screening) {
            if ($screening->hasMatches() && !$screening->isFalsePositive()) {
                if ($type === 'pep_check') {
                    $pepCleared = false;
                } else {
                    $sanctionsCleared = false;
                }
            }
        }

        $kycApplication->update([
            'sanctions_cleared' => $sanctionsCleared,
            'pep_cleared' => $pepCleared
        ]);
    }

    /**
     * Report to FMU (Financial Monitoring Unit)
     */
    private function reportToFMU(SanctionsScreening $screening): void
    {
        try {
            $fmuReference = 'FMU-' . date('Y') . '-' . str_pad($screening->id, 6, '0', STR_PAD_LEFT);

            // In a real implementation, this would send data to FMU's goAML system
            $reportData = [
                'reference' => $fmuReference,
                'screening_id' => $screening->id,
                'kyc_application_id' => $screening->kyc_application_id,
                'screening_type' => $screening->screening_type,
                'matches' => $screening->matches_found,
                'risk_level' => $screening->risk_level,
                'reported_at' => now()->toISOString()
            ];

            // Store report locally for audit
            Storage::put("fmu_reports/{$fmuReference}.json", json_encode($reportData, JSON_PRETTY_PRINT));

            $screening->reportToFmu($fmuReference);

            AuditTrail::logScreeningAction('reported', $screening, null, [
                'fmu_reference' => $fmuReference,
                'report_type' => 'sanctions_match'
            ]);

        } catch (\Exception $e) {
            Log::error('FMU Reporting Error', [
                'screening_id' => $screening->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update sanctions lists from external sources
     */
    public function updateSanctionsLists(): void
    {
        // This would typically fetch updated lists from official sources
        // For now, we'll just log the update attempt
        Log::info('Sanctions lists update initiated');

        // In production, implement actual list updates from:
        // - UN Security Council Consolidated List
        // - OFAC SDN List
        // - Local regulatory authorities
        // - PEP databases
    }

    /**
     * Get screening summary for KYC application
     */
    public function getScreeningSummary(KycApplication $kycApplication): array
    {
        $screenings = $kycApplication->sanctionsScreenings;

        $summary = [
            'total_screenings' => $screenings->count(),
            'clear_screenings' => $screenings->where('status', 'clear')->count(),
            'matches_found' => $screenings->where('status', 'match_found')->count(),
            'under_review' => $screenings->where('status', 'under_review')->count(),
            'requires_manual_review' => $screenings->where('requires_manual_review', true)->count(),
            'reported_to_fmu' => $screenings->where('reported_to_fmu', true)->count(),
            'highest_risk_level' => $screenings->max('risk_level') ?? 'low',
            'overall_status' => $this->determineOverallStatus($screenings)
        ];

        return $summary;
    }

    /**
     * Determine overall screening status
     */
    private function determineOverallStatus($screenings): string
    {
        if ($screenings->where('status', 'match_found')->where('final_decision', 'rejected')->count() > 0) {
            return 'rejected';
        }

        if ($screenings->where('requires_manual_review', true)->count() > 0) {
            return 'under_review';
        }

        if ($screenings->where('status', 'clear')->count() === $screenings->count()) {
            return 'clear';
        }

        return 'pending';
    }
}