<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Models\Communication\Conversation;
use App\Services\Communication\ConversationAuthorizationService;
use App\Services\Communication\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationReadController extends Controller
{
    public function mark(
        Request $request,
        Conversation $conversation,
        ConversationService $conversations,
        ConversationAuthorizationService $authorization,
    ): JsonResponse {
        $user = $request->user();
        $authorization->assertCanAccess($user, $conversation);
        $readAt = $conversations->markRead($conversation, $user);

        return response()->json([
            'success' => true,
            'read_at' => $readAt,
        ])->header('Cache-Control', 'no-store, private');
    }
}
