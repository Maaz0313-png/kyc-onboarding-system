<?php

namespace App\Http\Controllers;

use App\Models\KycApplication;
use App\Models\AuditTrail;
use App\Services\NADRAVerisysService;
use App\Services\SanctionsScreeningService;
use App\Http\Requests\KycApplicationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KycApplicationController extends Controller
{
    private NADRAVerisysService $nadraService;
    private SanctionsScreeningService $sanctionsService;

    public function __construct(
        NADRAVerisysService $nadraService,
        SanctionsScreeningService $sanctionsService
    ) {
        $this->nadraService = $nadraService;
        $this->sanctionsService = $sanctionsService;
    }

    /**
     * Get current user's KYC applications
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $applications = $user->kycApplications()
            ->with(['documents', 'verifications', 'sanctionsScreenings'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $applications,
            'current_status' => $user->getKycStatus(),
            'progress_percentage' => $user->getKycProgressPercentage()
        ]);
    }

    /**
     * Get specific KYC application
     */
    public function show(Request $request, KycApplication $kycApplication): JsonResponse
    {
        $user = $request->user();

        // Check if user owns this application or has review permissions
        if ($kycApplication->user_id !== $user->id && !$user->canReviewKyc()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to KYC application'
            ], 403);
        }

        $kycApplication->load([
            'documents',
            'verifications',
            'sanctionsScreenings',
            'user'
        ]);

        return response()->json([
            'success' => true,
            'data' => $kycApplication,
            'progress_percentage' => $kycApplication->getProgressPercentage(),
            'can_auto_approve' => $kycApplication->canAutoApprove(),
            'requires_manual_review' => $kycApplication->requiresManualReview()
        ]);
    }

    /**
     * Create new KYC application
     */
    public function store(KycApplicationRequest $request): JsonResponse
    {
        $user = $request->user();

        // Check if user can start KYC
        if (!$user->canStartKyc()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot start KYC process. Please verify email and phone first.',
                'requirements' => [
                    'email_verified' => $user->isEmailVerified(),
                    'phone_verified' => $user->isPhoneVerified(),
                    'account_active' => $user->isActive(),
                    'no_pending_kyc' => !$user->hasPendingKyc(),
                    'no_approved_kyc' => !$user->hasApprovedKyc()
                ]
            ], 400);
        }

        try {
            DB::beginTransaction();

            $kycApplication = KycApplication::create([
                'user_id' => $user->id,
                'cnic' => $request->cnic,
                'full_name' => $request->full_name,
                'father_name' => $request->father_name,
                'date_of_birth' => $request->date_of_birth,
                'gender' => $request->gender,
                'phone_number' => $request->phone_number,
                'email' => $request->email,
                'address' => $request->address,
                'city' => $request->city,
                'province' => $request->province,
                'postal_code' => $request->postal_code,
                'consent_given' => $request->consent_given,
                'status' => 'pending',
                'submitted_at' => now()
            ]);

            // Log KYC application creation
            AuditTrail::logKycAction('created', $kycApplication, $user);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'KYC application created successfully',
                'data' => $kycApplication,
                'next_steps' => [
                    'upload_cnic_front',
                    'upload_cnic_back',
                    'upload_selfie'
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('KYC Application Creation Error', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create KYC application'
            ], 500);
        }
    }

    /**
     * Update KYC application
     */
    public function update(KycApplicationRequest $request, KycApplication $kycApplication): JsonResponse
    {
        $user = $request->user();

        if ($kycApplication->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        if ($kycApplication->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update KYC application in current status'
            ], 400);
        }

        try {
            $oldValues = $kycApplication->toArray();

            $kycApplication->update($request->validated());

            // Log the update
            AuditTrail::log('updated', $kycApplication, $user, $oldValues, $kycApplication->toArray());

            return response()->json([
                'success' => true,
                'message' => 'KYC application updated successfully',
                'data' => $kycApplication
            ]);

        } catch (\Exception $e) {
            Log::error('KYC Application Update Error', [
                'kyc_application_id' => $kycApplication->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update KYC application'
            ], 500);
        }
    }

    /**
     * Submit KYC application for processing
     */
    public function submit(Request $request, KycApplication $kycApplication): JsonResponse
    {
        $user = $request->user();

        if ($kycApplication->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        if ($kycApplication->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'KYC application already submitted'
            ], 400);
        }

        // Check if all required documents are uploaded
        $requiredDocuments = ['cnic_front', 'cnic_back', 'selfie'];
        $uploadedDocuments = $kycApplication->documents()->pluck('document_type')->toArray();
        $missingDocuments = array_diff($requiredDocuments, $uploadedDocuments);

        if (!empty($missingDocuments)) {
            return response()->json([
                'success' => false,
                'message' => 'Missing required documents',
                'missing_documents' => $missingDocuments
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Update application status
            $kycApplication->update([
                'status' => 'in_progress',
                'submitted_at' => now()
            ]);

            // Start verification process
            $this->startVerificationProcess($kycApplication);

            // Log submission
            AuditTrail::logKycAction('submitted', $kycApplication, $user);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'KYC application submitted successfully',
                'data' => $kycApplication,
                'estimated_processing_time' => '24-48 hours'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('KYC Application Submission Error', [
                'kyc_application_id' => $kycApplication->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to submit KYC application'
            ], 500);
        }
    }

    /**
     * Get KYC application status
     */
    public function status(Request $request, KycApplication $kycApplication): JsonResponse
    {
        $user = $request->user();

        if ($kycApplication->user_id !== $user->id && !$user->canReviewKyc()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $verificationSummary = [
            'nadra_verified' => $kycApplication->nadra_verified,
            'biometric_verified' => $kycApplication->biometric_verified,
            'sanctions_cleared' => $kycApplication->sanctions_cleared,
            'pep_cleared' => $kycApplication->pep_cleared,
            'documents_verified' => $kycApplication->documents()->where('is_verified', true)->count()
        ];

        $screeningSummary = $this->sanctionsService->getScreeningSummary($kycApplication);

        return response()->json([
            'success' => true,
            'data' => [
                'application_id' => $kycApplication->application_id,
                'status' => $kycApplication->status,
                'risk_score' => $kycApplication->risk_score,
                'risk_category' => $kycApplication->risk_category,
                'progress_percentage' => $kycApplication->getProgressPercentage(),
                'verification_summary' => $verificationSummary,
                'screening_summary' => $screeningSummary,
                'can_auto_approve' => $kycApplication->canAutoApprove(),
                'requires_manual_review' => $kycApplication->requiresManualReview(),
                'submitted_at' => $kycApplication->submitted_at,
                'processed_at' => $kycApplication->processed_at
            ]
        ]);
    }

    /**
     * Approve KYC application (Admin/Compliance Officer only)
     */
    public function approve(Request $request, KycApplication $kycApplication): JsonResponse
    {
        $user = $request->user();

        if (!$user->canApproveKyc()) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions to approve KYC'
            ], 403);
        }

        if (!in_array($kycApplication->status, ['in_progress', 'under_review'])) {
            return response()->json([
                'success' => false,
                'message' => 'KYC application cannot be approved in current status'
            ], 400);
        }

        $request->validate([
            'account_tier' => 'required|in:basic,standard,premium',
            'comments' => 'nullable|string|max:1000'
        ]);

        try {
            DB::beginTransaction();

            $kycApplication->update([
                'status' => 'approved',
                'account_tier' => $request->account_tier,
                'processed_at' => now(),
                'processed_by' => $user->name,
                'compliance_data' => [
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                    'comments' => $request->comments,
                    'final_risk_score' => $kycApplication->risk_score
                ]
            ]);

            // Log approval
            AuditTrail::logKycAction('approved', $kycApplication, $user, [
                'account_tier' => $request->account_tier,
                'comments' => $request->comments
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'KYC application approved successfully',
                'data' => $kycApplication
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('KYC Approval Error', [
                'kyc_application_id' => $kycApplication->id,
                'approver_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve KYC application'
            ], 500);
        }
    }

    /**
     * Reject KYC application (Admin/Compliance Officer only)
     */
    public function reject(Request $request, KycApplication $kycApplication): JsonResponse
    {
        $user = $request->user();

        if (!$user->canApproveKyc()) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions to reject KYC'
            ], 403);
        }

        if (!in_array($kycApplication->status, ['in_progress', 'under_review'])) {
            return response()->json([
                'success' => false,
                'message' => 'KYC application cannot be rejected in current status'
            ], 400);
        }

        $request->validate([
            'rejection_reason' => 'required|string|max:1000'
        ]);

        try {
            DB::beginTransaction();

            $kycApplication->update([
                'status' => 'rejected',
                'processed_at' => now(),
                'processed_by' => $user->name,
                'rejection_reason' => $request->rejection_reason
            ]);

            // Log rejection
            AuditTrail::logKycAction('rejected', $kycApplication, $user, [
                'rejection_reason' => $request->rejection_reason
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'KYC application rejected',
                'data' => $kycApplication
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('KYC Rejection Error', [
                'kyc_application_id' => $kycApplication->id,
                'rejector_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject KYC application'
            ], 500);
        }
    }

    /**
     * Start verification process
     */
    private function startVerificationProcess(KycApplication $kycApplication): void
    {
        // Dispatch verification jobs
        dispatch(function () use ($kycApplication) {
            try {
                // NADRA verification
                $this->nadraService->verifyCNIC($kycApplication);

                // Sanctions screening
                $this->sanctionsService->performScreening($kycApplication);

                // Update risk score
                $kycApplication->updateRiskScore();

                // Check if can auto-approve
                if ($kycApplication->canAutoApprove()) {
                    $kycApplication->update([
                        'status' => 'approved',
                        'account_tier' => 'basic',
                        'processed_at' => now(),
                        'processed_by' => 'system'
                    ]);

                    AuditTrail::logKycAction('auto_approved', $kycApplication);
                } elseif ($kycApplication->requiresManualReview()) {
                    $kycApplication->update(['status' => 'under_review']);
                    AuditTrail::logKycAction('under_review', $kycApplication);
                }
            } catch (\Exception $e) {
                Log::error('KYC Verification Process Failed', [
                    'kyc_application_id' => $kycApplication->id,
                    'error' => $e->getMessage()
                ]);
                
                $kycApplication->update(['status' => 'under_review']);
                AuditTrail::logKycAction('verification_failed', $kycApplication, null, [
                    'error' => $e->getMessage()
                ]);
            }
        });
    }

    /**
     * Get KYC applications for review (Admin/Compliance Officer only)
     */
    public function forReview(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->canReviewKyc()) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions'
            ], 403);
        }

        $query = KycApplication::with(['user', 'documents', 'verifications', 'sanctionsScreenings'])
            ->whereIn('status', ['in_progress', 'under_review']);

        // Filter by risk category
        if ($request->has('risk_category')) {
            $query->where('risk_category', $request->risk_category);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Sort by priority (high risk first, then by submission date)
        $applications = $query->orderByRaw(
            "CASE WHEN risk_category = ? THEN 1 WHEN risk_category = ? THEN 2 WHEN risk_category = ? THEN 3 END",
            ['high', 'medium', 'low']
        )->orderBy('submitted_at', 'asc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $applications
        ]);
    }
}