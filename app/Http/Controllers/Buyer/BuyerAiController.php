<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Order;
use App\Models\User;
use App\Services\Buyer\BuyerGeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BuyerAiController extends Controller
{
    private const PAGE_CONTEXTS = [
        'home' => ['title' => 'Home', 'features' => ['browse products', 'view categories', 'view cart', 'view orders']],
        'products' => ['title' => 'Products', 'features' => ['search products', 'browse categories', 'view product details', 'choose quantity and variations', 'add to cart']],
        'cart' => ['title' => 'Shopping Cart', 'features' => ['view cart items', 'change quantity', 'select variations', 'apply voucher', 'view discounts', 'checkout']],
        'orders' => ['title' => 'My Orders', 'features' => ['view order status', 'track parcels', 'confirm receipt', 'leave feedback', 'request return or refund']],
        'messages' => ['title' => 'Messages', 'features' => ['view existing buyer messages', 'contact a seller or support']],
        'account' => ['title' => 'Account', 'features' => ['update profile', 'manage addresses', 'change password', 'view reviews']],
        'wishlist' => ['title' => 'Wishlist', 'features' => ['view saved products', 'remove saved products', 'add saved products to cart']],
        'rewards' => ['title' => 'Rewards & Vouchers', 'features' => ['view vouchers', 'view points', 'view cashback']],
        'buyer' => ['title' => 'Buyer portal', 'features' => ['browse products', 'view cart', 'view orders', 'manage your account']],
    ];

    public function chat(Request $request, BuyerGeminiService $gemini): JsonResponse
    {
        abort_unless($request->user()?->isAccountType(User::TYPE_BUYER), 403, 'Buyer access is required.');

        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'page' => ['nullable', 'string', 'max:40'],
        ]);
        $message = trim($data['message']);
        $page = array_key_exists($data['page'] ?? '', self::PAGE_CONTEXTS) ? $data['page'] : 'buyer';

        // Live account questions bypass the model so a model can never invent
        // delivery data or select another buyer's order.
        if ($this->isLiveOrderQuestion($message)) {
            return response()->json($this->answerOrderQuestion($request, $message));
        }

        if ($this->isNotificationCountQuestion($message)) {
            return response()->json(['reply' => $this->notificationCountAnswer($request)]);
        }

        if ($reply = $this->buyerPortalAnswer($request, $message)) {
            return response()->json(['reply' => $reply]);
        }

        $context = self::PAGE_CONTEXTS[$page];
        // Common Buyer guidance is deterministic and remains available even
        // when Gemini is down or has not been configured yet.
        if ($this->hasLocalHelpAnswer($message) || ! $gemini->configured()) {
            return response()->json(['reply' => $this->localHelp($message, $context)]);
        }

        try {
            return response()->json([
                'reply' => $gemini->generate($this->systemInstruction(), $this->generalPrompt($message, $context)),
            ]);
        } catch (Throwable) {
            report('LIKHAE Buyer AI request failed.');

            return response()->json([
                'reply' => $this->localHelp($message, $context),
                'mode' => 'offline-help',
            ]);
        }
    }

    private function isLiveOrderQuestion(string $message): bool
    {
        $question = mb_strtolower($message);
        // Questions such as “What does READY_FOR_PICKUP mean?” are portal
        // help, not requests to inspect a buyer's orders.
        if (preg_match('/\b(what does|what is|explain|meaning of|mean)\b/', $question)) {
            return false;
        }
        $hasExplicitOrder = (bool) preg_match('/\b(?:lk|lh|so)[-\s]?\d+\b/i', $message);
        $hasOrderIntent = (bool) preg_match('/\b(order|parcel|product|delivery|shipment|tracking|track)\b/', $question)
            || $hasExplicitOrder;
        $personalIntent = (bool) preg_match('/\b(my|mine|this|it|already|still|current|active)\b/', $question);
        $statusIntent = (bool) preg_match('/\b(where|status|track|out for delivery|sorting center|ready for pickup|delivery failed|arrived|active)\b/', $question);

        return $hasExplicitOrder || ($hasOrderIntent && $personalIntent && $statusIntent);
    }

    private function isNotificationCountQuestion(string $message): bool
    {
        return (bool) preg_match('/\b(notification|notifications|alert|alerts)\b/i', $message)
            && (bool) preg_match('/\b(how many|count|unread|new|do i have)\b/i', $message);
    }

    private function notificationCountAnswer(Request $request): string
    {
        // The relationship automatically scopes both queries to this buyer.
        $notifications = $request->user()->notifications();
        $total = (clone $notifications)->count();
        $unread = (clone $notifications)->whereNull('read_at')->count();

        if ($total === 0) {
            return 'You do not have any notifications right now.';
        }

        return "You have {$unread} unread notification".($unread === 1 ? '' : 's')." out of {$total} total. You can open Notifications from your Buyer account to review them.";
    }

    /**
     * Verified Buyer-only answers for the core marketplace flow. These answers
     * avoid depending on a third party for common actions and account counts.
     */
    private function buyerPortalAnswer(Request $request, string $message): ?string
    {
        $question = mb_strtolower($message);

        if (preg_match('/^\s*(hi|hello|hey)\b/', $question)) {
            return 'Hi! I can help with products, cart, checkout, orders, notifications, wishlist, rewards, and your Buyer account.';
        }

        if (preg_match('/\b(cart)\b/', $question) && preg_match('/\b(how many|count|items|products|have)\b/', $question)) {
            $cart = $request->user()->carts()->where('status', 'ACTIVE')->latest('id')->first();
            $lineCount = $cart?->items()->count() ?? 0;
            $quantity = $cart?->items()->sum('quantity') ?? 0;

            return $quantity === 0
                ? 'Your cart is currently empty.'
                : "Your cart has {$lineCount} product ".($lineCount === 1 ? 'type' : 'types')." and {$quantity} item".($quantity === 1 ? '' : 's').' in total.';
        }

        if (preg_match('/\b(wishlist|saved)\b/', $question) && preg_match('/\b(how many|count|items|products|have)\b/', $question)) {
            $count = $request->user()->wishlistItems()->count();

            return $count === 0
                ? 'Your Wishlist is currently empty.'
                : "You have {$count} saved product".($count === 1 ? '' : 's').' in your Wishlist.';
        }

        if (preg_match('/\b(wishlist|saved)\b/', $question)) {
            return 'You can open the Wishlist page from the Wishlist shortcut on the Buyer Home page. To save an item, use the heart control on a product card or product details page.';
        }

        if (preg_match('/\b(notification|alert)\b/', $question)) {
            return 'You can review notifications from the Notifications section of your Buyer account.';
        }

        if (preg_match('/\b(cart)\b/', $question)) {
            return 'You can open Cart from your Buyer navigation. In Cart, you can review items, adjust quantity, apply eligible vouchers, and continue to checkout.';
        }

        if (preg_match('/\b(checkout|place order|payment method|payment)\b/', $question)) {
            return 'To check out, add products to your cart, open Cart, and continue to checkout. Review your delivery details, apply an eligible voucher or discount, choose a payment method, then place your order.';
        }

        if (preg_match('/\b(voucher|discount)\b/', $question)) {
            return 'You can apply an eligible voucher or discount while reviewing your cart before checkout. Available vouchers can also be reviewed in Rewards.';
        }

        if (preg_match('/\b(search|find).*(product|item)|\b(product|item).*(search|find)\b/', $question)) {
            return 'Open Categories from your Buyer navigation, then use the product search and category options to narrow the catalog. Open a product to review its details, variations, and quantity.';
        }

        if (preg_match('/\b(variation|quantity|add.*cart|add.*bag)\b/', $question)) {
            return 'Open the product details page, select an available variation if one is offered, choose the quantity, then use Add to Cart.';
        }

        if (preg_match('/\b(received|confirm receipt)\b/', $question)) {
            return 'Open the delivered order from Orders. When every parcel is delivered, the order details page makes Confirm Receipt available.';
        }

        if (preg_match('/\b(rating|feedback|review)\b/', $question)) {
            return 'After an order is completed, open it from Orders to leave a product rating and feedback when the review option is available.';
        }

        if (preg_match('/\b(messages?|chat|seller)\b/', $question)) {
            return 'You can open the existing buyer Messages section from your Buyer navigation to contact a seller or support. It is separate from this AI Assistant.';
        }

        if (preg_match('/\b(account|profile|address|password|security)\b/', $question)) {
            return 'Open My Account from the Buyer navigation to update your profile, manage delivery addresses, change your password, and review your account details.';
        }

        if (preg_match('/\b(reward|points|cashback)\b/', $question)) {
            return 'Open Rewards from your Buyer navigation to review vouchers, points, and cashback activity.';
        }

        $statusExplanations = [
            'READY_FOR_PICKUP' => 'READY FOR PICKUP means the seller has prepared the parcel and it is ready for courier pickup.',
            'AT_SORTING_CENTER' => 'AT SORTING CENTER means the parcel has reached a logistics sorting center and is being prepared for its destination route.',
            'ASSIGNED_TO_RIDER' => 'ASSIGNED TO RIDER means a delivery rider has been assigned. The next update is usually OUT FOR DELIVERY.',
            'OUT_FOR_DELIVERY' => 'OUT FOR DELIVERY means the parcel is with the delivery rider for delivery.',
            'DELIVERY_FAILED' => 'DELIVERY FAILED means a delivery attempt was unsuccessful. Open the order details for the latest delivery update.',
            'COMPLETED' => 'COMPLETED means the order has been finished after receipt confirmation.',
        ];
        foreach ($statusExplanations as $status => $explanation) {
            if (str_contains(mb_strtoupper($message), $status)) {
                return $explanation;
            }
        }

        if (preg_match('/\b(where|view|see).*(order|orders)|\b(order|orders).*(where|view|see)\b/', $question)) {
            return 'You can view your order history and current order statuses from Orders in your Buyer navigation.';
        }

        return null;
    }

    /** @return array{reply:string,options?:array<int,string>} */
    private function answerOrderQuestion(Request $request, string $message): array
    {
        $orders = $request->user()->orders()
            ->with('sellerOrders.shipment')
            ->latest('placed_at')
            ->latest('id')
            ->get();

        $requestedNumber = $this->orderNumberFrom($message);
        if ($requestedNumber !== null) {
            $order = $orders->first(fn (Order $order): bool => strcasecmp($order->order_number, $requestedNumber) === 0);
            if (! $order) {
                return ['reply' => "I can't verify that order from your account right now."];
            }

            return ['reply' => $this->describeOrder($order, $message)];
        }

        if (preg_match('/\b(active|still active|open)\b/i', $message)) {
            $active = $orders->filter(fn (Order $order): bool => ! in_array($order->status, ['COMPLETED', 'CANCELLED'], true));
            if ($active->isEmpty()) {
                return ['reply' => 'You do not currently have any active orders.'];
            }

            return ['reply' => 'Your active order'.($active->count() === 1 ? ' is ' : 's are ').$active->map(fn (Order $order) => $this->orderSummary($order))->implode('; ').'.'];
        }

        $active = $orders->filter(fn (Order $order): bool => ! in_array($order->status, ['COMPLETED', 'CANCELLED'], true))->values();
        if ($active->isEmpty()) {
            return ['reply' => "I can't find an active order to track right now. You can review previous orders in the Orders section."];
        }
        if ($active->count() > 1) {
            return [
                'reply' => 'You currently have multiple active orders. Which one would you like to check?',
                'options' => $active->take(6)->pluck('order_number')->values()->all(),
            ];
        }

        return ['reply' => $this->describeOrder($active->first(), $message)];
    }

    private function describeOrder(Order $order, string $question): string
    {
        $statuses = $order->sellerOrders->pluck('shipment.current_status')->filter()->unique()->values();
        if ($statuses->isEmpty()) {
            return "Your order {$order->order_number} is ".str($order->status)->headline().'. I cannot verify a parcel location for it yet.';
        }
        if ($statuses->count() > 1) {
            return "Your order {$order->order_number} has multiple parcels with different verified statuses: ".$statuses->map(fn (string $status) => str($status)->headline())->join(', ').". Open the order in Orders for each parcel's tracking details.";
        }

        $status = $statuses->first();
        $answer = match ($status) {
            'PLACED' => 'has been placed and is waiting for seller confirmation.',
            'CONFIRMED' => 'has been confirmed by the seller.',
            'PREPARING' => 'is being prepared by the seller.',
            'READY_FOR_PICKUP' => 'is ready for a courier pickup.',
            'PICKED_UP' => 'has been picked up and is moving through delivery logistics.',
            'AT_SORTING_CENTER' => 'is currently at the sorting center, where it is being sorted for its destination.',
            'SORTED' => 'has been sorted and is waiting for delivery assignment.',
            'ASSIGNED_TO_RIDER' => 'has been assigned to a delivery rider. The next update is usually Out for Delivery.',
            'OUT_FOR_DELIVERY' => 'is out for delivery.',
            'DELIVERED' => 'has been delivered. You can confirm receipt from your order details when ready.',
            'COMPLETED' => 'is completed.',
            'DELIVERY_FAILED' => 'has a delivery attempt that was unsuccessful. Check the order details for the latest update.',
            'RETURNED' => 'is being returned.',
            default => 'has the verified status '.str($status)->headline().'.',
        };

        $prefix = "Your order {$order->order_number} ";
        if (preg_match('/\b(is|already|at)\b.*\bout\s+for\s+delivery\b/i', $question) && $status !== 'OUT_FOR_DELIVERY') {
            return "No. {$prefix}".$answer;
        }
        if (preg_match('/\b(is|already|at)\b.*\bsorting\s+center\b/i', $question) && $status !== 'AT_SORTING_CENTER') {
            return "No. {$prefix}".$answer;
        }

        return $prefix.$answer;
    }

    private function orderSummary(Order $order): string
    {
        $statuses = $order->sellerOrders->pluck('shipment.current_status')->filter()->unique()->values();
        $status = $statuses->count() === 1 ? str($statuses->first())->headline() : str($order->status)->headline();

        return "{$order->order_number} ({$status})";
    }

    private function orderNumberFrom(string $message): ?string
    {
        if (! preg_match('/\b(LK|LH|SO)[-\s]?(\d+)\b/i', $message, $match)) {
            return null;
        }

        return strtoupper($match[1]).'-'.$match[2];
    }

    /** @param array{title:string,features:array<int,string>} $context */
    private function generalPrompt(string $message, array $context): string
    {
        return "Buyer question: {$message}\n\nVerified buyer page context only:\nPage: {$context['title']}\nAvailable features: ".implode(', ', $context['features'])."\n\nThere is no live order or account data in this request. Do not imply that you checked any account data.";
    }

    private function systemInstruction(): string
    {
        return <<<'PROMPT'
You are the LIKHAE Buyer AI Assistant. Help only the authenticated buyer use the buyer portal: products, categories, search, product options, cart, checkout, vouchers, payments, orders, delivery status explanations, receipt confirmation, feedback, messages, and account features.

You are not a seller, courier, sorting-center, or admin assistant. Never give instructions for inventory, administration, courier operations, or private systems. The existing Messages feature is separate from this assistant; you may only explain where buyers can find it.

Use only the supplied page context. Never invent the visual location of a control (top, side, etc.). Never claim to have checked live account data, orders, parcels, payments, vouchers, riders, or stock unless the prompt explicitly includes verified data. Be concise, friendly, and practical.
PROMPT;
    }

    private function hasLocalHelpAnswer(string $message): bool
    {
        return (bool) preg_match('/\b(hi|hello|hey|stock|inventory|seller dashboard|admin|cart|checkout|voucher|discount|search|find|product|category|order|receipt|feedback|rating|notification|alert)\b/i', $message);
    }

    /** @param array{title:string,features:array<int,string>} $context */
    private function localHelp(string $message, array $context): string
    {
        $question = mb_strtolower($message);
        if (preg_match('/^\s*(hi|hello|hey)\b/', $question)) {
            return "Hi! I’m here to help you use the Buyer portal. You can ask about products, your cart, checkout, orders, notifications, or your account.";
        }
        if (preg_match('/\b(stock|inventory|seller dashboard|admin)\b/', $question)) {
            return 'Product inventory management is not part of your Buyer account. I can help with products, cart, checkout, and your orders.';
        }
        if (preg_match('/\b(cart|checkout|voucher|discount)\b/', $question)) {
            return "You're on the {$context['title']} page. You can use the cart and checkout options in your Buyer navigation; vouchers and discounts are available while reviewing your cart before placing an order.";
        }
        if (preg_match('/\b(search|find|product|category)\b/', $question)) {
            return "You're on the {$context['title']} page. Use product search or browse categories to find an item, then open its details to choose quantity or a variation before adding it to your cart.";
        }
        if (preg_match('/\b(order|receipt|feedback|rating)\b/', $question)) {
            return 'You can check order status from the Orders section of your Buyer account. Open an order to track it, confirm receipt after delivery, or leave feedback when available.';
        }
        if (preg_match('/\b(notification|alert)\b/', $question)) {
            return 'You can review notifications from the Notifications area of your Buyer account.';
        }

        return "You're currently on the {$context['title']} page. Available features here include ".implode(', ', $context['features']).'. How can I help with one of these?';
    }
}
