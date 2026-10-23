<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PolicyAcceptanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiAuthController extends Controller
{
    public function login(Request $request, PolicyAcceptanceService $policies): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $user = User::query()->where('email', mb_strtolower($credentials['email']))->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json(['success' => false, 'message' => 'Invalid email or password.'], 401);
        }

        if (! $user->isActive() || ! $user->email_verified_at) {
            return response()->json(['success' => false, 'message' => $user->inactiveMessage()], 403);
        }

        $token = Str::random(64);
        $expiresAt = now()->addDays(30);
        $tokenAttributes = [
            'api_token_hash' => hash('sha256', $token),
            'api_token_expires_at' => $expiresAt,
        ];
        if ($policies->schemaReady()) {
            $tokenAttributes['api_token_policy_snapshot'] = $policies->fingerprintFor($user);
        }
        $user->forceFill($tokenAttributes)->save();

        return response()->json([
            'success' => true,
            'token_type' => 'Bearer',
            'access_token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'pending_policies' => $policies->serializePolicies($policies->pendingPolicies($user)),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'account_type' => $user->account_type,
                'status' => $user->status,
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'account_type' => $user->account_type,
                'status' => $user->status,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $tokenAttributes = [
            'api_token_hash' => null,
            'api_token_expires_at' => null,
        ];
        if (app(PolicyAcceptanceService::class)->schemaReady()) {
            $tokenAttributes['api_token_policy_snapshot'] = null;
        }
        $request->user()->forceFill($tokenAttributes)->save();

        return response()->json(['success' => true, 'message' => 'Signed out.']);
    }
}
