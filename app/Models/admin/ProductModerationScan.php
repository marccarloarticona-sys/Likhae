<?php

namespace App\Models\Admin;

use App\Models\Seller\Product;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductModerationScan extends Model
{
    protected $fillable = [
        'product_id', 'seller_profile_id', 'seller_compliance_case_id', 'requested_by_admin_user_id', 'revision_hash', 'policy_version',
        'model_versions', 'is_automatic', 'scan_status', 'overall_risk', 'text_risk', 'image_risk',
        'detected_categories', 'text_findings', 'image_findings', 'object_detections', 'model_results',
        'evidence_summary', 'recommended_action', 'failure_code', 'retry_count', 'started_at', 'scanned_at',
        'listing_status_before_hold', 'held_at', 'decision_status', 'decision_reason',
        'decided_by_admin_user_id', 'decided_at', 'appeal_status', 'appeal_reason', 'appeal_explanation',
        'appeal_evidence_path', 'appeal_evidence_original_name', 'appeal_evidence_mime', 'appeal_evidence_size',
        'appeal_submitted_at', 'appeal_decided_by_admin_user_id', 'appeal_decision_reason', 'appeal_decided_at',
    ];

    protected function casts(): array
    {
        return [
            'model_versions' => 'array',
            'detected_categories' => 'array',
            'text_findings' => 'array',
            'image_findings' => 'array',
            'object_detections' => 'array',
            'model_results' => 'array',
            'is_automatic' => 'boolean',
            'retry_count' => 'integer',
            'appeal_evidence_size' => 'integer',
            'started_at' => 'datetime',
            'scanned_at' => 'datetime',
            'held_at' => 'datetime',
            'decided_at' => 'datetime',
            'appeal_submitted_at' => 'datetime',
            'appeal_decided_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(SellerComplianceCase::class, 'seller_compliance_case_id');
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function requestedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_admin_user_id');
    }

    public function decidedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_admin_user_id');
    }

    public function appealDecidedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appeal_decided_by_admin_user_id');
    }
}
