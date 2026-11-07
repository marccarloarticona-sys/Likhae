<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Communication\Conversation;
use App\Models\Rider\InterhubAttendanceSession;
use App\Services\Fulfillment\InterhubTimekeepingService;
use App\Services\Marketplace\SellerPromotionBuyerService;
use App\Support\RiderVehicleType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileWorkflowApiController extends Controller
{
    public function offers(Request $request, SellerPromotionBuyerService $offers): JsonResponse
    {
        $limit = min(max($request->integer('per_page', 20), 1), 50);
        $rows = $offers->forMarketplace($request->user(), $limit);

        return response()->json([
            'success' => true,
            'data' => $rows->map(fn ($offer): array => [
                'id' => (int) $offer->id,
                'name' => $offer->name,
                'type' => $offer->promotion_type,
                'seller' => [
                    'id' => (int) $offer->sellerProfile?->id,
                    'name' => $offer->sellerProfile?->business_name,
                ],
                'bundle_price' => $offer->bundle_price === null ? null : (float) $offer->bundle_price,
                'buy_quantity' => $offer->buy_quantity === null ? null : (int) $offer->buy_quantity,
                'reward_quantity' => $offer->reward_quantity === null ? null : (int) $offer->reward_quantity,
                'reward_discount_percent' => $offer->reward_discount_percent === null ? null : (float) $offer->reward_discount_percent,
                'starts_at' => $offer->starts_at?->toIso8601String(),
                'ends_at' => $offer->ends_at?->toIso8601String(),
                'redemptions_remaining' => $offer->buyer_redemptions_remaining ?? null,
            ])->values(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function settings(Request $request): JsonResponse
    {
        abort_unless($request->user()->riderProfile, 403);

        $defaults = [
            'theme' => 'system',
            'language' => 'en',
            'rider_notification_sounds' => true,
            'show_online_status' => true,
            'rider_ai_assistant_enabled' => true,
            'rider_ai_response_sound' => false,
            'rider_assignment_updates' => true,
            'rider_route_updates' => true,
            'rider_chat_updates' => true,
            'rider_earnings_updates' => true,
            'rider_safety_updates' => true,
        ];
        $preferences = array_merge($defaults, (array) $request->user()->notification_preferences);
        if (! is_string($preferences['language'])
            || ! array_key_exists($preferences['language'], config('app.supported_locales', ['en' => 'English']))) {
            $preferences['language'] = 'en';
        }

        return response()->json([
            'success' => true,
            'data' => ['preferences' => $preferences],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function receipt(Request $request, int $conversation): JsonResponse
    {
        $thread = Conversation::query()
            ->whereKey($conversation)
            ->whereHas('participants', fn ($query) => $query
                ->where('users.id', $request->user()->id)
                ->whereNull('conversation_participants.left_at'))
            ->with([
                'latestMessage:id,conversation_id,sender_user_id,sent_at',
                'participantRecords' => fn ($query) => $query->whereNull('left_at')->with('user:id,first_name,middle_initial,last_name,name_extension'),
            ])
            ->firstOrFail();
        $latestMessage = $thread->latestMessage;

        return response()->json([
            'success' => true,
            'data' => [
                'conversation_id' => (string) $thread->id,
                'latest_message_id' => $latestMessage ? (string) $latestMessage->id : null,
                'latest_message_at' => $latestMessage?->sent_at?->toIso8601String(),
                'participants' => $thread->participantRecords->map(function ($participant) use ($latestMessage): array {
                    $readAt = $participant->last_read_at;

                    return [
                        'user_id' => (string) $participant->user_id,
                        'name' => $participant->user?->name,
                        'read_at' => $readAt?->toIso8601String(),
                        'has_read_latest' => $latestMessage !== null
                            && $readAt !== null
                            && $readAt->greaterThanOrEqualTo($latestMessage->sent_at),
                    ];
                })->values(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function attendance(Request $request, InterhubTimekeepingService $timekeeping): JsonResponse
    {
        $rider = $request->user()->riderProfile;
        abort_unless($rider && RiderVehicleType::isInterLogistics($rider->vehicle_type), 403);

        $dashboard = $timekeeping->driverDashboardData($rider);
        $history = collect($dashboard['attendanceHistory'])->map(function (InterhubAttendanceSession $session): array {
            return [
                'id' => (int) $session->id,
                'clock_in_at' => $session->clock_in_at?->toIso8601String(),
                'clock_out_at' => $session->clock_out_at?->toIso8601String(),
                'worked_minutes' => $session->worked_minutes,
                'gross_pay' => $session->gross_pay === null ? null : (float) $session->gross_pay,
                'overtime_review' => (bool) $session->overtime_review,
                'manifest_number' => $session->manifest?->manifest_number,
                'payroll_status' => $session->payrollItem?->status,
                'events' => $session->events->map(fn ($event): array => [
                    'type' => $event->event_type,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->values(),
            ];
        })->values();
        $openSession = $dashboard['openSession'];

        return response()->json([
            'success' => true,
            'data' => [
                'timekeeping_ready' => $dashboard['timekeepingReady'],
                'region_type' => $dashboard['regionType'],
                'effective_daily_rate' => $dashboard['effectiveRate']?->daily_rate === null
                    ? null
                    : (float) $dashboard['effectiveRate']->daily_rate,
                'open_session' => $openSession ? [
                    'id' => (int) $openSession->id,
                    'clock_in_at' => $openSession->clock_in_at?->toIso8601String(),
                    'manifest_number' => $openSession->manifest?->manifest_number,
                ] : null,
                'current_minutes' => $dashboard['currentMinutes'],
                'estimated_current_pay' => $dashboard['estimatedCurrentPay'],
                'today' => [
                    'minutes' => $dashboard['todayMinutes'],
                    'status' => $dashboard['todayStatus'],
                    'pay_estimate' => $dashboard['todayPayEstimate'],
                ],
                'period' => [
                    'hours' => $dashboard['totalPeriodHours'] ?? 0,
                    'pay_estimate' => $dashboard['estimatedPeriodPay'] ?? 0,
                ],
                'history' => $history,
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
