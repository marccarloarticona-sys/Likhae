<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Address;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\AddressCoordinateValidator;
use App\Support\PhilippineAddressValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use App\Services\Account\ProfilePhotoService;
use Illuminate\Validation\Rule;

class RiderVehicleProfileController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        $rider = $this->rider($request)->load(['user', 'logisticsCenter', 'areaAssignments.serviceArea']);
        $user = $rider->user;
        $address = $user->addresses()->orderByDesc('is_default')->latest()->first();

        return response()->json(['success' => true, 'data' => [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'first_name' => $user->first_name,
                'middle_initial' => $user->middle_initial,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'contact_number' => $user->contact_number,
                'birthday' => $user->birthday?->toDateString(),
                'sex' => $user->sex,
                'status' => $user->status,
                'account_type' => $user->account_type,
                'profile_photo_url' => $user->profile_photo_path
                    ? Storage::disk('public')->url($user->profile_photo_path)
                    : null,
            ],
            'rider' => [
                'id' => $rider->id,
                'user_id' => $rider->user_id,
                'vehicle_type' => $rider->vehicle_type,
                'plate_number' => $rider->plate_number,
                'status' => $rider->status,
                'is_online' => (bool) $rider->is_online,
                'primary_role' => 'Rider',
                'logistics_center' => $rider->logisticsCenter?->business_name,
                'service_areas' => $rider->areaAssignments
                    ->where('is_active', true)
                    ->pluck('serviceArea.name')
                    ->filter()
                    ->values(),
            ],
            'residential_address' => $address ? $address->only([
                'id', 'region_code', 'region_name', 'province_code', 'province_name',
                'municipality_code', 'municipality_name', 'barangay_code', 'barangay_name',
                'postal_code', 'house_number', 'street_address', 'landmark', 'latitude', 'longitude',
            ]) : null,
        ]]);
    }

    public function updateProfile(Request $request, ProfilePhotoService $photos): JsonResponse
    {
        $user = $this->rider($request)->user;
        // Match the rider web account: registered identity/email require review.
        $data = $request->validate([
            'first_name' => ['sometimes', Rule::in([$user->first_name])],
            'last_name' => ['sometimes', Rule::in([$user->last_name])],
            'email' => ['sometimes', Rule::in([$user->email])],
            'contact_number' => ['required', 'string', 'max:30', Rule::unique('users', 'contact_number')->ignore($user->id)],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $user->update(['contact_number' => $data['contact_number']]);
        if ($request->hasFile('profile_photo')) {
            $photos->replace($user, $request->file('profile_photo'));
        }

        return $this->profile($request);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $this->rider($request)->user;
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ]);
        $user->update(['password' => Hash::make($data['password'])]);

        return response()->json(['success' => true, 'message' => 'Password updated.']);
    }

    public function updatePlateNumber(Request $request): JsonResponse
    {
        $rider = $this->rider($request);
        $data = $request->validate([
            'plate_number' => ['required', 'string', 'max:50', Rule::unique('rider_profiles', 'plate_number')->ignore($rider->id)],
        ]);
        $rider->update(['plate_number' => mb_strtoupper(trim($data['plate_number']))]);

        return response()->json([
            'success' => true,
            'message' => 'Vehicle plate number updated.',
            'data' => ['plate_number' => $rider->plate_number],
        ]);
    }

    public function updateResidentialAddress(Request $request): JsonResponse
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

        return response()->json([
            'success' => true,
            'message' => 'Residential address updated.',
            'data' => ['address' => [
                'id' => (string) $address->id,
                'label' => $address->label,
                'recipient_name' => $address->recipient_name,
                'contact_number' => $address->contact_number,
                'province_code' => $address->province_code,
                'province_name' => $address->province_name,
                'municipality_code' => $address->municipality_code,
                'municipality_name' => $address->municipality_name,
                'barangay_code' => $address->barangay_code,
                'barangay_name' => $address->barangay_name,
                'postal_code' => $address->postal_code,
                'house_number' => $address->house_number,
                'street_address' => $address->street_address,
                'landmark' => $address->landmark,
                'latitude' => $address->latitude !== null ? (float) $address->latitude : null,
                'longitude' => $address->longitude !== null ? (float) $address->longitude : null,
                'is_default' => (bool) $address->is_default,
                'formatted_address' => $address->formatted(),
            ]],
        ]);
    }

    private function rider(Request $request): RiderProfile
    {
        abort_unless($request->user()?->isAccountType(User::TYPE_RIDER), 403, 'Rider access is required.');
        $rider = $request->user()->riderProfile;
        abort_unless($rider && $rider->status === 'ACTIVE', 403, 'An active rider profile is required.');

        return $rider;
    }
}
