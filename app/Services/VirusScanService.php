<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class VirusScanService
{
    private string $scanProvider;
    private array $config;

    public function __construct()
    {
        $this->scanProvider = config('services.virus_scan.provider', 'clamav');
        $this->config = config('services.virus_scan', []);
    }

    /**
     * Scan file for viruses
     */
    public function scanFile(string $filePath): bool
    {
        try {
            switch ($this->scanProvider) {
                case 'clamav':
                    return $this->scanWithClamAV($filePath);
                case 'virustotal':
                    return $this->scanWithVirusTotal($filePath);
                case 'windows_defender':
                    return $this->scanWithWindowsDefender($filePath);
                default:
                    return $this->basicFileScan($filePath);
            }
        } catch (\Exception $e) {
            Log::error('Virus Scan Error', [
                'file_path' => $filePath,
                'provider' => $this->scanProvider,
                'error' => $e->getMessage()
            ]);

            // In case of scan failure, perform basic checks
            return $this->basicFileScan($filePath);
        }
    }

    /**
     * Scan with ClamAV
     */
    private function scanWithClamAV(string $filePath): bool
    {
        $clamavPath = $this->config['clamav']['path'] ?? 'clamscan';

        $command = sprintf(
            '%s --no-summary --infected "%s" 2>&1',
            escapeshellcmd($clamavPath),
            escapeshellarg($filePath)
        );

        $output = shell_exec($command);
        $exitCode = 0;

        // ClamAV returns 0 for clean files, 1 for infected files
        if ($output && (strpos($output, 'FOUND') !== false || strpos($output, 'Infected') !== false)) {
            Log::warning('Virus detected by ClamAV', [
                'file_path' => $filePath,
                'output' => $output
            ]);
            return false;
        }

        return true;
    }

    /**
     * Scan with VirusTotal API
     */
    private function scanWithVirusTotal(string $filePath): bool
    {
        $apiKey = $this->config['virustotal']['api_key'] ?? null;

        if (!$apiKey) {
            Log::warning('VirusTotal API key not configured, falling back to basic scan');
            return $this->basicFileScan($filePath);
        }

        // Check file size (VirusTotal has limits)
        $fileSize = filesize($filePath);
        if ($fileSize > 32 * 1024 * 1024) { // 32MB limit
            Log::info('File too large for VirusTotal, using basic scan', [
                'file_path' => $filePath,
                'file_size' => $fileSize
            ]);
            return $this->basicFileScan($filePath);
        }

        // Upload file for scanning
        $response = Http::withHeaders([
            'x-apikey' => $apiKey
        ])->attach('file', file_get_contents($filePath), basename($filePath))
            ->post('https://www.virustotal.com/api/v3/files');

        if (!$response->successful()) {
            Log::error('VirusTotal upload failed', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            return $this->basicFileScan($filePath);
        }

        $uploadResult = $response->json();
        $analysisId = $uploadResult['data']['id'] ?? null;

        if (!$analysisId) {
            return $this->basicFileScan($filePath);
        }

        // Wait for analysis to complete (with timeout)
        $maxWaitTime = 60; // 60 seconds
        $waitTime = 0;

        while ($waitTime < $maxWaitTime) {
            sleep(5);
            $waitTime += 5;

            $analysisResponse = Http::withHeaders([
                'x-apikey' => $apiKey
            ])->get("https://www.virustotal.com/api/v3/analyses/{$analysisId}");

            if ($analysisResponse->successful()) {
                $analysis = $analysisResponse->json();
                $status = $analysis['data']['attributes']['status'] ?? '';

                if ($status === 'completed') {
                    $stats = $analysis['data']['attributes']['stats'] ?? [];
                    $malicious = $stats['malicious'] ?? 0;
                    $suspicious = $stats['suspicious'] ?? 0;

                    if ($malicious > 0 || $suspicious > 2) {
                        Log::warning('Virus detected by VirusTotal', [
                            'file_path' => $filePath,
                            'malicious' => $malicious,
                            'suspicious' => $suspicious
                        ]);
                        return false;
                    }

                    return true;
                }
            }
        }

        // Timeout reached, assume clean
        Log::info('VirusTotal scan timeout, assuming clean', [
            'file_path' => $filePath
        ]);

        return true;
    }

    /**
     * Scan with Windows Defender (Windows only)
     */
    private function scanWithWindowsDefender(string $filePath): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return $this->basicFileScan($filePath);
        }

        $command = sprintf(
            'powershell.exe -Command "& {Start-MpScan -ScanType CustomScan -ScanPath \'%s\'}"',
            escapeshellarg($filePath)
        );

        $output = shell_exec($command);

        // Windows Defender will return error if threats are found
        if ($output && (strpos($output, 'threat') !== false || strpos($output, 'malware') !== false)) {
            Log::warning('Virus detected by Windows Defender', [
                'file_path' => $filePath,
                'output' => $output
            ]);
            return false;
        }

        return true;
    }

    /**
     * Basic file scan without external antivirus
     */
    private function basicFileScan(string $filePath): bool
    {
        // Check file size (reject extremely large files)
        $fileSize = filesize($filePath);
        if ($fileSize > 50 * 1024 * 1024) { // 50MB limit
            Log::warning('File rejected due to size', [
                'file_path' => $filePath,
                'file_size' => $fileSize
            ]);
            return false;
        }

        // Check for suspicious file extensions
        $suspiciousExtensions = [
            'exe',
            'bat',
            'cmd',
            'com',
            'pif',
            'scr',
            'vbs',
            'js',
            'jar',
            'app',
            'deb',
            'pkg',
            'rpm',
            'dmg',
            'iso',
            'msi',
            'dll'
        ];

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (in_array($extension, $suspiciousExtensions)) {
            Log::warning('File rejected due to suspicious extension', [
                'file_path' => $filePath,
                'extension' => $extension
            ]);
            return false;
        }

        // Check file content for suspicious patterns
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }

        $chunk = fread($handle, 1024); // Read first 1KB
        fclose($handle);

        // Check for executable signatures
        $executableSignatures = [
            'MZ',      // Windows PE
            '\x7fELF', // Linux ELF
            '\xca\xfe\xba\xbe', // Java class
            'PK',      // ZIP/JAR (could contain executables)
        ];

        foreach ($executableSignatures as $signature) {
            if (strpos($chunk, $signature) === 0) {
                Log::warning('File rejected due to executable signature', [
                    'file_path' => $filePath,
                    'signature' => bin2hex($signature)
                ]);
                return false;
            }
        }

        // Check for script patterns
        $scriptPatterns = [
            '<?php',
            '<script',
            'javascript:',
            'vbscript:',
            'eval(',
            'exec(',
            'system(',
            'shell_exec('
        ];

        $chunkLower = strtolower($chunk);
        foreach ($scriptPatterns as $pattern) {
            if (strpos($chunkLower, strtolower($pattern)) !== false) {
                Log::warning('File rejected due to script pattern', [
                    'file_path' => $filePath,
                    'pattern' => $pattern
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Scan file hash against known malware databases
     */
    public function scanFileHash(string $filePath): bool
    {
        $fileHash = hash_file('sha256', $filePath);

        // Check against local blacklist
        $blacklistedHashes = $this->getBlacklistedHashes();
        if (in_array($fileHash, $blacklistedHashes)) {
            Log::warning('File hash found in blacklist', [
                'file_path' => $filePath,
                'hash' => $fileHash
            ]);
            return false;
        }

        return true;
    }

    /**
     * Get blacklisted file hashes
     */
    private function getBlacklistedHashes(): array
    {
        // In production, this would load from a database or external service
        return [
            // Add known malware hashes here
        ];
    }

    /**
     * Quarantine infected file
     */
    public function quarantineFile(string $filePath): bool
    {
        try {
            $quarantineDir = storage_path('quarantine');

            if (!is_dir($quarantineDir)) {
                mkdir($quarantineDir, 0755, true);
            }

            $quarantineFile = $quarantineDir . '/' . basename($filePath) . '_' . time() . '.quarantine';

            if (rename($filePath, $quarantineFile)) {
                Log::info('File quarantined', [
                    'original_path' => $filePath,
                    'quarantine_path' => $quarantineFile
                ]);
                return true;
            }

        } catch (\Exception $e) {
            Log::error('Failed to quarantine file', [
                'file_path' => $filePath,
                'error' => $e->getMessage()
            ]);
        }

        return false;
    }

    /**
     * Get scan report
     */
    public function getScanReport(string $filePath): array
    {
        $fileSize = filesize($filePath);
        $fileHash = hash_file('sha256', $filePath);
        $mimeType = mime_content_type($filePath);

        return [
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'file_hash' => $fileHash,
            'mime_type' => $mimeType,
            'scan_provider' => $this->scanProvider,
            'scan_timestamp' => now()->toISOString(),
            'is_clean' => $this->scanFile($filePath)
        ];
    }

    /**
     * Check if virus scanning is available
     */
    public function isScanningAvailable(): bool
    {
        switch ($this->scanProvider) {
            case 'clamav':
                $clamavPath = $this->config['clamav']['path'] ?? 'clamscan';
                return !empty(shell_exec("which {$clamavPath}"));

            case 'virustotal':
                return !empty($this->config['virustotal']['api_key']);

            case 'windows_defender':
                return PHP_OS_FAMILY === 'Windows';

            default:
                return true; // Basic scanning is always available
        }
    }
}