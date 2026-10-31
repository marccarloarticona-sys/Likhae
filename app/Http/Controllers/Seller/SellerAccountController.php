<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Admin\AuditLog;
use App\Models\Auth\ApplicationDocument;
use App\Models\Buyer\Address;
use App\Models\Auth\RegistrationApplication;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Account\BuyerEmailChangeOtpService;
use App\Services\Account\BuyerMobileChangeOtpService;
use App\Services\Finance\PayoutDestinationReadiness;
use App\Services\Finance\PayoutDestinationManager;
use App\Services\Media\ImageOptimizationService;
use App\Support\PhilippineMobileNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        $description = $request->input('description');
        if (is_string($description)) {
            // Textarea submissions encode each line break as CRLF. Normalize
            // it so the server's character limit matches the UI counter.
            $request->merge([
                'description' => str_replace(["\r\n", "\r"], "\n", $description),
            ]);
        }

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

    public function account(
        Request $request,
        PayoutDestinationReadiness $destinations,
        PayoutDestinationManager $manager,
        BuyerEmailChangeOtpService $emailChanges,
        BuyerMobileChangeOtpService $mobileChanges,
    ): View
    {
        $shop = $this->shop($request);
        $sellerApplication = $shop->user->sellerVerificationApplication();
        $sellerApplication?->load('documents');
        $sellerDocuments = $sellerApplication?->documents
            ->groupBy(fn (ApplicationDocument $document): string => strtoupper((string) $document->document_type))
            ->map(fn ($versions): ApplicationDocument => $versions->sortByDesc('id')->first()) ?? collect();
        $sellerUpdateRequestDocumentIds = AuditLog::query()
            ->where('event', 'seller.document_update_requested_by_admin')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $shop->user_id)
            ->get(['new_values'])
            ->map(fn (AuditLog $entry): int => (int) data_get($entry->new_values, 'document_id'))
            ->filter()
            ->flip()
            ->all();
        $businessAddress = $this->ownedBusinessAddress($shop);
        $pickupAddresses = Address::query()
            ->where('user_id', $shop->user_id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
        $pickupRequestAddressIds = Schema::hasTable('seller_pickup_requests')
            ? \App\Models\Seller\SellerPickupRequest::query()
                ->where('seller_profile_id', $shop->id)
                ->pluck('pickup_address_id')
                ->map(fn ($id): int => (int) $id)
                ->all()
            : [];
        $pickupAddressEditProtectedIds = $pickupRequestAddressIds;
        if ($businessAddress && $shop->sellerOrders()->exists()) {
            $pickupAddressEditProtectedIds[] = (int) $businessAddress->id;
        }
        $pickupAddressDeleteProtectedIds = array_values(array_unique([
            ...$pickupRequestAddressIds,
            ...($businessAddress ? [(int) $businessAddress->id] : []),
        ]));
        $defaultPickupAddress = $pickupAddresses->first(fn (Address $address): bool => $address->is_default)
            ?? $businessAddress
            ?? $pickupAddresses->first();
        $pickupAddressToEdit = null;
        if ($request->filled('pickup_edit')) {
            $pickupAddressToEdit = $pickupAddresses->firstWhere('id', (int) $request->input('pickup_edit'));
            abort_unless($pickupAddressToEdit, 404);
            abort_if(in_array((int) $pickupAddressToEdit->id, $pickupAddressEditProtectedIds, true), 409, 'This address is used by existing pickup or order routes. Add a new address to preserve those routes.');
        }
        $payoutDestinationReady = $destinations->readyFor('seller_profiles');
        $payoutDestinationSaved = $payoutDestinationReady && $destinations->hasDestination($shop);
        $payoutMultipleReady = $destinations->supportsMultipleFor('seller_profiles');
        $payoutDestinations = $payoutMultipleReady ? $manager->accounts($shop) : [];

        return view('seller.account', [
            'tab' => $request->input('tab', 'profile'),
            'seller' => $shop->user,
            'pendingEmail' => $emailChanges->pendingEmail($shop->user),
            'pendingPhone' => $mobileChanges->pendingPhone($shop->user),
            'storeProfile' => $this->profile($shop),
            'sellerApplication' => $sellerApplication,
            'sellerDocuments' => $sellerDocuments,
            'sellerUpdateRequestDocumentIds' => $sellerUpdateRequestDocumentIds,
            'businessAddress' => $businessAddress,
            'pickupAddresses' => $pickupAddresses,
            'defaultPickupAddress' => $defaultPickupAddress,
            'pickupAddressToEdit' => $pickupAddressToEdit,
            'pickupAddressEditProtectedIds' => array_values(array_unique($pickupAddressEditProtectedIds)),
            'pickupAddressDeleteProtectedIds' => $pickupAddressDeleteProtectedIds,
            'payoutProfile' => $shop,
            'payoutDestinationReady' => $payoutDestinationReady,
            'payoutDestinationSaved' => $payoutDestinationSaved,
            'payoutMultipleReady' => $payoutMultipleReady,
            'payoutDestinations' => $payoutDestinations,
        ]);
    }

    public function updateProfile(
        Request $request,
        BuyerEmailChangeOtpService $emailChanges,
        BuyerMobileChangeOtpService $mobileChanges,
    ): RedirectResponse
    {
        $owner = $this->shop($request)->user;

        if ($request->input('contact_action') === 'cancel') {
            $emailChanges->invalidate($owner);
            $mobileChanges->invalidate($owner);

            return back()->with('status', 'Pending contact change canceled.');
        }

        if ($request->input('contact_action') === 'resend_email') {
            $pendingEmail = $emailChanges->pendingEmail($owner);
            if (! $pendingEmail) {
                throw ValidationException::withMessages(['email' => 'Request an email change before asking for a verification code.']);
            }

            $emailChanges->send($owner, $pendingEmail);

            return back()->withInput()->with('status', 'A new verification code was sent to '.$pendingEmail.'.');
        }

        if ($request->input('contact_action') === 'resend_mobile') {
            $pendingPhone = $mobileChanges->pendingPhone($owner);
            if (! $pendingPhone) {
                throw ValidationException::withMessages(['contact_number' => 'Request a mobile number change before asking for a verification code.']);
            }

            $mobileChanges->send($owner, $pendingPhone);

            return back()->withInput()->with('status', 'A new verification code was sent to your verified email address.');
        }

        foreach (['first_name', 'middle_initial', 'last_name', 'name_extension', 'email'] as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $value = $this->trimWhitespace($value);
                $request->merge([$field => $field === 'email' ? mb_strtolower($value) : $value]);
            }
        }
        $this->normalizeOptionalMobile($request, 'contact_number');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:10'],
            'last_name' => ['required', 'string', 'max:100'],
            'name_extension' => ['nullable', 'string', 'max:20'],
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
            'email_change_code' => ['nullable', 'digits:6'],
            'mobile_change_code' => ['nullable', 'digits:6'],
        ]);

        if (User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->whereKeyNot($owner->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This email address is already in use.',
            ]);
        }
        $normalizedContact = filled($data['contact_number'] ?? null)
            ? PhilippineMobileNumber::normalize((string) $data['contact_number'])
            : null;
        if ($normalizedContact !== null) {
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

        $emailChanged = mb_strtolower((string) $owner->email) !== $data['email'];
        $currentNormalizedContact = PhilippineMobileNumber::normalize((string) $owner->contact_number);
        $sameLegacyContact = trim((string) ($data['contact_number'] ?? '')) === trim((string) $owner->contact_number);
        $phoneChanged = $normalizedContact !== null
            ? $normalizedContact !== $currentNormalizedContact
            : (filled($data['contact_number'] ?? null) && ! $sameLegacyContact);

        if (blank($data['contact_number'] ?? null) && filled($owner->contact_number)) {
            throw ValidationException::withMessages([
                'contact_number' => 'Enter a Philippine mobile number beginning with +63. A saved contact number cannot be cleared here.',
            ]);
        }
        if ($emailChanged && $phoneChanged) {
            throw ValidationException::withMessages([
                'contact_number' => 'Change and verify your email first, then request a separate mobile number change.',
            ]);
        }

        $profileChanges = [
            'first_name' => $data['first_name'],
            'middle_initial' => $data['middle_initial'] ?? null,
            'last_name' => $data['last_name'],
            'name_extension' => $data['name_extension'] ?? null,
        ];

        if ($emailChanged) {
            if (! $owner->email_verified_at) {
                throw ValidationException::withMessages([
                    'email' => 'Verify your current account email before changing it.',
                ]);
            }

            if (! filled($data['email_change_code'] ?? null)) {
                if ($emailChanges->pendingEmail($owner) !== $data['email']) {
                    $mobileChanges->invalidate($owner);
                    $emailChanges->send($owner, $data['email']);
                }

                return back()->withInput()->with('status', 'A six-digit code was sent to the new email address. Your current verified email stays active until you confirm the code.');
            }

            $currentEmail = mb_strtolower((string) $owner->email);
            try {
                $emailChanges->confirm($owner, (string) $data['email_change_code'], function (string $pendingEmail) use ($owner, $data, $profileChanges, $currentEmail): void {
                    if ($pendingEmail !== $data['email']) {
                        throw ValidationException::withMessages(['email_change_code' => 'This code was sent for a different email address. Request a new code.']);
                    }

                    DB::transaction(function () use ($owner, $pendingEmail, $profileChanges, $currentEmail): void {
                        $lockedOwner = User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
                        if (! $lockedOwner->email_verified_at || mb_strtolower((string) $lockedOwner->email) !== $currentEmail) {
                            throw ValidationException::withMessages(['email_change_code' => 'The current verified email changed. Request a new email change code.']);
                        }

                        if (User::query()->whereRaw('LOWER(email) = ?', [$pendingEmail])->whereKeyNot($lockedOwner->id)->exists()) {
                            throw ValidationException::withMessages(['email' => 'This email address is no longer available. Try another address.']);
                        }

                        $lockedOwner->forceFill($profileChanges + [
                            'email' => $pendingEmail,
                            'email_verified_at' => now(),
                        ])->save();
                    });
                });
            } catch (QueryException $exception) {
                if (User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->whereKeyNot($owner->id)->exists()) {
                    $emailChanges->invalidate($owner);
                    throw ValidationException::withMessages(['email' => 'This email address is no longer available. Try another address.']);
                }

                throw $exception;
            }

            $mobileChanges->invalidate($owner);

            return back()->with('status', 'Your new email address is verified. Profile changes have been saved.');
        }

        if ($phoneChanged) {
            if (! $owner->email_verified_at) {
                throw ValidationException::withMessages(['contact_number' => 'Verify your account email before changing your mobile number.']);
            }

            if (! filled($data['mobile_change_code'] ?? null)) {
                if ($mobileChanges->pendingPhone($owner) !== $normalizedContact) {
                    $emailChanges->invalidate($owner);
                    $mobileChanges->send($owner, (string) $normalizedContact);
                }

                return back()->withInput()->with('status', 'A six-digit code was sent to your verified email address. Your current mobile number stays active until you confirm it.');
            }

            $originalContact = (string) $owner->contact_number;
            $verifiedEmail = mb_strtolower((string) $owner->email);
            try {
                $mobileChanges->confirm($owner, (string) $data['mobile_change_code'], function (string $pendingPhone) use ($owner, $normalizedContact, $originalContact, $verifiedEmail, $profileChanges): void {
                    if ($pendingPhone !== $normalizedContact) {
                        throw ValidationException::withMessages(['mobile_change_code' => 'This code was sent for a different mobile number. Request a new code.']);
                    }

                    DB::transaction(function () use ($owner, $pendingPhone, $originalContact, $verifiedEmail, $profileChanges): void {
                        $lockedOwner = User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
                        if (! $lockedOwner->email_verified_at || mb_strtolower((string) $lockedOwner->email) !== $verifiedEmail) {
                            throw ValidationException::withMessages(['mobile_change_code' => 'The verified email changed. Request a new mobile number code.']);
                        }
                        if ((string) $lockedOwner->contact_number !== $originalContact) {
                            throw ValidationException::withMessages(['mobile_change_code' => 'Your mobile number changed after this code was sent. Request a new code.']);
                        }

                        $localContact = '0'.substr($pendingPhone, 3);
                        if (User::query()->whereKeyNot($lockedOwner->id)->whereIn('contact_number', [$pendingPhone, $localContact])->exists()) {
                            throw ValidationException::withMessages(['contact_number' => 'This mobile number is already in use.']);
                        }

                        $lockedOwner->forceFill($profileChanges + ['contact_number' => $pendingPhone])->save();
                    });
                });
            } catch (QueryException $exception) {
                $localContact = '0'.substr((string) $normalizedContact, 3);
                if (User::query()->whereKeyNot($owner->id)->whereIn('contact_number', [$normalizedContact, $localContact])->exists()) {
                    $mobileChanges->invalidate($owner);
                    throw ValidationException::withMessages(['contact_number' => 'This mobile number is no longer available. Try another number.']);
                }

                throw $exception;
            }

            $emailChanges->invalidate($owner);

            return back()->with('status', 'Your mobile number has been confirmed. Profile changes have been saved.');
        }

        if ($normalizedContact !== null) {
            $profileChanges['contact_number'] = $normalizedContact;
        }
        $owner->forceFill($profileChanges)->save();

        return back()->with('status', 'Profile updated.');
    }

    public function updateBusiness(Request $request): RedirectResponse
    {
        $shop = $this->shop($request);

        $data = $request->validate([
            'dti_sec_number' => ['nullable', 'string', 'max:100'],
            'tin' => ['nullable', 'string', 'max:80'],
        ]);

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

        if ($profileChanges !== []) {
            $shop->update($profileChanges);
        }

        return back()->with('status', 'Business information updated.');
    }

    public function requestDocumentUpdate(Request $request): RedirectResponse
    {
        $shop = $this->shop($request);
        $seller = $shop->user;
        $request->validate([
            'documents' => ['required', 'array:'.implode(',', array_keys(ApplicationDocument::SELLER_UPLOAD_FIELDS))],
            'documents.*' => [
                'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
                'extensions:jpg,jpeg,png,webp,pdf', 'max:5120',
            ],
        ]);
        $files = collect($request->file('documents', []))
            ->filter(fn ($file): bool => $file instanceof \Illuminate\Http\UploadedFile)
            ->all();

        if ($files === []) {
            throw ValidationException::withMessages([
                'documents' => 'Choose at least one document to send for re-verification.',
            ]);
        }

        $storedPaths = [];
        try {
            DB::transaction(function () use ($request, $seller, $files, &$storedPaths): void {
                $lockedSeller = User::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
                if (! $lockedSeller->isAccountType(User::TYPE_SELLER)
                    || strtoupper((string) $lockedSeller->status) !== User::STATUS_ACTIVE
                    || ! $lockedSeller->sellerProfile()->where('status', 'ACTIVE')->exists()) {
                    throw ValidationException::withMessages([
                        'documents' => 'This seller account is not available for document updates.',
                    ]);
                }

                $application = $lockedSeller->sellerVerificationApplication();
                if ($application) {
                    $lockedApplication = RegistrationApplication::query()
                        ->whereKey($application->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                } else {
                    // Older active seller accounts may not have a registration row.
                    // Create a durable verification record only when they submit documents.
                    $lockedApplication = RegistrationApplication::create([
                        'application_number' => 'REG-SELLER-LEGACY-'.$lockedSeller->id.'-'.Str::upper(Str::random(8)),
                        'user_id' => $lockedSeller->id,
                        'status' => RegistrationApplication::STATUS_APPROVED,
                        'submitted_at' => now(),
                        'reviewed_at' => now(),
                        'decision_notes' => 'Verification record created for an existing active seller account because no registration application was available.',
                    ]);
                }

                $submittedDocuments = [];
                foreach ($files as $input => $file) {
                    $type = ApplicationDocument::SELLER_UPLOAD_FIELDS[$input] ?? null;
                    if (! $type) {
                        throw ValidationException::withMessages([
                            'documents' => 'Select a supported seller verification document.',
                        ]);
                    }

                    $latest = $lockedApplication->documents()
                        ->whereRaw('UPPER(document_type) = ?', [$type])
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->first();
                    if ($latest && strtoupper((string) $latest->verification_status) === 'PENDING') {
                        throw ValidationException::withMessages([
                            'documents' => ApplicationDocument::DOCUMENT_LABELS[$type].' is already waiting for Admin review.',
                        ]);
                    }

                    $directory = 'registration/seller/'.$lockedApplication->application_number
                        .'/'.strtolower($type).'/updates';
                    $path = $file->store($directory, 'registrations');
                    if (! is_string($path)) {
                        throw new \RuntimeException('The verification document could not be stored.');
                    }
                    $storedPaths[] = $path;

                    $document = $lockedApplication->documents()->create([
                        'document_type' => $type,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType(),
                        'verification_status' => 'PENDING',
                    ]);
                    $submittedDocuments[] = [
                        'document_id' => $document->id,
                        'document_type' => $type,
                        'verification_status' => 'PENDING',
                    ];
                }

                AuditLog::create([
                    'actor_user_id' => $lockedSeller->id,
                    'event' => 'seller.document_update_requested',
                    'auditable_type' => User::class,
                    'auditable_id' => $lockedSeller->id,
                    'new_values' => ['application_id' => $lockedApplication->id, 'documents' => $submittedDocuments],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'created_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('registrations')->delete($storedPaths);

            throw $exception;
        }

        return redirect()->route('seller.account', ['tab' => 'verification'])
            ->with('status', 'Your document update request was sent to Admin for review.');
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
            'joined' => $shop->created_at?->format('M Y'),
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
