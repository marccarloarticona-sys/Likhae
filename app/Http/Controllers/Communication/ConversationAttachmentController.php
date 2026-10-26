<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Models\Communication\Message;
use App\Models\User;
use App\Services\Communication\MessageAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

class ConversationAttachmentController extends Controller
{
    public function show(Request $request, int $message, MessageAttachmentService $attachments): Response|JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $isApiToken = false;
        if (! $user && ($token = $request->bearerToken())) {
            $user = User::query()
                ->where('api_token_hash', hash('sha256', $token))
                ->where('api_token_expires_at', '>', now())
                ->first();
            abort_unless($user?->isActive(), 401);
            $request->setUserResolver(static fn (): User => $user);
            Auth::setUser($user);
            $isApiToken = true;
        }

        if (! $user) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Authentication is required.'], 401)
                : redirect()->guest(route('login'));
        }

        abort_unless($user->isActive(), 403);
        if (! $isApiToken && ! $user->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }

        $message = Message::query()->findOrFail($message);
        $file = $attachments->authorizedFile($user, $message);

        $headers = [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $file['is_image'] ? 'inline' : 'attachment',
                $file['name'],
                Str::ascii($file['name']) ?: 'attachment',
            ),
        ];
        $contents = Storage::disk('local')->get($message->attachment_path);

        return response($contents, 200, $headers);
    }
}
