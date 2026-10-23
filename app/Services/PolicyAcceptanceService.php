<?php

namespace App\Services;

use App\Models\Admin\AuditLog;
use App\Models\Admin\PlatformPolicy;
use App\Models\Logistics\LogisticsCenter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PolicyAcceptanceService
{
    public const ACCEPTED_EVENT = 'MANDATORY_POLICY_ACCEPTED';

    private const POLICY_COLUMNS = [
        'policy_type',
        'audience',
        'logistics_center_id',
        'scope_key',
    ];

    public function schemaReady(): bool
    {
        return Schema::hasTable('platform_policies')
            && Schema::hasTable('audit_logs')
            && Schema::hasColumns('platform_policies', self::POLICY_COLUMNS)
            && Schema::hasColumn('users', 'api_token_policy_snapshot');
    }

    /** @return Collection<int, PlatformPolicy> */
    public function registrationPolicies(string $accountType): Collection
    {
        return $this->currentPoliciesForRole(strtoupper($accountType))
            ->whereIn('policy_type', ['TERMS', 'PRIVACY'])
            ->values();
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function registrationPoliciesByRole(): array
    {
        return collect([User::TYPE_BUYER, User::TYPE_SELLER, User::TYPE_LOGISTICS, User::TYPE_RIDER])
            ->mapWithKeys(fn (string $type): array => [
                strtolower($type) => $this->serializePolicies($this->registrationPolicies($type)),
            ])
            ->all();
    }

    /** @return Collection<int, PlatformPolicy> */
    public function applicablePolicies(User $user): Collection
    {
        $role = strtoupper((string) $user->account_type);
        $centerId = $user->isAccountType(User::TYPE_RIDER)
            ? $user->riderProfile?->logistics_center_id
            : null;

        return $this->currentPoliciesForRole($role, $centerId);
    }

    /** @return Collection<int, PlatformPolicy> */
    public function pendingPolicies(User $user): Collection
    {
        $policies = $this->applicablePolicies($user);

        if ($policies->isEmpty() || ! $this->schemaReady()) {
            return collect();
        }

        $acceptedIds = $this->acceptedIds($user->id, $policies);

        return $policies
            ->reject(fn (PlatformPolicy $policy): bool => in_array((int) $policy->id, $acceptedIds, true))
            ->values();
    }

    /** A stable snapshot invalidates every stale browser session and API token after a policy takes effect. */
    public function fingerprintFor(User $user): string
    {
        $snapshot = $this->applicablePolicies($user)
            ->map(fn (PlatformPolicy $policy): array => [(int) $policy->id, (string) $policy->version])
            ->values()
            ->all();

        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    /** @param array<string, mixed> $data
     *  @return Collection<int, PlatformPolicy>
     */
    public function validateRegistrationConsent(string $accountType, array $data): Collection
    {
        if (! $this->schemaReady()) {
            throw ValidationException::withMessages([
                'policy_acceptances' => 'Policy storage is not available yet. Please try again later.',
            ]);
        }

        $policies = $this->registrationPolicies($accountType);
        $types = $policies->pluck('policy_type')->unique()->all();

        if (! in_array('TERMS', $types, true) || ! in_array('PRIVACY', $types, true)) {
            throw ValidationException::withMessages([
                'policy_acceptances' => 'Registration is unavailable until Terms & Conditions and Privacy Policy are published for this account type.',
            ]);
        }

        if (! $this->acceptedCheckbox($data['terms_acknowledged'] ?? false)
            || ! $this->acceptedCheckbox($data['privacy_acknowledged'] ?? false)) {
            throw ValidationException::withMessages([
                'terms_acknowledged' => 'Acknowledge both Terms & Conditions and Privacy Policy to continue.',
            ]);
        }

        $submitted = $this->normalizeVersionMap($data['policy_acceptances'] ?? []);
        $expected = $policies->mapWithKeys(fn (PlatformPolicy $policy): array => [(string) $policy->id => (string) $policy->version])->all();
        ksort($submitted);
        ksort($expected);

        if ($submitted !== $expected) {
            throw ValidationException::withMessages([
                'policy_acceptances' => 'A policy changed while you were registering. Review and accept the current versions to continue.',
            ]);
        }

        return $policies;
    }

    /** @param Collection<int, PlatformPolicy> $policies */
    public function recordRegistrationAcceptances(User $user, Collection $policies, Request $request): void
    {
        $this->recordPolicies($user, $policies, $request, 'registration');
    }

    /** @param array<string, mixed> $submittedVersions */
    public function acceptCurrentPolicies(User $user, array $submittedVersions, array $acknowledgements, Request $request): void
    {
        if (! $this->schemaReady()) {
            abort(503, 'Policy acceptance is not available yet.');
        }

        DB::transaction(function () use ($user, $submittedVersions, $acknowledgements, $request): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $current = $this->applicablePolicies($lockedUser);
            $currentById = $current->keyBy(fn (PlatformPolicy $policy): string => (string) $policy->id);
            $submitted = $this->normalizeVersionMap($submittedVersions);
            $pending = $this->pendingPolicies($lockedUser);
            $pendingIds = $pending->map(fn (PlatformPolicy $policy): string => (string) $policy->id)->all();
            $acknowledgedIds = collect($acknowledgements)
                ->filter(fn ($value): bool => $this->acceptedCheckbox($value))
                ->keys()
                ->map(fn ($id): string => (string) $id)
                ->all();

            foreach ($pendingIds as $pendingId) {
                if (! array_key_exists($pendingId, $submitted) || ! in_array($pendingId, $acknowledgedIds, true)) {
                    throw ValidationException::withMessages([
                        'policy_acceptances' => 'Review and acknowledge every updated policy before continuing.',
                    ]);
                }
            }

            foreach ($submitted as $id => $version) {
                $policy = $currentById->get($id);
                if (! $policy || ! hash_equals((string) $policy->version, $version)) {
                    throw ValidationException::withMessages([
                        'policy_acceptances' => 'A policy changed while you were reviewing it. Reload and accept the current version.',
                    ]);
                }
            }

            $this->recordPolicies($lockedUser, $pending, $request, 'post_login');
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function serializePolicies(Collection $policies): array
    {
        return $policies
            ->map(fn (PlatformPolicy $policy): array => [
                'id' => (int) $policy->id,
                'title' => (string) $policy->title,
                'version' => (string) $policy->version,
                'policy_type' => (string) $policy->policy_type,
                'audience' => (string) $policy->audience,
                'effective_date' => ($policy->effective_at ?? $policy->published_at)?->format('F j, Y'),
                'body' => (string) $policy->body,
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, PlatformPolicy> */
    private function currentPoliciesForRole(string $role, ?int $centerId = null): Collection
    {
        if (! $this->schemaReady()) {
            return collect();
        }

        $at = now();
        $query = PlatformPolicy::query()
            ->where('is_active', true)
            ->whereIn('audience', ['ALL', $role])
            ->where(function ($query) use ($at): void {
                $query->whereNull('published_at')->orWhere('published_at', '<=', $at);
            })
            ->where(function ($query) use ($at): void {
                $query->whereNull('effective_at')->orWhere('effective_at', '<=', $at);
            })
            ->where(function ($query) use ($centerId, $role): void {
                $query->whereNull('logistics_center_id');
                if ($role === User::TYPE_RIDER && $centerId) {
                    $query->orWhere('logistics_center_id', $centerId);
                }
            });

        $policies = $query->get()->sortByDesc(function (PlatformPolicy $policy): string {
            $effective = $policy->effective_at ?? $policy->published_at ?? $policy->created_at;

            return sprintf('%020d:%020d', $effective?->getTimestamp() ?? 0, (int) $policy->id);
        });

        return $policies
            ->groupBy(function (PlatformPolicy $policy): string {
                if (in_array($policy->policy_type, ['TERMS', 'PRIVACY'], true)) {
                    return $policy->policy_type;
                }

                $family = $policy->policy_type.'|'.mb_strtolower(trim($policy->title));

                return (string) $policy->scope_key.'|'.$family;
            })
            ->map(fn (Collection $versions): PlatformPolicy => $versions->first())
            ->sortBy(fn (PlatformPolicy $policy): string => mb_strtolower($policy->title))
            ->values();
    }

    /** @param Collection<int, PlatformPolicy> $policies */
    private function acceptedIds(int $userId, Collection $policies): array
    {
        if ($policies->isEmpty()) {
            return [];
        }

        $versionsByPolicyId = $policies
            ->mapWithKeys(fn (PlatformPolicy $policy): array => [(int) $policy->id => (string) $policy->version])
            ->all();

        return AuditLog::query()
            ->where('actor_user_id', $userId)
            ->where('event', self::ACCEPTED_EVENT)
            ->where('auditable_type', PlatformPolicy::class)
            ->whereIn('auditable_id', array_keys($versionsByPolicyId))
            ->get(['auditable_id', 'new_values'])
            ->filter(function (AuditLog $acceptance) use ($versionsByPolicyId): bool {
                $policyId = (int) $acceptance->auditable_id;

                return isset($versionsByPolicyId[$policyId])
                    && (string) ($acceptance->new_values['version'] ?? '') === $versionsByPolicyId[$policyId];
            })
            ->pluck('auditable_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @param Collection<int, PlatformPolicy> $policies */
    private function recordPolicies(User $user, Collection $policies, Request $request, string $context): void
    {
        if ($policies->isEmpty()) {
            return;
        }

        $alreadyAccepted = $this->acceptedIds($user->id, $policies);
        foreach ($policies as $policy) {
            if (in_array((int) $policy->id, $alreadyAccepted, true)) {
                continue;
            }

            AuditLog::query()->create([
                'actor_user_id' => $user->id,
                'event' => self::ACCEPTED_EVENT,
                'auditable_type' => PlatformPolicy::class,
                'auditable_id' => $policy->id,
                'new_values' => [
                    'user_id' => $user->id,
                    'policy_id' => $policy->id,
                    'title' => $policy->title,
                    'version' => $policy->version,
                    'policy_type' => $policy->policy_type,
                    'audience' => $policy->audience,
                    'scope_key' => $policy->scope_key,
                    'user_role' => strtoupper((string) $user->account_type),
                    'logistics_center_id' => $policy->logistics_center_id,
                    'logistics_center_owner_user_id' => $policy->logistics_center_id
                        ? LogisticsCenter::query()->whereKey($policy->logistics_center_id)->value('owner_user_id')
                        : null,
                    'effective_at' => $policy->effective_at?->toIso8601String(),
                    'published_at' => $policy->published_at?->toIso8601String(),
                    'body_sha256' => hash('sha256', (string) $policy->body),
                    'context' => $context,
                ],
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 5000),
                'created_at' => now(),
            ]);
        }
    }

    /** @param array<int|string, mixed> $versions
     *  @return array<string, string>
     */
    private function normalizeVersionMap(array $versions): array
    {
        $normalized = [];
        foreach ($versions as $id => $version) {
            if (! ctype_digit((string) $id) || ! is_string($version)) {
                continue;
            }
            $normalized[(string) (int) $id] = $version;
        }

        return $normalized;
    }

    private function acceptedCheckbox(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'yes'], true);
    }
}
