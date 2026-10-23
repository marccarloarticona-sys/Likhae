<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\AuditLog;
use App\Models\Admin\Notification;
use App\Models\User;
use App\Services\Admin\AdminUserSessionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    public function index(Request $request, AdminUserSessionManager $sessions): View
    {
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
        $status = strtolower((string) $request->query('status', 'all'));
        $status = in_array($status, ['pending', 'active', 'suspended', 'deactivated', 'banned'], true) ? $status : 'all';
        if ($status === 'banned' && ! $accountEnforcementReady) {
            $status = 'all';
        }
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->whereIn('account_type', $accountTypes)
            ->when($role !== 'all', fn ($query) => $query->where('account_type', $roleTypes[$role]))
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
        ));
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
        $user->load(['addresses', 'sellerProfile', 'logisticsCenter', 'registrationApplications.documents']);
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
        $warningCount = AuditLog::query()
            ->where('event', 'ACCOUNT_WARNING')
            ->where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->count();

        return view('Admin.users.show', [
            'user' => $user,
            'changes' => $changes,
            'warnings' => $warnings,
            'warningCount' => $warningCount,
            'warningCategories' => self::ACCOUNT_WARNING_CATEGORIES[$user->account_type] ?? [],
            'accountEnforcementReady' => $accountEnforcementReady,
            'suspensionDurations' => array_map(
                fn (array $duration): string => $duration['label'],
                self::ACCOUNT_SUSPENSION_DURATIONS,
            ),
        ]);
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

        Notification::create([
            'user_id' => $user->id,
            'type' => 'ACCOUNT_WARNING',
            'title' => 'Account warning from LIKHAE',
            'message' => 'Your account received a warning for '.$newValues['category_label'].'. Reason: '.$newValues['reason'].' Corrective action: '.$newValues['corrective_action'].' Reference: '.$reference,
            'reference_type' => User::class,
            'reference_id' => $user->id,
            'action_url' => null,
        ]);

        return back()->with('status', 'Account warning recorded and notification sent. Reference: '.$reference);
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

        return back()->with('status', 'Account warning review saved.');
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
        Notification::create([
            'user_id' => $user->id,
            'type' => 'ACCOUNT_SUSPENDED',
            'title' => 'Account suspended',
            'message' => 'Your LIKHAE account was suspended until '.$suspendedUntil->format('M d, Y h:i A').'. Reason: '.trim($data['reason']).' Reference: '.$reference,
            'reference_type' => User::class,
            'reference_id' => $user->id,
            'action_url' => null,
        ]);

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
        Notification::create([
            'user_id' => $user->id,
            'type' => 'ACCOUNT_REACTIVATED',
            'title' => 'Account reactivated',
            'message' => 'Your LIKHAE account was reactivated by Admin. Reason: '.trim($data['reason']).' Reference: '.$reference,
            'reference_type' => User::class,
            'reference_id' => $user->id,
            'action_url' => null,
        ]);

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

        Notification::create([
            'user_id' => $user->id,
            'type' => 'ACCOUNT_PERMANENTLY_BANNED',
            'title' => 'Account permanently banned',
            'message' => 'Your LIKHAE account was permanently banned by Admin. Reason: '.trim($data['reason']).' Reference: '.$reference,
            'reference_type' => User::class,
            'reference_id' => $user->id,
            'action_url' => null,
        ]);

        return back()->with('status', 'User permanently banned. Reference: '.$reference);
    }

    private function makeAccountReference(User $user, string $action): string
    {
        return 'USR-'.$user->id.'-'.$action.'-'.Str::upper((string) Str::ulid());
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
