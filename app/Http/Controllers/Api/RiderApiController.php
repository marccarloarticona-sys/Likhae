<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Communication\Conversation;
use App\Models\Finance\RiderEarningPoolParticipant;
use App\Models\Finance\RiderRemittance;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderEarning;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderLocalTripItem;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Services\Communication\ConversationService;
use App\Services\Finance\FinanceSchemaReadiness;
use App\Services\Finance\RemittanceService;
use App\Services\Maps\RiderLocationService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\SellerPickupBatchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use RuntimeException;

class RiderApiController extends Controller
{
    public function dashboard(Request $request, LogisticsSchemaReadiness $schema): JsonResponse
    {
        $rider = $this->rider($request);
        $assignments = RiderAssignment::query()->where('rider_profile_id', $rider->id);
        $deliveries = (clone $assignments)->delivery();
        $pendingPickupCount = $schema->sellerPickupBatchesReady()
            ? RiderLocalTrip::query()->where('rider_profile_id', $rider->id)
                ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
                ->whereNotNull('seller_profile_id')
                ->whereIn('status', [RiderLocalTrip::LOADING, RiderLocalTrip::READY, RiderLocalTrip::IN_PROGRESS])
                ->count()
            : (clone $assignments)->pickup()->whereIn('status', ['ASSIGNED', 'ACCEPTED'])->count();
        $stats = [
            ['label' => 'Pending Seller Pickup Assignments', 'value' => $pendingPickupCount],
            ['label' => 'Assigned Parcels', 'value' => (clone $deliveries)->whereIn('status', ['ASSIGNED', 'ACCEPTED'])->count()],
            ['label' => 'In Transit', 'value' => (clone $deliveries)->where('status', 'IN_PROGRESS')->count()],
            ['label' => 'Out for Delivery', 'value' => (clone $deliveries)->where('status', 'IN_PROGRESS')->whereHas('shipment', fn ($query) => $query->where('current_status', 'OUT_FOR_DELIVERY'))->count()],
            ['label' => 'Completed Today', 'value' => (clone $deliveries)->where('status', 'COMPLETED')->whereDate('completed_at', today())->count()],
        ];

        $recent = RiderAssignment::query()
            ->where('rider_profile_id', $rider->id)
            ->delivery()
            ->with($this->assignmentRelations())
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (RiderAssignment $assignment): array => $this->assignmentPayload($assignment))
            ->values();

        return response()->json(['success' => true, 'data' => ['stats' => $stats, 'recent_parcels' => $recent]]);
    }

    public function assignments(Request $request): JsonResponse
    {
        $rider = $this->rider($request);
        $query = RiderAssignment::query()->where('rider_profile_id', $rider->id)->delivery()
            ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
            ->with($this->assignmentRelations())->latest();
        $rows = $query->paginate($this->perPage($request));
        $statsQuery = RiderAssignment::query()->where('rider_profile_id', $rider->id)->delivery();
        $stats = [
            'assigned' => (clone $statsQuery)->whereIn('status', ['ASSIGNED', 'ACCEPTED'])->count(),
            'out_for_delivery' => (clone $statsQuery)->where('status', 'IN_PROGRESS')->whereHas('shipment', fn ($builder) => $builder->where('current_status', 'OUT_FOR_DELIVERY'))->count(),
            'delivered_today' => (clone $statsQuery)->where('status', 'COMPLETED')->whereDate('completed_at', today())->count(),
            'failed_today' => (clone $statsQuery)->whereHas('deliveryAttempts', fn ($builder) => $builder->whereDate('attempted_at', today())->whereIn('status', ['FAILED', 'RESCHEDULED', 'RETURNED']))->count(),
        ];

        return $this->paginated($rows, fn (RiderAssignment $assignment): array => $this->assignmentPayload($assignment), ['stats' => $stats]);
    }

    public function pickups(Request $request, LogisticsSchemaReadiness $schema): JsonResponse
    {
        $rider = $this->rider($request);
        if (! $schema->sellerPickupBatchesReady()) {
            return response()->json(['success' => false, 'message' => 'Seller pickup batch workflow is not enabled until its additive schema migration is installed.'], 503);
        }

        $query = RiderLocalTrip::query()->where('rider_profile_id', $rider->id)
            ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
            ->whereNotNull('seller_profile_id')
            ->whereIn('status', [RiderLocalTrip::LOADING, RiderLocalTrip::READY, RiderLocalTrip::IN_PROGRESS, RiderLocalTrip::EN_ROUTE_TO_HUB])
            ->with(['seller.businessAddress', 'logisticsCenter.address', 'items.riderAssignment.shipment.sellerOrder.items']);
        $rows = $query->paginate($this->perPage($request));
        $statsQuery = RiderLocalTrip::query()->where('rider_profile_id', $rider->id)
            ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)->whereNotNull('seller_profile_id');
        $stats = [
            'assigned' => (clone $statsQuery)->where('status', RiderLocalTrip::LOADING)->count(),
            'accepted' => (clone $statsQuery)->where('status', RiderLocalTrip::READY)->count(),
            'in_progress' => (clone $statsQuery)->whereIn('status', [RiderLocalTrip::IN_PROGRESS, RiderLocalTrip::EN_ROUTE_TO_HUB])->count(),
            'completed_today' => (clone $statsQuery)->where('status', RiderLocalTrip::COMPLETED)->whereDate('completed_at', today())->count(),
        ];

        return $this->paginated($rows, fn (RiderLocalTrip $trip): array => $this->pickupBatchPayload($trip), ['stats' => $stats]);
    }

    public function acceptPickupBatch(Request $request, int $trip, SellerPickupBatchService $batches): JsonResponse
    {
        return $this->operatePickupBatch($request, $trip, fn (RiderLocalTrip $batch) => $batches->accept($request->user(), $batch), 'Seller pickup batch accepted.');
    }

    public function startPickupBatch(Request $request, int $trip, SellerPickupBatchService $batches): JsonResponse
    {
        return $this->operatePickupBatch($request, $trip, fn (RiderLocalTrip $batch) => $batches->start($request->user(), $batch), 'Seller pickup batch started. Scan each waybill separately.');
    }

    public function scanPickupBatch(Request $request, int $trip, SellerPickupBatchService $batches): JsonResponse
    {
        $data = $request->validate([
            'tracking_code' => ['required', 'string', 'max:150'],
            'scan_method' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
        ]);
        $batch = $this->ownedPickupBatch($request, $trip);
        try {
            $result = $batches->scan($request->user(), $batch, $data['tracking_code'], $data['scan_method'] ?? 'MANUAL');
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => [
            'already_verified' => $result['already_verified'],
            'assignment_id' => $result['assignment']->id,
            'shipment_status' => $result['assignment']->shipment?->current_status,
            'message' => $result['already_verified'] ? 'Parcel already verified.' : 'Parcel verified and collected.',
        ]]);
    }

    public function markPickupNotCollected(Request $request, int $trip, SellerPickupBatchService $batches): JsonResponse
    {
        $data = $request->validate([
            'rider_assignment_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $batch = $this->ownedPickupBatch($request, $trip);
        try {
            $assignment = $batches->markNotCollected($request->user(), $batch, (int) $data['rider_assignment_id'], $data['reason']);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => [
            'assignment_id' => $assignment->id,
            'status' => $assignment->status,
            'reason' => $assignment->rejection_reason,
        ]]);
    }

    public function finishPickupBatch(Request $request, int $trip, SellerPickupBatchService $batches): JsonResponse
    {
        return $this->operatePickupBatch($request, $trip, fn (RiderLocalTrip $batch) => $batches->finishSellerPickup($request->user(), $batch), 'Seller pickup batch completed.');
    }

    public function verifyPickup(Request $request, RiderAssignment $assignment, SellerPickupBatchService $batches): JsonResponse
    {
        $this->assertAssignment($request, $assignment, RiderAssignment::TYPE_PICKUP);
        $data = $request->validate([
            'tracking_code' => ['required', 'string', 'max:150'],
            'scan_method' => ['nullable', 'string', 'in:QR,BARCODE,MANUAL'],
        ]);
        $item = RiderLocalTripItem::query()
            ->where('rider_assignment_id', $assignment->id)
            ->whereHas('trip', fn ($query) => $query->where('trip_type', RiderLocalTrip::TYPE_PICKUP)->whereNotNull('seller_profile_id'))
            ->with('trip')
            ->latest('id')
            ->first();
        if (! $item?->trip) {
            return response()->json(['success' => false, 'message' => 'This parcel is not part of a seller pickup batch.'], 409);
        }
        try {
            $result = $batches->scan($request->user(), $item->trip, $data['tracking_code'], $data['scan_method'] ?? 'MANUAL');
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => [
            'verified' => true,
            'already_verified' => $result['already_verified'],
            'shipment_status' => $result['assignment']->shipment?->current_status,
        ]]);
    }

    public function updateLocation(Request $request, RiderAssignment $assignment, RiderLocationService $locations): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0', 'max:250'],
            'recorded_at' => ['required', 'date'],
        ]);
        abort_unless(is_finite((float) $data['latitude']) && is_finite((float) $data['longitude']), 422, 'Coordinates must be finite numeric values.');
        $this->assertAssignment($request, $assignment, $assignment->assignment_type);
        $location = $locations->update(
            $assignment,
            $request->user(),
            (float) $data['latitude'],
            (float) $data['longitude'],
            (float) $data['accuracy'],
            Carbon::parse($data['recorded_at']),
        );

        return response()->json(['success' => true, 'data' => [
            'latitude' => (float) $location->latitude,
            'longitude' => (float) $location->longitude,
            'accuracy' => (float) $data['accuracy'],
            'recorded_at' => $location->recorded_at?->toIso8601String(),
        ]]);
    }

    public function history(Request $request): JsonResponse
    {
        $rider = $this->rider($request);
        $rows = RiderAssignment::query()->where('rider_profile_id', $rider->id)
            ->where(fn ($query) => $query->whereIn('status', ['COMPLETED', 'REJECTED', 'CANCELLED'])->orWhereHas('deliveryAttempts'))
            ->with($this->assignmentRelations())->latest()->paginate($this->perPage($request));

        return $this->paginated($rows, function (RiderAssignment $assignment): array {
            $attempt = $assignment->deliveryAttempts->sortByDesc('attempt_number')->first();
            $shipment = $assignment->shipment;
            $buyer = $shipment?->sellerOrder?->order?->address?->recipient_name ?? $shipment?->sellerOrder?->order?->buyer?->name ?? 'Buyer unavailable';
            $status = $attempt?->status ?? $shipment?->current_status ?? $assignment->status;

            return [
                'id' => $assignment->id,
                'tracking' => $shipment?->tracking_number ?? 'Tracking unavailable',
                'buyer' => $buyer,
                'status' => $status,
                'status_label' => str($status)->headline()->toString(),
                'updated_at' => ($attempt?->attempted_at ?? $assignment->completed_at ?? $assignment->updated_at)?->toIso8601String(),
                'failure_reason' => $attempt?->failure_reason,
                'image_url' => $this->assignmentImage($assignment),
            ];
        });
    }

    public function earnings(Request $request, FinanceSchemaReadiness $schema): JsonResponse
    {
        $rider = $this->rider($request);
        if ($schema->step7Ready()) {
            $rows = RiderEarningPoolParticipant::query()
                ->where('rider_profile_id', $rider->id)
                ->with('pool.shipment')
                ->latest()
                ->paginate($this->perPage($request));

            return $this->paginated($rows, fn (RiderEarningPoolParticipant $participant): array => [
                'date' => $participant->created_at?->format('M d, Y') ?? 'Date unavailable',
                'reference' => $participant->pool?->shipment?->tracking_number ?? 'Pool #'.$participant->rider_earning_pool_id,
                'amount' => $participant->share_amount === null ? 0.0 : (float) $participant->share_amount,
                'status' => str($participant->status)->headline()->toString(),
            ], [
                'total' => (float) RiderEarningPoolParticipant::query()->where('rider_profile_id', $rider->id)->sum('share_amount'),
                'notice' => 'Rider pool shares remain pending until an authorized allocation and payout decision; COD remittance is separate.',
            ]);
        }

        $rows = $rider->earnings()->with('riderAssignment.shipment')->latest()->paginate($this->perPage($request));

        return $this->paginated($rows, fn (RiderEarning $earning): array => [
            'date' => $earning->earned_at?->format('M d, Y') ?? $earning->created_at?->format('M d, Y') ?? 'Date unavailable',
            'reference' => $earning->riderAssignment?->shipment?->tracking_number ?? 'Assignment #'.$earning->rider_assignment_id,
            'amount' => (float) $earning->amount,
            'status' => str($earning->status)->headline()->toString(),
        ], ['total' => (float) $rider->earnings()->sum('amount'), 'notice' => 'Recorded earnings from completed pickup and delivery assignments.']);
    }

    public function remittances(Request $request, RemittanceService $service, FinanceSchemaReadiness $schema): JsonResponse
    {
        $rider = $this->rider($request);
        if (! $schema->step6Ready()) {
            return response()->json(['success' => true, 'data' => ['schema_ready' => false, 'collections' => [], 'remittances' => []]]);
        }

        $history = $rider->remittances()->latest()->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => [
                'schema_ready' => true,
                'collections' => $service->availableCollections($rider)->map(fn ($collection): array => [
                    'id' => $collection->id,
                    'tracking' => $collection->shipment?->tracking_number,
                    'reference' => $collection->collection_reference,
                    'expected_amount' => (float) $collection->expected_amount,
                    'collected_amount' => (float) $collection->collected_amount,
                ])->values(),
                'remittances' => $history->getCollection()->map(fn (RiderRemittance $remittance): array => [
                    'id' => $remittance->id,
                    'reference' => $remittance->remittance_reference,
                    'status' => $remittance->status,
                    'expected_amount' => (float) $remittance->expected_amount,
                    'submitted_amount' => (float) $remittance->submitted_amount,
                    'verified_amount' => (float) $remittance->verified_amount,
                ])->values(),
                'meta' => ['current_page' => $history->currentPage(), 'last_page' => $history->lastPage(), 'total' => $history->total()],
            ],
        ]);
    }

    public function submitRemittance(Request $request, RemittanceService $service): JsonResponse
    {
        $data = $request->validate([
            'collection_ids' => ['required', 'array', 'min:1'],
            'collection_ids.*' => ['required', 'integer'],
            'submitted_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $remittance = $service->submit($request->user(), $data['collection_ids'], $data['submitted_amount'], $data['notes'] ?? null);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => [
            'id' => $remittance->id,
            'reference' => $remittance->remittance_reference,
            'status' => $remittance->status,
            'expected_amount' => (float) $remittance->expected_amount,
            'submitted_amount' => (float) $remittance->submitted_amount,
        ]], 201);
    }

    public function profile(Request $request): JsonResponse
    {
        $rider = $this->rider($request)->load(['user', 'logisticsCenter', 'areaAssignments.serviceArea']);
        $user = $rider->user;

        return response()->json(['success' => true, 'data' => [
            'user' => [
                'id' => $user->id, 'name' => $user->name, 'first_name' => $user->first_name, 'middle_initial' => $user->middle_initial,
                'last_name' => $user->last_name, 'email' => $user->email, 'contact_number' => $user->contact_number,
                'birthday' => $user->birthday?->toDateString(), 'sex' => $user->sex, 'status' => $user->status,
                'account_type' => $user->account_type,
                'profile_photo_url' => $user->profile_photo_path ? Storage::disk('public')->url($user->profile_photo_path) : null,
            ],
            'rider' => [
                'id' => $rider->id, 'user_id' => $rider->user_id, 'vehicle_type' => $rider->vehicle_type,
                'plate_number' => $rider->plate_number, 'status' => $rider->status, 'primary_role' => 'Rider',
                'logistics_center' => $rider->logisticsCenter?->business_name,
                'service_areas' => $rider->areaAssignments->where('is_active', true)->pluck('serviceArea.name')->filter()->values(),
            ],
        ]]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $this->rider($request);
        $rows = $request->user()->notifications()->latest()->paginate(
            min(max($request->integer('per_page', 20), 1), 50),
        );

        return response()->json([
            'success' => true,
            'data' => $rows->getCollection()->map(fn ($notification): array => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'action_url' => $notification->action_url,
                'reference_type' => $notification->reference_type,
                'reference_id' => $notification->reference_id,
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->values(),
            'unread_count' => $request->user()->notifications()->whereNull('read_at')->count(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function markNotificationsRead(Request $request): JsonResponse
    {
        $this->rider($request);
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function conversations(Request $request, ConversationService $service): JsonResponse
    {
        $this->rider($request);
        $user = $request->user();
        $conversations = $service->listFor($user, false, 100);
        $unreadCounts = $service->unreadCountsFor($user, $conversations);
        $rows = $conversations->map(function (Conversation $conversation) use ($user, $unreadCounts): array {
            $other = $conversation->participants->first(fn (User $participant): bool => (int) $participant->id !== (int) $user->id);
            $shipment = $conversation->shipment;

            return [
                'id' => (string) $conversation->id,
                'conversation_id' => (string) $conversation->id,
                'buyer_id' => (string) ($other?->id ?? ''),
                'buyer_name' => $other?->name ?? 'LIKHAE User',
                'order_id' => $conversation->order?->order_number,
                'tracking_code' => $shipment?->tracking_number,
                'last_message' => $conversation->latestMessage?->body ?? '',
                'last_message_at' => $conversation->latestMessage?->sent_at?->toIso8601String(),
                'unread_count' => (int) $unreadCounts->get($conversation->id, 0),
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function conversationMessages(Request $request, int $conversation, ConversationService $service): JsonResponse
    {
        $this->rider($request);
        $messages = $service->messagesFor(
            $request->user(),
            $conversation,
            50,
            max(0, $request->integer('after_id')) ?: null,
        );
        $thread = Conversation::query()->select('id')->findOrFail($conversation);
        $service->markRead($thread, $request->user());

        return response()->json(['success' => true, 'data' => $messages->map(fn ($message): array => [
            'id' => (string) $message->id, 'conversation_id' => (string) $message->conversation_id,
            'body' => $message->body, 'sender_user_id' => $message->sender_user_id,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'from_rider' => (int) $message->sender_user_id === (int) $request->user()->id,
        ])->values()]);
    }

    public function sendMessage(Request $request, ConversationService $service): JsonResponse
    {
        $this->rider($request);
        $data = $request->validate(['conversation_id' => ['required', 'integer'], 'body' => ['required', 'string', 'max:2000']]);
        $thread = Conversation::query()->whereKey($data['conversation_id'])
            ->whereHas('participants', fn ($query) => $query->where('users.id', $request->user()->id))
            ->with(['participants', 'shipment'])->firstOrFail();
        $recipient = $thread->participants->first(fn (User $participant): bool => (int) $participant->id !== (int) $request->user()->id);
        abort_unless($recipient, 409, 'The conversation has no other participant.');
        $message = $service->send($request->user(), (int) $recipient->id, trim($data['body']), [
            'type' => $thread->type, 'order_id' => $thread->order_id,
            'seller_order_id' => $thread->seller_order_id, 'shipment_id' => $thread->shipment_id,
        ], $thread);

        return response()->json(['success' => true, 'data' => [
            'id' => (string) $message->id, 'conversation_id' => (string) $message->conversation_id,
            'body' => $message->body, 'sent_at' => $message->sent_at?->toIso8601String(), 'from_rider' => true,
        ]], 201);
    }

    private function rider(Request $request): RiderProfile
    {
        abort_unless($request->user()?->isAccountType(User::TYPE_RIDER), 403, 'Rider access is required.');
        $rider = $request->user()->riderProfile;
        abort_unless($rider && $rider->status === 'ACTIVE', 403, 'An active rider profile is required.');
        return $rider;
    }

    private function ownedPickupBatch(Request $request, int $tripId): RiderLocalTrip
    {
        abort_unless(app(LogisticsSchemaReadiness::class)->sellerPickupBatchesReady(), 503, 'Seller pickup batch workflow is unavailable until its additive schema migration is installed.');
        $rider = $this->rider($request);

        return RiderLocalTrip::query()
            ->where('rider_profile_id', $rider->id)
            ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
            ->whereNotNull('seller_profile_id')
            ->with(['items.riderAssignment.shipment'])
            ->findOrFail($tripId);
    }

    private function operatePickupBatch(Request $request, int $tripId, callable $operation, string $message): JsonResponse
    {
        $batch = $this->ownedPickupBatch($request, $tripId);
        try {
            $updated = $operation($batch);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => $message, 'data' => [
            'id' => $updated->id,
            'batch_number' => 'P-'.$updated->id,
            'status' => $updated->status,
        ]]);
    }

    private function pickupBatchPayload(RiderLocalTrip $trip): array
    {
        $items = $trip->items->map(function (RiderLocalTripItem $item): array {
            $assignment = $item->riderAssignment;
            $shipment = $assignment?->shipment;

            return [
                'assignment_id' => $assignment?->id,
                'tracking' => $shipment?->tracking_number,
                'seller_order_number' => $shipment?->sellerOrder?->seller_order_number,
                'products' => $shipment?->sellerOrder?->items?->pluck('product_name')->filter()->values() ?? collect(),
                'assignment_status' => $assignment?->status,
                'shipment_status' => $shipment?->current_status,
                'not_collected_reason' => $assignment?->rejection_reason,
            ];
        })->values();
        $collected = $items->where('assignment_status', 'COMPLETED')->count();

        return [
            'id' => $trip->id,
            'batch_number' => 'P-'.$trip->id,
            'status' => $trip->status,
            'seller' => $trip->seller?->business_name,
            'pickup_address' => $trip->seller?->businessAddress?->formatted(),
            'logistics_center' => $trip->logisticsCenter?->business_name,
            'destination_address' => $trip->logisticsCenter?->address?->formatted(),
            'parcel_count' => $items->count(),
            'approved_capacity' => $trip->approved_parcel_capacity,
            'collected_count' => $collected,
            'items' => $items,
        ];
    }

    private function assertAssignment(Request $request, RiderAssignment $assignment, string $type): void
    {
        $rider = $this->rider($request);
        abort_unless((int) $assignment->rider_profile_id === (int) $rider->id && $assignment->assignment_type === $type, 403, 'This assignment does not belong to the rider.');
    }

    private function assignmentRelations(): array
    {
        return [
            'shipment.sellerOrder.items.product.images', 'shipment.sellerOrder.order.address', 'shipment.sellerOrder.order.buyer',
            'shipment.sellerOrder.sellerProfile.user', 'shipment.sellerOrder.sellerProfile.businessAddress',
            'shipment.deliveryAttempts', 'liveLocation',
        ];
    }

    private function assignmentPayload(RiderAssignment $assignment): array
    {
        $shipment = $assignment->shipment;
        $sellerOrder = $shipment?->sellerOrder;
        $order = $sellerOrder?->order;
        $address = $order?->address;
        $destination = $assignment->assignment_type === RiderAssignment::TYPE_PICKUP
            ? $sellerOrder?->sellerProfile?->businessAddress
            : $address;
        $current = strtolower((string) $shipment?->current_status);
        $status = match ($assignment->status) {
            'ASSIGNED' => 'assigned', 'ACCEPTED' => 'accepted',
            'IN_PROGRESS' => $assignment->assignment_type === RiderAssignment::TYPE_PICKUP ? 'accepted' : ($current === 'out_for_delivery' ? 'out_for_delivery' : 'in_transit'),
            'COMPLETED' => $assignment->assignment_type === RiderAssignment::TYPE_PICKUP ? 'picked_up' : 'delivered',
            default => strtolower((string) ($shipment?->current_status ?? $assignment->status)),
        };
        $latestAttempt = $assignment->deliveryAttempts->sortByDesc('attempt_number')->first();
        if ($latestAttempt && in_array($latestAttempt->status, ['FAILED', 'RESCHEDULED', 'RETURNED'], true)) {
            $status = strtolower($latestAttempt->status === 'RESCHEDULED' ? 'accepted' : 'delivery_failed');
        }

        return [
            'id' => $assignment->id, 'assignment_type' => $assignment->assignment_type,
            'tracking' => $shipment?->tracking_number ?? '',
            'buyer_name' => $address?->recipient_name ?? $order?->buyer?->name ?? 'Buyer unavailable',
            'buyer_contact' => $address?->contact_number ?? $order?->buyer?->contact_number ?? 'Not available',
            'seller_name' => $sellerOrder?->sellerProfile?->business_name ?? 'Seller unavailable',
            'address' => $assignment->assignment_type === RiderAssignment::TYPE_PICKUP ? ($sellerOrder?->sellerProfile?->businessAddress?->formatted() ?? $address?->formatted() ?? '') : ($address?->formatted() ?? ''),
            'amount' => (float) ($sellerOrder?->grand_total ?? 0), 'items' => (int) ($sellerOrder?->items?->sum('quantity') ?? 0),
            'status' => $status, 'status_label' => str($status)->replace('_', ' ')->headline()->toString(),
            'image_url' => $this->assignmentImage($assignment), 'failure_reason' => $latestAttempt?->failure_reason,
            'delivery_latitude' => $destination?->latitude !== null ? (float) $destination->latitude : null,
            'delivery_longitude' => $destination?->longitude !== null ? (float) $destination->longitude : null,
            'rider_location' => $assignment->liveLocation ? [
                'latitude' => (float) $assignment->liveLocation->latitude,
                'longitude' => (float) $assignment->liveLocation->longitude,
                'recorded_at' => $assignment->liveLocation->recorded_at?->toIso8601String(),
            ] : null,
        ];
    }

    private function assignmentImage(RiderAssignment $assignment): ?string
    {
        $images = $assignment->shipment?->sellerOrder?->items?->first()?->product?->images ?? collect();
        $path = $images->firstWhere('is_primary', true)?->file_path ?? $images->first()?->file_path;
        return $path ? Storage::url($path) : null;
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 20), 1), 100);
    }

    private function paginated(LengthAwarePaginator $paginator, callable $transform, array $extra = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['items' => $paginator->getCollection()->map($transform)->values(), ...$extra], 'meta' => [
            'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
        ]]);
    }
}
