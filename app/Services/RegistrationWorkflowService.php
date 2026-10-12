<?php

namespace App\Services;

use App\Http\Requests\StoreRegistrationRequest;
use App\Models\Admin\AuditLog;
use App\Models\Auth\ApplicationDocument;
use App\Models\Auth\RegistrationApplication;
use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsApplicationData;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderApplicationData;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\SellerApplicationData;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Notifications\RegistrationDecisionNotification;
use App\Services\Media\ImageOptimizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegistrationWorkflowService
{
    /**
     * @param  array<string, mixed>  $overrides  Server-derived values that must
     *                                           take precedence over client input.
     */
    public function submit(StoreRegistrationRequest $request, array $overrides = []): RegistrationApplication
    {
        $data = array_replace($request->validated(), $overrides);
        $this->ensureRiderCenterEligibility($request, $data);
        $storedPaths = [];
        $publicPaths = [];

        try {
            return DB::transaction(function () use ($request, $data, &$storedPaths, &$publicPaths): RegistrationApplication {
                $user = User::create([
                    'account_type' => $request->accountTypeConstant(),
                    'first_name' => trim($data['first_name']),
                    'middle_initial' => filled($data['middle_initial'] ?? null) ? trim((string) $data['middle_initial']) : null,
                    'last_name' => trim($data['last_name']),
                    'name_extension' => filled($data['name_extension'] ?? null) ? trim((string) $data['name_extension']) : null,
                    'sex' => $this->normalizeSex((string) $data['sex']),
                    'email' => mb_strtolower(trim($data['email'])),
                    'contact_number' => trim($data['contact_number']),
                    'birthday' => $data['birthday'],
                    'email_verified_at' => now(),
                    'password' => $data['password'],
                    'status' => User::STATUS_PENDING,
                ]);

                $addressData = [
                    'user_id' => $user->id,
                    'label' => 'Primary',
                    'recipient_name' => $user->name,
                    'contact_number' => $user->contact_number,
                    'province_code' => $data['province_code'],
                    'province_name' => $data['province'],
                    'municipality_code' => $data['municipality_code'],
                    'municipality_name' => $data['municipality'],
                    'barangay_code' => $data['barangay_code'],
                    'barangay_name' => $data['barangay'],
                    'postal_code' => $data['postal_code'] ?? null,
                    'house_number' => $data['house_number'] ?? null,
                    'street_address' => trim($data['street']),
                    'landmark' => $data['landmark'] ?? null,
                    'latitude' => $data['latitude'] ?? null,
                    'longitude' => $data['longitude'] ?? null,
                    'is_default' => true,
                ];
                $address = Address::create($addressData);

                $application = RegistrationApplication::create([
                    'application_number' => $this->newApplicationNumber(),
                    'user_id' => $user->id,
                    'status' => RegistrationApplication::STATUS_PENDING,
                    'submitted_at' => now(),
                ]);

                match ($user->account_type) {
                    User::TYPE_SELLER => SellerApplicationData::create([
                        'registration_application_id' => $application->id,
                        'category_id' => (int) $data['line_of_business'],
                        'business_name' => trim($data['business_name']),
                        'business_registration_number' => $data['business_registration_number'] ?? null,
                        'seller_type' => $data['seller_type'] ?? null,
                        'tin' => $data['tin'] ?? null,
                    ]),
                    User::TYPE_LOGISTICS => LogisticsApplicationData::create([
                        'registration_application_id' => $application->id,
                        'business_address_id' => $address->id,
                        'business_name' => trim($data['business_name']),
                        'business_registration_number' => $data['business_registration_number'] ?? null,
                        'dti_registration_number' => $data['dti_registration_number'] ?? null,
                        'tin' => $data['tin'] ?? null,
                    ]),
                    User::TYPE_RIDER => RiderApplicationData::create([
                        'registration_application_id' => $application->id,
                        'target_logistics_center_id' => (int) $data['target_logistics_center_id'],
                        'vehicle_type' => $data['vehicle_type'],
                        'plate_number' => mb_strtoupper(trim($data['plate_number'])),
                        'drivers_license_number' => mb_strtoupper(trim($data['drivers_license_number'])),
                    ]),
                    default => null,
                };

                if ($user->account_type === User::TYPE_SELLER) {
                    SellerProfile::create([
                        'user_id' => $user->id,
                        'primary_category_id' => (int) $data['line_of_business'],
                        'business_address_id' => $address->id,
                        'business_name' => trim($data['business_name']),
                        'business_registration_number' => $data['business_registration_number'] ?? null,
                        'seller_type' => $data['seller_type'] ?? null,
                        'tin' => $data['tin'] ?? null,
                        'status' => 'PENDING',
                    ]);
                } elseif ($user->account_type === User::TYPE_LOGISTICS) {
                    LogisticsCenter::create([
                        'owner_user_id' => $user->id,
                        'address_id' => $address->id,
                        'code' => 'LC-'.str_pad((string) $application->id, 6, '0', STR_PAD_LEFT),
                        'business_name' => trim($data['business_name']),
                        'business_registration_number' => $data['business_registration_number'] ?? null,
                        'dti_registration_number' => $data['dti_registration_number'] ?? null,
                        'tin' => $data['tin'] ?? null,
                        'status' => 'PENDING',
                    ]);
                } elseif ($user->account_type === User::TYPE_RIDER) {
                    RiderProfile::create([
                        'user_id' => $user->id,
                        'logistics_center_id' => (int) $data['target_logistics_center_id'],
                        'vehicle_type' => $data['vehicle_type'],
                        'plate_number' => mb_strtoupper(trim($data['plate_number'])),
                        'drivers_license_number' => mb_strtoupper(trim($data['drivers_license_number'])),
                        'status' => 'PENDING',
                    ]);
                }

                $documentMap = [
                    'valid_id' => 'VALID_ID',
                    'business_permit' => 'BUSINESS_PERMIT',
                    'dti_certificate' => 'DTI_CERTIFICATE',
                    'sec_certificate' => 'SEC_CERTIFICATE',
                    'bir_form_2303' => 'BIR_FORM_2303',
                    'or_cr' => 'VEHICLE_OR_CR',
                    'drivers_license' => 'DRIVERS_LICENSE',
                ];

                foreach ($documentMap as $input => $type) {
                    $file = $request->file($input);
                    if (! $file) {
                        continue;
                    }

                    $path = $file->store('registration/'.$application->application_number, 'registrations');
                    $storedPaths[] = $path;

                    ApplicationDocument::create([
                        'registration_application_id' => $application->id,
                        'document_type' => $type,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getClientMimeType(),
                        'verification_status' => 'PENDING',
                    ]);
                }

                // Handle optional profile picture
                if ($request->hasFile('profile_picture')) {
                    $profilePicture = $request->file('profile_picture');
                    $path = app(ImageOptimizationService::class)->store($profilePicture, 'profile-photos');
                    $publicPaths[] = $path;
                    $user->update(['profile_photo_path' => $path]);
                } else {
                    $googlePhoto = $request->hasSession()
                        ? $request->session()->get('google_buyer_registration.profile_photo_url')
                        : null;
                    if (is_string($googlePhoto) && str_starts_with($googlePhoto, 'https://')) {
                        $user->update(['profile_photo_path' => $googlePhoto]);
                    }
                }

                if ($user->account_type === User::TYPE_SELLER && $user->sellerProfile) {
                    $sellerProfile = $user->sellerProfile;
                    foreach (['store_avatar' => 'avatar_path', 'store_banner' => 'banner_path'] as $input => $column) {
                        if (! $request->hasFile($input)) {
                            continue;
                        }

                        $path = app(ImageOptimizationService::class)->store(
                            $request->file($input),
                            'seller-branding',
                        );
                        $publicPaths[] = $path;
                        $sellerProfile->{$column} = $path;
                    }
                    $sellerProfile->save();
                }

                $this->audit(
                    actor: null,
                    event: 'registration.submitted',
                    record: $application,
                    oldValues: null,
                    newValues: [
                        'status' => RegistrationApplication::STATUS_PENDING,
                        'account_type' => $user->account_type,
                    ],
                    request: $request,
                );

                return $application->load(['user', 'documents', 'sellerData', 'logisticsData', 'riderData']);
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('registrations')->delete($path);
            }

            foreach ($publicPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }
    }

    public function approveByAdmin(
        RegistrationApplication $application,
        User $reviewer,
        ?string $notes = null,
        ?Request $request = null,
    ): RegistrationApplication {
        $application->loadMissing(['user', 'documents', 'sellerData', 'logisticsData', 'riderData']);
        $type = $application->user?->account_type;

        if (! in_array($type, [User::TYPE_BUYER, User::TYPE_SELLER, User::TYPE_LOGISTICS, User::TYPE_RIDER], true)) {
            throw ValidationException::withMessages(['application' => 'Unsupported registration account type.']);
        }

        return $this->approve(
            application: $application,
            reviewer: $reviewer,
            notes: $notes,
            request: $request,
            expectedType: $type,
        );
    }

    public function approveRider(
        RegistrationApplication $application,
        User $reviewer,
        ?string $notes = null,
        ?Request $request = null,
    ): RegistrationApplication {
        $application->loadMissing(['user', 'documents', 'riderData.targetLogisticsCenter']);

        if ($application->user?->account_type !== User::TYPE_RIDER) {
            throw ValidationException::withMessages(['application' => 'This is not a rider application.']);
        }

        $center = $reviewer->logisticsCenter()->where('status', 'ACTIVE')->first();
        if (! $center || (int) $application->riderData?->target_logistics_center_id !== (int) $center->id) {
            abort(403, 'This rider application belongs to another logistics center.');
        }

        return $this->approve(
            application: $application,
            reviewer: $reviewer,
            notes: $notes,
            request: $request,
            expectedType: User::TYPE_RIDER,
        );
    }

    public function reject(
        RegistrationApplication $application,
        User $reviewer,
        string $reason,
        ?Request $request = null,
    ): RegistrationApplication {
        $application->loadMissing(['user', 'documents']);
        $before = $application->status;

        if (! in_array($before, [RegistrationApplication::STATUS_PENDING, RegistrationApplication::STATUS_UNDER_REVIEW], true)) {
            throw ValidationException::withMessages(['application' => 'Only pending applications can be rejected.']);
        }

        DB::transaction(function () use ($application, $reviewer, $reason, $request): void {
            $application = $application->newQuery()->lockForUpdate()->findOrFail($application->id);
            $before = $application->status;

            if (! in_array($before, [RegistrationApplication::STATUS_PENDING, RegistrationApplication::STATUS_UNDER_REVIEW], true)) {
                throw ValidationException::withMessages(['application' => 'Only pending applications can be rejected.']);
            }

            $application->loadMissing('documents');
            $application->update([
                'status' => RegistrationApplication::STATUS_REJECTED,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
                'decision_notes' => null,
            ]);

            $application->documents()->update([
                'verification_status' => 'REJECTED',
                'verified_by_user_id' => $reviewer->id,
                'verified_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit(
                actor: $reviewer,
                event: 'registration.rejected',
                record: $application,
                oldValues: ['status' => $before],
                newValues: ['status' => RegistrationApplication::STATUS_REJECTED, 'reason' => $reason],
                request: $request,
            );
        });

        $this->notifyDecision($application->fresh('user'));

        return $application->fresh(['user', 'documents']);
    }

    private function approve(
        RegistrationApplication $application,
        User $reviewer,
        ?string $notes,
        ?Request $request,
        string $expectedType,
    ): RegistrationApplication {
        $before = $application->status;

        if (! in_array($before, [RegistrationApplication::STATUS_PENDING, RegistrationApplication::STATUS_UNDER_REVIEW], true)) {
            throw ValidationException::withMessages(['application' => 'Only pending applications can be approved.']);
        }

        DB::transaction(function () use ($application, $reviewer, $notes, $request, $expectedType): void {
            $application = $application->newQuery()->lockForUpdate()->findOrFail($application->id);
            $before = $application->status;

            if (! in_array($before, [RegistrationApplication::STATUS_PENDING, RegistrationApplication::STATUS_UNDER_REVIEW], true)) {
                throw ValidationException::withMessages(['application' => 'Only pending applications can be approved.']);
            }

            $application->loadMissing(['user.addresses', 'sellerData', 'logisticsData', 'riderData', 'documents']);
            $user = $application->user;

            if (! $user || $user->account_type !== $expectedType) {
                throw ValidationException::withMessages(['application' => 'The application account type is invalid.']);
            }

            $this->ensureDocumentsReady($application, $expectedType);

            $defaultAddress = $user->addresses()->where('is_default', true)->first() ?? $user->addresses()->first();

            if ($expectedType === User::TYPE_SELLER) {
                $data = $application->sellerData;
                if (! $data) {
                    throw ValidationException::withMessages(['application' => 'Seller application data is missing.']);
                }

                SellerProfile::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'primary_category_id' => $data->category_id,
                        'business_address_id' => $defaultAddress?->id,
                        'business_name' => $data->business_name,
                        'business_registration_number' => $data->business_registration_number,
                        'seller_type' => $data->seller_type,
                        'tin' => $data->tin,
                        'status' => 'ACTIVE',
                        'approved_by_user_id' => $reviewer->id,
                        'approved_at' => now(),
                    ],
                );
            }

            if ($expectedType === User::TYPE_LOGISTICS) {
                $data = $application->logisticsData;
                if (! $data) {
                    throw ValidationException::withMessages(['application' => 'Logistics application data is missing.']);
                }

                LogisticsCenter::updateOrCreate(
                    ['owner_user_id' => $user->id],
                    [
                        'address_id' => $data->business_address_id ?: $defaultAddress?->id,
                        'code' => 'LC-'.str_pad((string) $application->id, 6, '0', STR_PAD_LEFT),
                        'business_name' => $data->business_name,
                        'business_registration_number' => $data->business_registration_number,
                        'dti_registration_number' => $data->dti_registration_number,
                        'tin' => $data->tin,
                        'status' => 'ACTIVE',
                        'approved_by_user_id' => $reviewer->id,
                        'approved_at' => now(),
                    ],
                );
            }

            if ($expectedType === User::TYPE_RIDER) {
                $data = $application->riderData;
                if (! $data) {
                    throw ValidationException::withMessages(['application' => 'Rider application data is missing.']);
                }

                RiderProfile::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'logistics_center_id' => $data->target_logistics_center_id,
                        'vehicle_type' => $data->vehicle_type,
                        'plate_number' => $data->plate_number,
                        'drivers_license_number' => $data->drivers_license_number,
                        'status' => 'ACTIVE',
                        'approved_by_user_id' => $reviewer->id,
                        'approved_at' => now(),
                    ],
                );
            }

            $user->update([
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => $user->email_verified_at ?: now(),
            ]);

            $application->update([
                'status' => RegistrationApplication::STATUS_APPROVED,
                'reviewed_by_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'decision_notes' => $notes,
                'rejection_reason' => null,
            ]);

            $this->audit(
                actor: $reviewer,
                event: 'registration.approved',
                record: $application,
                oldValues: ['status' => $before],
                newValues: ['status' => RegistrationApplication::STATUS_APPROVED, 'account_type' => $expectedType],
                request: $request,
            );
        });

        $fresh = $application->fresh(['user', 'documents']);
        $this->notifyDecision($fresh);

        return $fresh;
    }

    public function verifyDocument(
        RegistrationApplication $application,
        ApplicationDocument $document,
        User $reviewer,
        ?Request $request = null,
    ): ApplicationDocument {
        return DB::transaction(function () use ($application, $document, $reviewer, $request): ApplicationDocument {
            $application = $application->newQuery()->lockForUpdate()->findOrFail($application->id);
            $this->ensureReviewableStatus($application);
            $document = $application->documents()->lockForUpdate()->find($document->id);

            if (! $document) {
                throw ValidationException::withMessages(['document' => 'This document does not belong to the application.']);
            }

            $before = $document->verification_status;
            $document->update([
                'verification_status' => 'VERIFIED',
                'verified_by_user_id' => $reviewer->id,
                'verified_at' => now(),
                'rejection_reason' => null,
            ]);

            if ($application->status === RegistrationApplication::STATUS_PENDING) {
                $application->update(['status' => RegistrationApplication::STATUS_UNDER_REVIEW]);
            }

            $this->audit(
                actor: $reviewer,
                event: 'registration.document_verified',
                record: $application,
                oldValues: ['document_id' => $document->id, 'verification_status' => $before],
                newValues: ['document_id' => $document->id, 'verification_status' => 'VERIFIED'],
                request: $request,
            );

            return $document->fresh('verifier');
        });
    }

    public function rejectDocument(
        RegistrationApplication $application,
        ApplicationDocument $document,
        User $reviewer,
        string $reason,
        ?Request $request = null,
    ): ApplicationDocument {
        return DB::transaction(function () use ($application, $document, $reviewer, $reason, $request): ApplicationDocument {
            $application = $application->newQuery()->lockForUpdate()->findOrFail($application->id);
            $this->ensureReviewableStatus($application);
            $document = $application->documents()->lockForUpdate()->find($document->id);

            if (! $document) {
                throw ValidationException::withMessages(['document' => 'This document does not belong to the application.']);
            }

            $before = $document->verification_status;
            $document->update([
                'verification_status' => 'REJECTED',
                'verified_by_user_id' => $reviewer->id,
                'verified_at' => now(),
                'rejection_reason' => trim($reason),
            ]);

            if ($application->status === RegistrationApplication::STATUS_PENDING) {
                $application->update(['status' => RegistrationApplication::STATUS_UNDER_REVIEW]);
            }

            $this->audit(
                actor: $reviewer,
                event: 'registration.document_rejected',
                record: $application,
                oldValues: ['document_id' => $document->id, 'verification_status' => $before],
                newValues: ['document_id' => $document->id, 'verification_status' => 'REJECTED', 'reason' => trim($reason)],
                request: $request,
            );

            return $document->fresh('verifier');
        });
    }

    private function ensureReviewableStatus(RegistrationApplication $application): void
    {
        if (! in_array($application->status, [RegistrationApplication::STATUS_PENDING, RegistrationApplication::STATUS_UNDER_REVIEW], true)) {
            throw ValidationException::withMessages(['application' => 'This application is no longer awaiting review.']);
        }
    }

    private function ensureDocumentsReady(RegistrationApplication $application, string $expectedType): void
    {
        $documents = $application->documents;
        $requiredTypes = $this->requiredDocumentTypes($application, $expectedType);
        $missing = collect($requiredTypes)
            ->reject(fn (string $type): bool => $documents->contains(fn (ApplicationDocument $document): bool => strtoupper((string) $document->document_type) === $type))
            ->values();
        $unverified = $documents
            ->filter(fn (ApplicationDocument $document): bool => strtoupper((string) $document->verification_status) !== 'VERIFIED')
            ->map(fn (ApplicationDocument $document): string => str($document->document_type)->replace('_', ' ')->headline()->toString())
            ->unique()
            ->values();

        $errors = [];
        if ($missing->isNotEmpty()) {
            $errors['documents'][] = 'Required documents are missing: '.$missing->map(fn (string $type): string => str($type)->replace('_', ' ')->headline()->toString())->implode(', ').'.';
        }
        if ($unverified->isNotEmpty()) {
            $errors['documents'][] = 'Verify or reject every submitted document before approving: '.$unverified->implode(', ').'.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function requiredDocumentTypes(RegistrationApplication $application, string $expectedType): array
    {
        $required = ['VALID_ID'];

        if ($expectedType === User::TYPE_SELLER) {
            $sellerType = strtolower((string) $application->sellerData?->seller_type);
            if ($sellerType === 'sole_proprietorship') {
                $required = array_merge($required, ['DTI_CERTIFICATE', 'BIR_FORM_2303']);
            } elseif ($sellerType === 'corporation') {
                $required = array_merge($required, ['SEC_CERTIFICATE', 'BIR_FORM_2303']);
            }
        }

        if ($expectedType === User::TYPE_LOGISTICS) {
            $required = array_merge($required, ['BUSINESS_PERMIT', 'SEC_CERTIFICATE', 'BIR_FORM_2303']);
        }

        if ($expectedType === User::TYPE_RIDER) {
            $required = array_merge($required, ['VEHICLE_OR_CR', 'DRIVERS_LICENSE']);
        }

        return array_values(array_unique($required));
    }

    private function normalizeSex(string $sex): string
    {
        return match (strtolower($sex)) {
            'male' => 'MALE',
            'female' => 'FEMALE',
            'other' => 'OTHER',
            default => 'PREFER_NOT_TO_SAY',
        };
    }

    /** @param array<string, mixed> $data */
    private function ensureRiderCenterEligibility(StoreRegistrationRequest $request, array $data): void
    {
        if ($request->accountTypeConstant() !== User::TYPE_RIDER) {
            return;
        }

        $eligible = LogisticsCenter::query()
            ->eligibleForRiderAddress(
                (string) ($data['province_code'] ?? ''),
                (string) ($data['municipality_code'] ?? ''),
            )
            ->whereKey((int) ($data['target_logistics_center_id'] ?? 0))
            ->exists();

        if (! $eligible) {
            throw ValidationException::withMessages([
                'target_logistics_center_id' => 'The selected logistics hub does not serve the rider address municipality.',
            ]);
        }
    }

    private function newApplicationNumber(): string
    {
        do {
            $number = 'REG-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (RegistrationApplication::query()->where('application_number', $number)->exists());

        return $number;
    }

    private function notifyDecision(?RegistrationApplication $application): void
    {
        $user = $application?->user;
        if (! $user) {
            return;
        }

        try {
            $user->notify(new RegistrationDecisionNotification($application));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function audit(
        ?User $actor,
        string $event,
        RegistrationApplication $record,
        ?array $oldValues,
        ?array $newValues,
        ?Request $request,
    ): void {
        AuditLog::create([
            'actor_user_id' => $actor?->id,
            'event' => $event,
            'auditable_type' => RegistrationApplication::class,
            'auditable_id' => $record->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }
}
