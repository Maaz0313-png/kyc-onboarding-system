# KYC Onboarding System Configuration Guide

This document provides comprehensive configuration instructions for the KYC Onboarding System designed for Pakistani financial institutions.

## Table of Contents

1. [Environment Setup](#environment-setup)
2. [NADRA Integration](#nadra-integration)
3. [OCR Services](#ocr-services)
4. [Virus Scanning](#virus-scanning)
5. [Sanctions Screening](#sanctions-screening)
6. [FMU Reporting](#fmu-reporting)
7. [Risk Assessment](#risk-assessment)
8. [Document Processing](#document-processing)
9. [Notifications](#notifications)
10. [Audit & Compliance](#audit--compliance)
11. [Performance Tuning](#performance-tuning)

## Environment Setup

Copy the `.env.example` file to `.env` and configure the following sections:

```bash
cp .env.example .env
php artisan key:generate
```

## NADRA Integration

Configure NADRA Verisys integration for Pakistani identity verification:

```env
# NADRA Integration Configuration
NADRA_BASE_URL=https://verisys.nadra.gov.pk/api
NADRA_API_KEY=your_nadra_api_key
NADRA_CLIENT_ID=your_client_id
NADRA_TIMEOUT=30
NADRA_RETRY_ATTEMPTS=3
NADRA_SANDBOX_MODE=true  # Set to false in production
```

### Required NADRA Credentials

1. **API Key**: Obtain from NADRA Verisys portal
2. **Client ID**: Provided by NADRA during registration
3. **Sandbox Mode**: Use `true` for testing, `false` for production

## OCR Services

Configure OCR providers for document text extraction:

### Tesseract (Default - Free)

```env
OCR_PROVIDER=tesseract
TESSERACT_PATH=tesseract
TESSERACT_LANGUAGES=eng+urd  # English + Urdu for Pakistani documents
TESSERACT_PSM=6
TESSERACT_TIMEOUT=60
```

**Installation**:

```bash
# Ubuntu/Debian
sudo apt-get install tesseract-ocr tesseract-ocr-urd

# macOS
brew install tesseract tesseract-lang
```

### Google Vision API (Recommended)

```env
OCR_PROVIDER=google_vision
GOOGLE_VISION_API_KEY=your_google_api_key
GOOGLE_VISION_PROJECT_ID=your_project_id
GOOGLE_VISION_MAX_RESULTS=50
```

### Azure Cognitive Services

```env
OCR_PROVIDER=azure_cognitive
AZURE_COGNITIVE_ENDPOINT=https://your-region.api.cognitive.microsoft.com
AZURE_COGNITIVE_API_KEY=your_azure_key
AZURE_COGNITIVE_REGION=eastus
```

## Virus Scanning

Configure virus scanning for uploaded documents:

### Basic Scanning (Default)

```env
VIRUS_SCAN_PROVIDER=basic
VIRUS_SCAN_ENABLED=true
VIRUS_SCAN_QUARANTINE=true
VIRUS_SCAN_MAX_FILE_SIZE=52428800  # 50MB
```

### ClamAV (Recommended)

```env
VIRUS_SCAN_PROVIDER=clamav
CLAMAV_PATH=clamscan
CLAMAV_DATABASE_PATH=/var/lib/clamav
CLAMAV_TIMEOUT=60
CLAMAV_UPDATE_DATABASE=true
```

**Installation**:

```bash
# Ubuntu/Debian
sudo apt-get install clamav clamav-daemon
sudo freshclam

# macOS
brew install clamav
```

### VirusTotal (Cloud-based)

```env
VIRUS_SCAN_PROVIDER=virustotal
VIRUSTOTAL_API_KEY=your_virustotal_api_key
VIRUSTOTAL_MAX_FILE_SIZE=33554432  # 32MB
VIRUSTOTAL_TIMEOUT=120
VIRUSTOTAL_WAIT_FOR_ANALYSIS=true
VIRUSTOTAL_MAX_WAIT_TIME=300
```

## Sanctions Screening

Configure sanctions screening against various lists:

```env
# Sanctions Screening Configuration
SANCTIONS_SCREENING_ENABLED=true
SANCTIONS_AUTO_UPDATE=true
SANCTIONS_UPDATE_FREQUENCY=daily
SANCTIONS_MATCH_THRESHOLD=70
SANCTIONS_FUZZY_MATCHING=true
SANCTIONS_LISTS_PATH=sanctions

# Sanctions Lists URLs
UN_SANCTIONS_URL=https://scsanctions.un.org/resources/xml/en/consolidated.xml
OFAC_SDN_URL=https://www.treasury.gov/ofac/downloads/sdn.xml
TFS_REGIME_URL=your_tfs_regime_url
PEP_LIST_URL=your_pep_list_url
LOCAL_PROSCRIBED_URL=your_local_proscribed_url
```

### External Providers (Optional)

```env
# WorldCheck
WORLDCHECK_ENABLED=false
WORLDCHECK_API_KEY=your_worldcheck_key
WORLDCHECK_BASE_URL=https://api.worldcheck.com

# Refinitiv
REFINITIV_ENABLED=false
REFINITIV_API_KEY=your_refinitiv_key
REFINITIV_BASE_URL=https://api.refinitiv.com
```

## FMU Reporting

Configure Financial Monitoring Unit (goAML) reporting:

```env
# FMU Configuration
FMU_REPORTING_ENABLED=true
FMU_GOAML_ENDPOINT=https://goaml.fmu.gov.pk/api
FMU_INSTITUTION_CODE=your_institution_code
FMU_API_KEY=your_fmu_api_key
FMU_CERTIFICATE_PATH=storage/certificates/fmu_cert.pem
FMU_PRIVATE_KEY_PATH=storage/certificates/fmu_private.key
FMU_AUTO_REPORT=true
FMU_REPORT_THRESHOLD=75
FMU_SANDBOX_MODE=true  # Set to false in production
```

### Required FMU Credentials

1. **Institution Code**: Provided by FMU during registration
2. **API Key**: Obtained from FMU portal
3. **Digital Certificates**: Required for secure communication

## Risk Assessment

Configure risk scoring parameters:

```env
# Risk Assessment Configuration
RISK_ASSESSMENT_ENABLED=true
RISK_AUTO_SCORING=true
RISK_MANUAL_REVIEW_THRESHOLD=70
RISK_AUTO_APPROVE_THRESHOLD=30
RISK_HIGH_RISK_THRESHOLD=70

# Risk Factors (weights - should total 100)
RISK_FACTOR_NADRA=25
RISK_FACTOR_BIOMETRIC=20
RISK_FACTOR_SANCTIONS=30
RISK_FACTOR_PEP=25
RISK_FACTOR_DOCUMENT=15
RISK_FACTOR_AGE=10
```

### Risk Scoring Logic

-   **0-30**: Low Risk (Auto-approve eligible)
-   **31-70**: Medium Risk (Standard review)
-   **71-100**: High Risk (Manual review required)

## Document Processing

Configure document handling and retention:

```env
# Document Processing Configuration
DOCUMENT_MAX_FILE_SIZE=5242880  # 5MB
DOCUMENT_ALLOWED_TYPES=jpeg,jpg,png,pdf
DOCUMENT_ENCRYPTION_ENABLED=true
DOCUMENT_RETENTION_YEARS=7  # Pakistani regulatory requirement
DOCUMENT_AUTO_DELETE_EXPIRED=false
DOCUMENT_BACKUP_ENABLED=true
DOCUMENT_BACKUP_FREQUENCY=daily

# Processing Features
DOCUMENT_AUTO_OCR=true
DOCUMENT_AUTO_VERIFICATION=true
DOCUMENT_QUALITY_CHECK=true
DOCUMENT_MIN_RESOLUTION=300  # DPI
```

## Notifications

Configure notification channels:

### SMS Notifications

```env
# SMS Configuration
SMS_PROVIDER=twilio
SMS_NOTIFICATIONS_ENABLED=true

# Twilio (International)
TWILIO_SID=your_twilio_sid
TWILIO_TOKEN=your_twilio_token
TWILIO_FROM=+1234567890

# Jazz SMS (Pakistani Provider)
JAZZ_SMS_USERNAME=your_jazz_username
JAZZ_SMS_PASSWORD=your_jazz_password
JAZZ_SMS_MASK=your_sms_mask
```

### Email Notifications

```env
EMAIL_NOTIFICATIONS_ENABLED=true
EMAIL_NOTIFICATION_QUEUE=default
```

### Push Notifications

```env
PUSH_NOTIFICATIONS_ENABLED=false
FCM_SERVER_KEY=your_fcm_server_key
```

## Audit & Compliance

Configure audit logging for regulatory compliance:

```env
# Audit Configuration
AUDIT_LOGGING_ENABLED=true
AUDIT_LOG_ALL_ACTIONS=true
AUDIT_RETENTION_DAYS=2555  # 7 years
AUDIT_ENCRYPT_LOGS=true
AUDIT_BACKUP_LOGS=true
AUDIT_REAL_TIME_MONITORING=true
```

## Performance Tuning

Configure performance optimization:

```env
# Performance Configuration
KYC_CACHE_ENABLED=true
KYC_CACHE_TTL=3600  # 1 hour
KYC_QUEUE_PROCESSING=true
KYC_BATCH_PROCESSING=false
KYC_PARALLEL_PROCESSING=false
KYC_MAX_CONCURRENT_JOBS=5
```

### Queue Configuration

Ensure queue workers are running:

```bash
php artisan queue:work --tries=3 --timeout=300
```

### Caching

Configure Redis for better performance:

```env
CACHE_STORE=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

## Security Considerations

### File Permissions

```bash
chmod -R 755 storage/
chmod -R 755 bootstrap/cache/
```

### SSL/TLS Configuration

Ensure HTTPS is enabled for all external API communications:

```env
APP_URL=https://your-domain.com
```

### Database Security

Use strong database credentials and enable SSL connections:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kyc_production
DB_USERNAME=secure_username
DB_PASSWORD=strong_password
```

## Regulatory Compliance Checklist

-   [ ] NADRA integration configured and tested
-   [ ] All sanctions lists updated and functional
-   [ ] FMU reporting configured with valid certificates
-   [ ] Document retention policy set to 7 years
-   [ ] Audit logging enabled for all actions
-   [ ] Risk assessment thresholds aligned with SBP guidelines
-   [ ] Data encryption enabled for sensitive information
-   [ ] Backup procedures implemented
-   [ ] Access controls and role-based permissions configured

## Troubleshooting

### Common Issues

1. **NADRA Connection Issues**

    - Verify API credentials
    - Check network connectivity
    - Ensure sandbox/production mode is correct

2. **OCR Not Working**

    - Verify Tesseract installation
    - Check language packs
    - Validate image quality

3. **Virus Scanning Failures**

    - Update ClamAV database
    - Check file permissions
    - Verify VirusTotal API limits

4. **Sanctions Screening Errors**
    - Update sanctions lists
    - Check file permissions in storage/app/sanctions
    - Verify JSON format

### Log Files

Monitor these log files for issues:

-   `storage/logs/laravel.log` - General application logs
-   `storage/logs/kyc.log` - KYC-specific logs
-   `storage/logs/audit.log` - Audit trail logs

### Support

For technical support and regulatory compliance questions, contact:

-   Technical Support: tech-support@your-company.com
-   Compliance Team: compliance@your-company.com

---

**Note**: This configuration guide is specific to Pakistani financial regulations and SBP requirements. Ensure all configurations comply with your institution's specific regulatory obligations.
