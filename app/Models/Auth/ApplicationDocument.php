<?php

namespace App\Models\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationDocument extends Model
{
    public const UPLOAD_FIELDS = [
        'valid_id' => 'VALID_ID',
        'business_permit' => 'BUSINESS_PERMIT',
        'dti_certificate' => 'DTI_CERTIFICATE',
        'sec_certificate' => 'SEC_CERTIFICATE',
        'bir_form_2303' => 'BIR_FORM_2303',
        'or_cr' => 'VEHICLE_OR_CR',
        'drivers_license' => 'DRIVERS_LICENSE',
    ];

    public const SELLER_UPLOAD_FIELDS = [
        'valid_id' => 'VALID_ID',
        'business_permit' => 'BUSINESS_PERMIT',
        'dti_certificate' => 'DTI_CERTIFICATE',
        'sec_certificate' => 'SEC_CERTIFICATE',
        'bir_form_2303' => 'BIR_FORM_2303',
    ];

    public const DOCUMENT_LABELS = [
        'VALID_ID' => 'Valid Government ID',
        'BUSINESS_PERMIT' => 'Business Permit',
        'DTI_CERTIFICATE' => 'DTI Certificate',
        'SEC_CERTIFICATE' => 'SEC Certificate',
        'BIR_FORM_2303' => 'BIR Form 2303',
        'VEHICLE_OR_CR' => 'Vehicle OR / CR',
        'DRIVERS_LICENSE' => "Driver's License",
    ];

    protected $fillable = [
        'registration_application_id',
        'document_type',
        'file_path',
        'original_name',
        'mime_type',
        'verification_status',
        'verified_by_user_id',
        'verified_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(RegistrationApplication::class, 'registration_application_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
