<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkspaceSettingsController extends Controller
{
    public function show(Request $request, string $workspace): View
    {
        $config = $this->config($workspace);

        return view($config['view'], [
            'settingsConfig' => $config,
            'preferences' => array_merge($this->defaults($config), (array) $request->user()->notification_preferences),
        ]);
    }

    public function update(Request $request, string $workspace): RedirectResponse
    {
        $config = $this->config($workspace);
        foreach (['user_id', 'seller_id', 'seller_profile_id'] as $ownerKey) {
            if ($request->exists($ownerKey)) {
                throw ValidationException::withMessages([
                    $ownerKey => 'Account ownership is determined by your signed-in session.',
                ]);
            }
        }

        $rules = [
            'theme' => ['required', Rule::in(['light', 'dark', 'system'])],
            'language' => ['sometimes', Rule::in(['en'])],
        ];
        foreach ($this->booleanKeys($config) as $key) {
            $rules[$key] = ['sometimes', 'boolean'];
        }
        $allowedKeys = array_merge(['_token', '_method', 'theme', 'language'], array_keys($rules));
        $unknownKeys = array_diff(array_keys($request->all()), $allowedKeys);
        if ($unknownKeys !== []) {
            throw ValidationException::withMessages([
                reset($unknownKeys) => 'This setting is not available for your workspace.',
            ]);
        }
        $data = $request->validate($rules);
        $current = (array) $request->user()->notification_preferences;
        $updated = [
            'theme' => $data['theme'],
            'language' => $data['language'] ?? ($current['language'] ?? 'en'),
        ];

        foreach ($this->booleanKeys($config) as $key) {
            $updated[$key] = $request->boolean($key);
        }
        foreach ($config['notifications'] as $notification) {
            $key = $notification[0];
            if (($notification[3] ?? true) === false) {
                $updated[$key] = $current[$key] ?? false;
            }
        }

        $request->user()->forceFill([
            'notification_preferences' => array_merge($current, $updated),
        ])->save();

        return back()->with('status', $config['center'].' settings updated.');
    }

    /** @return array<string, mixed> */
    private function config(string $workspace): array
    {
        return match ($workspace) {
            'seller' => [
                'view' => 'Seller.settings', 'center' => 'Seller Center', 'account_route' => 'seller.account',
                'update_route' => 'seller.settings.update',
                'description' => 'Tune store alerts, workspace assistance, and display preferences.',
                'notifications' => [
                    ['seller_order_updates', 'Order Updates', 'New orders, returns, and pickup or shipment changes.', true],
                    ['seller_inventory_updates', 'Inventory Alerts', 'Low-stock and out-of-stock reminders for active products.', false],
                    ['seller_chat_updates', 'Customer Messages', 'Live chat remains available. Message alert preferences are coming soon.', false],
                    ['seller_review_updates', 'Review Updates', 'New product reviews. Compliance and security notices remain enabled.', true],
                    ['seller_marketing_updates', 'Marketing & Vouchers', 'Campaign and voucher alerts are coming soon.', false],
                ],
            ],
            'admin' => [
            'view' => 'Admin.system-settings.index', 'center' => 'Admin Center', 'account_route' => 'admin.account',
                'update_route' => 'admin.system-settings.update',
                'show_ai' => false,
                'description' => 'Control operational alerts and your personal Admin workspace experience.',
                'notifications' => [
                    ['admin_registration_updates', 'Registration Queue', 'New Seller, Logistics, and account applications.'],
                    ['admin_compliance_updates', 'Compliance Alerts', 'Product moderation, seller risk, and policy events.'],
                    ['admin_dispute_updates', 'Complaints & Disputes', 'Escalations and cases that require administrator review.'],
                    ['admin_finance_updates', 'Finance Alerts', 'Commission, payout, and financial reporting exceptions.'],
                    ['admin_security_updates', 'Security & Audit', 'Sensitive account actions and audit events.'],
                ],
            ],
            'logistics' => [
                'view' => 'Logistics.settings', 'center' => 'Logistics Center', 'account_route' => 'logistics.profile',
                'update_route' => 'logistics.settings.update',
                'description' => 'Choose operational alerts and how your sorting-center workspace behaves.',
                'notifications' => [
                    ['logistics_pickup_updates', 'Pickup Requests', 'New, approved, rejected, and overdue pickup requests.'],
                    ['logistics_parcel_updates', 'Parcel Intake & Sorting', 'Receiving scans, sorting progress, and parcel exceptions.'],
                    ['logistics_dispatch_updates', 'Dispatch Updates', 'Assignment, handoff, and delivery status changes.'],
                    ['logistics_rider_updates', 'Rider Management', 'Rider applications, availability, and assignment issues.'],
                    ['logistics_exception_updates', 'Urgent Exceptions', 'Failed scans, delayed parcels, and operational escalations.'],
                ],
            ],
            'rider' => [
                'view' => 'rider.settings', 'center' => 'Rider Panel', 'account_route' => 'rider.profile',
                'update_route' => 'rider.settings.update',
                'description' => 'Manage assignment alerts, safety cues, and your on-road workspace.',
                'notifications' => [
                    ['rider_assignment_updates', 'Assignment Alerts', 'New pickup and delivery assignments.'],
                    ['rider_route_updates', 'Route & Status Updates', 'Handoffs, destination changes, and delivery status reminders.'],
                    ['rider_chat_updates', 'Messages', 'New messages from Logistics and customers.'],
                    ['rider_earnings_updates', 'Earnings Updates', 'Completed-delivery earnings and payout notices.'],
                    ['rider_safety_updates', 'Safety & Exceptions', 'Urgent parcel issues and safety-related notices.'],
                ],
            ],
            default => abort(404),
        } + [
            'workspace' => $workspace,
            'show_ai' => true,
            'ai_key' => $workspace.'_ai_assistant_enabled',
            'ai_sound_key' => $workspace.'_ai_response_sound',
            'sound_key' => $workspace.'_notification_sounds',
        ];
    }

    /** @return array<string, bool|string> */
    private function defaults(array $config): array
    {
        $defaults = ['theme' => 'system', 'language' => 'en', $config['sound_key'] => true];
        if ($config['show_ai']) $defaults += [$config['ai_key'] => true, $config['ai_sound_key'] => false];
        foreach ($config['notifications'] as $notification) {
            $defaults[$notification[0]] = ($notification[3] ?? true) === true;
        }
        return $defaults;
    }

    /** @return array<int, string> */
    private function booleanKeys(array $config): array
    {
        $notificationKeys = [];
        foreach ($config['notifications'] as $notification) {
            if (($notification[3] ?? true) === true) {
                $notificationKeys[] = $notification[0];
            }
        }
        $keys = array_merge($notificationKeys, [$config['sound_key']]);
        return $config['show_ai'] ? array_merge($keys, [$config['ai_key'], $config['ai_sound_key']]) : $keys;
    }
}
