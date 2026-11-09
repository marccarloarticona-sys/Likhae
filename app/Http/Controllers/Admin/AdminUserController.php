<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AccountAlert;
use App\Models\Admin\AuditLog;
use App\Models\Admin\Notification;
use App\Models\Auth\ApplicationDocument;
use App\Models\Auth\RegistrationApplication;
use App\Models\User;
use App\Services\Admin\AdminUserSessionManager;
use App\Services\Buyer\BuyerRiskMonitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    private const ACCOUNT_WARNING_CATEGORIES = [
        User::TYPE_BUYER => [
            'REPEATED_CANCELLATIONS' => 'Repeated unjustified cancellations',
            'VERIFIED_NONPAYMENT' => 'Verified failure to pay an owed amount',
            'FRAUDULENT_ORDER_ATTEMPTS' => 'Fraudulent order attempts',
            'ABUSIVE_MARKETPLACE_USE' => 'Abusive use of marketplace features',
            'REPEATED_POLICY_VIOLATIONS' => 'Repeated substantiated policy violations',
            'OTHER' => 'Other',
        ],
        User::TYPE_SELLER => [
            'SELLER_CANCELLATIONS' => 'Repeated Seller-caused cancellations without valid reason',
            'PROHIBITED_PRODUCTS' => 'Prohibited product listings',
            'MISLEADING_LISTINGS' => 'Misleading listings',
            'UNRESOLVED_COMPLIANCE' => 'Repeated unresolved compliance warnings',
            'FRAUDULENT_ACTIVITY' => 'Substantiated fraudulent marketplace activity',
            'REFUND_NONCOOPERATION' => 'Failure to cooperate with valid complaints or refund obligations',
            'OTHER' => 'Other',
        ],
        User::TYPE_LOGISTICS => [
            'DELIVERY_FAILURES' => 'Repeated unexplained delivery failures',
            'INACCURATE_TRACKING' => 'Inaccurate tracking updates',
            'PARCEL_INCIDENTS' => 'Unresolved parcel-handling incidents',
            'SERVICE_POLICY_VIOLATIONS' => 'Repeated substantiated service-policy violations',
            'OTHER' => 'Other',
        ],
    ];

    private const ACCOUNT_SUSPENSION_DURATIONS = [
        'week' => ['label' => '1 week', 'unit' => 'week', 'count' => 1],
        'fortnight' => ['label' => '2 weeks', 'unit' => 'week', 'count' => 2],
        'month' => ['label' => '1 month', 'unit' => 'month', 'count' => 1],
        'quarter' => ['label' => '3 months', 'unit' => 'month', 'count' => 3],
        'half_year' => ['label' => '6 months', 'unit' => 'month', 'count' => 6],
    ];

    public function index(Request $request, AdminUserSessionManager $sessions, BuyerRiskMonitor $riskMonitor): View
    {
        $riskFilter = $request->query('risk') === 'flagged' ? 'flagged' : 'all';
        $openRiskBuyerIds = $riskMonitor->openBuyerIds();
        $accountEnforcementReady = User::accountEnforcementSchemaReady();
        if ($accountEnforcementReady) {
            User::expireDueSuspensions();
        }

        $accountTypes = [User::TYPE_BUYER, User::TYPE_SELLER, User::TYPE_ADMIN, User::TYPE_LOGISTICS];
        $role = strtolower((string) $request->query('role', $request->query('type', 'all')));
        $roleTypes = [
            'buyers' => User::TYPE_BUYER,
            'sellers' => User::TYPE_SELLER,
            'admins' => User::TYPE_ADMIN,
            'logistics' => User::TYPE_LOGISTICS,
        ];
        $role = match ($role) {
            'buyer' => 'buyers',
            'seller' => 'sellers',
            'admin' => 'admins',
            default => $role,
        };
        $role = array_key_exists($role, $roleTypes) ? $role : 'all';
        if ($riskFilter === 'flagged') {
            $role = 'buyers';
        }
        $status = strtolower((string) $request->query('status', 'all'));
        $status = in_array($status, ['pending', 'active', 'suspended', 'deactivated', 'banned'], true) ? $status : 'all';
        if ($status === 'banned' && ! $accountEnforcementReady) {
            $status = 'all';
        }
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->whereIn('account_type', $accountTypes)
            ->when($role !== 'all', fn ($query) => $query->where('account_type', $roleTypes[$role]))
            ->when($role === 'sellers', fn ($query) => $query->withCount([
                'registrationApplications as pending_seller_document_updates_count' => fn ($applications) => $applications
                    ->whereHas('documents', fn ($documents) => $documents->where('verification_status', 'PENDING')),
            ]))
            ->when($riskFilter === 'flagged', fn ($query) => $query->whereIn('id', $openRiskBuyerIds))
            ->when($status !== 'all', function ($query) use ($status, $accountEnforcementReady): void {
                if ($status === 'banned') {
                    $query->whereNotNull('permanently_banned_at');

                    return;
                }

                if ($accountEnforcementReady) {
                    $query->whereNull('permanently_banned_at');
                }
                $query->where('status', strtoupper($status));
            })
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                    if (preg_match('/^USR-(\d+)$/i', $search, $matches)) {
                        $query->orWhereKey((int) $matches[1]);
                    }
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total' => User::query()->whereIn('account_type', $accountTypes)->count(),
            'buyers' => User::query()->where('account_type', User::TYPE_BUYER)->count(),
            'sellers' => User::query()->where('account_type', User::TYPE_SELLER)->count(),
            'delivery' => User::query()->where('account_type', User::TYPE_LOGISTICS)->count(),
            'admins' => User::query()->where('account_type', User::TYPE_ADMIN)->count(),
            'open_buyer_risk_reviews' => $openRiskBuyerIds->count(),
        ];

        $sessionManagementAvailable = $sessions->available();
        $sessionSummaries = $sessions->summaries($users->getCollection());

        return view('Admin.users.index', compact(
            'users',
            'role',
            'status',
            'search',
            'stats',
            'accountEnforcementReady',
            'sessionManagementAvailable',
            'sessionSummaries',
            'riskFilter',
            'openRiskBuyerIds',
        ));
    }

    public function storeAdministrator(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:10'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^(?:\\+63|0)9\\d{9}$/', Rule::unique('users', 'contact_number')],
            'birthday' => ['required', 'date', 'before:today'],
            'password' => ['required', 'confirmed', 'max:72', Password::min(8)->mixedCase()->numbers()],
        ]);

        $administrator = DB::transaction(function () use ($data, $request): User {
            $administrator = User::create([
                'account_type' => User::TYPE_ADMIN,
                'first_name' => trim($data['first_name']),
                'middle_initial' => filled($data['middle_initial'] ?? null) ? trim($data['middle_initial']) : null,
                'last_name' => trim($data['last_name']),
                'sex' => null,
                'email' => mb_strtolower(trim($data['email'])),
                'contact_number' => trim($data['contact_number']),
                'birthday' => $data['birthday'],
                'password' => $data['password'],
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
            ]);

            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => 'admin.user.created',
                'auditable_type' => User::class,
                'auditable_id' => $administrator->id,
                'new_values' => [
                    'account_type' => User::TYPE_ADMIN,
                    'email' => $administrator->email,
                    'status' => User::STATUS_ACTIVE,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return $administrator;
        });

        return redirect()
            ->route('admin.users', ['role' => 'admins'])
            ->with('status', 'Administrator account created and activated.');
    }

    public function revokeSessions(Request $request, AdminUserSessionManager $sessions): RedirectResponse
    {
        if (! $sessions->available()) {
            return back()->withErrors([
                'sessions' => 'Session controls are unavailable because the configured session driver or session table is not supported.',
            ]);
        }

        $data = $request->validate([
            'single_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'user_ids' => ['required_without:single_user_id', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);

        $isSingle = filled($data['single_user_id'] ?? null);
        $requestedIds = $isSingle
            ? [(int) $data['single_user_id']]
            : collect($data['user_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $actorId = (int) $request->user()->id;
        if ($isSingle && in_array($actorId, $requestedIds, true)) {
            return back()->withErrors(['sessions' => 'You cannot suspend your own current session from the user directory.']);
        }

        $skippedSelf = in_array($actorId, $requestedIds, true);
        $targetIds = array_values(array_diff($requestedIds, [$actorId]));
        if ($targetIds === []) {
            return back()->withErrors(['sessions' => 'Select at least one other account to suspend its active sessions.']);
        }

        $users = User::query()
            ->whereIn('id', $targetIds)
            ->whereIn('account_type', [User::TYPE_BUYER, User::TYPE_SELLER, User::TYPE_ADMIN, User::TYPE_LOGISTICS])
            ->get();
        if ($users->count() !== count($targetIds)) {
            return back()->withErrors(['sessions' => 'One or more selected accounts are no longer available in the user directory.']);
        }

        $revokedCount = $sessions->revoke($request, $users->all());
        $accountCount = $users->count();
        $message = 'Suspended sessions for '.$accountCount.' account'.($accountCount === 1 ? '' : 's').'; revoked '.$revokedCount.' active browser session'.($revokedCount === 1 ? '' : 's').'. Each account was notified to sign in again.';
        if ($skippedSelf) {
            $message .= ' Your own account was skipped.';
        }

        return back()->with('status', $message);
    }

    public function show(User $user): View
    {
        $this->ensureViewable($user);
        $accountEnforcementReady = User::accountEnforcementSchemaReady();
        if ($accountEnforcementReady) {
            $user->isActive();
        }
        $user->load(['addresses', 'sellerProfile', 'logisticsCenter']);
        $verificationApplication = $user->isAccountType(User::TYPE_SELLER, User::TYPE_LOGISTICS)
            ? $user->sellerVerificationApplication()
            : null;
        $verificationApplication?->load('documents');
        $verificationDocuments = $verificationApplication?->documents
            ->groupBy(fn (ApplicationDocument $document): string => strtoupper((string) $document->document_type))
            ->map(fn ($versions): ApplicationDocument => $versions->sortByDesc('id')->first()) ?? collect();
        $verificationDocumentUpdateRequestIds = $user->isAccountType(User::TYPE_SELLER, User::TYPE_LOGISTICS)
            ? AuditLog::query()
                ->whereIn('event', [
                    'seller.document_update_requested_by_admin',
                    'logistics.document_update_requested_by_admin',
                ])
                ->where('auditable_type', User::class)
                ->where('auditable_id', $user->id)
                ->get(['new_values'])
                ->map(fn (AuditLog $entry): int => (int) data_get($entry->new_values, 'document_id'))
                ->filter()
                ->flip()
                ->all()
            : [];
        $missingDocumentRequestsByType = $user->isAccountType(User::TYPE_SELLER, User::TYPE_LOGISTICS)
            ? AuditLog::query()
                ->whereIn('event', [
                    'seller.document_requirement_requested_by_admin',
                    'logistics.document_requirement_requested_by_admin',
                ])
                ->where('auditable_type', User::class)
                ->where('auditable_id', $user->id)
                ->latest('created_at')
                ->get()
                ->unique(fn (AuditLog $entry): string => strtoupper((string) data_get($entry->new_values, 'document_type')))
                ->keyBy(fn (AuditLog $entry): string => strtoupper((string) data_get($entry->new_values, 'document_type')))
            : collect();
        $changes = AuditLog::query()
            ->with('actor')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->latest('created_at')
            ->paginate(10);
        $warnings = AuditLog::query()
            ->with('actor')
            ->where('event', 'ACCOUNT_WARNING')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->latest('created_at')
            ->paginate(10, ['*'], 'warnings_page');
        $riskSignals = $user->isAccountType(User::TYPE_BUYER)
            ? AuditLog::query()
                ->where('event', BuyerRiskMonitor::SIGNAL_EVENT)
                ->where('auditable_type', User::class)
                ->where('auditable_id', $user->id)
                ->latest('created_at')
                ->paginate(10, ['*'], 'risk_page')
            : collect();
        $warningCount = AuditLog::query()
            ->where('event', 'ACCOUNT_WARNING')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->count();

        return view('Admin.users.show', [
            'user' => $user,
            'verificationApplication' => $verificationApplication,
            'verificationDocuments' => $verificationDocuments,
            'verificationDocumentTypes' => $user->isAccountType(User::TYPE_LOGISTICS)
                ? array_values(ApplicationDocument::LOGISTICS_UPLOAD_FIELDS)
                : array_values(ApplicationDocument::SELLER_UPLOAD_FIELDS),
            'verificationDocumentUpdateRequestIds' => $verificationDocumentUpdateRequestIds,
            'missingDocumentRequestsByType' => $missingDocumentRequestsByType,
            'changes' => $changes,
            'warnings' => $warnings,
            'riskSignals' => $riskSignals,
            'warningCount' => $warningCount,
            'warningCategories' => self::ACCOUNT_WARNING_CATEGORIES[$user->account_type] ?? [],
            'accountEnforcementReady' => $accountEnforcementReady,
            'suspensionDurations' => array_map(
                fn (array $duration): string => $duration['label'],
                self::ACCOUNT_SUSPENSION_DURATIONS,
            ),
        ]);
    }

    public function reviewVerificationDocument(Request $request, User $user, ApplicationDocument $document): RedirectResponse
    {
        abort_unless($user->isAccountType(User::TYPE_SELLER, User::TYPE_LOGISTICS), 404);

        $data = $request->validate([
            'decision' => ['required', 'string', Rule::in(['verify', 'reject', 'request_update'])],
            'reason' => ['required_if:decision,reject,request_update', 'nullable', 'string', 'min:3', 'max:1000'],
        ]);
        $allowedDocumentTypes = $user->isAccountType(User::TYPE_LOGISTICS)
            ? array_values(ApplicationDocument::LOGISTICS_UPLOAD_FIELDS)
            : array_values(ApplicationDocument::SELLER_UPLOAD_FIELDS);
        abort_unless(in_array(strtoupper((string) $document->document_type), $allowedDocumentTypes, true), 404);
        $application = $document->application()->with('user')->firstOrFail();
        abort_unless((int) $application->user_id === (int) $user->id, 404);

        $decision = $data['decision'];
        $reason = filled($data['reason'] ?? null) ? trim($data['reason']) : null;
        $label = ApplicationDocument::DOCUMENT_LABELS[strtoupper((string) $document->document_type)]
            ?? str($document->document_type)->replace('_', ' ')->headline()->toString();

        $eventPrefix = $user->isAccountType(User::TYPE_LOGISTICS) ? 'logistics' : 'seller';

        DB::transaction(function () use ($request, $user, $application, $document, $decision, $reason, $label, $eventPrefix): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $lockedApplication = RegistrationApplication::query()
                ->whereKey($application->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedDocument = $lockedApplication->documents()
                ->whereKey($document->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                (int) $lockedApplication->user_id === (int) $lockedUser->id
                    && $lockedUser->isAccountType(User::TYPE_SELLER, User::TYPE_LOGISTICS)
                    && strtoupper((string) $lockedUser->status) === User::STATUS_ACTIVE
                    && ($lockedUser->isAccountType(User::TYPE_SELLER)
                        ? $lockedUser->sellerProfile()->where('status', User::STATUS_ACTIVE)->exists()
                        : $lockedUser->logisticsCenter()->where('status', User::STATUS_ACTIVE)->exists()),
                404,
            );

            $latestId = $lockedApplication->documents()
                ->whereRaw('UPPER(document_type) = ?', [strtoupper((string) $lockedDocument->document_type)])
                ->max('id');
            $currentStatus = strtoupper((string) $lockedDocument->verification_status);
            $validDecisionStatus = $decision === 'request_update'
                ? $currentStatus === 'VERIFIED'
                : $currentStatus === 'PENDING';
            if ((int) $latestId !== (int) $lockedDocument->id || ! $validDecisionStatus) {
                throw ValidationException::withMessages([
                    'document' => 'This document is no longer available for that action. Refresh the account record and review the latest status.',
                ]);
            }

            $status = $decision === 'verify' ? 'VERIFIED' : 'REJECTED';
            $lockedDocument->update([
                'verification_status' => $status,
                'verified_by_user_id' => $decision === 'request_update'
                    ? $lockedDocument->verified_by_user_id
                    : $request->user()->id,
                'verified_at' => $decision === 'request_update'
                    ? $lockedDocument->verified_at
                    : now(),
                'rejection_reason' => $decision === 'verify' ? null : $reason,
            ]);

            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => match ($decision) {
                    'verify' => $eventPrefix.'.document_update_verified',
                    'request_update' => $eventPrefix.'.document_update_requested_by_admin',
                    default => $eventPrefix.'.document_update_rejected',
                },
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'old_values' => ['document_id' => $lockedDocument->id, 'verification_status' => $currentStatus],
                'new_values' => [
                    'document_id' => $lockedDocument->id,
                    'document_type' => $lockedDocument->document_type,
                    'verification_status' => $status,
                    'reason' => $reason,
                    'requested_update' => $decision === 'request_update',
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        });

        $approved = $decision === 'verify';
        $updateRequested = $decision === 'request_update';
        $isLogistics = $user->isAccountType(User::TYPE_LOGISTICS);
        $workspaceLabel = $isLogistics ? 'Logistics Center' : 'Seller';
        $profileUrl = $isLogistics ? route('logistics.profile') : route('seller.account', ['tab' => 'verification']);
        Notification::create([
            'user_id' => $user->id,
            'type' => $approved
                ? strtoupper($eventPrefix).'_DOCUMENT_UPDATE_VERIFIED'
                : ($updateRequested ? strtoupper($eventPrefix).'_DOCUMENT_UPDATE_REQUESTED' : strtoupper($eventPrefix).'_DOCUMENT_UPDATE_REJECTED'),
            'title' => $approved
                ? 'Document update approved'
                : ($updateRequested ? 'Document update requested' : 'Document update needs a new upload'),
            'message' => $approved
                ? $label.' has been verified by Admin.'
                : ($updateRequested
                    ? 'Admin requested an updated '.$label.'. Reason: '.$reason
                    : $label.' was not accepted. Reason: '.$reason),
            'reference_type' => ApplicationDocument::class,
            'reference_id' => $document->id,
            'action_url' => $profileUrl,
        ]);

        return redirect()->route('admin.users.show', $user)->with(
            'status',
            $approved
                ? $label.' marked as verified.'
                : ($updateRequested
                    ? 'Update requested for '.$label.'. The '.$workspaceLabel.' can upload a replacement.'
                    : $label.' update rejected. The '.$workspaceLabel.' can upload a replacement.'),
        );
    }

    public function requestMissingVerificationDocument(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->isAccountType(User::TYPE_SELLER, User::TYPE_LOGISTICS), 404);

        $allowedDocumentTypes = $user->isAccountType(User::TYPE_LOGISTICS)
            ? array_values(ApplicationDocument::LOGISTICS_UPLOAD_FIELDS)
            : array_values(ApplicationDocument::SELLER_UPLOAD_FIELDS);

        $data = $request->validate([
            'document_type' => ['required', 'string', Rule::in($allowedDocumentTypes)],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        $documentType = strtoupper($data['document_type']);
        $reason = trim($data['reason']);
        $label = ApplicationDocument::DOCUMENT_LABELS[$documentType]
            ?? str($documentType)->replace('_', ' ')->headline()->toString();
        $eventPrefix = $user->isAccountType(User::TYPE_LOGISTICS) ? 'logistics' : 'seller';

        DB::transaction(function () use ($request, $user, $documentType, $reason, $eventPrefix): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $lockedUser->isAccountType(User::TYPE_SELLER, User::TYPE_LOGISTICS)
                    && strtoupper((string) $lockedUser->status) === User::STATUS_ACTIVE
                    && ($lockedUser->isAccountType(User::TYPE_SELLER)
                        ? $lockedUser->sellerProfile()->where('status', User::STATUS_ACTIVE)->exists()
                        : $lockedUser->logisticsCenter()->where('status', User::STATUS_ACTIVE)->exists()),
                404,
            );

            $application = $lockedUser->sellerVerificationApplication();
            if ($application) {
                $lockedApplication = RegistrationApplication::query()
                    ->whereKey($application->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $alreadySubmitted = $lockedApplication->documents()
                    ->whereRaw('UPPER(document_type) = ?', [$documentType])
                    ->exists();
                if ($alreadySubmitted) {
                    throw ValidationException::withMessages([
                        'document_type' => 'This requirement is no longer missing. Refresh the account record and review the latest upload.',
                    ]);
                }
            }

            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => $eventPrefix.'.document_requirement_requested_by_admin',
                'auditable_type' => User::class,
                'auditable_id' => $lockedUser->id,
                'new_values' => [
                    'application_id' => $application?->id,
                    'document_type' => $documentType,
                    'reason' => $reason,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        });

        $isLogistics = $user->isAccountType(User::TYPE_LOGISTICS);
        $workspaceLabel = $isLogistics ? 'Logistics Center' : 'Seller';
        Notification::create([
            'user_id' => $user->id,
            'type' => strtoupper($eventPrefix).'_DOCUMENT_REQUIREMENT_REQUESTED',
            'title' => 'Required document requested',
            'message' => 'Admin requested your '.$label.' for your '.$workspaceLabel.'. Requirement: '.$reason,
            'reference_type' => User::class,
            'reference_id' => $user->id,
            'action_url' => $isLogistics ? route('logistics.profile') : route('seller.account', ['tab' => 'verification']),
        ]);

        return redirect()->route('admin.users.show', $user)->with(
            'status', 'Requested '.$label.' from the '.$workspaceLabel.'. They can upload it from their verification page.',
        );
    }

    public function issueWarning(Request $request, User $user): RedirectResponse
    {
        $this->ensureAdminManaged($user);
        $categories = self::ACCOUNT_WARNING_CATEGORIES[$user->account_type] ?? [];
        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(array_keys($categories))],
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
            'corrective_action' => ['required', 'string', 'min:5', 'max:5000'],
            'evidence' => ['nullable', 'string', 'max:5000'],
        ]);

        $reference = $this->makeAccountReference($user, 'WARNING');
        $newValues = [
            'account_type' => $user->account_type,
            'category' => $data['category'],
            'category_label' => $categories[$data['category']],
            'reason' => trim($data['reason']),
            'corrective_action' => trim($data['corrective_action']),
            'reference' => $reference,
            'evidence' => filled($data['evidence'] ?? null) ? trim($data['evidence']) : null,
            'status' => 'OPEN',
        ];

        $result = DB::transaction(function () use ($request, $user, $newValues): string {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $warningQuery = AuditLog::query()
                ->where('event', 'ACCOUNT_WARNING')
                ->where('auditable_type', User::class)
                ->where('auditable_id', $user->id);

            if ($warningQuery->count() >= User::MAX_ACCOUNT_WARNINGS) {
                throw ValidationException::withMessages([
                    'warning_limit' => 'This account has reached the limit of three warnings.',
                ]);
            }

            $duplicateWarning = (clone $warningQuery)
                ->get(['new_values'])
                ->first(function (AuditLog $warning) use ($newValues): bool {
                    $values = $warning->new_values ?? [];

                    return data_get($values, 'category') === $newValues['category']
                        && trim((string) data_get($values, 'reason')) === $newValues['reason']
                        && in_array(data_get($values, 'status', 'OPEN'), ['OPEN', 'UNDER_REVIEW'], true);
                });

            if ($duplicateWarning) {
                return 'duplicate';
            }

            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => 'ACCOUNT_WARNING',
                'auditable_type' => User::class,
                'auditable_id' => $user->id,
                'new_values' => $newValues,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return 'created';
        });

        if ($result === 'duplicate') {
            return back()->with('status', 'This account warning is already recorded and remains open for review.');
        }

        $warningTitle = 'Account warning from LIKHAE';
        $warningMessage = 'Your account received a warning for '.$newValues['category_label'].'. Reason: '.$newValues['reason'].' Corrective action: '.$newValues['corrective_action'].' Reference: '.$reference;
        Notification::create([
            'user_id' => $user->id,
            'type' => 'ACCOUNT_WARNING',
            'title' => $warningTitle,
            'message' => $warningMessage,
            'reference_type' => User::class,
            'reference_id' => $user->id,
            'action_url' => route('notifications.index'),
        ]);
        $emailSent = $this->sendAccountAlertEmail($user, 'ACCOUNT_WARNING', $warningTitle, $warningMessage, route('notifications.index'));

        return back()->with('status', $emailSent
            ? 'Account warning recorded. The account received an in-app notification and email. Reference: '.$reference
            : 'Account warning recorded. The in-app notification was saved; the email could not be sent. Reference: '.$reference);
    }

    public function reviewWarning(Request $request, User $user, AuditLog $warning): RedirectResponse
    {
        $this->ensureAdminManaged($user);
        abort_unless(
            $warning->event === 'ACCOUNT_WARNING'
                && $warning->auditable_type === User::class
                && (int) $warning->auditable_id === (int) $user->id,
            404,
        );
        $data = $request->validate([
            'status' => ['required', 'string', 'in:OPEN,UNDER_REVIEW,RESOLVED,CLOSED'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        $values = $warning->new_values ?? [];
        $values['status'] = $data['status'];
        $values['resolution_note'] = filled($data['note'] ?? null) ? trim($data['note']) : null;
        $values['reviewed_by'] = $request->user()->id;
        $values['reviewed_at'] = now()->toISOString();
        $warning->update(['new_values' => $values]);
        AuditLog::create([
            'actor_user_id' => $request->user()->id,
            'event' => 'ACCOUNT_WARNING_REVIEWED',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'new_values' => [
                'warning_id' => $warning->id,
                'status' => $data['status'],
                'note' => $values['resolution_note'],
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        $reviewMessage = 'Your account warning '.($values['reference'] ?? '').' was updated to '.str($data['status'])->replace('_', ' ')->headline().'.';
        if (filled($values['resolution_note'] ?? null)) {
            $reviewMessage .= ' Admin note: '.$values['resolution_note'];
        }
        Notification::create([
            'user_id' => $user->id,
            'type' => 'ACCOUNT_WARNING_REVIEWED',
            'title' => 'Account warning updated',
            'message' => $reviewMessage,
            'reference_type' => AuditLog::class,
            'reference_id' => $warning->id,
            'action_url' => route('notifications.index'),
        ]);
        $emailSent = $this->sendAccountAlertEmail($user, 'ACCOUNT_WARNING_REVIEWED', 'Account warning updated', $reviewMessage, route('notifications.index'));

        return back()->with('status', $emailSent
            ? 'Account warning review saved. The account was notified in-app and by email.'
            : 'Account warning review saved. The in-app notification was saved; the email could not be sent.');
    }

    public function reviewRiskSignal(Request $request, User $user, AuditLog $signal): RedirectResponse
    {
        abort_unless($user->isAccountType(User::TYPE_BUYER), 404);
        abort_unless(
            $signal->event === BuyerRiskMonitor::SIGNAL_EVENT
                && $signal->auditable_type === User::class
                && (int) $signal->auditable_id === (int) $user->id,
            404,
        );

        $data = $request->validate([
            'status' => ['required', 'string', 'in:OPEN,UNDER_REVIEW,RESOLVED,CLOSED'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        $values = $signal->new_values ?? [];
        $values['status'] = $data['status'];
        $values['resolution_note'] = filled($data['note'] ?? null) ? trim($data['note']) : null;
        $values['reviewed_by'] = $request->user()->id;
        $values['reviewed_at'] = now()->toISOString();
        $signal->update(['new_values' => $values]);

        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => 'BUYER_RISK_SIGNAL_REVIEWED',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'new_values' => [
                'signal_id' => $signal->id,
                'signal_type' => data_get($values, 'signal_type'),
                'status' => $data['status'],
                'note' => $values['resolution_note'],
            ],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);

        return back()->with('status', 'Buyer safety signal review saved.');
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $this->ensureAdminManaged($user);
        if (! User::accountEnforcementSchemaReady()) {
            return back()->withErrors(['account_access' => 'Suspension controls are unavailable until the account enforcement database migration is applied.']);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'duration' => ['required', Rule::in(array_keys(self::ACCOUNT_SUSPENSION_DURATIONS))],
            'evidence' => ['nullable', 'string', 'max:5000'],
        ]);
        $reference = $this->makeAccountReference($user, 'SUSPENSION');
        $duration = self::ACCOUNT_SUSPENSION_DURATIONS[$data['duration']];
        $suspendedUntil = $duration['unit'] === 'week'
            ? now()->addWeeks($duration['count'])
            : now()->addMonths($duration['count']);
        DB::transaction(function () use ($request, $user, $data, $duration, $suspendedUntil, $reference): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $lockedUser->status === User::STATUS_ACTIVE && ! $lockedUser->isPermanentlyBanned(),
                422,
                'Only active accounts can be suspended.',
            );
            $oldStatus = $lockedUser->status;
            $lockedUser->update([
                'status' => User::STATUS_SUSPENDED,
                'suspended_until' => $suspendedUntil,
            ]);
            $lockedUser->sellerProfile?->update(['status' => User::STATUS_SUSPENDED]);
            $lockedUser->logisticsCenter?->update(['status' => User::STATUS_SUSPENDED]);
            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => 'ACCOUNT_SUSPENDED',
                'auditable_type' => User::class,
                'auditable_id' => $lockedUser->id,
                'old_values' => ['status' => $oldStatus],
                'new_values' => [
                    'status' => User::STATUS_SUSPENDED,
                    'duration' => $data['duration'],
                    'duration_label' => $duration['label'],
                    'suspended_until' => $suspendedUntil->toISOString(),
                    'reason' => trim($data['reason']),
                    'reference' => $reference,
                    'evidence' => filled($data['evidence'] ?? null) ? trim($data['evidence']) : null,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        });
        $suspensionTitle = 'Account suspended';
        $suspensionMessage = 'Your LIKHAE account was suspended until '.$suspendedUntil->format('M d, Y h:i A').'. Reason: '.trim($data['reason']).' Reference: '.$reference;
        Notification::create([
            'user_id' => $user->id,
            'type' => 'ACCOUNT_SUSPENDED',
            'title' => $suspensionTitle,
            'message' => $suspensionMessage,
            'reference_type' => User::class,
            'reference_id' => $user->id,
            'action_url' => null,
        ]);
        $this->sendAccountAlertEmail($user, 'ACCOUNT_SUSPENDED', $suspensionTitle, $suspensionMessage, null);

        return back()->with('status', 'User suspended until '.$suspendedUntil->format('M d, Y h:i A').'. Reference: '.$reference);
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        $this->ensureAdminManaged($user);
        if (! User::accountEnforcementSchemaReady()) {
            return back()->withErrors(['account_access' => 'Reactivation controls are unavailable until the account enforcement database migration is applied.']);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $reference = $this->makeAccountReference($user, 'REACTIVATION');
        DB::transaction(function () use ($request, $user, $data, $reference): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $lockedUser->status === User::STATUS_SUSPENDED && ! $lockedUser->isPermanentlyBanned(),
                422,
                'Only temporarily suspended accounts can be reactivated.',
            );
            $oldStatus = $lockedUser->status;
            $oldSuspendedUntil = $lockedUser->suspended_until;
            $lockedUser->update(['status' => User::STATUS_ACTIVE, 'suspended_until' => null]);
            $lockedUser->sellerProfile?->update(['status' => User::STATUS_ACTIVE]);
            $lockedUser->logisticsCenter?->update(['status' => User::STATUS_ACTIVE]);
            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => 'ACCOUNT_REACTIVATED',
                'auditable_type' => User::class,
                'auditable_id' => $lockedUser->id,
                'old_values' => [
                    'status' => $oldStatus,
                    'suspended_until' => $oldSuspendedUntil?->toISOString(),
                ],
                'new_values' => [
                    'status' => User::STATUS_ACTIVE,
                    'reason' => trim($data['reason']),
                    'reference' => $reference,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        });
        $reactivationTitle = 'Account reactivated';
        $reactivationMessage = 'Your LIKHAE account was reactivated by Admin. Reason: '.trim($data['reason']).' Reference: '.$reference;
        Notification::create([
            'user_id' => $user->id,
            'type' => 'ACCOUNT_REACTIVATED',
            'title' => $reactivationTitle,
            'message' => $reactivationMessage,
            'reference_type' => User::class,
            'reference_id' => $user->id,
            'action_url' => route('login'),
        ]);
        $this->sendAccountAlertEmail($user, 'ACCOUNT_REACTIVATED', $reactivationTitle, $reactivationMessage, route('login'));

        return back()->with('status', 'User reactivated. Reference: '.$reference);
    }

    public function permanentlyBan(Request $request, User $user): RedirectResponse
    {
        $this->ensureAdminManaged($user);
        if (! User::accountEnforcementSchemaReady()) {
            return back()->withErrors(['account_access' => 'Permanent ban controls are unavailable until the account enforcement database migration is applied.']);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'evidence' => ['nullable', 'string', 'max:5000'],
        ]);

        $reference = $this->makeAccountReference($user, 'BAN');
        $bannedAt = now();
        DB::transaction(function () use ($request, $user, $data, $bannedAt, $reference): void {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless(! $lockedUser->isPermanentlyBanned(), 422, 'This account is already permanently banned.');
            $oldValues = [
                'status' => $lockedUser->status,
                'suspended_until' => $lockedUser->suspended_until?->toISOString(),
            ];
            $lockedUser->forceFill([
                'status' => User::STATUS_DEACTIVATED,
                'suspended_until' => null,
                'permanently_banned_at' => $bannedAt,
                'api_token_hash' => null,
                'api_token_expires_at' => null,
                'remember_token' => null,
            ])->save();
            $lockedUser->sellerProfile?->update(['status' => User::STATUS_DEACTIVATED]);
            $lockedUser->logisticsCenter?->update(['status' => User::STATUS_DEACTIVATED]);

            AuditLog::create([
                'actor_user_id' => $request->user()->id,
                'event' => 'ACCOUNT_PERMANENTLY_BANNED',
                'auditable_type' => User::class,
                'auditable_id' => $lockedUser->id,
                'old_values' => $oldValues,
                'new_values' => [
                    'status' => 'PERMANENTLY_BANNED',
                    'account_status' => User::STATUS_DEACTIVATED,
                    'permanently_banned_at' => $bannedAt->toISOString(),
                    'reason' => trim($data['reason']),
                    'reference' => $reference,
                    'evidence' => filled($data['evidence'] ?? null) ? trim($data['evidence']) : null,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        });

        $banTitle = 'Account permanently banned';
        $banMessage = 'Your LIKHAE account was permanently banned by Admin. Reason: '.trim($data['reason']).' Reference: '.$reference;
        Notification::create([
            'user_id' => $user->id,
            'type' => 'ACCOUNT_PERMANENTLY_BANNED',
            'title' => $banTitle,
            'message' => $banMessage,
            'reference_type' => User::class,
            'reference_id' => $user->id,
            'action_url' => null,
        ]);
        $this->sendAccountAlertEmail($user, 'ACCOUNT_PERMANENTLY_BANNED', $banTitle, $banMessage, null);

        return back()->with('status', 'User permanently banned. Reference: '.$reference);
    }

    private function makeAccountReference(User $user, string $action): string
    {
        return 'USR-'.$user->id.'-'.$action.'-'.Str::upper((string) Str::ulid());
    }

    private function sendAccountAlertEmail(User $user, string $type, string $title, string $body, ?string $actionUrl): bool
    {
        if (blank($user->email) || $user->email_verified_at === null) {
            return false;
        }

        try {
            Mail::to($user->email)->send(new AccountAlert(
                type: $type,
                title: $title,
                body: $body,
                actionUrl: $actionUrl,
                actionLabel: 'View notification',
                userName: $user->name,
            ));

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Account enforcement email could not be sent.', [
                'user_id' => $user->id,
                'notification_type' => $type,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function ensureViewable(User $user): void
    {
        abort_unless(in_array($user->account_type, [
            User::TYPE_BUYER,
            User::TYPE_SELLER,
            User::TYPE_ADMIN,
            User::TYPE_LOGISTICS,
        ], true), 404);
    }

    private function ensureAdminManaged(User $user): void
    {
        abort_unless(in_array($user->account_type, [
            User::TYPE_BUYER,
            User::TYPE_SELLER,
            User::TYPE_LOGISTICS,
        ], true), 403);
    }
}
