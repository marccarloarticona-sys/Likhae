<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Rider\RiderAssignment;
use App\Models\Buyer\Address;
use App\Models\Finance\CodCollection;
use App\Models\Finance\RiderEarningPool;
use App\Models\Finance\RiderEarningPoolParticipant;
use App\Models\Finance\RiderRemittance;
use App\Models\Communication\Conversation;
use App\Models\User;
use App\Services\Account\ProfilePhotoService;
use App\Services\Communication\ConversationService;
use App\Support\PhilippineAddressValidator;
use App\Support\AddressCoordinateValidator;
use App\Services\Maps\MapDataService;
use App\Services\Finance\FinanceSchemaReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RiderController extends Controller
{
    public function dashboard(Request $request): View
    {
        $rider = $this->rider($request);

        $assignments = RiderAssignment::query()->where('rider_profile_id', $rider->id);
        $finance = $this->financeSummary($rider, app(FinanceSchemaReadiness::class));
        $riderStats = [
            ['label' => 'Pending pickups', 'value' => (clone $assignments)->pickup()->whereIn('status', ['ASSIGNED', 'ACCEPTED'])->count()],
            ['label' => 'Pending deliveries', 'value' => (clone $assignments)->delivery()->whereIn('status', ['ASSIGNED', 'ACCEPTED'])->count()],
            ['label' => 'In progress', 'value' => (clone $assignments)->where('status', 'IN_PROGRESS')->count()],
            ['label' => 'COD unremitted', 'value' => 'PHP '.number_format($finance['unremitted_cash'], 2)],
        ];
        $recentAssignments = (clone $assignments)
            ->with(['shipment.sellerOrder.items.product.images', 'shipment.sellerOrder.order.address'])
            ->latest()
            ->limit(8)
            ->get();
        $recentDeliveries = $recentAssignments->map(function (RiderAssignment $assignment): array {
            $shipment = $assignment->shipment;
            $sellerOrder = $shipment?->sellerOrder;
            $address = $sellerOrder?->order?->address;
            $images = $sellerOrder?->items?->first()?->product?->images ?? collect();
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

    public function pickupShow(Request $request, RiderAssignment $assignment): View
    {
        $rider = $this->rider($request);
        abort_unless((int) $assignment->rider_profile_id === (int) $rider->id && $assignment->assignment_type === 'PICKUP', 403);

        $assignment->load(['shipment.sellerOrder.items.product.images', 'shipment.sellerOrder.order.address', 'shipment.sellerOrder.sellerProfile.user', 'shipment.sellerOrder.sellerProfile.businessAddress', 'shipment.riderAssignments.liveLocation', 'shipment.logisticsCenter.address', 'liveLocation']);

        $shipment = $assignment->shipment;
        $order = $shipment->sellerOrder->order;
        $pickup = [
            'tracking' => $shipment->tracking_number,
            'status' => $assignment->status,
            'status_label' => str($assignment->status)->headline(),
            'seller' => $shipment->sellerOrder->sellerProfile->business_name,
            'buyer' => $order->address->recipient_name,
            'address' => $order->address->formatted(),
            'items' => $shipment->sellerOrder->items->sum('quantity'),
            'amount' => '₱'.number_format((float) $shipment->sellerOrder->grand_total, 2),
        ];
        $verified = $request->filled('tracking') && hash_equals($shipment->tracking_number, (string) $request->query('tracking'));
        $scanMethod = in_array(strtoupper((string) $request->query('scan_method')), ['QR', 'BARCODE'], true)
            ? strtoupper((string) $request->query('scan_method'))
            : 'MANUAL';
        $delivery = $assignment;

        // Pickup navigation needs only the rider and the seller's confirmed
        // location. Do not send the unrelated buyer marker to this mobile page.
        $mapMarkers = app(MapDataService::class)->forShipment($shipment, false, true);
        return view('Rider.pickups.show', compact('assignment', 'rider', 'pickup', 'verified', 'scanMethod', 'delivery', 'mapMarkers'));
    }

    public function deliveries(Request $request): View
    {
        $rider = $this->rider($request);

        $assignments = RiderAssignment::query()
            ->where('rider_profile_id', $rider->id)
            ->delivery()
            ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])
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
            'active' => (clone $deliveryQuery)->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])->count(),
            'assigned' => (clone $deliveryQuery)->whereIn('status', ['ASSIGNED', 'ACCEPTED'])->count(),
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

        $assignment->load(['shipment.sellerOrder.items.product.images', 'shipment.sellerOrder.order.address', 'shipment.sellerOrder.sellerProfile.user', 'shipment.sellerOrder.sellerProfile.businessAddress', 'shipment.deliveryAttempts', 'shipment.riderAssignments.liveLocation', 'shipment.logisticsCenter.address', 'liveLocation']);

        $shipment = $assignment->shipment;
        $order = $shipment->sellerOrder->order;
        $parcel = [
            'tracking' => $shipment->tracking_number,
            'status' => $assignment->status,
            'status_label' => str($assignment->status)->headline(),
            'buyer' => $order->address->recipient_name,
            'contact' => $order->address->contact_number,
            'address' => $order->address->formatted(),
            'amount' => '₱'.number_format((float) $shipment->sellerOrder->grand_total, 2),
        ];
        $delivery = $assignment;

        // Delivery navigation needs only the rider and the saved buyer address.
        $mapMarkers = app(MapDataService::class)->forShipment($shipment, true, false);
        return view('Rider.deliveries.show', compact('assignment', 'rider', 'parcel', 'delivery', 'mapMarkers'));
    }

    public function earnings(Request $request): View
    {
        $rider = $this->rider($request);
        $financeSchema = app(FinanceSchemaReadiness::class);

        if ($financeSchema->step7Ready()) {
            $earnings = RiderEarningPoolParticipant::query()
                ->where('rider_profile_id', $rider->id)
                ->with(['pool.shipment', 'assignment.shipment'])
                ->latest()
                ->paginate(15);
            $earningsRows = $earnings->getCollection()->map(fn (RiderEarningPoolParticipant $participant): array => [
                'date' => $participant->created_at?->format('M d, Y') ?? 'Date unavailable',
                'reference' => $participant->pool?->shipment?->tracking_number ?? 'Pool #'.$participant->rider_earning_pool_id,
                'amount' => $participant->share_amount === null ? 'Pending allocation' : 'PHP '.number_format((float) $participant->share_amount, 2),
                'status' => str($participant->status)->headline(),
            ]);
            $earningsTotal = (float) RiderEarningPoolParticipant::query()->where('rider_profile_id', $rider->id)->sum('share_amount');
            $poolSummary = [
                'schema_ready' => true,
                'unallocated' => (float) RiderEarningPool::query()->whereHas('participants', fn ($query) => $query->where('rider_profile_id', $rider->id))->sum('unresolved_share_amount'),
                'accrued' => $earningsTotal,
                'paid' => (float) RiderEarningPoolParticipant::query()->where('rider_profile_id', $rider->id)->where('status', 'PAID')->sum('share_amount'),
            ];
        } else {
            $earnings = $rider->earnings()->with('riderAssignment.shipment')->latest()->paginate(15);
            $earningsRows = $earnings->getCollection()->map(fn ($earning): array => [
                'date' => $earning->earned_at?->format('M d, Y') ?? $earning->created_at?->format('M d, Y') ?? 'Date unavailable',
                'reference' => $earning->riderAssignment?->shipment?->tracking_number ?? 'Assignment #'.$earning->rider_assignment_id,
                'amount' => 'PHP '.number_format((float) $earning->amount, 2),
                'status' => str($earning->status)->headline(),
            ]);
            $earningsTotal = (float) $rider->earnings()->sum('amount');
            $poolSummary = ['schema_ready' => false, 'unallocated' => 0.0, 'accrued' => $earningsTotal, 'paid' => (float) $rider->earnings()->where('status', 'PAID')->sum('amount')];
        }
        $earningsNotice = 'Cash held and rider earnings are separate. Cross-hub pool shares remain pending until policy approval.';
        $financeSummary = $this->financeSummary($rider, $financeSchema);

        return view('Rider.earnings.index', compact('rider', 'earnings', 'earningsRows', 'earningsTotal', 'earningsNotice', 'financeSummary', 'poolSummary'));
    }

    public function history(Request $request): View
    {
        $rider = $this->rider($request);

        $assignments = RiderAssignment::query()
            ->where('rider_profile_id', $rider->id)
            ->where(function ($query): void {
                $query->whereIn('status', ['COMPLETED', 'REJECTED', 'CANCELLED'])
                    ->orWhereHas('deliveryAttempts');
            })
            ->with(['shipment.sellerOrder.items.product.images', 'shipment.sellerOrder.order.address', 'deliveryAttempts'])
            ->latest()
            ->paginate(15);
        $history = $assignments->getCollection()->map(function (RiderAssignment $assignment): array {
            $shipment = $assignment->shipment;
            $attempt = $assignment->deliveryAttempts->sortByDesc('attempt_number')->first();

            return [
                'image' => $this->assignmentImage($assignment),
                'tracking' => $shipment?->tracking_number ?? 'Tracking unavailable',
                'buyer' => $shipment?->sellerOrder?->order?->address?->recipient_name ?? 'Buyer unavailable',
                'status_label' => $attempt
                    ? str($attempt->status)->headline()
                    : str($shipment?->current_status ?? $assignment->status)->headline(),
                'updated' => ($attempt?->attempted_at ?? $assignment->completed_at ?? $assignment->updated_at)?->format('M d, Y g:i A') ?? 'Date unavailable',
                'failure_reason' => $attempt?->failure_reason,
            ];
        });

        return view('Rider.history.index', compact('rider', 'history', 'assignments'));
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

    public function messages(Request $request, ConversationService $conversationService): View
    {
        $conversations = $conversationService->workspaceThreads(
            $request->user(),
            $request->integer('conversation') ?: null,
        );

        return view('Rider.messages', [
            'conversations' => $conversations,
            'contacts' => User::query()->where('status', 'ACTIVE')->whereKeyNot($request->user()->id)->orderBy('first_name')->limit(100)->get(['id', 'first_name', 'middle_initial', 'last_name', 'name_extension', 'account_type']),
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

    public function sendMessage(Request $request, ConversationService $conversationService): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $context = [];
        $conversation = null;
        if (! empty($data['conversation_id'])) {
            $conversation = Conversation::query()
                ->whereKey((int) $data['conversation_id'])
                ->whereHas('participants', fn ($query) => $query->where('users.id', $request->user()->id))
                ->whereHas('participants', fn ($query) => $query->where('users.id', (int) $data['recipient_user_id']))
                ->firstOrFail();
            $context = $conversationService->contextFor($conversation);
        }

        $message = $conversationService->send($request->user(), (int) $data['recipient_user_id'], trim($data['body']), $context, $conversation);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $conversationService->messagePayload($message),
            ]);
        }

        return back()->with('status', 'Message sent.');
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
        $items = $assignment->shipment?->sellerOrder?->items ?? collect();
        $images = $items->first()?->product?->images ?? collect();
        $imagePath = $images->firstWhere('is_primary', true)?->file_path ?? $images->first()?->file_path;

        return $imagePath ? Storage::url($imagePath) : asset('images/product-placeholder.svg');
    }
}
