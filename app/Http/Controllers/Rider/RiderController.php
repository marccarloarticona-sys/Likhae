<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Auth\RegistrationApplication;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Rider\RiderProfile;
use App\Models\Rider\RiderProfileUpdateRequest;
use App\Models\Buyer\Address;
use App\Models\Finance\CodCollection;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\RiderEarningPool;
use App\Models\Finance\RiderEarningPoolParticipant;
use App\Models\Finance\RiderRemittance;
use App\Models\Communication\Conversation;
use App\Models\Logistics\Shipment;
use App\Models\User;
use App\Services\Account\ProfilePhotoService;
use App\Services\Communication\ConversationService;
use App\Services\Communication\ConversationMessageSender;
use App\Services\Communication\MessageAttachmentService;
use App\Services\Communication\ConversationContextPresenter;
use App\Support\PhilippineAddressValidator;
use App\Support\AddressCoordinateValidator;
use App\Support\RiderVehicleType;
use App\Services\Maps\MapDataService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\RiderAssignmentOfferExpiryService;
use App\Services\Finance\FinanceSchemaReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RiderController extends Controller
{
    public function updateAvailability(Request $request, LogisticsSchemaReadiness $schema): RedirectResponse
    {
        abort_unless($schema->riderAvailabilityReady(), 503, 'Rider availability controls are temporarily unavailable until the Rider availability schema is installed.');
        $data = $request->validate([
            'is_online' => ['required', 'boolean'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ]);
        $rider = $this->rider($request);
        abort_unless(RiderVehicleType::isLocalBarangay($rider->vehicle_type), 403);

        $availability = DB::transaction(function () use ($rider, $data): ?bool {
            $lockedRider = RiderProfile::query()
                ->whereKey($rider->id)
                ->with('user')
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedRider->status, ['ACTIVE', 'WARNED'], true)
                || $lockedRider->user?->status !== User::STATUS_ACTIVE) {
                return null;
            }

            $online = (bool) $data['is_online'];
            $lockedRider->update([
                'is_online' => $online,
                'availability_latitude' => $online ? ($data['latitude'] ?? null) : null,
                'availability_longitude' => $online ? ($data['longitude'] ?? null) : null,
                'availability_location_updated_at' => $online && isset($data['latitude'], $data['longitude']) ? now() : null,
            ]);

            return $online;
        });

        if ($availability === null) {
            return back()->withErrors(['is_online' => 'Only active riders can change their availability.']);
        }

        return back()->with('status', $availability ? 'You are now online and available for assignments.' : 'You are now offline. Existing parcels remain assigned to you.');
    }

    public function updateAvailabilityLocation(Request $request, LogisticsSchemaReadiness $schema): JsonResponse|RedirectResponse
    {
        abort_unless($schema->riderAvailabilityReady(), 503, 'Rider availability location is unavailable until the Rider availability schema is installed.');
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $rider = $this->rider($request);
        abort_unless(RiderVehicleType::isLocalBarangay($rider->vehicle_type), 403);
        abort_unless($rider->is_online && in_array($rider->status, ['ACTIVE', 'WARNED'], true), 409, 'Go online before updating dispatch location.');

        $rider->update([
            'availability_latitude' => $data['latitude'],
            'availability_longitude' => $data['longitude'],
            'availability_location_updated_at' => now(),
        ]);

        return $request->expectsJson()
            ? response()->json(['success' => true, 'updated_at' => $rider->availability_location_updated_at?->toIso8601String()])
            : back()->with('status', 'Your dispatch location was updated.');
    }

    public function dashboard(Request $request, LogisticsSchemaReadiness $schema, RiderAssignmentOfferExpiryService $offers): View|RedirectResponse
    {
        $rider = $this->rider($request);
        if (RiderVehicleType::isInterLogistics($rider->vehicle_type)) {
            return redirect()->route('rider.transfers');
        }
        abort_unless(RiderVehicleType::isLocalBarangay($rider->vehicle_type), 403);
        $offers->expireDuePickupOffers((int) $rider->id);

        $assignments = RiderAssignment::query()->where('rider_profile_id', $rider->id);
        $finance = $this->financeSummary($rider, app(FinanceSchemaReadiness::class));
        $pendingPickupCount = $schema->sellerPickupBatchesReady()
            ? RiderLocalTrip::query()->where('rider_profile_id', $rider->id)
                ->where('trip_type', RiderLocalTrip::TYPE_PICKUP)
                ->whereNotNull('seller_profile_id')
                ->whereIn('status', [RiderLocalTrip::LOADING, RiderLocalTrip::READY, RiderLocalTrip::IN_PROGRESS])
                ->count()
            : (clone $assignments)->pickup()->whereIn('status', ['ASSIGNED', 'ACCEPTED'])->count();
        $riderStats = [
            ['label' => 'Pending pickups', 'value' => $pendingPickupCount],
            ['label' => 'Pending deliveries', 'value' => (clone $assignments)->delivery()->whereIn('status', ['ASSIGNED', 'ACCEPTED'])
                ->count()],
            ['label' => 'In progress', 'value' => (clone $assignments)->where('status', 'IN_PROGRESS')->count()],
            ['label' => 'COD unremitted', 'value' => 'PHP '.number_format($finance['unremitted_cash'], 2)],
        ];
        $recentAssignments = (clone $assignments)
            ->delivery()
            ->with(['shipment.orderItem.product.images', 'shipment.sellerOrder.order.address'])
            ->latest()
            ->limit(8)
            ->get();
        $recentDeliveries = $recentAssignments->map(function (RiderAssignment $assignment): array {
            $shipment = $assignment->shipment;
            $sellerOrder = $shipment?->sellerOrder;
            $address = $sellerOrder?->order?->address;
            $images = $shipment?->orderItem?->product?->images ?? collect();
            $imagePath = $images->firstWhere('is_primary', true)?->file_path ?? $images->first()?->file_path;

            return [
                'image' => $imagePath
                    ? Storage::url($imagePath)
                    : asset('images/product-placeholder.svg'),
                'tracking' => $shipment?->tracking_number ?? 'Tracking unavailable',
                'buyer' => $address?->recipient_name ?? 'Buyer unavailable',
                'address' => $address?->formatted() ?? 'Address unavailable',
                'status_label' => str($shipment?->current_status ?? $assignment->status)->headline(),
            ];
        });

        return view('rider.dashboard', [
            'rider' => $rider,
            'riderStats' => $riderStats,
            'recentDeliveries' => $recentDeliveries,
            'financeSummary' => $finance,
        ]);
    }

    public function shipments(Request $request): View
    {
        $rider = $this->rider($request);

        $assignments = RiderAssignment::query()
            ->where('rider_profile_id', $rider->id)
            ->where('assignment_type', RiderAssignment::TYPE_DELIVERY)
            ->with(['shipment.sellerOrder.order.address', 'shipment.sellerOrder.sellerProfile.user'])
            ->latest()
            ->paginate(15);

        return view('rider.shipments', compact('assignments', 'rider'));
    }

    public function pickups(Request $request): View
    {
        $rider = $this->rider($request);

        $pickupQuery = RiderAssignment::query()->where('rider_profile_id', $rider->id)->pickup();
        $assignments = RiderAssignment::query()
            ->where('rider_profile_id', $rider->id)
            ->pickup()
            ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
            ->with(['shipment.sellerOrder.items.product.images', 'shipment.sellerOrder.order.address', 'shipment.sellerOrder.sellerProfile.user'])
            ->latest()
            ->paginate(15);
        $pickups = $assignments->getCollection()->map(function (RiderAssignment $assignment): array {
            $shipment = $assignment->shipment;
            $sellerOrder = $shipment?->sellerOrder;

            return [
                'id' => $assignment->id,
                'image' => $this->assignmentImage($assignment),
                'tracking' => $shipment?->tracking_number ?? 'Tracking unavailable',
                'seller' => $sellerOrder?->sellerProfile?->business_name ?? 'Seller unavailable',
                'address' => $sellerOrder?->sellerProfile?->businessAddress?->formatted()
                    ?? $sellerOrder?->order?->address?->formatted()
                    ?? 'Address unavailable',
                'items' => (int) ($sellerOrder?->items?->sum('quantity') ?? 0),
                'status_label' => str($assignment->status)->headline(),
            ];
        });
        $pickupStats = [
            'ready' => (clone $pickupQuery)->where('status', 'ASSIGNED')->count(),
            'accepted' => (clone $pickupQuery)->whereIn('status', ['ACCEPTED', 'IN_PROGRESS'])->count(),
            'picked_up' => (clone $pickupQuery)->where('status', 'COMPLETED')->count(),
        ];

        return view('rider.pickups.index', compact('pickups', 'pickupStats', 'assignments', 'rider'));
    }

    public function pickupShow(Request $request, RiderAssignment $assignment, LogisticsSchemaReadiness $schema, RiderAssignmentOfferExpiryService $offers): RedirectResponse
    {
        $rider = $this->rider($request);
        abort_unless((int) $assignment->rider_profile_id === (int) $rider->id && $assignment->assignment_type === 'PICKUP', 403);
        if ($offers->isExpired($assignment) && $offers->expireDue($assignment)) {
            return redirect()->route('rider.pickups')->with('status', 'This pickup offer expired and was returned to Logistics for reassignment. Refresh Pickups for current work.');
        }
        if ($offers->wasExpired($assignment)) {
            return redirect()->route('rider.pickups')->with('status', 'This pickup offer expired and was returned to Logistics for reassignment. Refresh Pickups for current work.');
        }
        abort_unless($schema->sellerPickupBatchesReady(), 503, 'Seller pickup batch workflow is unavailable until its additive schema migration is installed.');

        $pickupTrip = $assignment->localTripItems()->with('trip')->latest('id')->first()?->trip;
        abort_unless(
            $pickupTrip?->trip_type === RiderLocalTrip::TYPE_PICKUP && filled($pickupTrip->seller_profile_id),
            409,
            'This parcel is not part of a seller-specific pickup batch.'
        );

        return redirect()->route('rider.pickups.batches.show', $pickupTrip->id);
    }

    public function deliveries(Request $request): RedirectResponse
    {
        return redirect()->route('rider.trips');
    }

    public function deliveryShow(Request $request, RiderAssignment $assignment): View|RedirectResponse
    {
        $rider = $this->rider($request);
        abort_unless((int) $assignment->rider_profile_id === (int) $rider->id && $assignment->assignment_type === 'DELIVERY', 403);

        $assignment->load(['shipment.sellerOrder.items.product.images', 'shipment.sellerOrder.order.address', 'shipment.sellerOrder.order.payments', 'shipment.sellerOrder.sellerProfile.user', 'shipment.sellerOrder.sellerProfile.businessAddress', 'shipment.deliveryAttempts', 'shipment.riderAssignments.liveLocation', 'shipment.logisticsCenter.address', 'shipment.codCollection', 'liveLocation']);
        $deliveryTrip = $assignment->localTripItems()
            ->with('trip')
            ->whereHas('trip', fn ($query) => $query->where('trip_type', RiderLocalTrip::TYPE_DELIVERY))
            ->latest('id')
            ->first()?->trip;

        $shipment = $assignment->shipment;
        $order = $shipment->sellerOrder->order;
        $parcel = [
            'tracking' => $shipment->tracking_number,
            'status' => $assignment->status,
            'status_label' => str($assignment->status)->replace('_', ' ')->upper()->toString(),
            'shipment_status' => $shipment->current_status,
            'shipment_status_label' => str($shipment->current_status)->replace('_', ' ')->upper()->toString(),
            'buyer' => $order->address->recipient_name,
            'contact' => $order->address->contact_number,
            'address' => $order->address->formatted(),
            'amount' => '₱'.number_format((float) $shipment->sellerOrder->grand_total, 2),
            'cod_expected' => number_format((float) ($shipment->codCollection?->expected_amount ?? $shipment->sellerOrder->grand_total), 2, '.', ''),
            'has_buyer_pin' => app(MapDataService::class)->addressMarker($order->address, 'order-address-'.$order->address->id, 'Buyer destination') !== null,
        ];
        $delivery = $assignment;

        // Delivery navigation needs only the rider and the saved buyer address.
        $mapMarkers = app(MapDataService::class)->forShipment($shipment, true, false, $request->user());
        $returnHandoffPending = app(\App\Services\Fulfillment\ShipmentReturnWorkflowService::class)->hasOpenWorkflow($shipment);
        return view('rider.deliveries.show', compact('assignment', 'rider', 'parcel', 'delivery', 'deliveryTrip', 'mapMarkers', 'returnHandoffPending'));
    }

    public function earnings(Request $request, \App\Services\Finance\EarningPayoutService $payouts, \App\Services\Finance\PayoutDestinationReadiness $destinations): View
    {
        $rider = $this->rider($request);
        $financeSchema = app(FinanceSchemaReadiness::class);
        $filters = $this->dateRange($request);
        $from = filled($filters['from']) ? \Illuminate\Support\Carbon::parse($filters['from'])->startOfDay() : null;
        $to = filled($filters['to']) ? \Illuminate\Support\Carbon::parse($filters['to'])->endOfDay() : null;
        $weekStart = now()->startOfWeek();
        $today = today();
        $eligiblePayoutParticipants = collect();
        $riderPayoutReceipts = collect();

        if ($financeSchema->step7Ready()) {
            $eligiblePayoutParticipants = $payouts->eligibleRiderParticipants($rider);
            $riderPayoutReceipts = $payouts->riderPayoutReceipts($rider);
            $eligiblePayoutIds = $eligiblePayoutParticipants->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $participantBase = RiderEarningPoolParticipant::query()->where('rider_profile_id', $rider->id);
            $filteredParticipants = clone $participantBase;
            if ($from) $filteredParticipants->whereRaw('COALESCE(approved_at, created_at) >= ?', [$from]);
            if ($to) $filteredParticipants->whereRaw('COALESCE(approved_at, created_at) <= ?', [$to]);

            $earnings = (clone $filteredParticipants)
                ->with(['pool.shipment', 'pool.allocation', 'assignment.shipment'])
                ->latest()
                ->paginate(15)
                ->withQueryString();
            $pageParticipantIds = $earnings->getCollection()->pluck('id')->map(fn ($id): int => (int) $id);
            $simulatedPayoutParticipantIds = $financeSchema->parcelSettlementReady() && $pageParticipantIds->isNotEmpty()
                ? FinancialLedgerEntry::query()
                    ->where('entry_type', 'RIDER_PAYOUT')
                    ->whereIn('idempotency_key', $pageParticipantIds->map(fn (int $id): string => 'rider-payout-'.$id))
                    ->get(['metadata'])
                    ->filter(fn (FinancialLedgerEntry $entry): bool => (bool) data_get($entry->metadata, 'is_simulated')
                        || str_starts_with((string) data_get($entry->metadata, 'payout_external_reference'), 'SIM-'))
                    ->map(fn (FinancialLedgerEntry $entry): int => (int) data_get($entry->metadata, 'participant_id'))
                    ->all()
                : [];
            $earningsRows = $earnings->getCollection()->map(function (RiderEarningPoolParticipant $participant) use ($eligiblePayoutIds, $simulatedPayoutParticipantIds): array {
                $allocation = $participant->pool?->allocation;
                $assignment = $participant->assignment;

                return [
                    'participant_id' => (int) $participant->id,
                    'can_request' => in_array((int) $participant->id, $eligiblePayoutIds, true),
                    'is_simulated' => in_array((int) $participant->id, $simulatedPayoutParticipantIds, true),
                    'date' => ($participant->approved_at ?? $participant->created_at)?->format('M d, Y') ?? 'Date unavailable',
                    'reference' => $participant->pool?->shipment?->tracking_number ?? 'Pool #'.$participant->rider_earning_pool_id,
                    'base_shipping' => $allocation ? 'PHP '.number_format((float) $allocation->shipping_fee, 2) : '—',
                    'additional_care' => $allocation ? 'PHP '.number_format((float) ($allocation->protection_amount ?? 0), 2) : '—',
                    'combined_delivery_earnings' => $allocation ? 'PHP '.number_format((float) $allocation->shipping_fee + (float) ($allocation->protection_amount ?? 0), 2) : '—',
                    'delivery_role' => str($participant->participant_role)->replace('_', ' ')->headline(),
                    'assignment_status' => str($assignment?->status ?? 'ASSIGNMENT UNAVAILABLE')->replace('_', ' ')->headline(),
                    'amount' => $participant->share_amount === null ? 'Pending allocation' : 'PHP '.number_format((float) $participant->share_amount, 2),
                    'status' => in_array((int) $participant->id, $simulatedPayoutParticipantIds, true)
                        ? 'SIMULATED — NOT PAID'
                        : str($participant->status)->headline(),
                ];
            });
            $earningsTotal = (float) (clone $filteredParticipants)->sum('share_amount');
            $dailyEarnings = (float) (clone $participantBase)->whereRaw('DATE(COALESCE(approved_at, created_at)) = ?', [$today->toDateString()])->sum('share_amount');
            $weeklyEarnings = (float) (clone $participantBase)->whereRaw('COALESCE(approved_at, created_at) BETWEEN ? AND ?', [$weekStart, now()])->sum('share_amount');
            $realRiderPayouts = FinancialLedgerEntry::query()
                ->where('entry_type', 'RIDER_PAYOUT')
                ->where('metadata->rider_profile_id', $rider->id)
                ->whereNotNull('paid_at')
                ->whereNotNull('metadata->payout_external_reference')
                ->whereNotNull('metadata->payout_proof_path')
                ->where(fn ($query) => $query->whereNull('metadata->is_simulated')->orWhere('metadata->is_simulated', false))
                ->where(fn ($query) => $query->whereNull('metadata->payout_external_reference')->orWhere('metadata->payout_external_reference', 'not like', 'SIM-%'));
            $poolSummary = [
                'schema_ready' => true,
                'unallocated' => (float) RiderEarningPool::query()->whereHas('participants', fn ($query) => $query->where('rider_profile_id', $rider->id))->sum('unresolved_share_amount'),
                'accrued' => $earningsTotal,
                'available' => (float) $eligiblePayoutParticipants->sum('share_amount'),
                'requested' => (float) RiderEarningPoolParticipant::query()->where('rider_profile_id', $rider->id)->where('status', 'PAYOUT_REQUESTED')->sum('share_amount'),
                'awaiting_ack' => (float) (clone $realRiderPayouts)->where('status', 'PAYOUT_PENDING_ACK')->sum('amount'),
                'paid' => (float) (clone $realRiderPayouts)->where('status', 'PAID')->whereNotNull('metadata->acknowledged_at')->sum('amount'),
                'paid_out' => (float) (clone $realRiderPayouts)
                    ->where(fn ($query) => $query->where('status', 'PAYOUT_PENDING_ACK')->orWhere(fn ($paid) => $paid->where('status', 'PAID')->whereNotNull('metadata->acknowledged_at')))
                    ->sum('amount'),
            ];
        } else {
            $legacyBase = $rider->earnings();
            $filteredLegacy = clone $legacyBase;
            if ($from) $filteredLegacy->whereRaw('COALESCE(earned_at, created_at) >= ?', [$from]);
            if ($to) $filteredLegacy->whereRaw('COALESCE(earned_at, created_at) <= ?', [$to]);
            $earnings = (clone $filteredLegacy)->with('riderAssignment.shipment')->latest()->paginate(15)->withQueryString();
            $earningsRows = $earnings->getCollection()->map(fn ($earning): array => [
                'date' => $earning->earned_at?->format('M d, Y') ?? $earning->created_at?->format('M d, Y') ?? 'Date unavailable',
                'reference' => $earning->riderAssignment?->shipment?->tracking_number ?? 'Assignment #'.$earning->rider_assignment_id,
                'base_shipping' => '—',
                'additional_care' => '—',
                'combined_delivery_earnings' => '—',
                'delivery_role' => 'Delivery',
                'assignment_status' => str($earning->riderAssignment?->status ?? 'ASSIGNMENT UNAVAILABLE')->replace('_', ' ')->headline(),
                'amount' => 'PHP '.number_format((float) $earning->amount, 2),
                'status' => str($earning->status)->headline(),
            ]);
            $earningsTotal = (float) (clone $filteredLegacy)->sum('amount');
            $dailyEarnings = (float) (clone $legacyBase)->whereRaw('DATE(COALESCE(earned_at, created_at)) = ?', [$today->toDateString()])->sum('amount');
            $weeklyEarnings = (float) (clone $legacyBase)->whereRaw('COALESCE(earned_at, created_at) BETWEEN ? AND ?', [$weekStart, now()])->sum('amount');
            $poolSummary = [
                'schema_ready' => false,
                'unallocated' => 0.0,
                'accrued' => $earningsTotal,
                'available' => 0.0,
                'requested' => 0.0,
                'awaiting_ack' => 0.0,
                'paid' => (float) $rider->earnings()->where('status', 'PAID')->sum('amount'),
                'paid_out' => (float) $rider->earnings()->where('status', 'PAID')->sum('amount'),
            ];
        }
        $earningsNotice = 'Cash held and rider earnings are separate. Cross-hub pool shares remain pending until policy approval.';
        $financeSummary = $this->financeSummary($rider, $financeSchema);
        $payoutDestinationReady = $destinations->readyFor('rider_profiles');
        $payoutDestinationSaved = $payoutDestinationReady && $destinations->hasDestination($rider);

        return view('rider.earnings.index', compact('rider', 'earnings', 'earningsRows', 'earningsTotal', 'earningsNotice', 'financeSummary', 'poolSummary', 'filters', 'dailyEarnings', 'weeklyEarnings', 'eligiblePayoutParticipants', 'riderPayoutReceipts', 'payoutDestinationReady', 'payoutDestinationSaved'));
    }

    public function history(Request $request): View
    {
        $rider = $this->rider($request);
        $filters = $this->dateRange($request);
        $from = filled($filters['from']) ? \Illuminate\Support\Carbon::parse($filters['from'])->startOfDay() : null;
        $to = filled($filters['to']) ? \Illuminate\Support\Carbon::parse($filters['to'])->endOfDay() : null;

        $assignmentQuery = RiderAssignment::query()
            ->where('rider_profile_id', $rider->id)
            ->where(function ($query): void {
                $query->whereIn('status', ['COMPLETED', 'REJECTED', 'CANCELLED'])
                    ->orWhereHas('deliveryAttempts');
            })
            ->with(['shipment.sellerOrder.items.product.images', 'shipment.sellerOrder.order.address', 'deliveryAttempts', 'earning']);
        if ($from || $to) {
            $assignmentQuery->where(function ($query) use ($from, $to): void {
                $query->where(function ($dates) use ($from, $to): void {
                    if ($from) $dates->whereRaw('COALESCE(completed_at, cancelled_at, updated_at, created_at) >= ?', [$from]);
                    if ($to) $dates->whereRaw('COALESCE(completed_at, cancelled_at, updated_at, created_at) <= ?', [$to]);
                })->orWhereHas('deliveryAttempts', function ($attempts) use ($from, $to): void {
                    if ($from) $attempts->where('attempted_at', '>=', $from);
                    if ($to) $attempts->where('attempted_at', '<=', $to);
                });
            });
        }
        $assignments = $assignmentQuery->latest()->paginate(15)->withQueryString();
        $poolShares = app(FinanceSchemaReadiness::class)->step7Ready()
            ? RiderEarningPoolParticipant::query()
                ->whereIn('rider_assignment_id', $assignments->getCollection()->modelKeys())
                ->get()
                ->keyBy('rider_assignment_id')
            : collect();
        $history = $assignments->getCollection()->map(function (RiderAssignment $assignment) use ($poolShares): array {
            $shipment = $assignment->shipment;
            $attempt = $assignment->deliveryAttempts->sortByDesc('attempt_number')->first();
            $poolShare = $poolShares->get($assignment->id);

            return [
                'image' => $this->assignmentImage($assignment),
                'tracking' => $shipment?->tracking_number ?? 'Tracking unavailable',
                'buyer' => $shipment?->sellerOrder?->order?->address?->recipient_name ?? 'Buyer unavailable',
                'job_type' => str($assignment->assignment_type)->headline()->toString(),
                'status_label' => $attempt
                    ? str($attempt->status)->headline()
                    : str($shipment?->current_status ?? $assignment->status)->headline(),
                'updated' => ($attempt?->attempted_at ?? $assignment->completed_at ?? $assignment->updated_at)?->format('M d, Y g:i A') ?? 'Date unavailable',
                'failure_reason' => $attempt?->failure_reason,
                'earning' => $poolShare?->share_amount !== null
                    ? 'PHP '.number_format((float) $poolShare->share_amount, 2)
                    : ($assignment->earning ? 'PHP '.number_format((float) $assignment->earning->amount, 2) : 'Pending allocation'),
            ];
        });

        return view('rider.history.index', compact('rider', 'history', 'assignments', 'filters'));
    }

    public function profile(Request $request): View
    {
        $rider = $this->rider($request)->load(['user', 'logisticsCenter', 'areaAssignments.serviceArea.locations']);
        $residentialAddress = $rider->user
            ?->addresses()
            ?->orderByDesc('is_default')
            ?->latest()
            ?->first();
        $riderApplication = RegistrationApplication::query()
            ->where('user_id', $rider->user_id)
            ->whereHas('riderData')
            ->with(['documents:id,registration_application_id,document_type,verification_status,verified_at'])
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->first();
        $riderDocuments = $riderApplication?->documents ?? collect();
        $profileUpdateSchemaReady = Schema::hasTable('rider_profile_update_requests')
            && Schema::hasTable('rider_profile_update_documents');
        $profileUpdateRequests = $profileUpdateSchemaReady
            ? $rider->profileUpdateRequests()->with('documents')->limit(5)->get()
            : collect();

        return view('rider.profile.index', compact(
            'rider',
            'residentialAddress',
            'riderDocuments',
            'profileUpdateRequests',
            'profileUpdateSchemaReady',
        ));
    }

    public function updateAccount(Request $request, ProfilePhotoService $profilePhotos): RedirectResponse
    {
        $user = $this->rider($request)->user;
        $data = $request->validate([
            'contact_number' => ['required', 'string', 'max:30', Rule::unique('users', 'contact_number')->ignore($user->id)],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $hasNewPhoto = $request->hasFile('profile_photo');
        unset($data['profile_photo']);
        $user->update(['contact_number' => $data['contact_number']]);

        if ($hasNewPhoto) {
            $profilePhotos->replace($user, $request->file('profile_photo'));
        }

        return back()->with('status', 'Account profile updated.');
    }

    public function submitProfileUpdate(Request $request): RedirectResponse
    {
        abort_unless(
            Schema::hasTable('rider_profile_update_requests') && Schema::hasTable('rider_profile_update_documents'),
            503,
            'Vehicle and document updates are temporarily unavailable until the profile review workflow is installed.',
        );

        $rider = $this->rider($request);
        $data = $request->validate([
            'vehicle_type' => ['required', Rule::in(RiderVehicleType::acceptedValues())],
            'plate_number' => ['required', 'string', 'max:50', Rule::unique('rider_profiles', 'plate_number')->ignore($rider->id)],
            'drivers_license_number' => ['required', 'string', 'max:100', Rule::unique('rider_profiles', 'drivers_license_number')->ignore($rider->id)],
            'vehicle_or_cr' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'drivers_license' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $requestedVehicleType = strtolower(trim($data['vehicle_type']));
        $requestedPlate = mb_strtoupper(trim($data['plate_number']));
        $requestedLicense = mb_strtoupper(trim($data['drivers_license_number']));
        $vehicleChanged = $requestedVehicleType !== strtolower((string) $rider->vehicle_type)
            || $requestedPlate !== mb_strtoupper(trim((string) $rider->plate_number));
        $licenseChanged = $requestedLicense !== mb_strtoupper(trim((string) $rider->drivers_license_number));

        if ($vehicleChanged && ! $request->hasFile('vehicle_or_cr')) {
            throw ValidationException::withMessages([
                'vehicle_or_cr' => 'Upload the vehicle OR/CR when changing the vehicle type or plate number.',
            ]);
        }

        if ($licenseChanged && ! $request->hasFile('drivers_license')) {
            throw ValidationException::withMessages([
                'drivers_license' => 'Upload a new driver’s license document when changing the license number.',
            ]);
        }

        if (! $vehicleChanged && ! $licenseChanged && ! $request->hasFile('vehicle_or_cr') && ! $request->hasFile('drivers_license')) {
            throw ValidationException::withMessages([
                'profile_update' => 'Change a vehicle or license detail, or upload a replacement document before submitting.',
            ]);
        }

        $files = collect([
            'vehicle_or_cr' => ['type' => 'VEHICLE_OR_CR', 'file' => $request->file('vehicle_or_cr')],
            'drivers_license' => ['type' => 'DRIVERS_LICENSE', 'file' => $request->file('drivers_license')],
        ])->filter(fn (array $item): bool => $item['file'] !== null);
        $storedPaths = [];

        try {
            DB::transaction(function () use ($rider, $request, $files, &$storedPaths, $requestedVehicleType, $requestedPlate, $requestedLicense): void {
                $lockedRider = RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();
                if ($lockedRider->profileUpdateRequests()->where('status', 'PENDING')->exists()) {
                    throw ValidationException::withMessages([
                        'profile_update' => 'A vehicle or document update is already awaiting Logistics review.',
                    ]);
                }

                $update = $lockedRider->profileUpdateRequests()->create([
                    'submitted_by_user_id' => $request->user()->id,
                    'vehicle_type' => $requestedVehicleType,
                    'plate_number' => $requestedPlate,
                    'drivers_license_number' => $requestedLicense,
                    'status' => 'PENDING',
                    'submitted_at' => now(),
                ]);

                foreach ($files as $input => $item) {
                    $file = $item['file'];
                    $path = $file->store('registration/rider-profile-updates/'.$lockedRider->id, 'registrations');
                    if (! is_string($path) || $path === '') {
                        throw new \RuntimeException('A profile document could not be stored.');
                    }
                    $storedPaths[] = $path;

                    $update->documents()->create([
                        'document_type' => $item['type'],
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'verification_status' => 'PENDING',
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('registrations')->delete($path);
            }

            throw $exception;
        }

        return back()->with('status', 'Vehicle and document updates were sent to Logistics for re-verification.');
    }

    public function updateResidentialAddress(Request $request): RedirectResponse
    {
        $rider = $this->rider($request);
        $data = $request->validate(PhilippineAddressValidator::rules() + AddressCoordinateValidator::rules());
        PhilippineAddressValidator::assertValid($data);
        AddressCoordinateValidator::assertValid($data);

        $address = $rider->user->addresses()->orderByDesc('is_default')->latest()->first()
            ?: new Address([
                'user_id' => $rider->user_id,
                'label' => 'Rider residence',
                'is_default' => true,
            ]);
        AddressCoordinateValidator::assertFreshForAddress($address->exists ? $address : null, $data);

        $address->fill([
            'label' => $address->label ?: 'Rider residence',
            'recipient_name' => $rider->user->name,
            'contact_number' => $rider->user->contact_number,
            ...PhilippineAddressValidator::storageAttributes($data),
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
        ]);
        $address->save();

        return back()->with('status', 'Registered residential address updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $this->rider($request)->user;
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        return back()->with('status', 'Account password updated.');
    }

    public function messages(Request $request, ConversationService $conversationService): View|RedirectResponse
    {
        $shipmentId = $request->integer('shipment_id');
        if ($shipmentId > 0) {
            $riderProfile = $request->user()->riderProfile;
            $shipment = Shipment::query()
                ->with(['sellerOrder.order'])
                ->whereKey($shipmentId)
                ->whereHas('riderAssignments', fn ($assignment) => $assignment
                    ->where('rider_profile_id', $riderProfile?->id)
                    ->where('assignment_type', RiderAssignment::TYPE_DELIVERY)
                    ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS', 'COMPLETED']))
                ->firstOrFail();
            $buyerId = (int) $shipment->sellerOrder?->order?->buyer_user_id;
            abort_unless($buyerId > 0, 404);
            if ($request->integer('recipient_user_id') > 0) {
                abort_unless($request->integer('recipient_user_id') === $buyerId, 403);
            }

            $conversation = $conversationService->start($request->user(), $buyerId, [
                'type' => 'SHIPMENT_DELIVERY',
                'order_id' => $shipment->sellerOrder?->order_id,
                'seller_order_id' => $shipment->seller_order_id,
                'shipment_id' => $shipment->id,
            ]);

            return redirect()->route('rider.messages', ['conversation' => $conversation->id]);
        }

        $conversations = $conversationService->workspaceThreads(
            $request->user(),
            $request->integer('conversation') ?: null,
        );
        $contacts = User::query()->where('status', 'ACTIVE')->whereKeyNot($request->user()->id)
            ->orderBy('first_name')->limit(100)
            ->get(['id', 'first_name', 'middle_initial', 'last_name', 'name_extension', 'account_type']);
        $contacts = app(\App\Services\Communication\ConversationStartAuthorizationService::class)
            ->filterRecipients($request->user(), $contacts);
        $activeConversation = $conversations->firstWhere('id', $request->integer('conversation'));
        $activeRecipient = $activeConversation?->participants?->first(fn ($participant) => (int) $participant->id !== (int) $request->user()->id);
        $smartHelpBootstrap = $activeConversation && $activeRecipient?->isAccountType(User::TYPE_ADMIN)
            ? app(\App\Services\Communication\SmartMessagingService::class)->bootstrapForRequester($request->user(), $activeConversation)
            : null;

        return view('rider.messages', [
            'conversations' => $conversations,
            'contacts' => $contacts,
            'messageAttachmentsReady' => app(MessageAttachmentService::class)->available(),
            'messageContext' => app(ConversationContextPresenter::class)->forUser(
                $request->user(),
                $conversations->firstWhere('id', $request->integer('conversation')),
            ),
            'smartHelpBootstrap' => $smartHelpBootstrap,
        ]);
    }

    public function messageStream(Request $request, ConversationService $conversationService): JsonResponse
    {
        return response()->json([
            'success' => true,
            'messages' => $conversationService->streamPayload(
                $request->user(),
                $request->integer('conversation'),
                $request->integer('after_id') ?: null,
            ),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function sendMessage(Request $request, ConversationService $conversationService, ConversationMessageSender $sender): JsonResponse|RedirectResponse
    {
        $recipientData = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $data = $recipientData + $sender->validate($request);
        $recipient = User::query()->whereKey($data['recipient_user_id'])->where('status', User::STATUS_ACTIVE)->firstOrFail();
        $message = $sender->send($request, $data, $recipient, $conversationService);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $conversationService->messagePayload($message),
            ]);
        }

        return back()->with('status', 'Message sent.');
    }

    /** @return array{from: string, to: string} */
    private function dateRange(Request $request): array
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $from = (string) ($filters['from'] ?? '');
        $to = (string) ($filters['to'] ?? '');

        if ($from !== '' && $to !== '' && $to < $from) {
            throw ValidationException::withMessages(['to' => 'The end date must be on or after the start date.']);
        }

        return ['from' => $from, 'to' => $to];
    }

    private function rider(Request $request)
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider, 403);

        return $rider;
    }

    /** @return array<string, float|int> */
    private function financeSummary($rider, FinanceSchemaReadiness $schema): array
    {
        if (! $schema->step6Ready()) {
            return ['cod_collected' => 0.0, 'unremitted_cash' => 0.0, 'remittances_submitted' => 0.0, 'verified_cash' => 0.0, 'discrepancies' => 0];
        }

        $collections = $rider->codCollections();
        $remittances = $rider->remittances();

        return [
            'cod_collected' => (float) (clone $collections)->whereIn('status', [CodCollection::COLLECTED, CodCollection::REMITTED, CodCollection::DISCREPANCY])->sum('collected_amount'),
            'unremitted_cash' => (float) (clone $collections)->where('status', CodCollection::COLLECTED)->whereDoesntHave('remittanceItem')->sum('expected_amount'),
            'remittances_submitted' => (float) (clone $remittances)->whereIn('status', [RiderRemittance::SUBMITTED, RiderRemittance::VERIFIED, RiderRemittance::RECONCILED, RiderRemittance::DISCREPANCY])->sum('submitted_amount'),
            'verified_cash' => (float) (clone $remittances)->whereIn('status', [RiderRemittance::VERIFIED, RiderRemittance::RECONCILED, RiderRemittance::DISCREPANCY])->sum('verified_amount'),
            'discrepancies' => (int) (clone $remittances)->where('status', RiderRemittance::DISCREPANCY)->count(),
        ];
    }

    private function assignmentImage(RiderAssignment $assignment): string
    {
        $images = $assignment->shipment?->orderItem?->product?->images ?? collect();
        $imagePath = $images->firstWhere('is_primary', true)?->file_path ?? $images->first()?->file_path;

        return $imagePath ? Storage::url($imagePath) : asset('images/product-placeholder.svg');
    }
}
