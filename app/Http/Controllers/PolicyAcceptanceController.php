<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PolicyAcceptanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PolicyAcceptanceController extends Controller
{
    public function registration(Request $request, PolicyAcceptanceService $policies): JsonResponse
    {
        $role = strtoupper((string) $request->query('role', User::TYPE_BUYER));
        abort_unless(in_array($role, [User::TYPE_BUYER, User::TYPE_SELLER, User::TYPE_LOGISTICS, User::TYPE_RIDER], true), 422);
        abort_unless($policies->schemaReady(), 503, 'Policy storage is not available yet.');

        $documents = $policies->registrationPolicies($role);
        $types = $documents->pluck('policy_type')->unique()->all();

        return response()->json([
            'success' => true,
            'ready' => in_array('TERMS', $types, true) && in_array('PRIVACY', $types, true),
            'policies' => $policies->serializePolicies($documents),
        ]);
    }

    public function store(Request $request, PolicyAcceptanceService $policies): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'policy_acceptances' => ['required', 'array'],
            'policy_acceptances.*' => ['required', 'string', 'max:50'],
            'policy_acknowledgements' => ['required', 'array'],
            'policy_acknowledgements.*' => ['accepted'],
        ]);

        $policies->acceptCurrentPolicies($request->user(), $data['policy_acceptances'], $data['policy_acknowledgements'], $request);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Policy acceptance saved.',
                'pending_policies' => $policies->serializePolicies($policies->pendingPolicies($request->user())),
            ]);
        }

        return redirect()->route($request->user()->workspaceRoute())->with('status', 'Policy acceptance saved.');
    }

    public function apiStore(Request $request, PolicyAcceptanceService $policies): JsonResponse
    {
        $data = $request->validate([
            'policy_acceptances' => ['required', 'array'],
            'policy_acceptances.*' => ['required', 'string', 'max:50'],
            'policy_acknowledgements' => ['required', 'array'],
            'policy_acknowledgements.*' => ['accepted'],
        ]);

        $policies->acceptCurrentPolicies($request->user(), $data['policy_acceptances'], $data['policy_acknowledgements'], $request);

        return response()->json([
            'success' => true,
            'message' => 'Policy acceptance saved.',
            'pending_policies' => $policies->serializePolicies($policies->pendingPolicies($request->user())),
        ]);
    }
}
