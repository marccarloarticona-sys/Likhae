<?php

namespace App\Models\Buyer;

use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRefundEvidence extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_refund_request_id',
        'seller_return_refund_request_id',
        'uploaded_by_user_id',
        'disk',
        'file_path',
        'original_name',
        'mime_type',
        'evidence_type',
        'encrypted',
        'notes',
    ];

    protected function casts(): array
    {
        return ['encrypted' => 'boolean'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ReturnRefundRequest::class, 'return_refund_request_id');
    }

    public function sellerRequest(): BelongsTo
    {
        return $this->belongsTo(SellerReturnRefundRequest::class, 'seller_return_refund_request_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
