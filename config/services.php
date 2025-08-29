<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | NADRA Integration Services
    |--------------------------------------------------------------------------
    |
    | Configuration for NADRA Verisys and Pak-ID integration services
    | for Pakistani identity verification and biometric matching.
    |
    */

    'nadra' => [
        'base_url' => env('NADRA_BASE_URL', 'https://verisys.nadra.gov.pk/api'),
        'api_key' => env('NADRA_API_KEY'),
        'client_id' => env('NADRA_CLIENT_ID'),
        'timeout' => env('NADRA_TIMEOUT', 30),
        'retry_attempts' => env('NADRA_RETRY_ATTEMPTS', 3),
        'sandbox_mode' => env('NADRA_SANDBOX_MODE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | OCR Services Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for various OCR providers including Tesseract,
    | Google Vision API, and Azure Cognitive Services.
    |
    */

    'ocr' => [
        'provider' => env('OCR_PROVIDER', 'tesseract'),
        'tesseract' => [
            'path' => env('TESSERACT_PATH', 'tesseract'),
            'languages' => env('TESSERACT_LANGUAGES', 'eng+urd'),
            'psm' => env('TESSERACT_PSM', 6),
            'timeout' => env('TESSERACT_TIMEOUT', 60),
        ],
        'google_vision' => [
            'api_key' => env('GOOGLE_VISION_API_KEY'),
            'project_id' => env('GOOGLE_VISION_PROJECT_ID'),
            'max_results' => env('GOOGLE_VISION_MAX_RESULTS', 50),
        ],
        'azure_cognitive' => [
            'endpoint' => env('AZURE_COGNITIVE_ENDPOINT'),
            'api_key' => env('AZURE_COGNITIVE_API_KEY'),
            'region' => env('AZURE_COGNITIVE_REGION', 'eastus'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Virus Scanning Services
    |--------------------------------------------------------------------------
    |
    | Configuration for virus scanning providers including ClamAV,
    | VirusTotal, and Windows Defender integration.
    |
    */

    'virus_scan' => [
        'provider' => env('VIRUS_SCAN_PROVIDER', 'basic'),
        'enabled' => env('VIRUS_SCAN_ENABLED', true),
        'quarantine_infected' => env('VIRUS_SCAN_QUARANTINE', true),
        'max_file_size' => env('VIRUS_SCAN_MAX_FILE_SIZE', 52428800), // 50MB

        'clamav' => [
            'path' => env('CLAMAV_PATH', 'clamscan'),
            'database_path' => env('CLAMAV_DATABASE_PATH', '/var/lib/clamav'),
            'timeout' => env('CLAMAV_TIMEOUT', 60),
            'update_database' => env('CLAMAV_UPDATE_DATABASE', true),
        ],

        'virustotal' => [
            'api_key' => env('VIRUSTOTAL_API_KEY'),
            'base_url' => env('VIRUSTOTAL_BASE_URL', 'https://www.virustotal.com/api/v3'),
            'max_file_size' => env('VIRUSTOTAL_MAX_FILE_SIZE', 33554432), // 32MB
            'timeout' => env('VIRUSTOTAL_TIMEOUT', 120),
            'wait_for_analysis' => env('VIRUSTOTAL_WAIT_FOR_ANALYSIS', true),
            'max_wait_time' => env('VIRUSTOTAL_MAX_WAIT_TIME', 300), // 5 minutes
        ],

        'windows_defender' => [
            'enabled' => env('WINDOWS_DEFENDER_ENABLED', false),
            'scan_timeout' => env('WINDOWS_DEFENDER_TIMEOUT', 120),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanctions Screening Services
    |--------------------------------------------------------------------------
    |
    | Configuration for sanctions screening against various lists
    | including UN, OFAC, TFS, PEP, and local proscribed entities.
    |
    */

    'sanctions' => [
        'enabled' => env('SANCTIONS_SCREENING_ENABLED', true),
        'auto_update_lists' => env('SANCTIONS_AUTO_UPDATE', true),
        'update_frequency' => env('SANCTIONS_UPDATE_FREQUENCY', 'daily'),
        'match_threshold' => env('SANCTIONS_MATCH_THRESHOLD', 70),
        'fuzzy_matching' => env('SANCTIONS_FUZZY_MATCHING', true),

        'providers' => [
            'internal' => [
                'enabled' => true,
                'lists_path' => env('SANCTIONS_LISTS_PATH', 'sanctions'),
            ],
            'worldcheck' => [
                'enabled' => env('WORLDCHECK_ENABLED', false),
                'api_key' => env('WORLDCHECK_API_KEY'),
                'base_url' => env('WORLDCHECK_BASE_URL'),
            ],
            'refinitiv' => [
                'enabled' => env('REFINITIV_ENABLED', false),
                'api_key' => env('REFINITIV_API_KEY'),
                'base_url' => env('REFINITIV_BASE_URL'),
            ],
        ],

        'lists' => [
            'un_sanctions' => [
                'enabled' => true,
                'url' => env('UN_SANCTIONS_URL', 'https://scsanctions.un.org/resources/xml/en/consolidated.xml'),
                'format' => 'xml',
                'update_frequency' => 'daily',
            ],
            'ofac' => [
                'enabled' => true,
                'url' => env('OFAC_SDN_URL', 'https://www.treasury.gov/ofac/downloads/sdn.xml'),
                'format' => 'xml',
                'update_frequency' => 'daily',
            ],
            'tfs_regime' => [
                'enabled' => true,
                'url' => env('TFS_REGIME_URL'),
                'format' => 'json',
                'update_frequency' => 'weekly',
            ],
            'pep_list' => [
                'enabled' => true,
                'url' => env('PEP_LIST_URL'),
                'format' => 'json',
                'update_frequency' => 'monthly',
            ],
            'local_proscribed' => [
                'enabled' => true,
                'url' => env('LOCAL_PROSCRIBED_URL'),
                'format' => 'json',
                'update_frequency' => 'weekly',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | FMU (Financial Monitoring Unit) Integration
    |--------------------------------------------------------------------------
    |
    | Configuration for goAML reporting and FMU integration
    | for Pakistani financial compliance reporting.
    |
    */

    'fmu' => [
        'enabled' => env('FMU_REPORTING_ENABLED', true),
        'goaml_endpoint' => env('FMU_GOAML_ENDPOINT'),
        'institution_code' => env('FMU_INSTITUTION_CODE'),
        'api_key' => env('FMU_API_KEY'),
        'certificate_path' => env('FMU_CERTIFICATE_PATH'),
        'private_key_path' => env('FMU_PRIVATE_KEY_PATH'),
        'auto_report' => env('FMU_AUTO_REPORT', true),
        'report_threshold' => env('FMU_REPORT_THRESHOLD', 75), // Match score threshold
        'sandbox_mode' => env('FMU_SANDBOX_MODE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk Assessment Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for risk scoring and assessment algorithms
    | used in the KYC process.
    |
    */

    'risk_assessment' => [
        'enabled' => env('RISK_ASSESSMENT_ENABLED', true),
        'auto_scoring' => env('RISK_AUTO_SCORING', true),
        'manual_review_threshold' => env('RISK_MANUAL_REVIEW_THRESHOLD', 70),
        'auto_approve_threshold' => env('RISK_AUTO_APPROVE_THRESHOLD', 30),
        'high_risk_threshold' => env('RISK_HIGH_RISK_THRESHOLD', 70),

        'factors' => [
            'nadra_verification' => env('RISK_FACTOR_NADRA', 25),
            'biometric_verification' => env('RISK_FACTOR_BIOMETRIC', 20),
            'sanctions_screening' => env('RISK_FACTOR_SANCTIONS', 30),
            'pep_screening' => env('RISK_FACTOR_PEP', 25),
            'document_confidence' => env('RISK_FACTOR_DOCUMENT', 15),
            'age_factor' => env('RISK_FACTOR_AGE', 10),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for document upload, processing, and retention
    | policies for KYC documents.
    |
    */

    'documents' => [
        'max_file_size' => env('DOCUMENT_MAX_FILE_SIZE', 5242880), // 5MB
        'allowed_types' => env('DOCUMENT_ALLOWED_TYPES', 'jpeg,jpg,png,pdf'),
        'encryption_enabled' => env('DOCUMENT_ENCRYPTION_ENABLED', true),
        'retention_years' => env('DOCUMENT_RETENTION_YEARS', 7),
        'auto_delete_expired' => env('DOCUMENT_AUTO_DELETE_EXPIRED', false),
        'backup_enabled' => env('DOCUMENT_BACKUP_ENABLED', true),
        'backup_frequency' => env('DOCUMENT_BACKUP_FREQUENCY', 'daily'),

        'processing' => [
            'auto_ocr' => env('DOCUMENT_AUTO_OCR', true),
            'auto_verification' => env('DOCUMENT_AUTO_VERIFICATION', true),
            'quality_check' => env('DOCUMENT_QUALITY_CHECK', true),
            'min_resolution' => env('DOCUMENT_MIN_RESOLUTION', 300), // DPI
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Services
    |--------------------------------------------------------------------------
    |
    | Configuration for various notification channels including
    | SMS, email, and push notifications for KYC updates.
    |
    */

    'notifications' => [
        'sms' => [
            'provider' => env('SMS_PROVIDER', 'twilio'),
            'enabled' => env('SMS_NOTIFICATIONS_ENABLED', true),
            'twilio' => [
                'sid' => env('TWILIO_SID'),
                'token' => env('TWILIO_TOKEN'),
                'from' => env('TWILIO_FROM'),
            ],
            'jazz' => [
                'username' => env('JAZZ_SMS_USERNAME'),
                'password' => env('JAZZ_SMS_PASSWORD'),
                'mask' => env('JAZZ_SMS_MASK'),
            ],
        ],

        'email' => [
            'enabled' => env('EMAIL_NOTIFICATIONS_ENABLED', true),
            'queue' => env('EMAIL_NOTIFICATION_QUEUE', 'default'),
        ],

        'push' => [
            'enabled' => env('PUSH_NOTIFICATIONS_ENABLED', false),
            'fcm_key' => env('FCM_SERVER_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit and Compliance Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for audit logging and compliance tracking
    | for regulatory requirements.
    |
    */

    'audit' => [
        'enabled' => env('AUDIT_LOGGING_ENABLED', true),
        'log_all_actions' => env('AUDIT_LOG_ALL_ACTIONS', true),
        'retention_days' => env('AUDIT_RETENTION_DAYS', 2555), // 7 years
        'encrypt_logs' => env('AUDIT_ENCRYPT_LOGS', true),
        'backup_logs' => env('AUDIT_BACKUP_LOGS', true),
        'real_time_monitoring' => env('AUDIT_REAL_TIME_MONITORING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance and Caching Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for caching and performance optimization
    | of KYC processing operations.
    |
    */

    'performance' => [
        'cache_enabled' => env('KYC_CACHE_ENABLED', true),
        'cache_ttl' => env('KYC_CACHE_TTL', 3600), // 1 hour
        'queue_processing' => env('KYC_QUEUE_PROCESSING', true),
        'batch_processing' => env('KYC_BATCH_PROCESSING', false),
        'parallel_processing' => env('KYC_PARALLEL_PROCESSING', false),
        'max_concurrent_jobs' => env('KYC_MAX_CONCURRENT_JOBS', 5),
    ],

];