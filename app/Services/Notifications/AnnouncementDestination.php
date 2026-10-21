<?php

namespace App\Services\Notifications;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

class AnnouncementDestination
{
    private const PAGE_DESTINATIONS = [
        'ALL' => [
            'Marketplace pages' => [
                'home' => 'LIKHAE home',
                'products' => 'Browse marketplace',
            ],
        ],
        'BUYERS' => [
            'Buyer pages' => [
                'buyer.home' => 'Buyer home',
                'buyer.products' => 'Browse products',
                'buyer.flash-deals' => 'Flash deals',
                'buyer.local-finds' => 'Local finds',
                'buyer.platform-events.index' => 'Platform events',
                'buyer.cart' => 'Shopping cart',
                'buyer.orders' => 'Orders',
                'buyer.rewards' => 'Rewards',
                'buyer.wishlist' => 'Wishlist',
                'buyer.messages' => 'Messages',
                'buyer.notifications' => 'Notifications',
                'buyer.account' => 'Account',
                'buyer.settings' => 'Settings',
            ],
        ],
        'SELLERS' => [
            'Seller pages' => [
                'seller.dashboard' => 'Seller dashboard',
                'seller.products' => 'Products',
                'seller.orders' => 'Orders',
                'seller.marketing' => 'Marketing',
                'seller.store' => 'Store profile',
                'seller.settlements' => 'Settlements',
                'seller.reviews' => 'Reviews',
                'seller.settings' => 'Settings',
            ],
        ],
        'LOGISTICS' => [
            'Logistics and rider pages' => [
                'logistics.home' => 'Logistics and rider portal',
            ],
        ],
        'LOGISTICS_CENTERS' => [
            'Logistics center pages' => [
                'logistics.dashboard' => 'Dashboard',
                'logistics.parcels' => 'Parcels',
                'logistics.pickups' => 'Pickups',
                'logistics.transfers' => 'Transfers',
                'logistics.remittances' => 'Remittances',
                'logistics.reports' => 'Reports',
                'logistics.settings' => 'Settings',
            ],
        ],
        'RIDERS' => [
            'Rider pages' => [
                'rider.dashboard' => 'Dashboard',
                'rider.deliveries' => 'Deliveries',
                'rider.earnings' => 'Earnings',
                'rider.history' => 'Delivery history',
                'rider.parcels.index' => 'Parcels',
                'rider.pickups' => 'Pickups',
                'rider.remittances' => 'Remittances',
                'rider.transfers' => 'Transfers',
                'rider.trips' => 'Trips',
                'rider.messages' => 'Messages',
                'rider.settings' => 'Settings',
            ],
        ],
    ];

    public function resolve(?string $destination): ?string
    {
        $destination = trim((string) $destination);
        if ($destination === '') {
            return null;
        }

        $namedRoute = Route::getRoutes()->getByName($destination);
        if ($namedRoute) {
            if ($namedRoute->parameterNames() !== []) {
                return null;
            }

            $path = route($destination, [], false);

            return str_starts_with($path, '/') ? $path : '/'.$path;
        }

        if (! $this->isSafeInternalPath($destination)) {
            return null;
        }

        try {
            Route::getRoutes()->match(Request::create($destination, 'GET'));
        } catch (Throwable) {
            return null;
        }

        return $destination;
    }

    /** @return array<string, array<int, array{label: string, options: array<int, array{value: string, label: string}>}>> */
    public function options(): array
    {
        $options = [];

        foreach (self::PAGE_DESTINATIONS as $audience => $groups) {
            foreach ($groups as $groupLabel => $routes) {
                $groupOptions = [];

                foreach ($routes as $routeName => $label) {
                    $route = Route::getRoutes()->getByName($routeName);
                    if (! $route || $route->parameterNames() !== [] || ! in_array('GET', $route->methods(), true)) {
                        continue;
                    }

                    $path = route($routeName, [], false);
                    $path = str_starts_with($path, '/') ? $path : '/'.$path;

                    if ($this->resolve($path)) {
                        $groupOptions[] = ['value' => $path, 'label' => $label];
                    }
                }

                if ($groupOptions !== []) {
                    $options[$audience][] = ['label' => $groupLabel, 'options' => $groupOptions];
                }
            }
        }

        return $options;
    }

    private function isSafeInternalPath(string $destination): bool
    {
        if (! str_starts_with($destination, '/')
            || str_starts_with($destination, '//')
            || str_contains($destination, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $destination) === 1) {
            return false;
        }

        $parts = parse_url($destination);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        $decodedPath = rawurldecode($path);

        if (isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! str_starts_with($path, '/')
            || str_starts_with($decodedPath, '//')
            || str_contains($decodedPath, '\\')) {
            return false;
        }

        return true;
    }
}
