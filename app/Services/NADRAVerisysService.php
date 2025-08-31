<?php

namespace App\Services;

use App\Models\KycApplication;
use App\Models\KycVerification;
use App\Models\AuditTrail;
use App\Models\KycStatus;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class NADRAVerisysService
{
    private Client $client;
    private string $baseUrl;
    private string $apiKey;
    private string $clientId;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = config('services.nadra.base_url', 'https://verisys.nadra.gov.pk/api');
        $this->apiKey = config('services.nadra.api_key');
        $this->clientId = config('services.nadra.client_id');
        $this->timeout = config('services.nadra.timeout', 30);
        
        if (empty($this->apiKey) || empty($this->clientId)) {
            throw new \InvalidArgumentException('NADRA API credentials are required');
        }

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-API-Key' => $this->apiKey,
                'X-Client-ID' => $this->clientId
            ]
        ]);
    }

    /**
     * Verify CNIC with NADRA Verisys
     */
    public function verifyCNIC(KycApplication $kycApplication): KycVerification
    {
        $verification = KycVerification::create([
            'kyc_application_id' => $kycApplication->id,
            'verification_type' => 'nadra_verisys',
            'provider' => 'NADRA',
            'status' => 'pending',
            'request_data' => [
                'cnic' => $kycApplication->cnic,
                'full_name' => $kycApplication->full_name,
                'father_name' => $kycApplication->father_name,
                'date_of_birth' => $kycApplication->date_of_birth->format('Y-m-d')
            ]
        ]);

        try {
            $response = $this->client->post('/verify-cnic', [
                'json' => [
                    'cnic' => $kycApplication->cnic,
                    'full_name' => $kycApplication->full_name,
                    'father_name' => $kycApplication->father_name,
                    'date_of_birth' => $kycApplication->date_of_birth->format('d/m/Y'),
                    'transaction_id' => $verification->id
                ]
            ]);

            $responseBody = $response->getBody()->getContents();
            $responseData = json_decode($responseBody, true);
            
            if ($responseData === null) {
                throw new \Exception('Invalid JSON response from NADRA API');
            }

            if ($responseData['status'] === 'success') {
                $matchScore = $this->calculateMatchScore($responseData);

                $verification->markAsSuccessful($responseData, $matchScore);
                $verification->update([
                    'transaction_id' => $responseData['transaction_id'] ?? null,
                    'nadra_session_id' => $responseData['session_id'] ?? null,
                    'nadra_response' => $responseData
                ]);

                // Update KYC application
                if ($matchScore >= 85) {
                    $kycApplication->update(['nadra_verified' => true]);
                }

                AuditTrail::logVerificationAction('verified', $verification, null, [
                    'match_score' => $matchScore,
                    'nadra_verified' => $matchScore >= 85
                ]);

            } else {
                $verification->markAsFailed(
                    $responseData['error_code'] ?? 'VERIFICATION_FAILED',
                    $responseData['message'] ?? 'NADRA verification failed'
                );
            }

        } catch (RequestException $e) {
            Log::error('NADRA Verisys API Error', [
                'kyc_application_id' => $kycApplication->id,
                'error' => $e->getMessage(),
                'response' => $e->getResponse() ? $e->getResponse()->getBody()->getContents() : null
            ]);

            $verification->markAsFailed('API_ERROR', 'Failed to connect to NADRA Verisys API');
        } catch (\Exception $e) {
            Log::error('NADRA Verisys Service Error', [
                'kyc_application_id' => $kycApplication->id,
                'error' => $e->getMessage()
            ]);

            $verification->markAsFailed('SERVICE_ERROR', 'Internal service error');
        }

        return $verification;
    }

    /**
     * Verify biometric data with NADRA
     */
    public function verifyBiometric(KycApplication $kycApplication, string $biometricData): KycVerification
    {
        $verification = KycVerification::create([
            'kyc_application_id' => $kycApplication->id,
            'verification_type' => 'biometric',
            'provider' => 'NADRA',
            'status' => 'pending',
            'request_data' => [
                'cnic' => $kycApplication->cnic,
                'biometric_type' => 'fingerprint'
            ]
        ]);

        try {
            $response = $this->client->post('/verify-biometric', [
                'json' => [
                    'cnic' => $kycApplication->cnic,
                    'biometric_data' => $biometricData,
                    'biometric_type' => 'fingerprint',
                    'transaction_id' => $verification->id
                ]
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($responseData['status'] === 'success') {
                $matchScore = $responseData['match_score'] ?? 0;

                $verification->markAsSuccessful($responseData, $matchScore);
                $verification->update([
                    'transaction_id' => $responseData['transaction_id'] ?? null,
                    'nadra_session_id' => $responseData['session_id'] ?? null
                ]);

                // Update KYC application
                if ($matchScore >= 80) {
                    $kycApplication->update(['biometric_verified' => true]);
                }

                AuditTrail::logVerificationAction('biometric_verified', $verification, null, [
                    'match_score' => $matchScore,
                    'biometric_verified' => $matchScore >= 80
                ]);

            } else {
                $verification->markAsFailed(
                    $responseData['error_code'] ?? 'BIOMETRIC_FAILED',
                    $responseData['message'] ?? 'Biometric verification failed'
                );
            }

        } catch (RequestException $e) {
            Log::error('NADRA Biometric API Error', [
                'kyc_application_id' => $kycApplication->id,
                'error' => $e->getMessage()
            ]);

            $verification->markAsFailed('API_ERROR', 'Failed to connect to NADRA Biometric API');
        } catch (\Exception $e) {
            Log::error('NADRA Biometric Service Error', [
                'kyc_application_id' => $kycApplication->id,
                'error' => $e->getMessage()
            ]);

            $verification->markAsFailed('SERVICE_ERROR', 'Internal service error');
        }

        return $verification;
    }

    /**
     * Perform liveness check
     */
    public function performLivenessCheck(KycApplication $kycApplication, string $selfieImage): KycVerification
    {
        $verification = KycVerification::create([
            'kyc_application_id' => $kycApplication->id,
            'verification_type' => 'liveness_check',
            'provider' => 'NADRA',
            'status' => 'pending',
            'request_data' => [
                'cnic' => $kycApplication->cnic,
                'check_type' => 'liveness'
            ]
        ]);

        try {
            $response = $this->client->post('/liveness-check', [
                'json' => [
                    'cnic' => $kycApplication->cnic,
                    'selfie_image' => $selfieImage,
                    'transaction_id' => $verification->id
                ]
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            if ($responseData['status'] === 'success') {
                $livenessScore = $responseData['liveness_score'] ?? 0;
                $faceMatchScore = $responseData['face_match_score'] ?? 0;
                $overallScore = ($livenessScore + $faceMatchScore) / 2;

                $verification->markAsSuccessful([
                    'liveness_score' => $livenessScore,
                    'face_match_score' => $faceMatchScore,
                    'liveness_check' => $livenessScore >= 70,
                    'face_match' => $faceMatchScore >= 75,
                    'overall_result' => $responseData
                ], $overallScore);

                AuditTrail::logVerificationAction('liveness_checked', $verification, null, [
                    'liveness_score' => $livenessScore,
                    'face_match_score' => $faceMatchScore,
                    'overall_score' => $overallScore
                ]);

            } else {
                $verification->markAsFailed(
                    $responseData['error_code'] ?? 'LIVENESS_FAILED',
                    $responseData['message'] ?? 'Liveness check failed'
                );
            }

        } catch (RequestException $e) {
            Log::error('NADRA Liveness Check API Error', [
                'kyc_application_id' => $kycApplication->id,
                'error' => $e->getMessage()
            ]);

            $verification->markAsFailed('API_ERROR', 'Failed to connect to NADRA Liveness API');
        } catch (\Exception $e) {
            Log::error('NADRA Liveness Service Error', [
                'kyc_application_id' => $kycApplication->id,
                'error' => $e->getMessage()
            ]);

            $verification->markAsFailed('SERVICE_ERROR', 'Internal service error');
        }

        return $verification;
    }

    /**
     * Get verification status
     */
    public function getVerificationStatus(string $transactionId): ?array
    {
        try {
            $response = $this->client->get("/verification-status/{$transactionId}");
            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('NADRA Status Check Error', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Calculate match score based on NADRA response
     */
    private function calculateMatchScore(array $responseData): float
    {
        $score = 0;
        $maxScore = 100;

        // Name match (30 points)
        if (isset($responseData['name_match'])) {
            $score += $responseData['name_match'] ? 30 : 0;
        }

        // Father name match (25 points)
        if (isset($responseData['father_name_match'])) {
            $score += $responseData['father_name_match'] ? 25 : 0;
        }

        // Date of birth match (25 points)
        if (isset($responseData['dob_match'])) {
            $score += $responseData['dob_match'] ? 25 : 0;
        }

        // CNIC validity (20 points)
        if (isset($responseData['cnic_valid'])) {
            $score += $responseData['cnic_valid'] ? 20 : 0;
        }

        return min($score, $maxScore);
    }

    /**
     * Retry failed verification
     */
    public function retryVerification(KycVerification $verification): KycVerification
    {
        if (!$verification->canRetry()) {
            throw new \Exception('Verification cannot be retried');
        }

        $verification->incrementRetryCount();
        $verification->update(['status' => 'pending']);

        $kycApplication = $verification->kycApplication;

        switch ($verification->verification_type) {
            case 'nadra_verisys':
                return $this->verifyCNIC($kycApplication);
            case 'biometric':
                // Would need biometric data from original request
                throw new \Exception('Biometric retry requires original biometric data');
            case 'liveness_check':
                // Would need selfie image from original request
                throw new \Exception('Liveness check retry requires original selfie image');
            default:
                throw new \Exception('Unknown verification type');
        }
    }

    /**
     * Check if NADRA service is available
     */
    public function isServiceAvailable(): bool
    {
        try {
            $response = $this->client->get('/health-check');
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['status'] === 'healthy';
        } catch (\Exception $e) {
            return false;
        }
    }
}