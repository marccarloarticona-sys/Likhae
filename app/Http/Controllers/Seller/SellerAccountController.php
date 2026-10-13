<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Address;
use App\Models\Seller\SellerProfile;
use App\Services\Account\ProfilePhotoService;
use App\Services\Media\ImageOptimizationService;
use App\Support\PhilippineAddressValidator;
use App\Support\AddressCoordinateValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SellerAccountController extends Controller
{
    public function store(Request $request): View
    {
        $shop = $this->shop($request);

        return view('Seller.store', [
            'tab' => $request->input('tab', 'profile'),
            'seller' => $shop->user,
            'storeProfile' => $this->profile($shop),
        ]);
    }

    public function updateStore(Request $request, ImageOptimizationService $images): RedirectResponse
    {
        $shop = $this->shop($request);

        $data = $request->validate([
            'tagline' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:255'],
            'business_days' => ['nullable', 'string', 'max:80'],
            'business_hours' => ['nullable', 'string', 'max:80'],
            'processing_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'order_cutoff' => ['nullable', 'string', 'max:80'],
            'vacation_mode' => ['nullable', 'boolean'],
            'auto_accept_orders' => ['nullable', 'boolean'],
            'store_visibility' => ['nullable', 'boolean'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $shop->fill(collect($data)->only([
            'tagline', 'description', 'location', 'business_days', 'business_hours',
            'processing_days', 'order_cutoff', 'vacation_mode', 'auto_accept_orders', 'store_visibility',
        ])->all());

        $newPaths = [];
        $oldPaths = [];
        foreach (['banner' => 'banner_path'] as $input => $column) {
            if (! $request->hasFile($input)) {
                continue;
            }

            $oldPath = $shop->{$column};
            $shop->{$column} = $images->store($request->file($input), 'seller-branding');
            $newPaths[] = $shop->{$column};
            if ($oldPath && $oldPath !== $shop->{$column} && ! Str::startsWith($oldPath, ['http://', 'https://'])) {
                $oldPaths[] = $oldPath;
            }
        }

        try {
            $shop->save();
        } catch (Throwable $exception) {
            // The file is written before the database row is saved. Remove it
            // if persistence fails so a rejected update cannot leak storage.
            Storage::disk('public')->delete($newPaths);

            throw $exception;
        }

        Storage::disk('public')->delete($oldPaths);

        return back()->with('status', 'Store profile saved.');
    }

    public function account(Request $request): View
    {
        $shop = $this->shop($request);

        return view('Seller.account', [
            'tab' => $request->input('tab', 'profile'),
            'seller' => $shop->user,
            'storeProfile' => $this->profile($shop),
            'businessAddress' => $shop->businessAddress,
        ]);
    }

    public function updateProfile(Request $request, ProfilePhotoService $profilePhotos): RedirectResponse
    {
        $owner = $this->shop($request)->user;

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($owner->id)],
            'contact_number' => ['nullable', 'string', 'max:30', Rule::unique('users', 'contact_number')->ignore($owner->id)],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $hasNewPhoto = $request->hasFile('profile_photo');
        unset($data['profile_photo']);
        $owner->update($data);

        if ($hasNewPhoto) {
            $profilePhotos->replace($owner, $request->file('profile_photo'));
        }

        return back()->with('status', 'Profile updated.');
    }

    public function updateBusiness(Request $request): RedirectResponse
    {
        $shop = $this->shop($request);

        $data = $request->validate([
            'business_type' => ['nullable', 'string', 'max:80'],
            'dti_sec_number' => ['nullable', 'string', 'max:100'],
            'tin' => ['nullable', 'string', 'max:80'],
            'contact_number' => ['nullable', 'string', 'max:30'],
        ] + PhilippineAddressValidator::rules() + AddressCoordinateValidator::rules());

        PhilippineAddressValidator::assertValid($data);
        AddressCoordinateValidator::assertValid($data);
        AddressCoordinateValidator::assertFreshForAddress($shop->businessAddress, $data);

        if (filled($data['contact_number'] ?? null)) {
            $shop->user->update(['contact_number' => $data['contact_number']]);
        }

        $profileChanges = [];

        if (filled($data['dti_sec_number'] ?? null)) {
            $profileChanges['business_registration_number'] = $data['dti_sec_number'];
        }

        $address = $shop->businessAddress ?: new Address([
            'user_id' => $shop->user_id,
            'label' => 'Seller business',
            'is_default' => false,
        ]);
        $address->fill([
            'recipient_name' => $shop->business_name,
            'contact_number' => $data['contact_number'] ?? $shop->user->contact_number,
            ...PhilippineAddressValidator::storageAttributes($data),
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
        ]);
        $address->save();
        $profileChanges['business_address_id'] = $address->id;

        if ($profileChanges !== []) {
            $shop->update($profileChanges);
        }

        return back()->with('status', 'Business information updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $owner = $this->shop($request)->user;

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $owner->update(['password' => Hash::make($request->string('password')->toString())]);

        return back()->with('status', 'Password changed successfully.');
    }

    public function updateNotifications(Request $request): RedirectResponse
    {
        $data = $request->validate(['preferences' => ['nullable', 'array']]);
        $allowedKeys = [
            'new_order', 'order_cancellation', 'pickup_shipping', 'inventory_alerts',
            'buyer_messages', 'finance_payouts', 'marketing_updates',
        ];
        $channels = ['email', 'push', 'sms'];
        $input = $data['preferences'] ?? [];
        $preferences = [];

        foreach ($allowedKeys as $key) {
            foreach ($channels as $channel) {
                $preferences[$key][$channel] = filter_var(
                    data_get($input, "$key.$channel", false),
                    FILTER_VALIDATE_BOOLEAN,
                );
            }
        }

        $this->shop($request)->user->forceFill(['notification_preferences' => $preferences])->save();

        return back()->with('status', 'Notification preferences saved.');
    }

    private function shop(Request $request): SellerProfile
    {
        return $request->user()
            ->sellerProfile()
            ->with(['user', 'businessAddress', 'primaryCategory'])
            ->where('status', 'ACTIVE')
            ->firstOrFail();
    }

    private function profile(SellerProfile $shop): object
    {
        $address = $shop->businessAddress;

        return (object) [
            'shop_name' => $shop->business_name,
            'description' => $shop->description ?? '',
            'avatar_path' => $shop->avatar_path,
            'banner_path' => $shop->banner_path,
            'tagline' => $shop->tagline ?? '',
            'location' => $shop->location ?: ($address?->formatted() ?? ''),
            'business_days' => $shop->business_days ?? '',
            'business_hours' => $shop->business_hours ?? '',
            'processing_days' => $shop->processing_days ?? 1,
            'order_cutoff' => $shop->order_cutoff ?? '',
            'vacation_mode' => $shop->vacation_mode ?? false,
            'auto_accept_orders' => $shop->auto_accept_orders ?? false,
            'store_visibility' => $shop->store_visibility ?? true,
            'notification_preferences' => $shop->user?->notification_preferences ?? [],
            'business_name' => $shop->business_name,
            'business_type' => $shop->primaryCategory?->name ?? '',
            'dti_sec_number' => $shop->business_registration_number,
            'tin' => $shop->tin ?? '',
            'province' => $address?->province_name,
            'municipality' => $address?->municipality_name,
            'barangay' => $address?->barangay_name,
            'house_number' => $address?->house_number,
            'street' => $address?->street_address,
        ];
    }

}
