<?php

namespace App\Models;

class KycStatus
{
    // KYC Application Status
    const PENDING = 'pending';
    const IN_PROGRESS = 'in_progress';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';
    const UNDER_REVIEW = 'under_review';

    // Risk Categories
    const RISK_LOW = 'low';
    const RISK_MEDIUM = 'medium';
    const RISK_HIGH = 'high';

    // Verification Status
    const VERIFICATION_SUCCESS = 'success';
    const VERIFICATION_FAILED = 'failed';
    const VERIFICATION_PENDING = 'pending';

    // Sanctions Screening Status
    const SANCTIONS_CLEAR = 'clear';
    const SANCTIONS_MATCH_FOUND = 'match_found';
    const SANCTIONS_UNDER_REVIEW = 'under_review';

    // Decision Types
    const DECISION_APPROVED = 'approved';
    const DECISION_REJECTED = 'rejected';
    const DECISION_ESCALATED = 'escalated';

    // Risk Thresholds
    const RISK_AUTO_APPROVE_THRESHOLD = 30;
    const RISK_MANUAL_REVIEW_THRESHOLD = 70;

    // Age Limits
    const MIN_AGE = 18;
    const MAX_AGE = 100;

    // Liveness Check Thresholds
    const LIVENESS_THRESHOLD = 70;
    const FACE_MATCH_THRESHOLD = 75;
}