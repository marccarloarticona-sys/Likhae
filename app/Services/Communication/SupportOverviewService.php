<?php

namespace App\Services\Communication;

use App\Models\Communication\ConversationSupportInteraction;
use App\Models\Communication\ConversationSupportRequest;
use App\Models\Communication\MessagingFaq;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SupportOverviewService
{
    public function __construct(private readonly ConversationAuthorizationService $authorization) {}

    public function schemaReady(): bool
    {
        return Schema::hasTable('conversation_support_requests')
            && Schema::hasTable('conversation_support_interactions')
            && Schema::hasTable('conversation_support_sessions')
            && Schema::hasColumn('conversation_support_sessions', 'current_request_id')
            && Schema::hasColumn('conversation_support_sessions', 'selected_category_snapshot');
    }

    /** @return array{requests:LengthAwarePaginator,stats:array<string,int>,top_faqs:\Illuminate\Support\Collection<int,MessagingFaq>,selected:?ConversationSupportRequest,filter:?string,search:string} */
    public function overview(User $recipient, ?string $filter = null, string $search = '', ?int $selectedId = null): array
    {
        $base = $this->scopeForRecipient($recipient);
        $counts = (clone $base)->select('status', DB::raw('COUNT(*) as aggregate'))->groupBy('status')->pluck('aggregate', 'status');

        $query = (clone $base)->with([
            'requester:id,first_name,middle_initial,last_name,name_extension,account_type',
            'selectedFaq:id,question,category',
            'acceptedBy:id,first_name,middle_initial,last_name,name_extension',
            'acknowledgedBy:id,first_name,middle_initial,last_name,name_extension',
            'resolvedBy:id,first_name,middle_initial,last_name,name_extension',
            'conversation:id,order_id,seller_order_id,shipment_id',
        ])->addSelect([
            'last_buyer_message' => DB::table('messages')->select('body')
                ->whereColumn('messages.conversation_id', 'conversation_support_requests.conversation_id')
                ->whereColumn('messages.sender_user_id', 'conversation_support_requests.requester_user_id')
                ->orderByDesc('sent_at')->orderByDesc('id')->limit(1),
            'last_buyer_message_at' => DB::table('messages')->select('sent_at')
                ->whereColumn('messages.conversation_id', 'conversation_support_requests.conversation_id')
                ->whereColumn('messages.sender_user_id', 'conversation_support_requests.requester_user_id')
                ->orderByDesc('sent_at')->orderByDesc('id')->limit(1),
        ]);

        if ($filter && in_array($filter, [
            ConversationSupportRequest::NEW,
            ConversationSupportRequest::AWAITING_RESPONSE,
            ConversationSupportRequest::IN_PROGRESS,
            ConversationSupportRequest::RESOLVED,
        ], true)) {
            $query->where('status', $filter);
        }

        $search = trim($search);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn (Builder $nested) => $nested
                ->where('category', 'like', $like)
                ->orWhere('selected_question', 'like', $like)
                ->orWhere('unresolved_concern', 'like', $like)
                ->orWhere('order_reference', 'like', $like)
                ->orWhereHas('requester', fn (Builder $buyer) => $buyer
                    ->where('first_name', 'like', $like)
                    ->orWhere('middle_initial', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('name_extension', 'like', $like)));
        }

        $requests = $query->orderByRaw("CASE status WHEN 'NEW' THEN 0 WHEN 'AWAITING_RESPONSE' THEN 1 WHEN 'IN_PROGRESS' THEN 2 ELSE 3 END")
            ->orderByDesc('requested_at')->paginate(12)->withQueryString();

        $isAdmin = $recipient->isAccountType(User::TYPE_ADMIN);
        $interactions = ConversationSupportInteraction::query()->whereHas('supportSession', function (Builder $session) use ($recipient, $isAdmin): void {
            if ($isAdmin) {
                $session->whereHas('recipient', fn (Builder $user) => $user->where('account_type', User::TYPE_ADMIN));
            } else {
                $session->where('recipient_user_id', $recipient->id);
            }
        });
        $faqStats = [
            'faq_resolved' => (clone $interactions)->where('feedback', 'RESOLVED')->count(),
            'faq_unresolved' => (clone $interactions)->where('feedback', 'UNRESOLVED')->count(),
        ];
        $topFaqs = MessagingFaq::query()
            ->where('owner_type', $recipient->isAccountType(User::TYPE_ADMIN) ? MessagingFaq::OWNER_ADMIN : MessagingFaq::OWNER_SELLER)
            ->when($recipient->isAccountType(User::TYPE_ADMIN), fn (Builder $faq) => $faq->whereNull('seller_profile_id'))
            ->when($recipient->isAccountType(User::TYPE_SELLER), fn (Builder $faq) => $faq->where('seller_profile_id', $recipient->sellerProfile?->id))
            ->where('entry_type', 'FAQ')->where('is_automatic', true)
            ->where('selection_count', '>', 0)
            ->orderByDesc('selection_count')->orderBy('id')->limit(3)->get(['id', 'question', 'category', 'selection_count']);

        $selected = $selectedId ? (clone $this->scopeForRecipient($recipient))
            ->with([
                'requester:id,first_name,middle_initial,last_name,name_extension,account_type',
                'selectedFaq:id,question,category',
                'acknowledgedBy:id,first_name,middle_initial,last_name,name_extension',
                'acceptedBy:id,first_name,middle_initial,last_name,name_extension',
                'resolvedBy:id,first_name,middle_initial,last_name,name_extension',
                'interactions.selectedFaq:id,question,category',
                'conversation:id,order_id,seller_order_id,shipment_id',
            ])
            ->addSelect([
                'last_buyer_message' => DB::table('messages')->select('body')
                    ->whereColumn('messages.conversation_id', 'conversation_support_requests.conversation_id')
                    ->whereColumn('messages.sender_user_id', 'conversation_support_requests.requester_user_id')
                    ->orderByDesc('sent_at')->orderByDesc('id')->limit(1),
                'last_buyer_message_at' => DB::table('messages')->select('sent_at')
                    ->whereColumn('messages.conversation_id', 'conversation_support_requests.conversation_id')
                    ->whereColumn('messages.sender_user_id', 'conversation_support_requests.requester_user_id')
                    ->orderByDesc('sent_at')->orderByDesc('id')->limit(1),
            ])->whereKey($selectedId)->firstOrFail() : null;

        if ($selected) {
            $participantIds = $selected->conversation->participantRecords()->whereNull('left_at')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            abort_unless(count($participantIds) === 2
                && in_array((int) $selected->requester_user_id, $participantIds, true)
                && (in_array((int) $selected->recipient_user_id, $participantIds, true)
                    || in_array((int) $selected->accepted_by_user_id, $participantIds, true)), 403);
            if (! $isAdmin) {
                $this->authorization->assertCanAccess($recipient, $selected->conversation);
                abort_unless(in_array((int) $recipient->id, $participantIds, true), 403);
            }
        }

        $totalSessions = 0;
        $botResolved = 0;
        $averageResponseMinutes = null;
        $averageResolutionMinutes = null;
        $unansweredQuestions = collect();
        $topCategories = collect();
        if ($isAdmin) {
            $sessions = \App\Models\Communication\ConversationSupportSession::query()
                ->whereHas('recipient', fn (Builder $user) => $user->where('account_type', User::TYPE_ADMIN));
            $totalSessions = (clone $sessions)->count();
            $botResolved = (clone $sessions)->where('status', 'RESOLVED')->whereNull('current_request_id')->count();
            $metricRequests = ConversationSupportRequest::query()->whereNull('seller_profile_id')
                ->addSelect(['first_human_response_at' => DB::table('messages')->select('sent_at')
                    ->whereColumn('messages.conversation_id', 'conversation_support_requests.conversation_id')
                    ->whereColumn('messages.sender_user_id', 'conversation_support_requests.accepted_by_user_id')
                    ->whereColumn('messages.sent_at', '>=', 'conversation_support_requests.requested_at')
                    ->orderBy('messages.sent_at')->orderBy('messages.id')->limit(1)])
                ->get();
            $responseMinutes = $metricRequests->filter(fn ($row) => $row->requested_at && $row->first_human_response_at)
                ->map(fn ($row) => max(0, $row->requested_at->diffInSeconds(\Illuminate\Support\Carbon::parse($row->first_human_response_at)) / 60));
            $resolutionMinutes = $metricRequests->filter(fn ($row) => $row->requested_at && $row->resolved_at)
                ->map(fn ($row) => max(0, $row->requested_at->diffInSeconds($row->resolved_at) / 60));
            $averageResponseMinutes = $responseMinutes->isNotEmpty() ? (int) round($responseMinutes->avg()) : null;
            $averageResolutionMinutes = $resolutionMinutes->isNotEmpty() ? (int) round($resolutionMinutes->avg()) : null;
            $unansweredQuestions = (clone $interactions)->where('feedback', 'UNRESOLVED')
                ->select('question_snapshot')->selectRaw('COUNT(*) AS attempts')
                ->groupBy('question_snapshot')->orderByDesc('attempts')->limit(5)->get();
            $topCategories = (clone $interactions)->select('category')->selectRaw('COUNT(*) AS selections')
                ->groupBy('category')->orderByDesc('selections')->limit(5)->get();
        }

        return [
            'requests' => $requests,
            'stats' => [
                'new' => (int) $counts->get(ConversationSupportRequest::NEW, 0),
                'awaiting_response' => (int) $counts->get(ConversationSupportRequest::AWAITING_RESPONSE, 0),
                'in_progress' => (int) $counts->get(ConversationSupportRequest::IN_PROGRESS, 0),
                'resolved' => (int) $counts->get(ConversationSupportRequest::RESOLVED, 0),
                'total_conversations' => $totalSessions,
                'bot_resolved' => $botResolved,
                'escalated' => $isAdmin ? (clone $base)->count() : 0,
                'waiting' => $isAdmin ? (int) $counts->get(ConversationSupportRequest::NEW, 0) + (int) $counts->get(ConversationSupportRequest::AWAITING_RESPONSE, 0) : 0,
                'avg_first_response_minutes' => $averageResponseMinutes,
                'avg_resolution_minutes' => $averageResolutionMinutes,
                ...$faqStats,
            ],
            'top_faqs' => $topFaqs,
            'top_categories' => $topCategories,
            'unanswered_questions' => $unansweredQuestions,
            'selected' => $selected,
            'filter' => $filter,
            'search' => $search,
        ];
    }

    private function scopeForRecipient(User $recipient): Builder
    {
        abort_unless($recipient->isActive(), 403);
        if ($recipient->isAccountType(User::TYPE_SELLER)) {
            $profile = $recipient->sellerProfile;
            abort_unless($profile?->status === 'ACTIVE', 403);

            return ConversationSupportRequest::query()
                ->where('recipient_user_id', $recipient->id)
                ->where('seller_profile_id', $profile->id);
        }
        abort_unless($recipient->isAccountType(User::TYPE_ADMIN), 403);

        return ConversationSupportRequest::query()->whereNull('seller_profile_id');
    }
}
