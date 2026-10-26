<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Models\Communication\Conversation;
use App\Services\Communication\SmartMessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmartMessagingController extends Controller
{
    public function answer(Request $request, Conversation $conversation, SmartMessagingService $smartMessaging): JsonResponse
    {
        $data = $request->validate([
            'question_key' => ['required', 'string', 'max:100'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
        ]);

        return response()->json($smartMessaging->answer(
            $request->user(),
            $conversation,
            $data['question_key'],
            isset($data['product_id']) ? (int) $data['product_id'] : null,
            isset($data['variant_id']) ? (int) $data['variant_id'] : null,
        ))->header('Cache-Control', 'no-store, private');
    }

    public function feedback(Request $request, Conversation $conversation, SmartMessagingService $smartMessaging): JsonResponse
    {
        $data = $request->validate(['feedback' => ['required', 'in:RESOLVED,UNRESOLVED']]);

        return response()->json($smartMessaging->recordFeedback($request->user(), $conversation, $data['feedback']))
            ->header('Cache-Control', 'no-store, private');
    }

    public function handoff(Request $request, Conversation $conversation, SmartMessagingService $smartMessaging): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'concern' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json($smartMessaging->requestHuman(
            $request->user(),
            $conversation,
            isset($data['product_id']) ? (int) $data['product_id'] : null,
            isset($data['variant_id']) ? (int) $data['variant_id'] : null,
            isset($data['concern']) ? (string) $data['concern'] : null,
        ))->header('Cache-Control', 'no-store, private');
    }

    public function resolve(Request $request, Conversation $conversation, SmartMessagingService $smartMessaging): JsonResponse
    {
        return response()->json($smartMessaging->resolveByRecipient($request->user(), $conversation))
            ->header('Cache-Control', 'no-store, private');
    }
}
