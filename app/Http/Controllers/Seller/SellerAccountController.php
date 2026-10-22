<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Address;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Account\ProfilePhotoService;
use App\Services\Finance\PayoutDestinationReadiness;
use App\Services\Media\ImageOptimizationService;
use App\Support\PhilippineAddressValidator;
use App\Support\AddressCoordinateValidator;
use App\Support\PhilippineMobileNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SellerAccountController extends Controller
{
    public function store(Request $request): View
    {
        $shop = $this->shop($request);

        return view('seller.store', [
            'tab' => $request->input('tab', 'profile'),
            'seller' => $shop->user,
            'storeProfile' => $this->profile($shop),
        ]);
    }

    public function updateStore(Request $request, ImageOptimizationService $images): RedirectResponse
    {
        $shop = $this->shop($request);

        $name = $request->input('business_name');
        if (is_string($name)) {
            $name = preg_replace('/[\\s\\p{Z}]+/u', ' ', $name) ?? $name;
            $name = $this->trimWhitespace($name);
            $request->merge(['business_name' => $name]);
        }

        $data = $request->validate([
            'business_name' => ['required', 'string', 'min:3', 'max:30'],
            'tagline' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:500'],
            'location' => ['nullable', 'string', 'max:255'],
            'business_days' => ['nullable', 'string', 'max:80'],
            'business_hours' => ['nullable', 'string', 'max:80'],
            'processing_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'order_cutoff' => ['nullable', 'string', 'max:80'],
            'vacation_mode' => ['nullable', 'boolean'],
            'auto_accept_orders' => ['nullable', 'boolean'],
            'store_visibility' => ['nullable', 'boolean'],
            'avatar' => $this->brandingImageRules('avatar_original'),
            'banner' => $this->brandingImageRules('banner_original'),
            'avatar_original' => $this->brandingImageRules('avatar'),
            'banner_original' => $this->brandingImageRules('banner'),
        ]);

        $shopId = (int) $shop->id;
        $newPaths = [];
        $oldPaths = [];
        try {
            DB::transaction(function () use ($request, $images, $data, $shopId, &$shop, &$newPaths, &$oldPaths): void {
                // Serialise store-name changes so two sellers cannot claim the
                // same normalized name in the same moment without a schema change.
                $profiles = SellerProfile::query()->orderBy('id')->lockForUpdate()->get();
                $shop = $profiles->firstWhere('id', $shopId);
                abort_unless($shop && $shop->status === 'ACTIVE', 404);

                $normalizedName = $this->normalizedStoreName((string) $data['business_name']);
                $duplicateName = $profiles->contains(fn (SellerProfile $profile): bool => (int) $profile->id !== $shopId
                    && $this->normalizedStoreName((string) $profile->business_name) === $normalizedName);
                if ($duplicateName) {
                    throw ValidationException::withMessages([
                        'business_name' => 'This store name is already in use. Choose a different name.',
                    ]);
                }

                $shop->fill(collect($data)->only([
                    'business_name', 'tagline', 'description', 'location', 'business_days', 'business_hours',
                    'processing_days', 'order_cutoff', 'vacation_mode', 'auto_accept_orders', 'store_visibility',
                ])->all());

                foreach (['avatar' => 'avatar_path', 'banner' => 'banner_path'] as $input => $column) {
                    if (! $request->hasFile($input)) {
                        continue;
                    }

                    $oldPath = $shop->{$column};
                    $shop->{$column} = $images->store($request->file($input), 'seller-branding');
                    $newPaths[] = $shop->{$column};
                    if ($oldPath && $oldPath !== $shop->{$column} && ! $this->isRemoteImagePath($oldPath)) {
                        $oldPaths[] = $oldPath;
                    }
                }

                $shop->save();
            });
        } catch (Throwable $exception) {
            // The file is written before the database row is saved. Remove it
            // if persistence fails so a rejected update cannot leak storage.
            Storage::disk('public')->delete($newPaths);

            throw $exception;
        }

        Storage::disk('public')->delete($oldPaths);

        return back()->with('status', 'Store profile saved.');
    }

    public function account(Request $request, PayoutDestinationReadiness $destinations): View
    {
        $shop = $this->shop($request);
        $businessAddress = $this->ownedBusinessAddress($shop);
        $payoutDestinationReady = $destinations->readyFor('seller_profiles');
        $payoutDestinationSaved = $payoutDestinationReady && $destinations->hasDestination($shop);

        return view('seller.account', [
            'tab' => $request->input('tab', 'profile'),
            'seller' => $shop->user,
            'storeProfile' => $this->profile($shop),
            'businessAddress' => $businessAddress,
            'payoutProfile' => $shop,
            'payoutDestinationReady' => $payoutDestinationReady,
            'payoutDestinationSaved' => $payoutDestinationSaved,
        ]);
    }

    public function updateProfile(Request $request, ProfilePhotoService $profilePhotos): RedirectResponse
    {
        $owner = $this->shop($request)->user;

        foreach (['first_name', 'last_name', 'email'] as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $value = $this->trimWhitespace($value);
                $request->merge([$field => $field === 'email' ? mb_strtolower($value) : $value]);
            }
        }
        $this->normalizeOptionalMobile($request, 'contact_number');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'contact_number' => [
                'nullable', 'string', 'max:30', Rule::unique('users', 'contact_number')->ignore($owner->id),
                function (string $attribute, mixed $value, \Closure $fail) use ($owner): void {
                    if (blank($value)
                        || PhilippineMobileNumber::normalize((string) $value) !== null
                        || trim((string) $value) === trim((string) $owner->contact_number)) {
                        return;
                    }
                    $fail('Enter a valid Philippine mobile number.');
                },
            ],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if (User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->whereKeyNot($owner->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This email address is already in use.',
            ]);
        }
        if (filled($data['contact_number'] ?? null)) {
            $normalizedContact = PhilippineMobileNumber::normalize((string) $data['contact_number']);
            $localContact = $normalizedContact ? '0'.substr($normalizedContact, 3) : null;
            if ($normalizedContact && User::query()
                ->whereKeyNot($owner->id)
                ->whereIn('contact_number', [$normalizedContact, $localContact])
                ->exists()) {
                throw ValidationException::withMessages([
                    'contact_number' => 'This mobile number is already in use.',
                ]);
            }
        }

        $hasNewPhoto = $request->hasFile('profile_photo');
        unset($data['profile_photo']);
        $emailChanged = mb_strtolower((string) $owner->email) !== $data['email'];
        $profileChanges = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
        ];
        if (array_key_exists('contact_number', $data)) {
            $profileChanges['contact_number'] = $data['contact_number'];
        }
        if ($emailChanged) {
            $profileChanges['email_verified_at'] = null;
        }
        $owner->forceFill($profileChanges)->save();

        if ($hasNewPhoto) {
            $profilePhotos->replace($owner, $request->file('profile_photo'));
        }

        if ($emailChanged) {
            $owner->sendEmailVerificationNotification();

            return redirect()->route('verification.notice')->with(
                'status',
                'A verification link was sent to your new email address. Verify it to continue using Seller Center.',
            );
        }

        return back()->with('status', 'Profile updated.');
    }

    public function updateBusiness(Request $request): RedirectResponse
    {
        $shop = $this->shop($request);
        $businessAddress = $this->ownedBusinessAddress($shop);
        $this->normalizeOptionalMobile($request, 'contact_number');

        $data = $request->validate([
            'dti_sec_number' => ['nullable', 'string', 'max:100'],
            'tin' => ['nullable', 'string', 'max:80'],
            'contact_number' => [
                'nullable', 'string', 'max:30',
                function (string $attribute, mixed $value, \Closure $fail) use ($businessAddress, $shop): void {
                    $existing = $businessAddress?->contact_number ?? $shop->user->contact_number;
                    if (blank($value)
                        || PhilippineMobileNumber::normalize((string) $value) !== null
                        || trim((string) $value) === trim((string) $existing)) {
                        return;
                    }
                    $fail('Enter a valid Philippine mobile number.');
                },
            ],
        ] + PhilippineAddressValidator::rules() + AddressCoordinateValidator::rules());

        PhilippineAddressValidator::assertValid($data);
        AddressCoordinateValidator::assertValid($data);
        AddressCoordinateValidator::assertFreshForAddress($businessAddress, $data);

        $profileChanges = [];

        if (array_key_exists('dti_sec_number', $data)) {
            $profileChanges['business_registration_number'] = filled($data['dti_sec_number'] ?? null)
                ? $this->trimWhitespace((string) $data['dti_sec_number'])
                : null;
        }
        if (array_key_exists('tin', $data)) {
            $profileChanges['tin'] = filled($data['tin'] ?? null)
                ? $this->trimWhitespace((string) $data['tin'])
                : null;
        }

        $address = $businessAddress ?: new Address([
            'user_id' => $shop->user_id,
            'label' => 'Seller business',
            'is_default' => false,
        ]);
        $address->fill([
            'recipient_name' => $shop->business_name,
            'contact_number' => array_key_exists('contact_number', $data)
                ? $data['contact_number']
                : ($address->contact_number ?? $shop->user->contact_number),
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
        $notificationKeys = [
            'new_order', 'order_cancellation', 'pickup_shipping', 'inventory_alerts',
            'buyer_messages', 'finance_payouts', 'marketing_updates',
        ];
        $channels = ['email', 'push', 'sms'];
        $rules = ['preferences' => ['sometimes', 'array']];
        foreach ($notificationKeys as $key) {
            foreach ($channels as $channel) {
                $rules["preferences.$key.$channel"] = ['sometimes', 'boolean'];
            }
        }
        $data = $request->validate($rules);
        $allowedKeys = [
            'new_order', 'order_cancellation', 'pickup_shipping', 'inventory_alerts',
            'buyer_messages', 'finance_payouts', 'marketing_updates',
        ];
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

        $user = $this->shop($request)->user;
        $user->forceFill([
            'notification_preferences' => array_merge((array) $user->notification_preferences, $preferences),
        ])->save();

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

    private function ownedBusinessAddress(SellerProfile $shop): ?Address
    {
        $address = $shop->businessAddress;

        return $address && (int) $address->user_id === (int) $shop->user_id ? $address : null;
    }

    private function normalizeOptionalMobile(Request $request, string $field): void
    {
        $value = $request->input($field);
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $normalized = PhilippineMobileNumber::normalize($value);
        if ($normalized !== null) {
            $request->merge([$field => $normalized]);
        }
    }

    /** @return array<int, mixed> */
    private function brandingImageRules(?string $originalField = null): array
    {
        $rules = ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'];
        if ($originalField !== null) {
            $rules[] = 'required_with:'.$originalField;
        }

        $rules[] = static function (string $attribute, mixed $value, \Closure $fail): void {
            if (! $value instanceof \Illuminate\Http\UploadedFile || ! $value->isValid()) {
                return;
            }

            $image = @getimagesize($value->getRealPath());
            $mime = $image['mime'] ?? null;
            $extension = strtolower($value->getClientOriginalExtension());
            $extensionsByMime = [
                'image/jpeg' => ['jpg', 'jpeg'],
                'image/png' => ['png'],
                'image/webp' => ['webp'],
            ];

            if (! isset($extensionsByMime[$mime]) || ! in_array($extension, $extensionsByMime[$mime], true)) {
                $fail('Upload a valid JPEG, PNG, or WebP image whose file extension matches its contents.');
            }
        };

        return $rules;
    }

    private function trimWhitespace(string $value): string
    {
        return preg_replace('/^[\\s\\p{Z}]+|[\\s\\p{Z}]+$/u', '', $value) ?? trim($value);
    }

    private function normalizedStoreName(string $value): string
    {
        $collapsed = preg_replace('/[\\s\\p{Z}]+/u', ' ', $value) ?? $value;

        return mb_strtolower($this->trimWhitespace($collapsed));
    }

    private function isRemoteImagePath(string $path): bool
    {
        return Str::startsWith($path, '//') || filled(parse_url($path, PHP_URL_SCHEME));
    }

    private function profile(SellerProfile $shop): object
    {
        $address = $this->ownedBusinessAddress($shop);

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
