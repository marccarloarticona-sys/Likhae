<?php

namespace App\Models\Admin;

use App\Models\Seller\SellerProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerComplianceResponse extends Model
{
    protected $fillable = [
        'seller_compliance_case_id',
        'seller_profile_id',
        'explanation',
        'corrective_action',
        'evidence_path',
        'evidence_original_name',
        'evidence_mime',
        'evidence_size',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence_size' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(SellerComplianceCase::class, 'seller_compliance_case_id');
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class);
    }
}
