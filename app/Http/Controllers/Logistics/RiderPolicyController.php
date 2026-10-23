<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Admin\AuditLog;
use App\Models\Admin\PlatformPolicy;
use App\Models\User;
use App\Services\PolicyAcceptanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RiderPolicyController extends Controller
{
    public function store(Request $request, PolicyAcceptanceService $policyAcceptance): RedirectResponse
    {
        abort_unless($policyAcceptance->schemaReady(), 503, 'Run the policy scope migration before publishing Rider policies.');

        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403, 'This account does not own a Logistics Center.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:50'],
            'body' => ['required', 'string'],
            'effective_at' => ['nullable', 'date'],
        ]);
        $scopeKey = 'RIDER_PROVIDER:'.$center->id;

        validator($data, [
            'version' => [Rule::unique('platform_policies', 'version')
                ->where(fn ($query) => $query->where('title', $data['title'])->where('scope_key', $scopeKey))],
        ])->validate();

        $policy = PlatformPolicy::query()->create($data + [
            'published_by_user_id' => $request->user()->id,
            'published_at' => now(),
            'policy_type' => 'RIDER_OPERATIONAL',
            'audience' => User::TYPE_RIDER,
            'logistics_center_id' => $center->id,
            'scope_key' => $scopeKey,
            'is_active' => true,
        ]);

        AuditLog::query()->create([
            'actor_user_id' => $request->user()->id,
            'event' => 'RIDER_POLICY_PUBLISHED',
            'auditable_type' => PlatformPolicy::class,
            'auditable_id' => $policy->id,
            'new_values' => [
                'policy_id' => $policy->id,
                'title' => $policy->title,
                'version' => $policy->version,
                'policy_type' => $policy->policy_type,
                'audience' => $policy->audience,
                'scope_key' => $policy->scope_key,
                'logistics_center_id' => $center->id,
                'logistics_center_owner_user_id' => $center->owner_user_id,
                'effective_at' => $policy->effective_at?->toIso8601String(),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 5000),
            'created_at' => now(),
        ]);

        return back()->with('status', 'Rider Policy version published. Only your Riders will be required to sign in again when it takes effect.');
    }
}
