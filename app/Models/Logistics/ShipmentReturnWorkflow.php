<?php

namespace App\Models\Logistics;

use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentReturnWorkflow extends Model
{
    public const AWAITING_CENTER_HANDOFF = 'AWAITING_CENTER_HANDOFF';
    public const AWAITING_CENTER_RECEIPT = 'AWAITING_CENTER_RECEIPT';
    public const READY_FOR_RETURN_ASSIGNMENT = 'READY_FOR_RETURN_ASSIGNMENT';
    public const ASSIGNED_TO_RETURN_RIDER = 'ASSIGNED_TO_RETURN_RIDER';
    public const RETURN_RIDER_ACCEPTED = 'RETURN_RIDER_ACCEPTED';
    public const IN_TRANSIT_TO_SELLER = 'IN_TRANSIT_TO_SELLER';
    public const AWAITING_CROSS_CENTER_TRANSFER = 'AWAITING_CROSS_CENTER_TRANSFER';
    public const RETURN_RIDER_HANDOFF_PENDING = 'RETURN_RIDER_HANDOFF_PENDING';
    public const RETURN_EXCEPTION = 'RETURN_EXCEPTION';
    public const AWAITING_SELLER_CONFIRMATION = 'AWAITING_SELLER_CONFIRMATION';
    public const SELLER_RECEIVED = 'SELLER_RECEIVED';
    public const CENTER_RECEIVED_FOR_REDELIVERY = 'CENTER_RECEIVED_FOR_REDELIVERY';

    public const DISPOSITION_SELLER_RETURN = 'SELLER_RETURN';
    public const DISPOSITION_REDELIVERY = 'REDELIVERY';

    public const OPEN_STATUSES = [
        self::AWAITING_CENTER_HANDOFF,
        self::AWAITING_CENTER_RECEIPT,
        self::READY_FOR_RETURN_ASSIGNMENT,
        self::ASSIGNED_TO_RETURN_RIDER,
        self::RETURN_RIDER_ACCEPTED,
        self::IN_TRANSIT_TO_SELLER,
        self::AWAITING_CROSS_CENTER_TRANSFER,
        self::RETURN_RIDER_HANDOFF_PENDING,
        self::RETURN_EXCEPTION,
        self::AWAITING_SELLER_CONFIRMATION,
    ];

    protected $fillable = [
        'shipment_id', 'logistics_center_id', 'original_delivery_assignment_id', 'return_rider_profile_id',
        'status', 'reason', 'initiated_by_user_id', 'initiated_at', 'center_handoff_by_user_id',
        'center_handoff_at', 'center_received_by_user_id', 'center_received_at',
        'return_rider_assigned_by_user_id', 'return_rider_assigned_at', 'return_rider_accepted_at',
        'return_rider_picked_up_by_user_id', 'return_rider_picked_up_at', 'seller_handoff_by_user_id',
        'seller_handoff_at', 'seller_received_by_user_id', 'seller_received_at', 'seller_confirmation_notes',
        'return_disposition', 'pending_return_rider_profile_id', 'handoff_authorized_by_user_id',
        'handoff_authorized_at', 'handoff_reason', 'handoff_from_confirmed_at', 'handoff_to_confirmed_at',
        'handoff_completed_at', 'return_exception_reason', 'return_exception_recorded_by_user_id',
        'return_exception_recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'initiated_at' => 'datetime',
            'center_handoff_at' => 'datetime',
            'center_received_at' => 'datetime',
            'return_rider_assigned_at' => 'datetime',
            'return_rider_accepted_at' => 'datetime',
            'return_rider_picked_up_at' => 'datetime',
            'seller_handoff_at' => 'datetime',
            'seller_received_at' => 'datetime',
            'handoff_authorized_at' => 'datetime',
            'handoff_from_confirmed_at' => 'datetime',
            'handoff_to_confirmed_at' => 'datetime',
            'handoff_completed_at' => 'datetime',
            'return_exception_recorded_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function logisticsCenter(): BelongsTo
    {
        return $this->belongsTo(LogisticsCenter::class);
    }

    public function originalDeliveryAssignment(): BelongsTo
    {
        return $this->belongsTo(RiderAssignment::class, 'original_delivery_assignment_id');
    }

    public function returnRider(): BelongsTo
    {
        return $this->belongsTo(RiderProfile::class, 'return_rider_profile_id');
    }

    public function pendingReturnRider(): BelongsTo
    {
        return $this->belongsTo(RiderProfile::class, 'pending_return_rider_profile_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function centerHandoffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'center_handoff_by_user_id');
    }

    public function centerReceivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'center_received_by_user_id');
    }

    public function returnRiderAssignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_rider_assigned_by_user_id');
    }

    public function handoffAuthorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handoff_authorized_by_user_id');
    }

    public function returnExceptionRecordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_exception_recorded_by_user_id');
    }

    public function returnRiderPickedUpBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'return_rider_picked_up_by_user_id');
    }

    public function sellerHandoffBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_handoff_by_user_id');
    }

    public function sellerReceivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_received_by_user_id');
    }
}
