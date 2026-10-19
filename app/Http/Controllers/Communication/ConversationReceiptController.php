<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Models\Communication\Conversation;
use App\Models\Communication\ConversationParticipant;
use App\Services\Communication\ConversationAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ConversationReceiptController extends Controller
{
    public function show(
        Request $request,
        Conversation $conversation,
        ConversationAuthorizationService $authorization,
    ): JsonResponse {
        $user = $request->user();
        $authorization->assertCanAccess($user, $conversation);

        $recipient = ConversationParticipant::query()
            ->with('user:id,notification_preferences')
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->where('user_id', '<>', $user->id)
            ->firstOrFail();

        $recipientCanShowOnline = (bool) data_get(
            $recipient->user?->notification_preferences,
            'show_online_status',
            true,
        );

        return response()->json([
            'success' => true,
            'recipient_online' => $recipientCanShowOnline
                && Cache::store('database')->has('message-presence:user:'.$recipient->user_id),
            'recipient_read_at' => $recipient->last_read_at?->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }
}
