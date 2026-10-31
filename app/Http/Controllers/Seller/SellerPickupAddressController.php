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
        $this->assertAddressEditable($seller, $address);

        $data = $this->validatedAddress($request);
        AddressCoordinateValidator::assertFreshForAddress($address, $data);
        $contactNumber = $this->normalizedMobile($data['contact_number']);

        DB::transaction(function () use ($seller, $addressId, $data, $contactNumber): void {
            $lockedSeller = SellerProfile::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $lockedAddress = Address::query()
                ->where('user_id', $seller->user_id)
                ->whereKey($addressId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertAddressEditable($lockedSeller, $lockedAddress);
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

    private function assertAddressEditable(SellerProfile $seller, Address $address): void
    {
        if ($this->hasPickupHistory($seller, $address)) {
            throw ValidationException::withMessages([
                'pickup_address' => 'This address is part of pickup history. Add a new address to keep the saved route unchanged.',
            ]);
        }

        if ((int) $seller->business_address_id === (int) $address->id
            && SellerOrder::query()->where('seller_profile_id', $seller->id)->exists()) {
            throw ValidationException::withMessages([
                'pickup_address' => 'This address has been used to route Seller orders. Add a new pickup address to preserve those routes.',
            ]);
        }
    }

    private function hasPickupHistory(SellerProfile $seller, Address $address): bool
    {
        return Schema::hasTable('seller_pickup_requests') && SellerPickupRequest::query()
            ->where('seller_profile_id', $seller->id)
            ->where('pickup_address_id', $address->id)
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
