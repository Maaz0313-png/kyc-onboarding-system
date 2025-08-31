<?php

namespace App\Services;

use App\Models\KycStatus;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class OCRService
{
    private string $ocrProvider;
    private array $config;

    public function __construct()
    {
        $this->ocrProvider = config('services.ocr.provider', 'tesseract');
        $this->config = config('services.ocr', []);
    }

    /**
     * Extract text from image using OCR
     */
    public function extractText(string $imagePath): array
    {
        try {
            switch ($this->ocrProvider) {
                case 'tesseract':
                    return $this->extractWithTesseract($imagePath);
                case 'google_vision':
                    return $this->extractWithGoogleVision($imagePath);
                case 'azure_cognitive':
                    return $this->extractWithAzureCognitive($imagePath);
                default:
                    return $this->extractWithTesseract($imagePath);
            }
        } catch (\Exception $e) {
            Log::error('OCR Text Extraction Error', [
                'image_path' => $imagePath,
                'provider' => $this->ocrProvider,
                'error' => $e->getMessage()
            ]);

            return [];
        }
    }

    /**
     * Extract text using Tesseract OCR
     */
    private function extractWithTesseract(string $imagePath): array
    {
        // For Pakistani CNIC, we need to handle Urdu text as well
        $languages = 'eng+urd'; // English + Urdu

        $command = sprintf(
            'tesseract "%s" stdout -l %s --psm 6 -c tessedit_char_whitelist=0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz/-:.,\(\)\ ',
            escapeshellarg($imagePath),
            $languages
        );

        $output = shell_exec($command);

        if (!$output) {
            return [];
        }

        // Clean and process the output
        $lines = array_filter(array_map('trim', explode("\n", $output)));

        return [
            'raw_text' => $output,
            'lines' => $lines,
            'processed_text' => $this->processExtractedText($lines)
        ];
    }

    /**
     * Extract text using Google Vision API
     */
    private function extractWithGoogleVision(string $imagePath): array
    {
        $apiKey = $this->config['google_vision']['api_key'] ?? null;

        if (!$apiKey) {
            throw new \Exception('Google Vision API key not configured');
        }

        $imageData = base64_encode(file_get_contents($imagePath));

        $response = Http::post("https://vision.googleapis.com/v1/images:annotate?key={$apiKey}", [
            'requests' => [
                [
                    'image' => [
                        'content' => $imageData
                    ],
                    'features' => [
                        [
                            'type' => 'TEXT_DETECTION',
                            'maxResults' => 50
                        ]
                    ]
                ]
            ]
        ]);

        if (!$response->successful()) {
            throw new \Exception('Google Vision API request failed');
        }

        $result = $response->json();
        $textAnnotations = $result['responses'][0]['textAnnotations'] ?? [];

        if (empty($textAnnotations)) {
            return [];
        }

        $fullText = $textAnnotations[0]['description'] ?? '';
        $lines = array_filter(array_map('trim', explode("\n", $fullText)));

        return [
            'raw_text' => $fullText,
            'lines' => $lines,
            'processed_text' => $this->processExtractedText($lines),
            'confidence' => $this->calculateGoogleVisionConfidence($textAnnotations)
        ];
    }

    /**
     * Extract text using Azure Cognitive Services
     */
    private function extractWithAzureCognitive(string $imagePath): array
    {
        $endpoint = $this->config['azure_cognitive']['endpoint'] ?? null;
        $apiKey = $this->config['azure_cognitive']['api_key'] ?? null;

        if (!$endpoint || !$apiKey) {
            throw new \Exception('Azure Cognitive Services not configured');
        }

        $imageData = file_get_contents($imagePath);

        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $apiKey,
            'Content-Type' => 'application/octet-stream'
        ])->post("{$endpoint}/vision/v3.2/ocr", [$imageData]);

        if (!$response->successful()) {
            throw new \Exception('Azure Cognitive Services request failed');
        }

        $result = $response->json();
        $regions = $result['regions'] ?? [];

        $lines = [];
        $fullText = '';

        foreach ($regions as $region) {
            foreach ($region['lines'] as $line) {
                $lineText = '';
                foreach ($line['words'] as $word) {
                    $lineText .= $word['text'] . ' ';
                }
                $lineText = trim($lineText);
                if ($lineText) {
                    $lines[] = $lineText;
                    $fullText .= $lineText . "\n";
                }
            }
        }

        return [
            'raw_text' => trim($fullText),
            'lines' => $lines,
            'processed_text' => $this->processExtractedText($lines)
        ];
    }

    /**
     * Process extracted text for Pakistani CNIC
     */
    private function processExtractedText(array $lines): array
    {
        $processed = [
            'cnic_number' => null,
            'name' => null,
            'father_name' => null,
            'date_of_birth' => null,
            'date_of_issue' => null,
            'date_of_expiry' => null,
            'address' => null,
            'gender' => null
        ];

        foreach ($lines as $line) {
            $line = trim($line);

            // Extract CNIC number
            if (preg_match('/(\d{5}[-\s]?\d{7}[-\s]?\d)/', $line, $matches)) {
                $processed['cnic_number'] = $this->formatCNIC($matches[1]);
            }

            // Extract name (usually after "Name" keyword)
            if (preg_match('/(?:Name|نام)[:\s]+([A-Za-z\s]+)/i', $line, $matches)) {
                $processed['name'] = trim($matches[1]);
            }

            // Extract father's name
            if (preg_match('/(?:Father|والد)[:\s]+([A-Za-z\s]+)/i', $line, $matches)) {
                $processed['father_name'] = trim($matches[1]);
            }

            // Extract dates
            if (preg_match('/(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{4})/', $line, $matches)) {
                $date = $matches[1];

                if (stripos($line, 'birth') !== false || stripos($line, 'پیدائش') !== false) {
                    $processed['date_of_birth'] = $this->formatDate($date);
                } elseif (stripos($line, 'issue') !== false || stripos($line, 'جاری') !== false) {
                    $processed['date_of_issue'] = $this->formatDate($date);
                } elseif (stripos($line, 'expiry') !== false || stripos($line, 'ختم') !== false) {
                    $processed['date_of_expiry'] = $this->formatDate($date);
                }
            }

            // Extract address (usually longer text)
            if (
                strlen($line) > 30 && !$processed['address'] &&
                !preg_match('/(?:Name|Father|Date|CNIC)/i', $line)
            ) {
                $processed['address'] = $line;
            }

            // Extract gender
            if (preg_match('/(?:Male|Female|M|F|مرد|عورت)/i', $line, $matches)) {
                $gender = strtolower($matches[0]);
                if (in_array($gender, ['male', 'm', 'مرد'])) {
                    $processed['gender'] = 'male';
                } elseif (in_array($gender, ['female', 'f', 'عورت'])) {
                    $processed['gender'] = 'female';
                }
            }
        }

        return $processed;
    }

    /**
     * Format CNIC number to standard format
     */
    private function formatCNIC(string $cnic): string
    {
        $cnic = preg_replace('/[^0-9]/', '', $cnic);

        if (strlen($cnic) === 13) {
            return substr($cnic, 0, 5) . '-' . substr($cnic, 5, 7) . '-' . substr($cnic, 12, 1);
        }

        return $cnic;
    }

    /**
     * Format date to standard format
     */
    private function formatDate(string $date): string
    {
        try {
            $date = preg_replace('/[\/\-\.]/', '-', $date);
            $parts = explode('-', $date);

            if (count($parts) === 3) {
                // Assume DD-MM-YYYY format for Pakistani documents
                return sprintf('%04d-%02d-%02d', $parts[2], $parts[1], $parts[0]);
            }
        } catch (\Exception $e) {
            Log::warning('Date formatting error', ['date' => $date, 'error' => $e->getMessage()]);
        }

        return $date;
    }

    /**
     * Calculate confidence score from OCR results
     */
    public function getConfidenceScore(array $ocrData): float
    {
        if (isset($ocrData['confidence'])) {
            return $ocrData['confidence'];
        }

        // Calculate confidence based on extracted data quality
        $score = 0;
        $maxScore = 100;
        $processed = $ocrData['processed_text'] ?? [];

        // CNIC number found and properly formatted
        if (!empty($processed['cnic_number']) && preg_match('/\d{5}-\d{7}-\d/', $processed['cnic_number'])) {
            $score += 30;
        }

        // Name extracted
        if (!empty($processed['name']) && strlen($processed['name']) > 2) {
            $score += 25;
        }

        // Father's name extracted
        if (!empty($processed['father_name']) && strlen($processed['father_name']) > 2) {
            $score += 20;
        }

        // Date of birth extracted
        if (!empty($processed['date_of_birth'])) {
            $score += 15;
        }

        // Address extracted
        if (!empty($processed['address']) && strlen($processed['address']) > 10) {
            $score += 10;
        }

        return min($score, $maxScore);
    }

    /**
     * Calculate confidence from Google Vision API results
     */
    private function calculateGoogleVisionConfidence(array $textAnnotations): float
    {
        if (count($textAnnotations) <= 1) {
            return 0;
        }

        $totalConfidence = 0;
        $count = 0;

        // Skip the first annotation (full text) and calculate average confidence
        for ($i = 1; $i < count($textAnnotations); $i++) {
            if (isset($textAnnotations[$i]['confidence'])) {
                $totalConfidence += $textAnnotations[$i]['confidence'];
                $count++;
            }
        }

        return $count > 0 ? ($totalConfidence / $count) * 100 : 0;
    }

    /**
     * Validate extracted CNIC data
     */
    public function validateCNICData(array $extractedData): array
    {
        $errors = [];

        // Validate CNIC number
        if (empty($extractedData['cnic_number'])) {
            $errors[] = 'CNIC number not found';
        } elseif (!preg_match('/^\d{5}-\d{7}-\d$/', $extractedData['cnic_number'])) {
            $errors[] = 'Invalid CNIC number format';
        }

        // Validate name
        if (empty($extractedData['name']) || strlen($extractedData['name']) < 2) {
            $errors[] = 'Name not found or too short';
        }

        // Validate father's name
        if (empty($extractedData['father_name']) || strlen($extractedData['father_name']) < 2) {
            $errors[] = 'Father\'s name not found or too short';
        }

        // Validate date of birth
        if (!empty($extractedData['date_of_birth'])) {
            try {
                $dob = new \DateTime($extractedData['date_of_birth']);
                $now = new \DateTime();
                $age = $now->diff($dob)->y;

                if ($age < KycStatus::MIN_AGE || $age > KycStatus::MAX_AGE) {
                    $errors[] = 'Invalid date of birth (age must be between 18-100)';
                }
            } catch (\Exception $e) {
                $errors[] = 'Invalid date of birth format';
            }
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'data' => $extractedData
        ];
    }
}