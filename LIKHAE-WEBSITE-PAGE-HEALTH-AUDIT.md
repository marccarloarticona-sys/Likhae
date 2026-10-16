# LIKHAE Website Page Health Audit

**Audit date:** 2026-10-15
**Scope:** Current workspace source, route registry, Blade templates, JavaScript, recent local log excerpt, and the three supplied LIKHAE workflow PDFs.
**Live target:** https://likhae.online/seller/orders

## Executive Summary

Total pages checked: 178 GET/HEAD web-route candidates statically inventoried; 0 authenticated page requests were executed. The route inventory includes streams, exports, proof/evidence downloads, and other GET endpoints as well as rendered pages.
Passed: 0 dynamically verified pages
Fixed: 0 pages marked FIXED because no full authenticated page/action retest was possible
Moderate: 0 currently reproduced pages
Failed: 0 errors reproduced during this audit
Blocked: 178 route candidates awaiting safe authenticated HTTP verification
Not Implemented: 0 confirmed from route inventory; dynamic navigation and full feature completeness remain unverified

Source-level checks passed: 425 routes load, 394 application controller actions exist, 187 Blade templates compile, 187 templates have no unresolved literal route names, no missing literal Blade includes/layouts were found, all 78 changed/new PHP files pass php -l, all 37 JavaScript files pass node --check, Composer validates, and git diff --check passes.

The user-reported live /seller/orders error could not be reproduced: the web reader could not access the host, and no seller login/session is available. Current workspace source contains order-item shipment relationship fixes and an additive migration for shipments.order_item_id; hosted migration state is unknown. A historical local log also contains a Buyer Orders Blade syntax error, while the current full Blade cache compile succeeds.

Current source already contains measured parcel weight/volume capture, final-mile trip association, transfer-driver acceptance before release, and validated API receipt confirmation. These supersede corresponding FAIL findings in the older fulfillment audit by source inspection, but are not marked FIXED here because authenticated end-to-end actions were not retested. One stray ? after @else in the Logistics transfer detail view was removed; compilation passed after the edit.

## Environment

- Laravel version: 13.34.0
- PHP version: 8.3.33
- Application environment: production in local .env; secrets were not printed.
- Database driver: MySQL
- Testing DB: PHPUnit config uses a separate MySQL test database name (likhae_test, recorded in the prior audit); disposability was not proven. .env.testing is absent.
- Workspace state preserved: Yes. No reset, clean, restore, staging, or broad revert was used. Existing modifications and untracked files were reviewed and retained.
- Database safety: No database query, migration, seed, reset, or test was run during this audit. Before this stricter attached request was read, prior assistant work had already applied five migrations to the local configured database: voucher platform scope, announcement target audience, shipment order-item relation, checkout attempts, and message attachment path. The local database is distinct from the inaccessible hosted site. No rollback was attempted because it could change existing data. Hosted schema was not accessed.

## Audit Method and Limits

- Inspected ERP-Components-updated.pdf, ERP-Flow.pdf, and Sorting-Center.pdf as functional references.
- Ran route, PHP, Blade, JavaScript, Composer, and diff checks without connecting to the application database.
- Compared Blade route names to the route registry and checked literal Blade layout/include references.
- No valid test accounts for all roles, safe isolated database, authenticated production browser access, or hosted server logs were available.
- The role tables inventory all 178 non-API GET/HEAD web routes. Stream/export/proof endpoints are included so they are not skipped. Route/action presence and template compilation do not prove database-backed rendering or successful actions.

## Guest

| Page | Route | Status Before | Problem | Fix | Status After |
|---|---|---|---|---|---|
| home | GET/HEAD / -> App\Http\Controllers\Buyer\GuestMarketplaceController@home | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| address.philippines.barangays | GET/HEAD /address/philippines/municipalities/{municipality}/barangays -> App\Http\Controllers\Auth\PhilippineAddressController@barangays | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| address.philippines.postal-code | GET/HEAD /address/philippines/postal-code -> App\Http\Controllers\Auth\PhilippineAddressController@postalCode | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| address.philippines.municipalities | GET/HEAD /address/philippines/provinces/{province}/municipalities -> App\Http\Controllers\Auth\PhilippineAddressController@municipalities | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| address.philippines.regions | GET/HEAD /address/philippines/regions -> App\Http\Controllers\Auth\PhilippineAddressController@regions | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| address.philippines.provinces | GET/HEAD /address/philippines/regions/{region}/provinces -> App\Http\Controllers\Auth\PhilippineAddressController@provinces | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| google.redirect | GET/HEAD /auth/google -> App\Http\Controllers\Auth\GoogleAuthenticationController@redirect | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| google.callback | GET/HEAD /auth/google/callback -> App\Http\Controllers\Auth\GoogleAuthenticationController@callback | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| (unnamed) | GET/HEAD /broadcasting/auth -> Illuminate\Broadcasting\BroadcastController@authenticate | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| guest.continue | GET/HEAD /continue-as-guest -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| delivery-events.proof | GET/HEAD /delivery-events/{event}/proof -> App\Http\Controllers\Buyer\TrackingController@proof | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| verification.notice | GET/HEAD /email/verify -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| verification.verify | GET/HEAD /email/verify/{id}/{hash} -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| password.request | GET/HEAD /forgot-password -> App\Http\Controllers\Auth\AccountRecoveryController@request | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| guest.home | GET/HEAD /guest-account -> App\Http\Controllers\Buyer\GuestMarketplaceController@products | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| login | GET/HEAD /login -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| notifications.index | GET/HEAD /notifications -> App\Http\Controllers\NotificationCenterController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| partner.create | GET/HEAD /partner -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| partner.status | GET/HEAD /partner/status -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| products | GET/HEAD /products -> App\Http\Controllers\Buyer\GuestMarketplaceController@products | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| products.show | GET/HEAD /products/{slug} -> App\Http\Controllers\Buyer\GuestMarketplaceController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| register | GET/HEAD /register -> App\Http\Controllers\Auth\RegistrationController@create | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| password.reset | GET/HEAD /reset-password/{token} -> App\Http\Controllers\Auth\AccountRecoveryController@reset | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| sell.create | GET/HEAD /sell -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| storage.local | GET/HEAD /storage/{path} -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| tracking.show | GET/HEAD /track/{trackingCode} -> App\Http\Controllers\Buyer\TrackingController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| tracking.live | GET/HEAD /track/{trackingCode}/live -> App\Http\Controllers\Buyer\TrackingController@live | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| (unnamed) | GET/HEAD /up -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |

## Buyer

| Page | Route | Status Before | Problem | Fix | Status After |
|---|---|---|---|---|---|
| buyer.account | GET/HEAD /buyer/account -> App\Http\Controllers\Buyer\BuyerController@account | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.account.addresses | GET/HEAD /buyer/account/addresses -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.account.profile | GET/HEAD /buyer/account/profile -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.account.reviews | GET/HEAD /buyer/account/reviews -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.account.security | GET/HEAD /buyer/account/security -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.cart | GET/HEAD /buyer/cart -> App\Http\Controllers\Buyer\BuyerController@cart | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.checkout | GET/HEAD /buyer/checkout -> App\Http\Controllers\Buyer\CheckoutController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.flash-deals | GET/HEAD /buyer/flash-deals -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.home | GET/HEAD /buyer/home -> App\Http\Controllers\Buyer\BuyerController@home | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.local-finds | GET/HEAD /buyer/local-finds -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.messages | GET/HEAD /buyer/messages -> App\Http\Controllers\Buyer\BuyerController@messages | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.messages.stream | GET/HEAD /buyer/messages/stream -> App\Http\Controllers\Buyer\BuyerController@messageStream | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.notifications | GET/HEAD /buyer/notifications -> App\Http\Controllers\Buyer\BuyerController@notifications | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.notifications.stream | GET/HEAD /buyer/notifications/stream -> App\Http\Controllers\Buyer\BuyerController@notificationStream | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.orders | GET/HEAD /buyer/orders -> App\Http\Controllers\Buyer\BuyerOrderController@index | FAIL (historical local log) | A historical local log records an unexpected endif Blade syntax error in Buyer/orders.blade.php. | Current Blade source compiles in the full view:cache check; authenticated HTTP retest was unavailable. | BLOCKED |
| buyer.orders.stream | GET/HEAD /buyer/orders/stream -> App\Http\Controllers\Buyer\BuyerOrderController@stream | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.orders.success | GET/HEAD /buyer/orders/success -> App\Http\Controllers\Buyer\BuyerOrderController@success | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.orders.show | GET/HEAD /buyer/orders/{order} -> App\Http\Controllers\Buyer\BuyerOrderController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.orders.return-refund.form | GET/HEAD /buyer/orders/{order}/return-refund -> App\Http\Controllers\Buyer\BuyerOrderController@returnRefundForm | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.pending | GET/HEAD /buyer/pending -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.products | GET/HEAD /buyer/products -> App\Http\Controllers\Buyer\BuyerController@products | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.product-details | GET/HEAD /buyer/products/{slug} -> App\Http\Controllers\Buyer\BuyerController@product | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.returns | GET/HEAD /buyer/returns -> App\Http\Controllers\Buyer\BuyerReturnRefundController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.returns.evidence | GET/HEAD /buyer/returns/evidence/{evidence} -> App\Http\Controllers\Buyer\BuyerReturnRefundController@evidence | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.returns.payout-proof | GET/HEAD /buyer/returns/payouts/{payout}/proof -> App\Http\Controllers\Buyer\BuyerReturnRefundController@payoutProof | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.returns.parent | GET/HEAD /buyer/returns/request/{returnRequest} -> App\Http\Controllers\Buyer\BuyerReturnRefundController@parentShow | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.returns.show | GET/HEAD /buyer/returns/{sellerRequest} -> App\Http\Controllers\Buyer\BuyerReturnRefundController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.rewards | GET/HEAD /buyer/rewards -> App\Http\Controllers\Buyer\BuyerController@rewards | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.settings | GET/HEAD /buyer/settings -> App\Http\Controllers\Buyer\BuyerController@settings | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.shop | GET/HEAD /buyer/shop/{seller} -> App\Http\Controllers\Buyer\BuyerController@shop | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| buyer.wishlist | GET/HEAD /buyer/wishlist -> App\Http\Controllers\Buyer\BuyerController@wishlist | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |

## Seller

| Page | Route | Status Before | Problem | Fix | Status After |
|---|---|---|---|---|---|
| seller.entry | GET/HEAD /seller -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.account | GET/HEAD /seller/account -> App\Http\Controllers\Seller\SellerAccountController@account | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.compliance | GET/HEAD /seller/compliance -> App\Http\Controllers\Seller\SellerComplianceController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.compliance.show | GET/HEAD /seller/compliance/{case} -> App\Http\Controllers\Seller\SellerComplianceController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.compliance.responses.evidence | GET/HEAD /seller/compliance/{case}/responses/{response}/evidence -> App\Http\Controllers\Seller\SellerComplianceController@downloadResponseEvidence | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.dashboard | GET/HEAD /seller/dashboard -> App\Http\Controllers\Seller\SellerOperationsController@dashboard | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.logistics | GET/HEAD /seller/logistics -> App\Http\Controllers\Seller\SellerOperationsController@logistics | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.marketing | GET/HEAD /seller/marketing -> App\Http\Controllers\Seller\SellerEngagementController@marketing | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.messages | GET/HEAD /seller/messages -> App\Http\Controllers\Seller\SellerEngagementController@messages | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.messages.stream | GET/HEAD /seller/messages/stream -> App\Http\Controllers\Seller\SellerEngagementController@stream | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.notifications | GET/HEAD /seller/notifications -> App\Http\Controllers\Seller\SellerEngagementController@notifications | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.orders | GET/HEAD /seller/orders -> App\Http\Controllers\Seller\SellerOrderController@index | FAIL (user-reported) | User reports a live server error. The hosted URL was inaccessible to the web reader; no current exception or HTTP status was captured. Historical local order-query errors involved the missing shipments.order_item_id schema capability. | Current source has the multi-shipment/order-item relationship path and additive migration; local migration was applied before the latest safety instruction. Hosted migration state remains unknown. | BLOCKED |
| seller.orders.export | GET/HEAD /seller/orders-export -> App\Http\Controllers\Seller\SellerOperationsController@exportOrders | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.orders.refunds.evidence | GET/HEAD /seller/orders/refunds/evidence/{evidence} -> App\Http\Controllers\Seller\SellerReturnRefundController@evidence | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.orders.refunds.payout-proof | GET/HEAD /seller/orders/refunds/payouts/{payout}/proof -> App\Http\Controllers\Seller\SellerReturnRefundController@payoutProof | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.orders.refunds.show | GET/HEAD /seller/orders/refunds/{sellerRequest} -> App\Http\Controllers\Seller\SellerReturnRefundController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.orders.waybill.item | GET/HEAD /seller/orders/{sellerOrder}/items/{orderItem}/waybill -> App\Http\Controllers\Seller\SellerOperationsController@waybillItem | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.orders.waybill | GET/HEAD /seller/orders/{sellerOrder}/waybill -> App\Http\Controllers\Seller\SellerOperationsController@waybill | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.products | GET/HEAD /seller/products -> App\Http\Controllers\Seller\SellerProductController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.products.export | GET/HEAD /seller/products-export -> App\Http\Controllers\Seller\SellerProductController@export | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.products.inventory.download | GET/HEAD /seller/products/inventory/download -> App\Http\Controllers\Seller\SellerProductController@downloadInventoryPdf | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.products.inventory.preview | GET/HEAD /seller/products/inventory/preview -> App\Http\Controllers\Seller\SellerProductController@inventoryPreview | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.products.trash | GET/HEAD /seller/products/trash -> App\Http\Controllers\Seller\SellerProductController@trash | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.reports | GET/HEAD /seller/reports -> App\Http\Controllers\Seller\SellerOperationsController@reports | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.reports.download | GET/HEAD /seller/reports/download -> App\Http\Controllers\Seller\SellerOperationsController@downloadReport | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.reviews | GET/HEAD /seller/reviews -> App\Http\Controllers\Seller\SellerEngagementController@reviews | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.reviews.export | GET/HEAD /seller/reviews-export -> App\Http\Controllers\Seller\SellerEngagementController@exportReviews | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.settings | GET/HEAD /seller/settings -> App\Http\Controllers\WorkspaceSettingsController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| seller.store | GET/HEAD /seller/store -> App\Http\Controllers\Seller\SellerAccountController@store | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |

## Rider

| Page | Route | Status Before | Problem | Fix | Status After |
|---|---|---|---|---|---|
| rider.home | GET/HEAD /rider -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.account | GET/HEAD /rider/account -> App\Http\Controllers\Rider\RiderController@profile | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.dashboard | GET/HEAD /rider/dashboard -> App\Http\Controllers\Rider\RiderController@dashboard | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.deliveries | GET/HEAD /rider/deliveries -> App\Http\Controllers\Rider\RiderController@deliveries | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.deliveries.show | GET/HEAD /rider/deliveries/{assignment} -> App\Http\Controllers\Rider\RiderController@deliveryShow | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.earnings | GET/HEAD /rider/earnings -> App\Http\Controllers\Rider\RiderController@earnings | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.history | GET/HEAD /rider/history -> App\Http\Controllers\Rider\RiderController@history | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.messages | GET/HEAD /rider/messages -> App\Http\Controllers\Rider\RiderController@messages | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.messages.stream | GET/HEAD /rider/messages/stream -> App\Http\Controllers\Rider\RiderController@messageStream | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.parcels.index | GET/HEAD /rider/parcels -> App\Http\Controllers\Rider\RiderController@shipments | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.parcels.show | GET/HEAD /rider/parcels/{assignment} -> App\Http\Controllers\Rider\RiderController@pickupShow | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.pickups | GET/HEAD /rider/pickups -> App\Http\Controllers\Rider\RiderPickupBatchController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.pickups.batches.show | GET/HEAD /rider/pickups/batches/{trip} -> App\Http\Controllers\Rider\RiderPickupBatchController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.pickups.scan | GET/HEAD /rider/pickups/scan -> App\Http\Controllers\Rider\RiderPickupBatchController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.pickups.show | GET/HEAD /rider/pickups/{assignment} -> App\Http\Controllers\Rider\RiderController@pickupShow | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.profile | GET/HEAD /rider/profile -> App\Http\Controllers\Rider\RiderController@profile | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.remittances | GET/HEAD /rider/remittances -> App\Http\Controllers\Rider\RiderRemittanceController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.settings | GET/HEAD /rider/settings -> App\Http\Controllers\WorkspaceSettingsController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.shipments | GET/HEAD /rider/shipments -> App\Http\Controllers\Rider\RiderShipmentController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.transfers | GET/HEAD /rider/transfers -> App\Http\Controllers\Rider\RiderTransferController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.transfers.show | GET/HEAD /rider/transfers/{manifest} -> App\Http\Controllers\Rider\RiderTransferController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.trips | GET/HEAD /rider/trips -> App\Http\Controllers\Rider\RiderTripController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| rider.trips.show | GET/HEAD /rider/trips/{trip} -> App\Http\Controllers\Rider\RiderTripController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |

## Logistics

| Page | Route | Status Before | Problem | Fix | Status After |
|---|---|---|---|---|---|
| logistics.home | GET/HEAD /logistics -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.account | GET/HEAD /logistics/account -> App\Http\Controllers\Logistics\LogisticsPortalController@profile | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.assignments | GET/HEAD /logistics/assignments -> App\Http\Controllers\Logistics\DispatchController@assignments | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.dashboard | GET/HEAD /logistics/dashboard -> App\Http\Controllers\Logistics\LogisticsPortalController@dashboard | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.delivery-areas | GET/HEAD /logistics/delivery-areas -> App\Http\Controllers\Logistics\LogisticsPortalController@deliveryAreas | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.dispatch | GET/HEAD /logistics/dispatch -> App\Http\Controllers\Logistics\DispatchController@assignments | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.local-trips | GET/HEAD /logistics/local-trips -> App\Http\Controllers\Logistics\LocalTripController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.local-trips.show | GET/HEAD /logistics/local-trips/{trip} -> App\Http\Controllers\Logistics\LocalTripController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.login | GET/HEAD /logistics/login -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.messages | GET/HEAD /logistics/messages -> App\Http\Controllers\Logistics\LogisticsPortalController@messages | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.messages.stream | GET/HEAD /logistics/messages/stream -> App\Http\Controllers\Logistics\LogisticsPortalController@messageStream | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.parcels | GET/HEAD /logistics/parcels -> App\Http\Controllers\Logistics\DispatchController@parcels | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.parcels.receive | GET/HEAD /logistics/parcels/receive -> App\Http\Controllers\Logistics\DispatchController@receive | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.parcels.tracking | GET/HEAD /logistics/parcels/tracking -> App\Http\Controllers\Logistics\DispatchController@tracking | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.parcels.show | GET/HEAD /logistics/parcels/{shipment} -> App\Http\Controllers\Logistics\DispatchController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.pickups | GET/HEAD /logistics/pickups -> App\Http\Controllers\Logistics\DispatchController@pickups | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.profile | GET/HEAD /logistics/profile -> App\Http\Controllers\Logistics\LogisticsPortalController@profile | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.remittances | GET/HEAD /logistics/remittances -> App\Http\Controllers\Logistics\LogisticsRemittanceController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.reports | GET/HEAD /logistics/reports -> App\Http\Controllers\Logistics\LogisticsPortalController@reports | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.reports.export | GET/HEAD /logistics/reports/export -> App\Http\Controllers\Logistics\LogisticsPortalController@exportReport | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.riders | GET/HEAD /logistics/riders -> App\Http\Controllers\Logistics\LogisticsPortalController@riders | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.riders.applications | GET/HEAD /logistics/riders/applications -> App\Http\Controllers\Logistics\LogisticsPortalController@riderApplications | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.riders.application.show | GET/HEAD /logistics/riders/applications/{application} -> App\Http\Controllers\Logistics\LogisticsPortalController@riderApplicationShow | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.riders.show | GET/HEAD /logistics/riders/{rider} -> App\Http\Controllers\Logistics\LogisticsPortalController@riderShow | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.scanner | GET/HEAD /logistics/scanner -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.settings | GET/HEAD /logistics/settings -> App\Http\Controllers\WorkspaceSettingsController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.sorting | GET/HEAD /logistics/sorting -> App\Http\Controllers\Logistics\DispatchController@sorting | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.transfers | GET/HEAD /logistics/transfers -> App\Http\Controllers\Logistics\TransferController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| logistics.transfers.show | GET/HEAD /logistics/transfers/{manifest} -> App\Http\Controllers\Logistics\TransferController@show | MODERATE (static inspection) | The receipt cell had a stray literal question mark after an @else branch. | Removed the stray token; full Blade compilation passed. HTTP retest was unavailable. | BLOCKED |
| logistics.waybills.show | GET/HEAD /logistics/waybills/{tracking} -> App\Http\Controllers\Logistics\DispatchController@waybill | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |

## Admin

| Page | Route | Status Before | Problem | Fix | Status After |
|---|---|---|---|---|---|
| admin.dashboard | GET/HEAD /admin -> App\Http\Controllers\Admin\AdminDashboardController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.account | GET/HEAD /admin/account -> App\Http\Controllers\Admin\AdminOperationsController@account | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.audit.export | GET/HEAD /admin/audit-export -> App\Http\Controllers\Admin\AdminOperationsController@exportAuditLogs | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.categories | GET/HEAD /admin/categories -> App\Http\Controllers\Admin\AdminOperationsController@categories | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.complaints | GET/HEAD /admin/complaints -> App\Http\Controllers\Admin\AdminOperationsController@complaints | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.compliance | GET/HEAD /admin/compliance -> App\Http\Controllers\Admin\AdminOperationsController@compliance | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.compliance.export | GET/HEAD /admin/compliance-export -> App\Http\Controllers\Admin\AdminOperationsController@exportCompliance | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.compliance.show | GET/HEAD /admin/compliance/{case} -> App\Http\Controllers\Admin\AdminOperationsController@showComplianceCase | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.compliance.responses.evidence | GET/HEAD /admin/compliance/{case}/responses/{response}/evidence -> App\Http\Controllers\Admin\AdminOperationsController@downloadComplianceResponseEvidence | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.disputes.payout-proof | GET/HEAD /admin/disputes/payouts/{payout}/proof -> App\Http\Controllers\Admin\AdminOperationsController@refundPayoutProof | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.disputes.refund-evidence | GET/HEAD /admin/disputes/refund-evidence/{evidence} -> App\Http\Controllers\Admin\AdminOperationsController@refundEvidence | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.disputes.show | GET/HEAD /admin/disputes/{dispute} -> App\Http\Controllers\Admin\AdminOperationsController@showDispute | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.finance | GET/HEAD /admin/finance -> App\Http\Controllers\Admin\AdminOperationsController@finance | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.finance.export | GET/HEAD /admin/finance-export -> App\Http\Controllers\Admin\AdminOperationsController@exportFinance | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.home | GET/HEAD /admin/home -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.marketing | GET/HEAD /admin/marketing -> App\Http\Controllers\Admin\AdminMarketingController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.messages | GET/HEAD /admin/messages -> App\Http\Controllers\Admin\AdminOperationsController@messages | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.messages.stream | GET/HEAD /admin/messages/stream -> App\Http\Controllers\Admin\AdminOperationsController@messageStream | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.notifications | GET/HEAD /admin/notifications -> App\Http\Controllers\Admin\AdminOperationsController@notifications | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.payments | GET/HEAD /admin/payments -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.products | GET/HEAD /admin/products -> App\Http\Controllers\Admin\AdminOperationsController@products | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.products.export | GET/HEAD /admin/products-export -> App\Http\Controllers\Admin\AdminOperationsController@exportProducts | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.products.show | GET/HEAD /admin/products/{product} -> App\Http\Controllers\Admin\AdminOperationsController@productDetails | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.protection-rules | GET/HEAD /admin/protection-rules -> App\Http\Controllers\Admin\AdminProtectionRuleController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.refunds | GET/HEAD /admin/refunds -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.registrations | GET/HEAD /admin/registrations -> App\Http\Controllers\Admin\AdminOnboardingController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.registrations.show | GET/HEAD /admin/registrations/{application} -> App\Http\Controllers\Admin\AdminOnboardingController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.registrations.documents.show | GET/HEAD /admin/registrations/{application}/documents/{document} -> App\Http\Controllers\Admin\AdminOnboardingController@document | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.reports | GET/HEAD /admin/reports -> App\Http\Controllers\Admin\AdminOperationsController@reports | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.reports.export | GET/HEAD /admin/reports-export -> App\Http\Controllers\Admin\AdminOperationsController@exportReport | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.riders | GET/HEAD /admin/riders -> Closure | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.sellers.index | GET/HEAD /admin/sellers -> App\Http\Controllers\Admin\AdminOnboardingController@sellers | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.sellers.approvals | GET/HEAD /admin/sellers/approvals -> App\Http\Controllers\Admin\AdminOnboardingController@sellers | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.settings | GET/HEAD /admin/settings -> App\Http\Controllers\Admin\AdminOperationsController@settings | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.system-settings | GET/HEAD /admin/system-settings -> App\Http\Controllers\WorkspaceSettingsController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.users | GET/HEAD /admin/users -> App\Http\Controllers\Admin\AdminUserController@index | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |
| admin.users.show | GET/HEAD /admin/users/{user} -> App\Http\Controllers\Admin\AdminUserController@show | BLOCKED | No safe HTTP baseline: this route may require role authentication and database-backed data. | Route/action registry and Blade compilation passed statically; runtime output/action not verified. | BLOCKED |

## Server Errors Fixed or Previously Observed

### ERR-SELLER-001

- Role: Seller; page: Seller Orders; URL: /seller/orders.
- Exception: No live exception could be captured. User reports a server error; historical local order-query errors referenced missing shipments.order_item_id.
- Root cause: Per-order-item shipment queries require the additive shipment column and correct legacy-aware relationships. Hosted schema state is unknown.
- Files: SellerOrderController, OrderItem, Shipment, SellerOrder, 2026_10_14_000400_add_order_item_to_shipments.php, and related seller views.
- Fix: Current source supports the order-item shipment relationship and seller-order shipment collections. The migration was already applied to the local configured database in prior work, before the latest safety instruction.
- Retest: route:list, PHP lint, route-reference checks, and Blade compilation pass. Live URL was inaccessible in the web reader; no authenticated seller page retest. BLOCKED.

### ERR-BUYER-001

- Role: Buyer; page: Buyer Orders; URL: /buyer/orders.
- Exception: Historical local log: unexpected token endif while rendering Buyer/orders.blade.php.
- Root cause: Blade conditional mismatch in the prior local source snapshot.
- Fix: Current view parses and the entire view cache compiles.
- Retest: Blade compilation passed; no authenticated Buyer HTTP request. BLOCKED.

### ERR-ADMIN-001

- Role: Admin; page: Complaints; URL: /admin/complaints.
- Exception: Historical log/report indicated a missing complaints view.
- Root cause: The view was absent in that older snapshot.
- Fix: Current literal view-reference scan found no missing view, and all Blade templates compile.
- Retest: No authenticated Admin HTTP request. BLOCKED.

No HTTP 500 was reproduced during this audit because no application page request was made. Historical log entries are not treated as current runtime failures.

## Dead/Broken Links

- Static Blade route-name scan: 0 unresolved literal route names across 187 Blade templates.
- Literal Blade layout/include scan: 0 missing references.
- Route action scan: 394 application controller actions checked; 0 missing controller files/methods.
- No dead link was proven statically. Dynamic links, route parameters, browser navigation, pagination, and action method behavior need authenticated HTTP verification.

## Broken Forms

No form was submitted. Dynamic form status is BLOCKED. Static review confirms the Seller packed-measurement form targets a registered PATCH route, includes CSRF/method spoofing, validates positive measured values, and is scoped to the seller's own order item. Buyer checkout, seller product/voucher, rider scan/delivery, Logistics receive/sort/dispatch/transfer, and Admin approval/campaign/announcement forms still need safe valid and invalid submission checks.

## Broken APIs/AJAX

- API routes registered: 74; JavaScript files parsed: 37.
- API/AJAX request statuses: BLOCKED. No authenticated token/session or safe app request environment was available, so no status/response-body matrix could be recorded.
- Static source review found the Buyer receipt endpoint delegates to the validated completion service before returning COMPLETED. The prior status-order defect described in the older audit is not present in the current controller.
- Address lookup, cart/checkout, messages, notifications, tracking, scans, dispatch, trips, reports, and dashboard requests remain unverified over HTTP.

## Authorization Problems

No static missing role middleware was found in the role route groups. Registered role pages use authenticated/verified role middleware; Seller pages also require seller approval, Logistics pages require provider approval, and Rider workflows have operational middleware. Example: /seller/orders uses auth, verified, seller role, and seller.approved. Cross-user/cross-center denial behavior was not exercised; report it as BLOCKED, not PASS.

## Data/Relationship Problems

- Current code uses the per-OrderItem shipment relation for the new parcel path and the seller order's shipment collection for multi-parcel pages. Seller waybill routes include order-level and per-item variants.
- Legacy shipment records without an item relation should remain represented through the legacy fallback; two ambiguous multi-item legacy shipments were intentionally left unlinked in prior local work rather than guessed.
- Seller parcel measurement capture uses actual measured values. Transfer capacity rejects missing/non-positive weight or volume instead of inventing values.
- Current source includes a Logistics dispatch path that creates or selects a delivery trip and attaches the assignment before the rider can start. Current source also requires transfer-driver acceptance before Logistics release.
- These are source-level findings. Row-level ownership, empty/multiple-record UI, and Pagsanjan-to-Pila operational data were not queried during this audit.

## Schema-Dependent Problems

These capabilities require matching migrations in the hosted database:

- shipments.order_item_id for one parcel per distinct order item and multi-waybill behavior.
- checkout_attempts for checkout idempotency.
- messages.attachment_path for message attachment handling.
- Platform voucher scope and announcement target audience fields for Admin/Seller marketing features.

The corresponding additive migrations are present in the workspace. Hosted migration state is unknown and was not queried. Do not assume local migration state proves production readiness. No migration was applied or rolled back during this audit.

## Files Modified

The workspace had these modified tracked files at audit time (existing user/assistant work was preserved):

- app/Console/Commands/AutoConfirmDeliveredOrders.php
- app/Console/Commands/PublishScheduledAnnouncements.php
- app/Http/Controllers/Admin/AdminOperationsController.php
- app/Http/Controllers/Api/BuyerApiController.php
- app/Http/Controllers/Api/RiderApiController.php
- app/Http/Controllers/Api/WorkflowApiController.php
- app/Http/Controllers/Buyer/BuyerAiController.php
- app/Http/Controllers/Buyer/BuyerController.php
- app/Http/Controllers/Buyer/BuyerOrderController.php
- app/Http/Controllers/Buyer/CheckoutController.php
- app/Http/Controllers/Buyer/TrackingController.php
- app/Http/Controllers/Logistics/DispatchController.php
- app/Http/Controllers/Logistics/LocalTripController.php
- app/Http/Controllers/Logistics/TransferController.php
- app/Http/Controllers/Rider/RiderController.php
- app/Http/Controllers/Rider/RiderPickupBatchController.php
- app/Http/Controllers/Rider/RiderTransferController.php
- app/Http/Controllers/Seller/SellerEngagementController.php
- app/Http/Controllers/Seller/SellerOperationsController.php
- app/Http/Controllers/Seller/SellerOrderController.php
- app/Models/Buyer/OrderItem.php
- app/Models/Communication/Message.php
- app/Models/Logistics/Shipment.php
- app/Models/admin/Announcement.php
- app/Models/seller/SellerOrder.php
- app/Models/seller/Voucher.php
- app/Services/Finance/CodCollectionService.php
- app/Services/Finance/FinancialAllocationService.php
- app/Services/Fulfillment/LocalRiderTripService.php
- app/Services/Fulfillment/ShipmentTransferService.php
- app/Services/Fulfillment/ShipmentWorkflowService.php
- app/Services/Fulfillment/TransferCapacityService.php
- app/Services/Fulfillment/TransferManifestService.php
- app/Services/Marketplace/BuyerOrderActionService.php
- app/Services/Marketplace/CheckoutService.php
- app/Services/Returns/CashRefundWorkflowService.php
- composer.json
- config/likhae.php
- resources/css/admin/admin.css
- resources/css/seller/seller.css
- resources/css/shared/mapbox.css
- resources/js/shared/mapbox.js
- resources/views/Admin/dashboard/index.blade.php
- resources/views/Admin/messages/index.blade.php
- resources/views/Buyer/cart.blade.php
- resources/views/Buyer/checkout.blade.php
- resources/views/Buyer/orders.blade.php
- resources/views/Buyer/rewards.blade.php
- resources/views/Buyer/tracking/show.blade.php
- resources/views/Logistics/assignments/index.blade.php
- resources/views/Logistics/local-trips/show.blade.php
- resources/views/Logistics/parcels/show.blade.php
- resources/views/Logistics/pickups/index.blade.php
- resources/views/Logistics/transfers/show.blade.php
- resources/views/Logistics/waybill.blade.php
- resources/views/Seller/logistics.blade.php
- resources/views/Seller/marketing.blade.php
- resources/views/Seller/orders.blade.php
- resources/views/Seller/waybill.blade.php
- resources/views/components/admin/sidebar.blade.php
- resources/views/components/seller/order-card.blade.php
- resources/views/rider/deliveries/show.blade.php
- resources/views/rider/pickups/show.blade.php
- resources/views/rider/shipments.blade.php
- resources/views/rider/transfer-show.blade.php
- routes/Admin.php
- routes/Seller.php
- tests/Feature/AdminProductsRemainingIssuesTest.php
- tests/Feature/BuyerRewardsTest.php
- tests/Feature/CheckoutSellerNoteAndProtectionTest.php

## Files Created

The paths below were untracked/new in the workspace at audit time; that does not establish who created them. This audit report is newly created in this run.

- LIKHAE-END-TO-END-FULFILLMENT-AUDIT-2026-10-15.md
- LIKHAE-FULL-IMPLEMENTATION-AUDIT-2026-10-14.md
- LIKHAE-WEBSITE-PAGE-HEALTH-AUDIT.md
- app/Http/Controllers/Admin/AdminMarketingController.php
- app/Services/Fulfillment/FinalMileDispatchService.php
- app/Services/Marketplace/VoucherRedemptionCounter.php
- app/Support/OrderItemParcelAmount.php
- database/migrations/2026_10_14_000200_add_platform_voucher_scope.php
- database/migrations/2026_10_14_000300_add_target_audience_to_announcements.php
- database/migrations/2026_10_14_000400_add_order_item_to_shipments.php
- database/migrations/2026_10_15_000100_create_checkout_attempts_table.php
- database/migrations/2026_10_15_000200_add_attachment_path_to_messages.php
- resources/views/Admin/marketing/index.blade.php
- resources/views/Seller/partials/parcel-measurements.blade.php
- resources/views/Seller/partials/waybill-label.blade.php
- resources/views/Seller/waybills.blade.php

## Tests Executed

| Test | Database | Result |
|---|---|---|
| Authenticated page/action HTTP checks for all roles | No requests made | BLOCKED |
| Database-backed PHPUnit/feature tests | Separate MySQL test DB name; disposability unproven | NOT EXECUTED — DATABASE SAFETY |
| PHP syntax check (php -l) on 78 changed/new PHP files | None | PASSED |
| Laravel route list (php artisan route:list --json) | None | PASSED — 425 routes |
| Composer validation (composer validate --no-check-publish) | None | PASSED — exact package-version warnings only |
| Blade compilation (php artisan view:cache --no-interaction) | None | PASSED |
| JavaScript syntax (node --check) on 37 JS/MJS files | None | PASSED |
| Literal Blade route/view references | None | PASSED — 0 unresolved |
| App controller action existence | None | PASSED — 394 checked |
| git diff --check | None | PASSED |

## Static Checks

- php -l: PASSED (78 changed/new PHP files)
- route:list: PASSED (425 registered routes)
- composer validate: PASSED with exact-version warnings for endroid/qr-code and picqer/php-barcode-generator
- Blade: PASSED (view:cache)
- JS: PASSED (37 files)
- git diff --check: PASSED
- PHPUnit/feature tests: NOT EXECUTED — DATABASE SAFETY
- php artisan about: not run; application environment is configured as production and no DB-backed runtime inspection was needed for this audit.

## Remaining Issues

1. The live https://likhae.online/seller/orders error needs an authenticated retest and the hosted exception log; this environment could not access the URL.
2. Buyer, Seller, Rider, Logistics, and Admin rendering and primary actions need an authenticated HTTP/browser pass using role-appropriate test accounts.
3. Hosted migration state must be verified by the deployment owner before release. The live database was not read or changed.
4. Database-backed tests require an explicitly disposable isolated database; the configured PHPUnit test database was not proven disposable.
5. Actual Pagsanjan seller, Pila buyer, logistics-center, service-area, and rider records were not inspected. The PDFs do not establish those live operational mappings.
6. Responsive desktop/tablet/mobile rendering and browser network statuses were not verified.

## Required Role Checklist

Every item is BLOCKED for authenticated HTTP verification. Route presence and static compilation were verified separately.

### GUEST

- [ ] Landing — BLOCKED
- [ ] Login — BLOCKED
- [ ] Buyer registration — BLOCKED
- [ ] Seller registration — BLOCKED
- [ ] Rider registration — BLOCKED
- [ ] Logistics registration — BLOCKED
- [ ] Forgot/reset password — BLOCKED

### BUYER

- [ ] Home/Dashboard — BLOCKED
- [ ] Products — BLOCKED
- [ ] Search — BLOCKED
- [ ] Product details — BLOCKED
- [ ] Cart — BLOCKED
- [ ] Checkout — BLOCKED
- [ ] Rewards — BLOCKED
- [ ] Orders — BLOCKED
- [ ] Order details — BLOCKED
- [ ] Tracking — BLOCKED
- [ ] Notifications — BLOCKED
- [ ] Messages — BLOCKED
- [ ] Account — BLOCKED

### SELLER

- [ ] Dashboard — BLOCKED
- [ ] Products — BLOCKED
- [ ] Product create/edit — BLOCKED
- [ ] Inventory — BLOCKED
- [ ] Orders — BLOCKED
- [ ] Order details — BLOCKED
- [ ] Preparation — BLOCKED
- [ ] Logistics/Pickup — BLOCKED
- [ ] Waybill — BLOCKED
- [ ] Marketing/Vouchers — BLOCKED
- [ ] Notifications — BLOCKED
- [ ] Reports — BLOCKED
- [ ] Messages — BLOCKED
- [ ] Account — BLOCKED

### RIDER

- [ ] Dashboard — BLOCKED
- [ ] Pickup assignments — BLOCKED
- [ ] Pickup batch details — BLOCKED
- [ ] Scanner — BLOCKED
- [ ] Transfer assignments — BLOCKED
- [ ] Local trips — BLOCKED
- [ ] Deliveries — BLOCKED
- [ ] Delivery detail — BLOCKED
- [ ] Delivery history — BLOCKED
- [ ] Earnings — BLOCKED
- [ ] Notifications — BLOCKED
- [ ] Messages — BLOCKED
- [ ] Account — BLOCKED

### LOGISTICS

- [ ] Dashboard — BLOCKED
- [ ] Rider management — BLOCKED
- [ ] Rider applications — BLOCKED
- [ ] Pickup requests — BLOCKED
- [ ] Pickup batches — BLOCKED
- [ ] Incoming parcels — BLOCKED
- [ ] Scanner — BLOCKED
- [ ] Parcel detail — BLOCKED
- [ ] Sorting — BLOCKED
- [ ] Service/delivery areas — BLOCKED
- [ ] Rider assignments — BLOCKED
- [ ] Dispatch — BLOCKED
- [ ] Local trips — BLOCKED
- [ ] Transfer manifests — BLOCKED
- [ ] Incoming transfers — BLOCKED
- [ ] Outgoing transfers — BLOCKED
- [ ] Delivery monitoring — BLOCKED
- [ ] Reports — BLOCKED
- [ ] Notifications — BLOCKED
- [ ] Messages — BLOCKED
- [ ] Account — BLOCKED

### ADMIN

- [ ] Dashboard — BLOCKED
- [ ] Registrations — BLOCKED
- [ ] User accounts — BLOCKED
- [ ] Seller compliance — BLOCKED
- [ ] Complaints — BLOCKED
- [ ] Disputes — BLOCKED
- [ ] Commission — BLOCKED
- [ ] Reports — BLOCKED
- [ ] Platform Marketing — BLOCKED
- [ ] Announcements — BLOCKED
- [ ] Messages — BLOCKED
- [ ] Notifications — BLOCKED
- [ ] Account/Settings — BLOCKED

## Final Result

**BLOCKED — ENVIRONMENT PREVENTED COMPLETE VERIFICATION**

Current static checks pass and current source includes fixes for several previously reported fulfillment and view issues. This result is not a claim that the hosted Seller Orders page or all authenticated pages work. A live authenticated retest and confirmed production migration state are still required.
