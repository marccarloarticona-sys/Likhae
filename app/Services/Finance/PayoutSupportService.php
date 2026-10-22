<?php

namespace App\Services\Finance;

use App\Models\Communication\Conversation;
use App\Models\User;
use App\Services\Communication\ConversationService;
use App\Services\Communication\ConversationStartAuthorizationService;
use RuntimeException;

final class PayoutSupportService
{
    public function __construct(private readonly ConversationService $conversations) {}

    public function contactAdmin(User $requester, string $message): Conversation
    {
        $admin = User::query()
            ->where('account_type', User::TYPE_ADMIN)
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('id')
            ->first();
        if (! $admin) {
            throw new RuntimeException('Payout support is unavailable. Please try again later.');
        }

        $context = app(ConversationStartAuthorizationService::class)->contextForNew($requester, $admin);
        if ($context === null) {
            throw new RuntimeException('This account cannot start a payout support conversation.');
        }

        $supportContext = ['type' => 'DIRECT'];
        $conversation = $this->conversations->start($requester, $admin->id, $supportContext);
        $this->conversations->send($requester, $admin->id, $message, $supportContext, $conversation);

        return $conversation;
    }
}
