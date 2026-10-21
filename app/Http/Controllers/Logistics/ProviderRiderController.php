<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Rider\RiderProfile;
use App\Services\RiderRatingService;
use App\Support\RiderVehicleType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProviderRiderController extends Controller
{
    public function index(Request $request): View
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();

        $riders = $center->riders()
            ->with(['user', 'areaAssignments.serviceArea'])
            ->withCount([
                'assignments as active_assignments_count' => fn ($query) => $query->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS']),
            ])
            ->orderByDesc('approved_at')
            ->get();

        $rows = $riders->map(function (RiderProfile $rider): array {
            $activeArea = $rider->areaAssignments->firstWhere('is_active', true)?->serviceArea?->name;

            return [
                'id' => $rider->id,
                'name' => $rider->user?->name ?? 'Rider',
                'code' => 'RID-'.str_pad((string) $rider->id, 4, '0', STR_PAD_LEFT),
                'area' => $activeArea ?: 'Unassigned',
                'parcels' => (int) $rider->active_assignments_count,
                'status' => str($rider->status)->headline()->toString(),
            ];
        });

        return view('logistics.riders.index', [
            'logisticsRiders' => $rows,
            'logisticsRiderStats' => [
                ['label' => 'Active Riders', 'value' => $riders->where('status', 'ACTIVE')->count(), 'tone' => 'success'],
                ['label' => 'Delivering', 'value' => $riders->filter(fn ($rider) => (int) $rider->active_assignments_count > 0)->count(), 'tone' => 'info'],
                ['label' => 'Inactive', 'value' => $riders->whereIn('status', ['SUSPENDED', 'DEACTIVATED'])->count(), 'tone' => 'warning'],
            ],
        ]);
    }

    public function show(Request $request, RiderProfile $rider, RiderRatingService $riderRatings): View
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        abort_unless((int) $rider->logistics_center_id === (int) $center->id, 403);

        $rider->load(['user', 'areaAssignments.serviceArea']);
        $activeArea = $rider->areaAssignments->firstWhere('is_active', true)?->serviceArea?->name;
        $activeAssignments = $rider->assignments()->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'])->count();
        $ratingSummary = $riderRatings->summary($rider);

        return view('logistics.riders.show', [
            'rider' => [
                'id' => $rider->id,
                'name' => $rider->user?->name ?? 'Rider',
                'email' => $rider->user?->email ?? 'Not recorded',
                'contact' => $rider->user?->contact_number ?? 'Not recorded',
                'area' => $activeArea ?: 'Unassigned',
                'status' => str($rider->status)->headline()->toString(),
                'parcels' => $activeAssignments,
                'vehicle' => str($rider->vehicle_type)->headline()->toString(),
                'plate' => $rider->plate_number,
                'license' => $rider->drivers_license_number,
                'rating_average' => $ratingSummary['average'],
                'rating_count' => $ratingSummary['count'],
            ],
        ]);
    }

    public function edit(Request $request, RiderProfile $rider): View
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        abort_unless((int) $rider->logistics_center_id === (int) $center->id, 403);

        return $this->show($request, $rider);
    }

    public function update(Request $request, RiderProfile $rider): RedirectResponse
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        abort_unless((int) $rider->logistics_center_id === (int) $center->id, 403);

        $data = $request->validate([
            'contact_number' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('users', 'contact_number')->ignore($rider->user_id)],
            'vehicle_type' => ['sometimes', 'required', Rule::in(RiderVehicleType::acceptedValues())],
            'plate_number' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('rider_profiles', 'plate_number')->ignore($rider->id)],
            'drivers_license_number' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('rider_profiles', 'drivers_license_number')->ignore($rider->id)],
        ]);

        $rider->getConnection()->transaction(function () use ($rider, $data): void {
            if (array_key_exists('contact_number', $data)) {
                $rider->user?->update(['contact_number' => $data['contact_number']]);
            }

            $rider->update(array_filter([
                'vehicle_type' => $data['vehicle_type'] ?? null,
                'plate_number' => isset($data['plate_number']) ? mb_strtoupper(trim($data['plate_number'])) : null,
                'drivers_license_number' => isset($data['drivers_license_number']) ? mb_strtoupper(trim($data['drivers_license_number'])) : null,
            ], static fn ($value) => $value !== null));
        });

        return back()->with('success', 'Rider profile updated.');
    }

    public function activate(Request $request, RiderProfile $rider): RedirectResponse
    {
        return $this->setStatus($request, $rider, 'ACTIVE', 'Rider reactivated.');
    }

    public function deactivate(Request $request, RiderProfile $rider): RedirectResponse
    {
        return $this->setStatus($request, $rider, 'DEACTIVATED', 'Rider deactivated.');
    }

    private function setStatus(Request $request, RiderProfile $rider, string $status, string $message): RedirectResponse
    {
        $center = $request->user()->logisticsCenter()->where('status', 'ACTIVE')->firstOrFail();
        abort_unless((int) $rider->logistics_center_id === (int) $center->id, 403);

        $rider->getConnection()->transaction(function () use ($rider, $status): void {
            $rider->update(['status' => $status]);
            $rider->user?->update(['status' => $status === 'ACTIVE' ? 'ACTIVE' : 'DEACTIVATED']);
        });

        return back()->with('success', $message);
    }
}
