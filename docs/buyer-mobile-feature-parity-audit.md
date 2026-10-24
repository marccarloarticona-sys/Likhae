# Buyer web-to-mobile feature parity audit

**Purpose:** Use this as the page-by-page implementation checklist for the buyer mobile app.

**Scope and evidence:** This audit covers the buyer navigation supplied in chat and the additional pages needed for shopping, checkout, order support, and account management. It is based on the Laravel routes, Blade views/components, controllers, and `/api/v1` routes in this repository.

**Limit:** The deployed page could not be opened with the available browser inspection tool, and this workspace does not contain the mobile UI. This is a source audit, not a live visual comparison.

## How to use this document

Each page below lists the web features, the mobile behavior to preserve, and the current mobile API support or gap. Keep the page names and journeys recognizable. Mobile layouts can use native navigation, sheets, and dialogs while preserving the same actions, information, and eligibility rules.

## Shared navigation and buyer shell

**Web features**

- Main destinations: Home, Categories, Cart, Orders, Messages, Notifications, Rewards, My Account, System Settings.
- Desktop collapsible sidebar; narrow-screen off-canvas menu.
- Header marketplace search with suggestions/recent searches, notification and cart shortcuts, profile menu, profile photo, and saved-account switching.
- Cart, order, message, and notification badges use live account counts. The supplied values (Cart 1, Orders 20, Notifications 69) are examples; do not hard-code them.
- Shared announcement UI, optional AI assistant, theme toggle, confirmation dialogs, feedback, toast/error state, and logout.

**Mobile parity**

Keep all nine destinations reachable and badges current. A five-tab layout can use Home, Categories, Cart, Orders, and Account, with persistent Messages and Notifications shortcuts. Keep Rewards, Wishlist, Following Stores, profile, addresses, and settings close to Account.

**API status**

Authentication and account lookup endpoints exist. No single buyer-home or navigation-count endpoint is declared; the app must compose counts from existing APIs or add a small buyer summary endpoint.

## Page-by-page audit

### 1. Home — `/buyer/home`

**Web features:** Editorial hero, category carousel with paging controls, quick actions, featured products, flash picks/current deals, platform event cards, personalized recommendations, and section-specific empty states.

**Mobile parity:** Preserve the section order and entry points. Product cards must link to details and expose wishlist/cart actions. Use horizontal carousels or compact lists, with loading and empty states per section.

**API status:** There is no buyer-home aggregation endpoint. Products, categories, and events can be fetched separately; recommendations and featured/section ordering need a mobile contract or a dedicated home payload.

### 2. Categories / marketplace — `/buyer/products`

**Web features:** Search; category and parent/child category selection; price range; rating; seller location/local focus; availability; sorting; grid/list view; result count; pagination; search suggestions; product cards with price/discount, rating, sales signals, seller, wishlist, and add-to-cart.

**Mobile parity:** Preserve every filter and the selected filter state when opening a product and returning. Use filter/sort sheets and removable filter chips; keep result count and pagination/load-more state visible.

**API status:** `GET /api/v1/products` supports paginated catalog search/filtering; `GET /api/v1/categories` provides categories. Suggestions are served by the web route `/marketplace/search-suggestions`; confirm its request/response contract for mobile.

### 3. Product detail — `/buyer/products/{slug}`

**Web features:** Product gallery, description/specifications, seller summary, options/variants, variant-dependent price and stock, quantity, wishlist, Add to Cart, Buy Now, reviews/photos, and related products.

**Mobile parity:** Variant selection must update price, stock, and selected image where applicable. Prevent adding unavailable variants or purchasing from sellers who cannot accept orders. Preserve review content and related-product navigation.

**API status:** Public product detail/list endpoints and authenticated cart/wishlist mutations exist. Review submission exists through the order-item review endpoint; review images and full review presentation should be checked against the product payload contract.

### 4. Seller storefront — `/buyer/shop/{seller}`

**Web features:** Seller identity, banner/avatar, category, location, rating/follower data, seller vouchers, products, store filters, and follow/unfollow. Store visibility and vacation state affect availability.

**Mobile parity:** Keep seller identity and availability visible while browsing products; show whether the shop is followed and preserve voucher and filter actions.

**API status:** `GET /api/v1/stores/{seller}` and `GET /api/v1/buyer/following-stores` exist. Follow/unfollow mutations currently have web routes only.

### 5. Flash deals — `/buyer/flash-deals`

**Web features:** Active discounted products, upcoming flash sales, sale timing/countdown, and reminder state/actions. `/buyer/local-finds` redirects to the catalog with local focus.

**Mobile parity:** Show sale windows clearly and keep reminder state synced. Expired or upcoming products must not appear purchasable outside their valid sale period.

**API status:** Product listing supports deals mode. Flash-sale reminder create/remove actions are web-only routes.

### 6. Platform events — `/buyer/platform-events` and `/buyer/platform-events/{voucher}`

**Web features:** Event cards and details with active/upcoming status, dates, code, discount, minimum spend, maximum discount, and routes to eligible products or rewards.

**Mobile parity:** Preserve event detail and its product/reward destination. Display expiry and eligibility beside the voucher action.

**API status:** `GET /api/v1/events` lists campaigns. Event detail and voucher claim/collect are web-only routes; mobile needs those operations or an equivalent supported flow.

### 7. Wishlist — `/buyer/wishlist`

**Web features:** Saved products, search, sort by recent/price/rating, remove selected items or clear all, product detail/purchase actions, and empty/no-match states.

**Mobile parity:** Keep saved state consistent between product cards, detail, and Wishlist. Confirm removals and show a useful empty state with a return-to-shopping action.

**API status:** Wishlist list and toggle endpoints exist. Bulk clear/removal is not declared as a mobile endpoint.

### 8. Cart — `/buyer/cart`

**Web features:** Items grouped by seller; selection; variant, stock and availability; quantity update/removal; subtotals; seller/platform/shipping voucher entry and picker; shipping/savings/total preview; continue shopping and checkout.

**Mobile parity:** Keep seller grouping and selected-item state. Refresh totals after every quantity, selection, voucher, or stock change. Explain stale prices, unavailable items, voucher failures, and shipping changes before checkout.

**API status:** Cart read/add/update/remove endpoints exist. The web has a separate cart preview endpoint; no equivalent `/api/v1` quote/preview endpoint exposes authoritative selected-item totals, voucher eligibility, shipping, protection, and availability.

### 9. Checkout — `/buyer/checkout`

**Web features:** Saved delivery address selection; exact pin and delivery availability; logistics provider selection when manual routing is configured; seller-grouped products; per-seller notes; per-item Additional Care/protection and fees; reward redemption; seller/platform/shipping vouchers; totals; validation and final confirmation.

**Mobile parity:** Use server-validated totals and address eligibility. Keep the final total and Place Order action easy to review, and preserve errors such as changed prices, stock, address coverage, or voucher eligibility. The web currently enables Cash on Delivery and labels E-Wallet “Coming Soon”; show the same payment availability.

**API status:** `POST /api/v1/buyer/checkout` accepts cart item IDs, address ID, payment method, voucher codes, and reward points. It does not expose the web quote/validation response or accept logistics choice, seller notes, or Additional Care selections. Although the API validator accepts `ONLINE`, the web UI marks E-Wallet as Coming Soon.

### 10. Order success — `/buyer/orders/success`

**Web features:** Order confirmation, order number and summary, with routes to order details, orders, and continued shopping.

**Mobile parity:** Show a durable confirmation state and retain the order number and next actions if the user leaves the screen.

**API status:** Checkout returns the created order identifier, number, status, total, and redeemed points. The app can use that response to open order detail.

### 11. Orders list — `/buyer/orders`

**Web features:** Tabs and counts for All, To Pay, To Ship, To Receive, Completed, Return / Refund, and Cancelled; seller/parcel summaries; stream refresh. Eligible actions include cancel, buy again, contact seller, detail, confirm receipt, review/rate, and return/refund.

**Mobile parity:** Preserve status mapping, seller grouping, action eligibility, and refresh after mutations. Do not infer status from a single shipment when orders may contain several seller orders/parcels.

**API status:** Orders list/detail and cancel, return-request, review, and receipt-confirmation actions exist. API list filtering/counts, buy-again, delivery PIN confirmation, and explicit order-to-seller/support conversation actions are not declared.

### 12. Order detail and delivery — `/buyer/orders/{order}`

**Web features:** Order/payment/address summary; seller orders/items; per-parcel progress; live map/rider location; delivery PIN; proof of delivery; seller note; contact seller/support; cancel, confirm receipt, review, and return/refund. The page also explains auto-confirm and the return deadline.

**Mobile parity:** Show each parcel’s timeline and actions separately where needed. Only expose cancel/receipt/review/return actions when allowed by the server; explain automatic receipt timing and return deadlines.

**API status:** Order detail and live rider location exist. Delivery PIN confirmation and direct seller/support conversation-start routes are missing from the mobile API.

### 13. Tracking — `/track/{trackingCode}`

**Web features:** Tracking number/status, parcel and destination, status guidance and next actor, map, event timeline/timestamps, live tracking, and proof access when authorized.

**Mobile parity:** Keep the timeline readable on small screens and refresh live status without losing the selected order/parcel context.

**API status:** Public tracking lookup exists at `/api/v1/tracking/{trackingNumber}`. Verify whether it provides all live updates/map data needed by the app; buyer order API also provides live rider location for an order.

### 14. Return/refund list — `/buyer/returns`

**Web features:** Return/refund requests grouped by order with request number/status and links to the parent request or seller-level case; empty state.

**Mobile parity:** Provide a history screen with current status, last update, and a clear way back to the originating order.

**API status:** No return/refund list endpoint is declared.

### 15. Return/refund request — `/buyer/orders/{order}/return-refund`

**Web features:** Eligibility/deadline; affected items and quantities; reason; additional details; refund method; evidence/photo uploads; review and submission; success page.

**Mobile parity:** Preserve reason and refund-method choices, evidence upload, validation, and server eligibility. Confirm user selection before final submission and show the request reference afterward.

**API status:** A return/refund submission endpoint exists. Confirm its multipart evidence contract for mobile; there is no dedicated mobile quote/eligibility endpoint.

### 16. Return/refund case — `/buyer/returns/request/{request}` and `/buyer/returns/{sellerRequest}`

**Web features:** Parent and seller-level case status, affected products, history, evidence and payout proof; confirm refund receipt/cash receipt; escalate dispute.

**Mobile parity:** Show case history and payer/status clearly, with actions only when available. Keep proof documents accessible from the case.

**API status:** Case list/detail, refund/cash confirmation, escalation, evidence, and payout-proof endpoints are not declared for mobile. This is a major post-purchase parity gap.

### 17. Messages — `/buyer/messages`

**Web features:** Conversation list, latest message/time, unread counts, seller online presence, thread history, text/attachment sending, product/order context, read state/receipts, realtime updates.

**Mobile parity:** Keep unread state and conversation context synced across inbox, thread, and badge. Support attachments, offline/retry state, and realtime reconnect. Buyer AI is a separate feature from seller messages.

**API status:** Conversation list/thread/send endpoints exist, including attachment sending; thread fetch supports `after_id`, and broadcast auth is declared. Confirm mobile support for presence, receipts, reconnect, pagination, and secure attachment retrieval.

### 18. Notifications — `/notifications` (current sidebar target)

**Web features:** Shared activity center with total/unread counts, priority announcements, category/target, timestamps, unread state, opening/dismissing announcements, mark all read, and pagination. A second buyer page at `/buyer/notifications` adds filters for All, Orders, Messages, Stores, Rewards, Account, and Announcements plus a live stream.

**Mobile parity:** Use one canonical mobile notification screen while retaining the shared center’s announcement behavior and the buyer page’s filters/live updates. Preserve the sidebar destination’s behavior and category/unread badges.

**API status:** Buyer API lists notifications and marks all read. Mark-one-read/open/dismiss, announcement destinations, and a mobile stream contract are not declared. Resolve the `/notifications` versus `/buyer/notifications` split before freezing mobile navigation.

### 19. Rewards — `/buyer/rewards`

**Web features:** My Vouchers and Reward Points tabs; seller vouchers and platform campaigns; voucher code/value/minimum/expiry; claim/collect; voucher history; points balance/activity and redemption rules. Cashback values currently show zero/no activity.

**Mobile parity:** Show eligibility and expiry with each voucher, synchronize claim state, and explain how points apply during checkout.

**API status:** Rewards read endpoint provides vouchers, point balance/activity, and redemption constants. Voucher claim/collect is web-only; checkout remains the authority for voucher eligibility. Campaign/event detail is also missing from the API.

### 20. My Account — `/buyer/account?tab=...`

#### Profile

Name, email, mobile, birthday/sex, profile photo, verified states, and email/mobile change verification. Mobile change requires a code sent to the verified email.

**API:** Profile read/update, mobile-change resend, and profile-photo deletion exist. Email change/verification is web-only.

#### Addresses

Up to 10 delivery addresses; add/edit/delete; set default; recipient and contact; Philippine region/province/municipality/barangay/postal data; house/street/landmark; map pin and coordinates.

**API:** List/create/delete/set-default exist. Address update/edit is missing.

#### Following Stores

Followed-store list, store navigation, and unfollow controls.

**API:** List only; follow/unfollow mutations are missing.

#### Reviews

Buyer review history and product links, alongside review submission from completed orders.

**API:** Review submission exists, but a dedicated review-history endpoint is missing.

#### Password and Danger Zone

Change password with current-password validation; account deletion with password confirmation.

**API:** Password update exists. Account deletion is web-only.

#### Privacy Settings

Account visibility/online-status preference is surfaced in the account/settings experience.

**API:** No buyer settings read/update endpoint is declared.

### 21. System Settings — `/buyer/settings`

**Web features:** Show online status; enable/disable AI assistant and AI response sound; order/delivery/chat/promotion notification preferences; notification-sound master toggle and per-category previews; Light/Dark/System theme; English language selector.

**Mobile parity:** Separate OS push permission state from in-app notification preferences. Preserve account-synced choices where intended and app-local appearance/sound choices where appropriate; show save/error feedback.

**API status:** No buyer settings API routes are declared for online visibility, notification categories/sounds, AI preferences, theme, or language.

### 22. LIKHAE AI assistant — shared buyer component

**Web features:** Optional floating assistant on buyer pages, AI chat endpoint, preference to show/hide it, and optional response sound. It is independent of the seller Messages inbox.

**Mobile parity:** Keep the assistant optional, reachable from buyer screens, and clearly separate from seller support conversations.

**API status:** `/api/v1/buyer/ai/chat` exists. Preference read/update is part of web settings only.

## Cross-page implementation order

1. **Purchase path:** marketplace/search → product/variant → cart quote → address/delivery → vouchers/rewards → place order → tracking → receipt/review/return.
2. **Post-purchase parity:** add return/refund list/detail/actions, delivery PIN confirmation, and parcel-aware status filtering/counts.
3. **Account parity:** address update, email verification, store follow/unfollow, review history, settings, and deletion.
4. **Engagement parity:** notification individual actions/announcements, flash-sale reminders, voucher claims, and message presence/read/reconnect behavior.
5. Every page needs loading, empty, error, offline/retry, and stale-data states. Keep prices, totals, eligibility, points, status, and badge counts server-sourced.

### Source map

- Web buyer routes: `routes/Buyer.php`; shared notification routes: `routes/web.php`.
- Mobile/API routes: `routes/api.php`.
- Shared shell/navigation: `resources/views/layouts/buyer/app.blade.php`, `resources/views/components/buyer/sidebar.blade.php`, `resources/views/components/buyer/header.blade.php`.
- Buyer pages/components: `resources/views/buyer/` and `resources/views/components/buyer/`.
- Main behavior: `app/Http/Controllers/Buyer/BuyerController.php`, `BuyerOrderController.php`, `BuyerReturnRefundController.php`, `CheckoutController.php`, `app/Http/Controllers/Api/BuyerApiController.php`, and `app/Services/Marketplace/`.
