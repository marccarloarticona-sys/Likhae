<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Address;
use App\Models\Seller\SellerPickupRequest;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\SellerOrder;
use App\Support\AddressCoordinateValidator;
use App\Support\PhilippineAddressValidator;
use App\Support\PhilippineMobileNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SellerPickupAddressController extends Controller
{
    private const MAX_ADDRESSES = 3;

    public function store(Request $request): RedirectResponse
    {
        $seller = $this->seller($request);
        $data = $this->validatedAddress($request);
        $contactNumber = $this->normalizedMobile($data['contact_number']);

        DB::transaction(function () use ($seller, $data, $contactNumber): void {
            $lockedSeller = SellerProfile::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $addresses = Address::query()->where('user_id', $lockedSeller->user_id)->lockForUpdate()->get();
            if ($addresses->count() >= self::MAX_ADDRESSES) {
                throw ValidationException::withMessages([
                    'pickup_address' => 'You can save up to 3 pickup addresses. Remove an unused address before adding another.',
                ]);
            }

            $existingDefault = $addresses->first(fn (Address $address): bool => $address->is_default)
                ?? $addresses->firstWhere('id', (int) $lockedSeller->business_address_id)
                ?? $addresses->first();
            if ($existingDefault) {
                Address::query()->where('user_id', $lockedSeller->user_id)->update([
                    'is_default' => false,
                ]);
                $existingDefault->update(['is_default' => true]);
            }

            $newAddress = Address::query()->create([
                'user_id' => $lockedSeller->user_id,
                'label' => trim($data['label']),
                'recipient_name' => trim($data['recipient_name']),
                'contact_number' => $contactNumber,
                ...PhilippineAddressValidator::storageAttributes($data),
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'is_default' => ! $existingDefault,
            ]);
            if (! $lockedSeller->business_address_id && ! $lockedSeller->sellerOrders()->exists()) {
                $lockedSeller->update(['business_address_id' => $existingDefault?->id ?? $newAddress->id]);
            }
        });

        return $this->accountRedirect()->with('status', 'Pickup address saved.');
    }

    public function update(Request $request, int $addressId): RedirectResponse
    {
        $seller = $this->seller($request);
        $address = Address::query()
            ->where('user_id', $seller->user_id)
            ->whereKey($addressId)
            ->firstOrFail();
        if ($request->input('pin_only') === '1') {
            return $this->saveFirstPinOnly($request, $seller, $addressId);
        }

        $data = $this->validatedAddress($request);
        $contactNumber = $this->normalizedMobile($data['contact_number']);
        $data['contact_number'] = $contactNumber;
        $completionOnly = $this->assertAddressEditable($seller, $address, $data);
        if (! $completionOnly) {
            AddressCoordinateValidator::assertFreshForAddress($address, $data);
        }

        DB::transaction(function () use ($seller, $addressId, $data, $contactNumber): void {
            $lockedSeller = SellerProfile::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $lockedAddress = Address::query()
                ->where('user_id', $seller->user_id)
                ->whereKey($addressId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertAddressEditable($lockedSeller, $lockedAddress, $data);
            $lockedAddress->fill([
                'label' => trim($data['label']),
                'recipient_name' => trim($data['recipient_name']),
                'contact_number' => $contactNumber,
                ...PhilippineAddressValidator::storageAttributes($data),
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
            ])->save();
        });

        return $this->accountRedirect()->with('status', 'Pickup address updated.');
    }

    private function saveFirstPinOnly(Request $request, SellerProfile $seller, int $addressId): RedirectResponse
    {
        $data = $request->validate(AddressCoordinateValidator::rules());
        AddressCoordinateValidator::assertValid($data);

        DB::transaction(function () use ($seller, $addressId, $data): void {
            $lockedSeller = SellerProfile::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $address = Address::query()
                ->where('user_id', $lockedSeller->user_id)
                ->whereKey($addressId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->hasActivePickupRequest($lockedSeller, $address)) {
                throw ValidationException::withMessages([
                    'pickup_address' => 'This address is being used by an active pickup request. Save its pin after that request is completed or cancelled.',
                ]);
            }

            if ((int) $lockedSeller->business_address_id !== (int) $address->id
                || ! SellerOrder::query()->where('seller_profile_id', $lockedSeller->id)->exists()) {
                throw ValidationException::withMessages([
                    'pickup_address' => 'Pin-only completion is available only for a Seller origin address already used by orders.',
                ]);
            }

            if (is_numeric($address->latitude) && is_numeric($address->longitude)) {
                throw ValidationException::withMessages([
                    'latitude' => 'This address already has a confirmed pin. Pin-only completion can only add a missing pin.',
                ]);
            }

            $address->update([
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
            ]);
        });

        return $this->accountRedirect()->with('status', 'Pickup pin saved. Existing address details and routes were not changed.');
    }

    public function setDefault(Request $request, int $addressId): RedirectResponse
    {
        $seller = $this->seller($request);

        DB::transaction(function () use ($seller, $addressId): void {
            $lockedSeller = SellerProfile::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $addresses = Address::query()
                ->where('user_id', $seller->user_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $address = $addresses->firstWhere('id', $addressId);
            if (! $address) {
                abort(404);
            }
            if (! is_numeric($address->latitude) || ! is_numeric($address->longitude)) {
                throw ValidationException::withMessages([
                    'pickup_address' => 'Confirm this address map pin before making it the default pickup location.',
                ]);
            }

            Address::query()->where('user_id', $seller->user_id)->update(['is_default' => false]);
            $address->update(['is_default' => true]);
            if (! $lockedSeller->sellerOrders()->exists()) {
                $lockedSeller->update(['business_address_id' => $address->id]);
            }
        });

        return $this->accountRedirect()->with('status', 'Default pickup address updated.');
    }

    public function destroy(Request $request, int $addressId): RedirectResponse
    {
        $seller = $this->seller($request);

        DB::transaction(function () use ($seller, $addressId): void {
            $lockedSeller = SellerProfile::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $addresses = Address::query()
                ->where('user_id', $lockedSeller->user_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $address = $addresses->firstWhere('id', $addressId);
            if (! $address) {
                abort(404);
            }
            if ($addresses->count() <= 1) {
                throw ValidationException::withMessages([
                    'pickup_address' => 'Keep at least one pickup address on the Seller account.',
                ]);
            }
            if ((int) $lockedSeller->business_address_id === (int) $address->id) {
                throw ValidationException::withMessages([
                    'pickup_address' => 'The current Seller origin address is protected. Add another pickup address instead of removing this route record.',
                ]);
            }
            if ($this->hasPickupHistory($lockedSeller, $address)) {
                throw ValidationException::withMessages([
                    'pickup_address' => 'This address is part of pickup history and cannot be removed.',
                ]);
            }

            $address->delete();

            $remaining = Address::query()
                ->where('user_id', $lockedSeller->user_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($remaining->isNotEmpty()) {
                $replacement = $remaining->first(fn (Address $saved): bool => $saved->is_default)
                    ?? $remaining->firstWhere('id', (int) $lockedSeller->business_address_id)
                    ?? $remaining->first();
                Address::query()->where('user_id', $lockedSeller->user_id)->update(['is_default' => false]);
                $replacement->update(['is_default' => true]);
            }
        });

        return $this->accountRedirect()->with('status', 'Pickup address removed.');
    }

    /** @return array<string, mixed> */
    private function validatedAddress(Request $request): array
    {
        $data = $request->validate(array_merge([
            'label' => ['required', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:200'],
            'contact_number' => ['required', 'string', 'max:30'],
        ], PhilippineAddressValidator::rules(), AddressCoordinateValidator::rules()));

        PhilippineAddressValidator::assertValid($data);
        AddressCoordinateValidator::assertValid($data);

        return $data;
    }

    private function normalizedMobile(string $mobile): string
    {
        $normalized = PhilippineMobileNumber::normalize($mobile);
        if ($normalized === null) {
            throw ValidationException::withMessages([
                'contact_number' => 'Enter a valid Philippine mobile number.',
            ]);
        }

        return $normalized;
    }

    private function assertAddressEditable(SellerProfile $seller, Address $address, ?array $data = null): bool
    {
        if ($this->hasPickupHistory($seller, $address)) {
            throw ValidationException::withMessages([
                'pickup_address' => 'This address is part of pickup history. Add a new address to keep the saved route unchanged.',
            ]);
        }

        if ((int) $seller->business_address_id === (int) $address->id
            && SellerOrder::query()->where('seller_profile_id', $seller->id)->exists()) {
            if ($data !== null && $this->onlyCompletesMissingDetails($address, $data)) {
                return true;
            }

            throw ValidationException::withMessages([
                'pickup_address' => 'This address is used by existing orders. You can only add missing address details or its first map pin; existing saved details cannot be changed. Add a separate address to change the pickup location.',
            ]);
        }

        return false;
    }

    /**
     * Allow safe completion of a routed business address without changing any
     * existing address value, route fields, contact details, or confirmed pin.
     *
     * @param array<string, mixed> $data
     */
    private function onlyCompletesMissingDetails(Address $address, array $data): bool
    {
        foreach (['label', 'recipient_name', 'contact_number'] as $field) {
            if (trim((string) $address->{$field}) !== trim((string) ($data[$field] ?? ''))) {
                return false;
            }
        }

        foreach ([
            'region_code', 'region_name',
            'province_code', 'province_name',
            'municipality_code', 'municipality_name',
            'barangay_code', 'barangay_name',
            'postal_code', 'house_number', 'street_address', 'landmark',
        ] as $field) {
            $saved = trim((string) ($address->{$field} ?? ''));
            $submitted = trim((string) ($data[$field] ?? ''));
            if ($saved !== '' && $saved !== $submitted) {
                return false;
            }
        }

        foreach (['latitude', 'longitude'] as $field) {
            $saved = $address->{$field};
            $submitted = $data[$field] ?? null;
            if (filled($saved)
                && (! is_numeric($saved) || ! is_numeric($submitted)
                    || abs((float) $saved - (float) $submitted) > 0.0000001)) {
                return false;
            }
        }

        return true;
    }

    private function hasPickupHistory(SellerProfile $seller, Address $address): bool
    {
        return Schema::hasTable('seller_pickup_requests') && SellerPickupRequest::query()
            ->where('seller_profile_id', $seller->id)
            ->where('pickup_address_id', $address->id)
            ->exists();
    }

    private function hasActivePickupRequest(SellerProfile $seller, Address $address): bool
    {
        return Schema::hasTable('seller_pickup_requests') && SellerPickupRequest::query()
            ->where('seller_profile_id', $seller->id)
            ->where('pickup_address_id', $address->id)
            ->whereIn('status', ['REQUESTED', 'ASSIGNED'])
            ->exists();
    }

    private function seller(Request $request): SellerProfile
    {
        return $request->user()
            ->sellerProfile()
            ->where('status', 'ACTIVE')
            ->firstOrFail();
    }

    private function accountRedirect(): RedirectResponse
    {
        return redirect()->route('seller.account', ['tab' => 'pickup-addresses']);
    }
}
