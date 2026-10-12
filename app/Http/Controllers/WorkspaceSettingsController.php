<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        $data = $request->validate([
            'theme' => ['required', Rule::in(['light', 'dark', 'system'])],
            'language' => ['required', Rule::in(['en'])],
        ]);
        $current = (array) $request->user()->notification_preferences;
        $updated = ['theme' => $data['theme'], 'language' => $data['language']];

        foreach ($this->booleanKeys($config) as $key) {
            $updated[$key] = $request->boolean($key);
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
                    ['seller_order_updates', 'Order Updates', 'New orders, cancellations, returns, and fulfillment changes.'],
                    ['seller_inventory_updates', 'Inventory Alerts', 'Low-stock and out-of-stock reminders for active products.'],
                    ['seller_chat_updates', 'Customer Messages', 'New messages and buyer follow-ups.'],
                    ['seller_review_updates', 'Reviews & Compliance', 'New reviews, moderation actions, and account notices.'],
                    ['seller_marketing_updates', 'Marketing & Vouchers', 'Campaign status, voucher usage, and promotion reminders.'],
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
        foreach ($config['notifications'] as [$key]) $defaults[$key] = true;
        return $defaults;
    }

    /** @return array<int, string> */
    private function booleanKeys(array $config): array
    {
        $keys = array_merge(array_column($config['notifications'], 0), [$config['sound_key']]);
        return $config['show_ai'] ? array_merge($keys, [$config['ai_key'], $config['ai_sound_key']]) : $keys;
    }
}
