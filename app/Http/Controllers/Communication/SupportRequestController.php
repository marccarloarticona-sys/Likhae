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
        )->with('status', 'Support concern marked as resolved.');
    }

    public function addInternalNote(Request $request, ConversationSupportRequest $supportRequest, SmartMessagingService $smartMessaging): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1500']]);
        $smartMessaging->addInternalNote($request->user(), $supportRequest, $data['note']);

        return back()->with('status', 'Internal note saved.');
    }

    public function reassign(Request $request, ConversationSupportRequest $supportRequest, SmartMessagingService $smartMessaging): RedirectResponse
    {
        $data = $request->validate(['admin_user_id' => ['required', 'integer', 'min:1']]);
        $result = $smartMessaging->reassignRequest($request->user(), $supportRequest, (int) $data['admin_user_id']);

        return redirect()->route('admin.messages', ['section' => 'chats', 'conversation' => $result['conversation_id']])
            ->with('status', 'Support conversation reassigned.');
    }

    public function reopen(Request $request, ConversationSupportRequest $supportRequest, SmartMessagingService $smartMessaging): RedirectResponse
    {
        $result = $smartMessaging->reopenRequest($request->user(), $supportRequest);

        return redirect()->route('admin.messages', ['section' => 'overview', 'request' => $supportRequest->id])
            ->with('status', 'Support request reopened and returned to the Admin queue.');
    }
}
