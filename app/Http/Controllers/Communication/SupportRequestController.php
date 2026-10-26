<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Models\Communication\ConversationSupportRequest;
use App\Models\User;
use App\Services\Communication\SmartMessagingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SupportRequestController extends Controller
{
    public function acknowledge(Request $request, ConversationSupportRequest $supportRequest, SmartMessagingService $smartMessaging): RedirectResponse
    {
        $smartMessaging->acknowledgeRequest($request->user(), $supportRequest);

        return back()->with('status', 'Request marked as awaiting a response.');
    }

    public function accept(Request $request, ConversationSupportRequest $supportRequest, SmartMessagingService $smartMessaging): RedirectResponse
    {
        $result = $smartMessaging->acceptRequest($request->user(), $supportRequest);
        $route = $request->user()->isAccountType(User::TYPE_ADMIN) ? 'admin.messages' : 'seller.messages';

        return redirect()->route($route, ['section' => 'chats', 'conversation' => $result['conversation_id']]);
    }

    public function resolve(Request $request, ConversationSupportRequest $supportRequest, SmartMessagingService $smartMessaging): RedirectResponse
    {
        $smartMessaging->resolveRequestByRecipient($request->user(), $supportRequest);

        return redirect()->route(
            $request->user()->isAccountType(User::TYPE_ADMIN) ? 'admin.messages' : 'seller.messages',
            ['section' => 'overview', 'request' => $supportRequest->id],
        )->with('status', 'Buyer concern marked as resolved.');
    }
}
