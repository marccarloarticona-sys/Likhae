<?php

namespace App\Providers;

use App\Models\Admin\Notification;
use App\Models\Admin\Dispute;
use App\Models\Admin\SellerComplianceCase;
use App\Models\Auth\RegistrationApplication;
use App\Models\Buyer\Cart;
use App\Models\Buyer\Order;
use App\Models\Buyer\Review;
use App\Models\Communication\Message;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\PickupRequest;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderEarning;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\AccountNotificationService;
use App\Policies\LogisticsCenterPolicy;
use App\Policies\RiderProfilePolicy;
use App\Policies\SellerProfilePolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        ResetPassword::createUrlUsing(function ($notifiable, string $token): string {
            $request = request();
            $portal = $request->hasSession()
                ? $request->session()->get('password_reset_portal', 'marketplace')
                : 'marketplace';
            $portal = $portal === 'logistics' ? 'logistics' : 'marketplace';

            return url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
                'portal' => $portal,
            ], false));
        });

        ResetPassword::toMailUsing(function ($notifiable, string $token): MailMessage {
            $request = request();
            $portal = $request->hasSession()
                ? $request->session()->get('password_reset_portal', 'marketplace')
                : 'marketplace';
            $portal = $portal === 'logistics' ? 'logistics' : 'marketplace';
            $resetUrl = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
                'portal' => $portal,
            ], false));

            return (new MailMessage)
                ->subject('Reset Your LIKHAE Password')
                ->view('emails.auth.reset-password', [
                    'resetUrl' => $resetUrl,
                    'expirationMinutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
                ]);
        });

        // The local development server may use Vite's hot marker. Public
        // likhae.online requests must ignore it because it can point to a
        // private LAN address that public visitors cannot reach.
        $host = strtolower((string) request()->getHost());
        $isLikhaeOnline = in_array($host, ['likhae.online', 'www.likhae.online'], true);

        Vite::useHotFile(
            $isLikhaeOnline
                ? storage_path('framework/vite.public-disabled.hot')
                : storage_path('framework/vite.hot')
        );

        // Keep notification records in step with every core workflow change,
        // regardless of whether it came from a web page or an API endpoint.
        $accountNotifications = app(AccountNotificationService::class);
        Order::created(fn (Order $order) => $accountNotifications->orderPlaced($order));
        Order::updated(function (Order $order) use ($accountNotifications): void {
            if ($order->wasChanged('status')) {
                $accountNotifications->orderStatusChanged($order);
            }
        });
        SellerOrder::created(fn (SellerOrder $sellerOrder) => $accountNotifications->sellerOrderCreated($sellerOrder));
        SellerOrder::updated(function (SellerOrder $sellerOrder) use ($accountNotifications): void {
            if ($sellerOrder->wasChanged('status')) {
                $accountNotifications->sellerOrderStatusChanged($sellerOrder);
            }
        });
        Shipment::updated(function (Shipment $shipment) use ($accountNotifications): void {
            if ($shipment->wasChanged('current_status')) {
                $accountNotifications->shipmentStatusChanged($shipment);
            }
        });
        RiderAssignment::created(fn (RiderAssignment $assignment) => $accountNotifications->riderAssignmentCreated($assignment));
        RiderAssignment::updated(function (RiderAssignment $assignment) use ($accountNotifications): void {
            if ($assignment->wasChanged('status')) {
                $accountNotifications->riderAssignmentStatusChanged($assignment);
            }
        });
        Review::created(fn (Review $review) => $accountNotifications->reviewSubmitted($review));
        RiderEarning::created(fn (RiderEarning $earning) => $accountNotifications->riderEarningCreated($earning));

        // Force HTTPS URLs when behind Cloudflare or other SSL terminator
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        Gate::policy(SellerProfile::class, SellerProfilePolicy::class);
        Gate::policy(LogisticsCenter::class, LogisticsCenterPolicy::class);
        Gate::policy(RiderProfile::class, RiderProfilePolicy::class);

        $memo = static function (string $key, callable $resolve): mixed {
            $attributes = request()->attributes;

            if ($attributes->has($key)) {
                return $attributes->get($key);
            }

            $value = $resolve();
            $attributes->set($key, $value);

            return $value;
        };

        $hasTable = static fn (string $table): bool => $memo(
            '_likhae_schema_'.$table,
            static fn (): bool => Schema::hasTable($table),
        );

        $unreadMessageCount = static function (?User $user) use ($memo, $hasTable): int {
            if (! $user || ! $hasTable('messages') || ! $hasTable('conversation_participants')) {
                return 0;
            }

            return $memo('_likhae_unread_messages_'.$user->id, static fn (): int => Message::query()
                ->join('conversation_participants as cp', function ($join) use ($user): void {
                    $join->on('cp.conversation_id', '=', 'messages.conversation_id')
                        ->where('cp.user_id', '=', $user->id)
                        ->whereNull('cp.left_at');
                })
                ->whereNull('messages.deleted_at')
                ->where(function ($query) use ($user): void {
                    $query->whereNull('messages.sender_user_id')
                        ->orWhere('messages.sender_user_id', '<>', $user->id);
                })
                ->where(function ($query): void {
                    $query->whereNull('cp.last_read_at')
                        ->orWhereColumn('messages.sent_at', '>', 'cp.last_read_at');
                })
                ->count('messages.id'));
        };

        $unreadNotificationCount = static function (?User $user) use ($memo, $hasTable): int {
            if (! $user || ! $hasTable('notifications')) {
                return 0;
            }

            return $memo('_likhae_unread_notifications_'.$user->id, static fn (): int => Notification::query()
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count());
        };

        View::composer(['components.admin.sidebar', 'components.admin.header'], function ($view) use ($unreadMessageCount, $unreadNotificationCount): void {
            /** @var User|null $admin */
            $admin = auth()->user();

            $view->with('adminUiCounts', [
                'messages' => $admin?->isAccountType(User::TYPE_ADMIN) ? $unreadMessageCount($admin) : 0,
                'notifications' => $admin?->isAccountType(User::TYPE_ADMIN) ? $unreadNotificationCount($admin) : 0,
            ]);
        });

        View::composer(['components.seller.sidebar', 'components.seller.header'], function ($view) use ($unreadMessageCount, $unreadNotificationCount): void {
            /** @var User|null $sellerUser */
            $sellerUser = auth()->user();

            $counts = [
                'to_process' => 0,
                'to_prepare' => 0,
                'ready_pickup' => 0,
                'shipping' => 0,
                'completed' => 0,
                'returns' => 0,
                'messages' => 0,
                'notifications' => 0,
            ];

            if ($sellerUser?->isAccountType(User::TYPE_SELLER) && Schema::hasTable('seller_orders')) {
                $sellerProfileId = $sellerUser->sellerProfile()->where('status', 'ACTIVE')->value('id');

                if ($sellerProfileId) {
                    SellerOrder::query()
                        ->where('seller_profile_id', $sellerProfileId)
                        ->selectRaw('status, COUNT(*) as total')
                        ->groupBy('status')
                        ->pluck('total', 'status')
                        ->each(function ($total, string $status) use (&$counts): void {
                            $key = match ($status) {
                                'PLACED' => 'to_process',
                                'CONFIRMED', 'PREPARING' => 'to_prepare',
                                'READY_FOR_PICKUP' => 'ready_pickup',
                                'PICKED_UP' => 'shipping',
                                'COMPLETED' => 'completed',
                                default => null,
                            };

                            if ($key !== null) {
                                $counts[$key] += (int) $total;
                            }
                        });
                }

                $counts['messages'] = $unreadMessageCount($sellerUser);
                $counts['notifications'] = $unreadNotificationCount($sellerUser);
            }

            $view->with('sellerSidebarCounts', $counts);
            $view->with('sellerUiCounts', $counts);
        });

        View::composer(['components.buyer.sidebar', 'components.buyer.header'], function ($view) use ($unreadMessageCount, $unreadNotificationCount): void {
            /** @var User|null $buyer */
            $buyer = auth()->user();

            $counts = ['cart' => 0, 'messages' => 0, 'notifications' => 0];

            if ($buyer?->isAccountType(User::TYPE_BUYER)) {
                if (Schema::hasTable('carts') && Schema::hasTable('cart_items')) {
                    $counts['cart'] = (int) ($buyer->carts()
                        ->where('status', 'ACTIVE')
                        ->with('items:id,cart_id,quantity')
                        ->latest('id')
                        ->first()?->items?->sum('quantity') ?? 0);
                }

                $counts['messages'] = $unreadMessageCount($buyer);
                $counts['notifications'] = $unreadNotificationCount($buyer);
            }

            $view->with('buyerUiCounts', $counts);
        });

        View::composer(['components.admin.sidebar', 'Logistics.app', 'rider.app'], function ($view) use ($unreadMessageCount, $unreadNotificationCount): void {
            /** @var User|null $user */
            $user = auth()->user();
            $counts = [
                'registrations' => 0,
                'products' => 0,
                'compliance' => 0,
                'disputes' => 0,
                'messages' => 0,
                'orders' => 0,
                'pickups' => 0,
                'deliveries' => 0,
                'incoming' => 0,
                'sorting' => 0,
                'assignments' => 0,
                'monitoring' => 0,
                'rider_applications' => 0,
                'pickup_requests' => 0,
                'cart' => 0,
                'notifications' => 0,
            ];

            if ($user?->isAccountType(User::TYPE_ADMIN)) {
                if (Schema::hasTable('registration_applications')) {
                    $counts['registrations'] = RegistrationApplication::query()
                        ->whereIn('status', [RegistrationApplication::STATUS_PENDING, RegistrationApplication::STATUS_UNDER_REVIEW])
                        ->whereHas('user', fn ($query) => $query->whereIn('account_type', [User::TYPE_BUYER, User::TYPE_SELLER, User::TYPE_LOGISTICS]))
                        ->count();
                }
                if (Schema::hasTable('seller_compliance_cases')) {
                    $counts['compliance'] = SellerComplianceCase::query()->whereIn('status', ['OPEN', 'UNDER_REVIEW'])->count();
                    $counts['products'] = SellerComplianceCase::query()
                        ->whereIn('status', ['OPEN', 'UNDER_REVIEW'])
                        ->whereNotNull('product_id')
                        ->distinct()
                        ->count('product_id');
                }
                if (Schema::hasTable('disputes')) {
                    $counts['disputes'] = Dispute::query()->whereIn('status', ['OPEN', 'UNDER_REVIEW'])->count();
                }
                $counts['messages'] = $unreadMessageCount($user);
            }

            if ($user?->isAccountType(User::TYPE_SELLER) && Schema::hasTable('seller_orders')) {
                $sellerProfileId = $user->sellerProfile()->where('status', 'ACTIVE')->value('id');
                if ($sellerProfileId) {
                    $counts['orders'] = SellerOrder::query()
                        ->where('seller_profile_id', $sellerProfileId)
                        ->whereIn('status', ['PLACED', 'CONFIRMED', 'PREPARING'])
                        ->count();
                }
                $counts['messages'] = $unreadMessageCount($user);
            }

            if ($user?->isAccountType(User::TYPE_RIDER) && $user->riderProfile && Schema::hasTable('rider_assignments')) {
                $activeStatuses = ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS'];
                $riderAssignments = RiderAssignment::query()->where('rider_profile_id', $user->riderProfile->id)->whereIn('status', $activeStatuses);
                $counts['pickups'] = (clone $riderAssignments)->where('assignment_type', 'PICKUP')->count();
                $counts['deliveries'] = (clone $riderAssignments)->where('assignment_type', 'DELIVERY')->count();
                $counts['messages'] = $unreadMessageCount($user);
            }

            if ($user?->isAccountType(User::TYPE_BUYER)) {
                if (Schema::hasTable('carts') && Schema::hasTable('cart_items')) {
                    $activeCart = Cart::query()
                        ->where('buyer_user_id', $user->id)
                        ->where('status', Cart::STATUS_ACTIVE)
                        ->first();
                    $counts['cart'] = (int) ($activeCart?->items()->sum('quantity') ?? 0);
                }
                if (Schema::hasTable('orders')) {
                    $counts['orders'] = Order::query()
                        ->where('buyer_user_id', $user->id)
                        ->whereNotIn('status', ['COMPLETED', 'CANCELLED', 'PARTIALLY_CANCELLED', 'RETURNED'])
                        ->count();
                }
                $counts['messages'] = $unreadMessageCount($user);
            }

            if ($user?->isAccountType(User::TYPE_LOGISTICS) && Schema::hasTable('shipments')) {
                $centerId = $user->logisticsCenter?->id;
                if ($centerId) {
                    $forCenter = static function ($query) use ($centerId): void {
                        $query->where(function ($centerQuery) use ($centerId): void {
                            $centerQuery->where('logistics_center_id', $centerId)
                                ->orWhere(fn ($unassigned) => $unassigned->whereNull('logistics_center_id')
                                    ->whereHas('serviceArea', fn ($area) => $area->where('logistics_center_id', $centerId)));
                        });
                    };
                    $centerShipments = Shipment::query()->where($forCenter);

                    $counts['incoming'] = (clone $centerShipments)->where('current_status', 'PICKED_UP')->count();
                    $counts['sorting'] = (clone $centerShipments)->where('current_status', 'AT_SORTING_CENTER')->count();
                    $counts['assignments'] = (clone $centerShipments)
                        ->where('current_status', 'SORTED')
                        ->whereDoesntHave('riderAssignments', fn ($query) => $query
                            ->where('assignment_type', 'DELIVERY')
                            ->whereIn('status', ['ASSIGNED', 'ACCEPTED', 'IN_PROGRESS']))
                        ->count();
                    $counts['monitoring'] = (clone $centerShipments)
                        ->whereIn('current_status', ['ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERY_FAILED'])
                        ->count();

                    if (Schema::hasTable('pickup_requests')) {
                        $counts['pickup_requests'] = PickupRequest::query()
                            ->where('status', 'PENDING')
                            ->whereHas('shipment', $forCenter)
                            ->count();
                    }
                    if (Schema::hasTable('registration_applications')) {
                        $counts['rider_applications'] = RegistrationApplication::query()
                            ->whereIn('status', [RegistrationApplication::STATUS_PENDING, RegistrationApplication::STATUS_UNDER_REVIEW])
                            ->whereHas('user', fn ($query) => $query->where('account_type', User::TYPE_RIDER))
                            ->whereHas('riderData', fn ($query) => $query->where('target_logistics_center_id', $centerId))
                            ->count();
                    }
                }
                $counts['messages'] = $unreadMessageCount($user);
            }

            $counts['notifications'] = $unreadNotificationCount($user);

            $view->with('sidebarCounts', $counts);
            $view->with('workspaceNotificationCount', $counts['notifications']);
        });
    }
}
