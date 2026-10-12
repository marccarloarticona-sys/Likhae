<?php

namespace App\Models\Seller;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundPayout extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_return_refund_request_id',
        'submitted_by_user_id',
        'amount_sent',
        'transaction_reference',
        'transaction_reference_hash',
        'sent_at',
        'proof_disk',
        'proof_path',
        'proof_original_name',
        'proof_mime',
        'seller_note',
        'payment_report_state',
    ];

    protected function casts(): array
    {
        return [
            'amount_sent' => 'decimal:2',
            'transaction_reference' => 'encrypted',
            'sent_at' => 'datetime',
        ];
    }

    public function sellerRequest(): BelongsTo
    {
        return $this->belongsTo(SellerReturnRefundRequest::class, 'seller_return_refund_request_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
