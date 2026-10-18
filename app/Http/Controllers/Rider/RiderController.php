<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderLocalTrip;
use App\Models\Buyer\Address;
use App\Models\Finance\CodCollection;
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
use App\Services\Finance\FinanceSchemaReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RiderController extends Controller
{
    public function updateAvailability(Request $request): RedirectResponse
    {
        $data = $request->validate(['is_online' => ['required', 'boolean']]);
        $rider = $this->rider($request);
        abort_unless(RiderVehicleType::isLocalBarangay($rider->vehicle_type), 403);

        if (! in_array($rider->status, ['ACTIVE', 'WARNED'], true)
            || $rider->user?->status !== User::STATUS_ACTIVE) {
            return back()->withErrors(['is_online' => 'Only active riders can change their availability.']);
        }

        $rider->update(['is_online' => (bool) $data['is_online']]);

        return back()->with('status', $rider->is_online ? 'You are now online and available for assignments.' : 'You are now offline.');
    }

    public function dashboard(Request $request, LogisticsSchemaReadiness $schema): View|RedirectResponse
    {
        $rider = $this->rider($request);
        if (RiderVehicleType::isInterLogistics($rider->vehicle_type)) {
            return redirect()->route('rider.transfers');
        }
        abort_unless(RiderVehicleType::isLocalBarangay($rider->vehicle_type), 403);

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
                ->where(fn ($query) => $query->where('status', '<>', 'ASSIGNED')->orWhereNull('assigned_at')->orWhere('assigned_at', '>', $this->offerCutoff()))
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

        return view('Rider.dashboard', [
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

        return view('Rider.shipments', compact('assignments', 'rider'));
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

        return view('Rider.pickups.index', compact('pickups', 'pickupStats', 'assignments', 'rider'));
    }

    public function pickupShow(Request $request, RiderAssignment $assignment, LogisticsSchemaReadiness $schema): RedirectResponse
    {
        $rider = $this->rider($request);
        abort_unless((int) $assignment->rider_profile_id === (int) $rider->id && $assignment->assignment_type === 'PICKUP', 403);
        abort_unless($schema->sellerPickupBatchesReady(), 503, 'Seller pickup batch workflow is unavailable until its additive schema migration is installed.');

        $pickupTrip = $assignment->localTripItems()->with('trip')->latest('id')->first()?->trip;
        abort_unless(
            $pickupTrip?->trip_type === RiderLocalTrip::TYPE_PICKUP && filled($pickupTrip->seller_profile_id),
            409,
            'This parcel is not part of a seller-specific pickup batch.'
        );

        return redirect()->route('rider.pickups.batches.show', $pickupTrip->id);
    }

    public function deliveries(Request $request): View
    {
        $rider = $this->rider($request);

        $assignments = RiderAssignment::query()
            ->where('rider_profile_id', $rider->id)
            ->delivery()
            ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
            ->where(fn ($query) => $query->where('status', '<>', 'ASSIGNED')->orWhereNull('assigned_at')->orWhere('assigned_at', '>', $this->offerCutoff()))
            ->with(['shipment.sellerOrder.items.product.images', 'shipment.sellerOrder.order.address', 'shipment.sellerOrder.sellerProfile.user'])
            ->latest()
            ->paginate(15);
        $deliveries = $assignments->getCollection()->map(function (RiderAssignment $assignment): array {
            $shipment = $assignment->shipment;
            $order = $shipment?->sellerOrder?->order;

            return [
                'id' => $assignment->id,
                'image' => $this->assignmentImage($assignment),
                'tracking' => $shipment?->tracking_number ?? 'Tracking unavailable',
                'buyer' => $order?->address?->recipient_name ?? $order?->buyer?->name ?? 'Buyer unavailable',
                'address' => $order?->address?->formatted() ?? 'Address unavailable',
                'amount' => 'PHP '.number_format((float) ($shipment?->sellerOrder?->grand_total ?? 0), 2),
                'status_label' => str($shipment?->current_status ?? $assignment->status)->headline(),
            ];
        });
        $deliveryQuery = RiderAssignment::query()->where('rider_profile_id', $rider->id)->delivery();
        $deliveryStats = [
            'active' => (clone $deliveryQuery)->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
                ->where(fn ($query) => $query->where('status', '<>', 'ASSIGNED')->orWhereNull('assigned_at')->orWhere('assigned_at', '>', $this->offerCutoff()))
                ->count(),
            'assigned' => (clone $deliveryQuery)->whereIn('status', ['ASSIGNED', 'ACCEPTED'])
                ->where(fn ($query) => $query->where('status', '<>', 'ASSIGNED')->orWhereNull('assigned_at')->orWhere('assigned_at', '>', $this->offerCutoff()))
                ->count(),
            'out_for_delivery' => (clone $deliveryQuery)->where('status', 'IN_PROGRESS')->whereHas('shipment', fn ($query) => $query->where('current_status', 'OUT_FOR_DELIVERY'))->count(),
            'delivered_today' => (clone $deliveryQuery)->where('status', 'COMPLETED')->whereDate('completed_at', today())->count(),
            'failed_today' => (clone $deliveryQuery)->whereHas('deliveryAttempts', fn ($query) => $query->whereDate('attempted_at', today())->whereIn('status', ['FAILED', 'RESCHEDULED', 'RETURNED']))->count(),
        ];

        return view('Rider.deliveries.index', compact('deliveries', 'deliveryStats', 'assignments', 'rider'));
    }

    public function deliveryShow(Request $request, RiderAssignment $assignment): View
    {
        $rider = $this->rider($request);
        abort_unless((int) $assignment->rider_profile_id === (int) $rider->id && $assignment->assignment_type === 'DELIVERY', 403);

        $assignment->load(['shipment.sellerOrder.items.product.images', 'shipment.sellerOrder.order.address', 'shipment.sellerOrder.order.payments', 'shipment.sellerOrder.sellerProfile.user', 'shipment.sellerOrder.sellerProfile.businessAddress', 'shipment.deliveryAttempts', 'shipment.riderAssignments.liveLocation', 'shipment.logisticsCenter.address', 'shipment.codCollection', 'liveLocation']);

        $shipment = $assignment->shipment;
        $order = $shipment->sellerOrder->order;
        $parcel = [
            'tracking' => $shipment->tracking_number,
            'status' => $assignment->status,
            'offer_expired' => $assignment->status === 'ASSIGNED'
                && $assignment->assigned_at
                && $assignment->assigned_at->lte($this->offerCutoff()),
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
        return view('Rider.deliveries.show', compact('assignment', 'rider', 'parcel', 'delivery', 'mapMarkers'));
    }

    public function earnings(Request $request): View
    {
        $rider = $this->rider($request);
        $financeSchema = app(FinanceSchemaReadiness::class);
        $filters = $this->dateRange($request);
        $from = filled($filters['from']) ? \Illuminate\Support\Carbon::parse($filters['from'])->startOfDay() : null;
        $to = filled($filters['to']) ? \Illuminate\Support\Carbon::parse($filters['to'])->endOfDay() : null;
        $weekStart = now()->startOfWeek();
        $today = today();

        if ($financeSchema->step7Ready()) {
            $participantBase = RiderEarningPoolParticipant::query()->where('rider_profile_id', $rider->id);
            $filteredParticipants = clone $participantBase;
            if ($from) $filteredParticipants->whereRaw('COALESCE(approved_at, created_at) >= ?', [$from]);
            if ($to) $filteredParticipants->whereRaw('COALESCE(approved_at, created_at) <= ?', [$to]);

            $earnings = (clone $filteredParticipants)
                ->with(['pool.shipment', 'assignment.shipment'])
                ->latest()
                ->paginate(15)
                ->withQueryString();
            $earningsRows = $earnings->getCollection()->map(fn (RiderEarningPoolParticipant $participant): array => [
                'date' => ($participant->approved_at ?? $participant->created_at)?->format('M d, Y') ?? 'Date unavailable',
                'reference' => $participant->pool?->shipment?->tracking_number ?? 'Pool #'.$participant->rider_earning_pool_id,
                'amount' => $participant->share_amount === null ? 'Pending allocation' : 'PHP '.number_format((float) $participant->share_amount, 2),
                'status' => str($participant->status)->headline(),
            ]);
            $earningsTotal = (float) (clone $filteredParticipants)->sum('share_amount');
            $dailyEarnings = (float) (clone $participantBase)->whereRaw('DATE(COALESCE(approved_at, created_at)) = ?', [$today->toDateString()])->sum('share_amount');
            $weeklyEarnings = (float) (clone $participantBase)->whereRaw('COALESCE(approved_at, created_at) BETWEEN ? AND ?', [$weekStart, now()])->sum('share_amount');
            $poolSummary = [
                'schema_ready' => true,
                'unallocated' => (float) RiderEarningPool::query()->whereHas('participants', fn ($query) => $query->where('rider_profile_id', $rider->id))->sum('unresolved_share_amount'),
                'accrued' => $earningsTotal,
                'paid' => (float) RiderEarningPoolParticipant::query()->where('rider_profile_id', $rider->id)->where('status', 'PAID')->sum('share_amount'),
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
                'amount' => 'PHP '.number_format((float) $earning->amount, 2),
                'status' => str($earning->status)->headline(),
            ]);
            $earningsTotal = (float) (clone $filteredLegacy)->sum('amount');
            $dailyEarnings = (float) (clone $legacyBase)->whereRaw('DATE(COALESCE(earned_at, created_at)) = ?', [$today->toDateString()])->sum('amount');
            $weeklyEarnings = (float) (clone $legacyBase)->whereRaw('COALESCE(earned_at, created_at) BETWEEN ? AND ?', [$weekStart, now()])->sum('amount');
            $poolSummary = ['schema_ready' => false, 'unallocated' => 0.0, 'accrued' => $earningsTotal, 'paid' => (float) $rider->earnings()->where('status', 'PAID')->sum('amount')];
        }
        $earningsNotice = 'Cash held and rider earnings are separate. Cross-hub pool shares remain pending until policy approval.';
        $financeSummary = $this->financeSummary($rider, $financeSchema);

        return view('Rider.earnings.index', compact('rider', 'earnings', 'earningsRows', 'earningsTotal', 'earningsNotice', 'financeSummary', 'poolSummary', 'filters', 'dailyEarnings', 'weeklyEarnings'));
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

        return view('Rider.history.index', compact('rider', 'history', 'assignments', 'filters'));
    }

    public function profile(Request $request): View
    {
        $rider = $this->rider($request)->load(['user', 'logisticsCenter', 'areaAssignments.serviceArea.locations']);
        $residentialAddress = $rider->user->addresses()->orderByDesc('is_default')->latest()->first();

        return view('Rider.profile.index', compact('rider', 'residentialAddress'));
    }

    public function updateAccount(Request $request, ProfilePhotoService $profilePhotos): RedirectResponse
    {
        $user = $this->rider($request)->user;
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'contact_number' => ['required', 'string', 'max:30', Rule::unique('users', 'contact_number')->ignore($user->id)],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $hasNewPhoto = $request->hasFile('profile_photo');
        unset($data['profile_photo']);
        $user->update($data);

        if ($hasNewPhoto) {
            $profilePhotos->replace($user, $request->file('profile_photo'));
        }

        return back()->with('status', 'Account profile updated.');
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

        return view('Rider.messages', [
            'conversations' => $conversations,
            'contacts' => $contacts,
            'messageAttachmentsReady' => app(MessageAttachmentService::class)->available(),
            'messageContext' => app(ConversationContextPresenter::class)->forUser(
                $request->user(),
                $conversations->firstWhere('id', $request->integer('conversation')) ?: $conversations->first(),
            ),
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

    private function offerCutoff(): \Illuminate\Support\Carbon
    {
        return now()->subMinutes(max(1, (int) config('rider.assignment_offer_minutes', 15)));
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
