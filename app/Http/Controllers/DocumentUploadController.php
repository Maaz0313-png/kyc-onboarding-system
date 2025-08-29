<?php

namespace App\Http\Controllers;

use App\Models\KycApplication;
use App\Models\KycDocument;
use App\Models\AuditTrail;
use App\Services\OCRService;
use App\Services\VirusScanService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class DocumentUploadController extends Controller
{
    private OCRService $ocrService;
    private VirusScanService $virusScanService;
    private ImageManager $imageManager;

    public function __construct(OCRService $ocrService, VirusScanService $virusScanService)
    {
        $this->ocrService = $ocrService;
        $this->virusScanService = $virusScanService;
        $this->imageManager = new ImageManager(new Driver());
    }

    /**
     * Upload document for KYC application
     */
    public function upload(Request $request, KycApplication $kycApplication): JsonResponse
    {
        $user = $request->user();

        // Check ownership
        if ($kycApplication->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Check if application is in correct status
        if (!in_array($kycApplication->status, ['pending', 'in_progress'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot upload documents for KYC application in current status'
            ], 400);
        }

        $request->validate([
            'document_type' => 'required|in:cnic_front,cnic_back,selfie,utility_bill,bank_statement,salary_certificate',
            'file' => 'required|file|mimes:jpeg,jpg,png,pdf|max:5120' // 5MB max
        ]);

        try {
            $file = $request->file('file');
            $documentType = $request->document_type;

            // Check if document already exists
            $existingDocument = $kycApplication->documents()
                ->where('document_type', $documentType)
                ->first();

            if ($existingDocument) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document of this type already uploaded. Please delete the existing one first.'
                ], 400);
            }

            // Virus scan
            if (!$this->virusScanService->scanFile($file->getPathname())) {
                return response()->json([
                    'success' => false,
                    'message' => 'File failed virus scan'
                ], 400);
            }

            // Process and store file
            $processedFile = $this->processFile($file, $documentType);
            $filePath = $this->storeFile($processedFile, $kycApplication->id, $documentType);

            // Create document record
            $document = KycDocument::create([
                'kyc_application_id' => $kycApplication->id,
                'document_type' => $documentType,
                'file_path' => $filePath,
                'file_name' => $file->getClientOriginalName(),
                'file_hash' => hash_file('sha256', $processedFile['path']),
                'file_size' => $processedFile['size'],
                'mime_type' => $file->getMimeType(),
                'virus_scanned' => true,
                'is_encrypted' => true,
                'expires_at' => now()->addYears(7), // 7-year retention policy
                'status' => 'uploaded'
            ]);

            // Perform OCR if it's an image document
            if (in_array($documentType, ['cnic_front', 'cnic_back']) && $this->isImageFile($file)) {
                $this->performOCR($document, $processedFile['path']);
            }

            // Log document upload
            AuditTrail::logDocumentAction('uploaded', $document, $user);

            return response()->json([
                'success' => true,
                'message' => 'Document uploaded successfully',
                'data' => [
                    'document_id' => $document->id,
                    'document_type' => $document->document_type,
                    'file_name' => $document->file_name,
                    'file_size' => $document->getFileSize(),
                    'status' => $document->status,
                    'uploaded_at' => $document->created_at
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Document Upload Error', [
                'kyc_application_id' => $kycApplication->id,
                'document_type' => $request->document_type,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload document'
            ], 500);
        }
    }

    /**
     * Get documents for KYC application
     */
    public function index(Request $request, KycApplication $kycApplication): JsonResponse
    {
        $user = $request->user();

        // Check ownership or review permissions
        if ($kycApplication->user_id !== $user->id && !$user->canReviewKyc()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $documents = $kycApplication->documents()
            ->select(['id', 'document_type', 'file_name', 'file_size', 'status', 'is_verified', 'confidence_score', 'created_at'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $documents
        ]);
    }

    /**
     * Get specific document
     */
    public function show(Request $request, KycApplication $kycApplication, KycDocument $document): JsonResponse
    {
        $user = $request->user();

        // Check ownership or review permissions
        if ($kycApplication->user_id !== $user->id && !$user->canReviewKyc()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Check if document belongs to the KYC application
        if ($document->kyc_application_id !== $kycApplication->id) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found'
            ], 404);
        }

        $documentData = [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'file_name' => $document->file_name,
            'file_size' => $document->getFileSize(),
            'status' => $document->status,
            'is_verified' => $document->is_verified,
            'confidence_score' => $document->confidence_score,
            'ocr_data' => $document->ocr_data,
            'verification_data' => $document->verification_data,
            'created_at' => $document->created_at,
            'expires_at' => $document->expires_at
        ];

        // Add extracted CNIC data if available
        if (in_array($document->document_type, ['cnic_front', 'cnic_back'])) {
            $documentData['extracted_data'] = $document->extractCNICData();
        }

        return response()->json([
            'success' => true,
            'data' => $documentData
        ]);
    }

    /**
     * Download document
     */
    public function download(Request $request, KycApplication $kycApplication, KycDocument $document)
    {
        $user = $request->user();

        // Check ownership or review permissions
        if ($kycApplication->user_id !== $user->id && !$user->canReviewKyc()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Check if document belongs to the KYC application
        if ($document->kyc_application_id !== $kycApplication->id) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found'
            ], 404);
        }

        try {
            // Verify file integrity
            if (!$document->verifyIntegrity()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document integrity check failed'
                ], 400);
            }

            // Log document access
            AuditTrail::logDocumentAction('downloaded', $document, $user);

            // Get decrypted content
            $content = $document->getDecryptedContent();
            if (!$content) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to decrypt document'
                ], 500);
            }

            return response($content)
                ->header('Content-Type', $document->mime_type)
                ->header('Content-Disposition', 'attachment; filename="' . $document->file_name . '"');

        } catch (\Exception $e) {
            Log::error('Document Download Error', [
                'document_id' => $document->id,
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to download document'
            ], 500);
        }
    }

    /**
     * Delete document
     */
    public function destroy(Request $request, KycApplication $kycApplication, KycDocument $document): JsonResponse
    {
        $user = $request->user();

        // Check ownership
        if ($kycApplication->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Check if application is in correct status
        if (!in_array($kycApplication->status, ['pending'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete documents for KYC application in current status'
            ], 400);
        }

        // Check if document belongs to the KYC application
        if ($document->kyc_application_id !== $kycApplication->id) {
            return response()->json([
                'success' => false,
                'message' => 'Document not found'
            ], 404);
        }

        try {
            // Delete file from storage
            if (Storage::exists($document->file_path)) {
                Storage::delete($document->file_path);
            }

            // Log document deletion
            AuditTrail::logDocumentAction('deleted', $document, $user);

            // Delete document record
            $document->delete();

            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Document Deletion Error', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete document'
            ], 500);
        }
    }

    /**
     * Verify document (Admin/KYC Officer only)
     */
    public function verify(Request $request, KycApplication $kycApplication, KycDocument $document): JsonResponse
    {
        $user = $request->user();

        if (!$user->canReviewKyc()) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions'
            ], 403);
        }

        $request->validate([
            'is_verified' => 'required|boolean',
            'comments' => 'nullable|string|max:1000'
        ]);

        try {
            if ($request->is_verified) {
                $document->markAsVerified([
                    'verified_by' => $user->id,
                    'verified_at' => now(),
                    'comments' => $request->comments
                ]);

                AuditTrail::logDocumentAction('verified', $document, $user, [
                    'comments' => $request->comments
                ]);

                $message = 'Document verified successfully';
            } else {
                $document->markAsRejected($request->comments ?? 'Document verification failed');

                AuditTrail::logDocumentAction('rejected', $document, $user, [
                    'rejection_reason' => $request->comments
                ]);

                $message = 'Document rejected';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $document
            ]);

        } catch (\Exception $e) {
            Log::error('Document Verification Error', [
                'document_id' => $document->id,
                'verifier_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to verify document'
            ], 500);
        }
    }

    /**
     * Process uploaded file
     */
    private function processFile($file, string $documentType): array
    {
        $tempPath = $file->getPathname();

        // For image files, optimize and resize
        if ($this->isImageFile($file)) {
            $image = $this->imageManager->read($tempPath);

            // Resize if too large (max 2048px width/height)
            if ($image->width() > 2048 || $image->height() > 2048) {
                $image->scale(width: 2048, height: 2048);
            }

            // Optimize quality
            $processedPath = $tempPath . '_processed';
            $image->save($processedPath, quality: 85);

            return [
                'path' => $processedPath,
                'size' => filesize($processedPath)
            ];
        }

        return [
            'path' => $tempPath,
            'size' => $file->getSize()
        ];
    }

    /**
     * Store file with encryption
     */
    private function storeFile(array $processedFile, int $kycApplicationId, string $documentType): string
    {
        $content = file_get_contents($processedFile['path']);
        $encryptedContent = Crypt::encrypt($content);

        $fileName = sprintf(
            'kyc/%d/%s_%s.enc',
            $kycApplicationId,
            $documentType,
            now()->format('Y-m-d_H-i-s')
        );

        Storage::put($fileName, $encryptedContent);

        return $fileName;
    }

    /**
     * Perform OCR on document
     */
    private function performOCR(KycDocument $document, string $filePath): void
    {
        try {
            $ocrData = $this->ocrService->extractText($filePath);
            $confidenceScore = $this->ocrService->getConfidenceScore($ocrData);

            $document->update([
                'ocr_data' => $ocrData,
                'confidence_score' => $confidenceScore,
                'status' => 'processing'
            ]);

        } catch (\Exception $e) {
            Log::error('OCR Processing Error', [
                'document_id' => $document->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if file is an image
     */
    private function isImageFile($file): bool
    {
        return in_array($file->getMimeType(), [
            'image/jpeg',
            'image/jpg',
            'image/png'
        ]);
    }
}