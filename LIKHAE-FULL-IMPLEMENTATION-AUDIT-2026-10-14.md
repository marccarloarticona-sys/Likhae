# LIKHAE FULL IMPLEMENTATION AUDIT

**Date:** 2026-10-14  
**Current branch/commit:** detached HEAD, 66a11d8  
**Laravel:** 13.34.0  
**PHP:** 8.3.33  
**Node:** 24.19.0

## Audit scope and limits

This is a read-only audit of the current working tree at 66a11d8. The working tree contains many staged and unstaged changes plus unresolved merge state; those changes were preserved. No application source, database, migration, or user data was changed by this audit. This report is the only artifact created by the audit.

The project tree advanced during the audit from an earlier snapshot at 655974c to the current detached 66a11d8 snapshot. The earlier preliminary PHP lint result of zero errors and initial route-list count of 415 are superseded. On the latest snapshot, PHP lint found four errors in 369 PHP files, and route:list fails while loading LogisticsPortalController. Findings below use the latest snapshot.

“Verified in code” means the UI, route declaration, authorization checks, controller/service logic, and resulting state were traced statically where available. It does not mean the feature passed end-to-end runtime tests. Database-backed tests and visual browser checks were not run.

Baseline requirements were compared with ERP-Components-updated.pdf, ERP-Flow.pdf, and Sorting-Center.pdf. Those references describe buyer-to-seller-to-rider-to-sorting-center-to-delivery flow, role responsibilities, seller grouping, and per-parcel center intake.

## 1. Executive summary

Counts across the 30-row feature matrix:

| Status | Count | Meaning |
|---|---:|---|
| ✅ Verified in code | 12 | Code path and relevant guard/state logic present; runtime remains unproven |
| 🟡 Partial | 11 | Substantial implementation exists, but a gap or runtime blocker remains |
| ❌ Missing | 0 | No requested in-scope feature was found to be wholly absent |
| 🔴 Broken / critical | 3 | Current source has parse failures or a direct privacy exposure |
| ⚠️ Conflict | 1 | Competing rider eligibility policy remains in conflict-marked code |
| ❓ Unable to verify | 3 | Depends on deployed DB, scheduler host, or visual runtime |

### Ten highest-priority findings

1. Four PHP files fail syntax parsing due to merge markers. The PHP errors are in RiderProfile.php, ShipmentWorkflowService.php, LogisticsPortalController.php, and DispatchController.php. php artisan route:list --json currently fails while loading LogisticsPortalController.
2. Two Blade files also contain merge conflict markers: the Logistics pickup queue and guest hero carousel. Their rendered behavior is unverified and the pickup page contains competing page fragments.
3. Thirty model files violate Composer PSR-4 path casing. They work on a case-insensitive Windows filesystem but can fail to autoload on Linux.
4. Delivery proof images are uploaded to the public storage disk. The authenticated proof controller is correctly ownership-checked, but the public storage URL bypasses that gate.
5. Rider moderation policy is inconsistent: EnsureRiderOperationalType requires profile status ACTIVE, while conflict-marked Logistics/workflow code includes both ACTIVE only and ACTIVE or WARNED variants.
6. PSGC routing validation exceptions use the address_id key. Checkout renders that key under the buyer delivery address, even when the failed address is a Seller pickup origin.
7. Checkout supports per-seller vouchers in a legacy voucher_codes array, but the current UI presents one global voucher field. The documented per-seller experience is incomplete.
8. The schema and migration state of the configured database could not be checked. Several migrations affect existing data and need a controlled rollout review.
9. The route registry cannot currently be enumerated because of the PHP parse error. Static route declarations show role groups and middleware, but a resolved per-route listing is unavailable.
10. Database-backed feature tests were not run because PHPUnit forces a MySQL database named likhae_test, uses destructive reset/migration traits, and its disposability was not proven. The selected database-free unit run had 18 passes and 4 errors caused by the current source parse failure.

## 2. Role-by-role audit

### Buyer

- **Pages/routes:** marketplace, /buyer/cart, /buyer/checkout, /buyer/orders, /buyer/orders/{order}, account addresses and exact-pin confirmation, public tracking at /track/{trackingCode}, and authenticated proof at /delivery-events/{event}/proof.
- **Authorization:** Buyer routes declare auth, verified, and EnsureWorkspaceRole:buyer. Order and proof handlers check buyer ownership. Public tracking is read-only and throttled. The proof handler checks Buyer/order ownership, assigned Rider, or staff role.
- **Functions:** cart groups by Seller; checkout creates one parent Order with per-Seller SellerOrders and Shipments; buyer notes are per SellerOrder; Additional Care is recalculated server-side; exact pin is stored on the order address snapshot; order timeline uses shipment events; Buyer receipt requires delivered shipments.
- **Status:** verified in source for grouping, note, protection, pin, timeline, and receipt chains. Runtime feature tests were not run.
- **Issues:** voucher UI offers one global code; seller-origin PSGC errors are displayed as Buyer address errors; proof files are still publicly addressable from storage URLs.

### Seller

- **Pages/routes:** dashboard; catalog and product editor; grouped SKU inventory; inventory HTML preview and PDF download; orders and seller status transitions; refunds/returns; reports; messages, reviews, marketing, and account.
- **Authorization:** Seller routes declare auth, verified, EnsureWorkspaceRole:seller, and seller.approved. Inventory queries begin from the signed-in SellerProfile.
- **Functions:** active variants are the inventory unit; product cards aggregate and group variants; each variant can be updated independently; preview/download share inventoryReportData; report output is application/pdf; buyer note displays on the seller order.
- **Status:** verified in source for inventory arithmetic and report data flow. Seller order progression depends on ShipmentWorkflowService, which currently cannot parse.
- **Issues:** case-sensitive autoload mismatch affects 16 Seller model files on Linux; runtime PDF and responsive layout were not exercised.

### Rider Local

- **Pages/routes:** /rider/pickups, pickup batch detail and scan actions, /rider/deliveries, delivery detail, history, earnings, remittances, messages, profile/account.
- **Authorization:** Rider routes declare authentication, verification, Rider role, and active Rider checks. Pickup/delivery route groups add rider.operational:LOCAL_BARANGAY. EnsureRiderOperationalType also requires RiderProfile status ACTIVE.
- **Functions:** seller pickup is one Seller stop with individual waybill scans; scan advances READY_FOR_PICKUP to PICKED_UP; delivery success stores proof and records the COD result.
- **Status:** partial because the workflow service and Logistics assignment controller have unresolved parse errors. Static type separation exists.
- **Issues:** WARNED eligibility differs between competing conflict versions and the operational middleware. Delivery proof storage is public.

### Rider Inter-Logistics

- **Pages/routes:** /rider/transfers, manifest detail, accept assignment, and start transport.
- **Authorization:** routes add rider.operational:INTER_LOGISTICS; profile vehicle classification rejects local vehicles.
- **Functions:** manifest status transitions, individual destination intake, physical capacity checks, and origin/destination center custody are represented in services.
- **Status:** partial; static guards exist, but transfer feature tests and runtime routes were not verified.
- **Issues:** TransferManifestService::assignDriver checks RiderProfile status and account type but does not check the linked User status at assignment time. Later manifest actions do check the actor’s active status, which can leave a suspended User with an unusable assignment.

### Logistics

- **Pages/routes:** dashboard, parcel receive, Seller pickup queue, sorting, local assignments/trips, hub transfers/manifests, Riders, service areas, reports, messages, settings/account.
- **Authorization:** private routes declare auth, verified, EnsureWorkspaceRole:logistics, and provider.approved; controller/service code scopes many records to the signed-in center.
- **Functions:** Seller-grouped pickup assignment, parcel-by-parcel intake, sorting, local dispatch, inter-center custody, and service-area management are represented in source.
- **Status:** broken for current route bootstrap/runtime because both DispatchController.php and LogisticsPortalController.php have parse errors. The pickup Blade template also contains conflict fragments.
- **Issues:** resolved route list and manual URL outcomes cannot be certified until PHP and Blade conflicts are resolved. Rider eligibility policy needs one explicit WARNED rule.

### Admin

- **Pages/routes:** dashboard, account/user approvals, Seller and Logistics compliance, catalog moderation, disputes/refunds, protection rules, settings/policies, announcements, reports, and audit history.
- **Authorization:** Admin routes are grouped behind authentication, verification, and Admin workspace-role checks.
- **Functions:** protection-rule administration validates one target scope and numeric bounds; role-management and compliance pages are present.
- **Status:** partial. Static route groups, views, and controllers were reviewed. route:list failed before it could validate all resolved actions/middleware.
- **Issues:** 14 Admin model files have PSR-4 directory-case mismatches on Linux.

## 3. Page-by-page audit

| Page / route | View | Controller / service | Status and issue |
|---|---|---|---|
| Guest home and catalog: /, /products, /products/{slug} | guest home/catalog and guest/partials/hero-carousel.blade.php | GuestMarketplaceController | Partial; hero carousel contains merge markers and competing markup |
| Login, registration, recovery | auth/* | Authentication, registration, and recovery controllers | Static checks show throttled login/reset, role-aware login, and session regeneration; runtime unverified |
| Buyer cart: /buyer/cart | Buyer/cart.blade.php | BuyerController, cart service | Verified in source; grouping by Seller and selected-item submission |
| Buyer checkout: /buyer/checkout | Buyer/checkout.blade.php | CheckoutController, CheckoutService, routing/protection services | Partial; seller grouping and totals present; one voucher input; seller pickup errors rendered under delivery address |
| Buyer order list/detail: /buyer/orders, /buyer/orders/{order} | Buyer orders views | BuyerOrderController | Verified in source for seller shipment grouping, event timeline, note, proof link, and receipt controls |
| Buyer exact pin: /buyer/orders/{order}/delivery-pin | order detail/address UI | BuyerOrderController::confirmDeliveryPin | Verified in source; writes only that order’s historical address snapshot |
| Public tracking: /track/{trackingCode} and /live | tracking views / JSON | TrackingController | Read-only and rate-limited; route registry runtime not certified |
| Delivery proof: /delivery-events/{event}/proof | full image response | TrackingController::proof | Authorization checks exist; storage uses public disk, so the file URL can bypass this action |
| Seller dashboard/catalog: /seller/dashboard, /seller/products | Seller dashboard/products | Seller controllers/catalog service | Product and variant management present; Seller model casing risk on Linux |
| Seller inventory | Seller/products.blade.php | SellerProductController | Verified in source for one product group with active variant rows, per-SKU update, and threshold 10 |
| Inventory preview/download: /seller/products/inventory/preview, /download | Seller/inventory-preview.blade.php and PDF response | SellerProductController, SellerInventoryPdfService | Verified in source for shared report data and PDF response; output not rendered/runtime tested |
| Seller orders: /seller/orders and transitions | Seller orders view | SellerOrderController, ShipmentWorkflowService | Partial; note display and status controls exist, but workflow service parse failure blocks runtime |
| Rider local pickup list/detail | rider/pickups/index.blade.php, show.blade.php | RiderPickupBatchController, SellerPickupBatchService | Partial; per-Seller batch and explicit not-collected reason are present, but dependent workflow service currently fails parsing |
| Rider delivery list/detail | Rider delivery views | Rider controllers, ShipmentWorkflowService | Partial; route type guard exists; runtime blocked by service parse error |
| Rider transfer list/detail | rider/transfers*.blade.php | RiderTransferController, TransferManifestService | Partial; Inter-Logistics guard and custody transitions present; active linked User omission at assignment |
| Logistics pickup queue: /logistics/pickups | Logistics/pickups/index.blade.php | DispatchController | Broken; Blade merge markers and DispatchController parse error |
| Logistics intake: /logistics/parcels/receive | Logistics/parcels/receive.blade.php | DispatchController | Partial; page presents one scan per parcel and batch received/pending counts; controller parse error blocks route runtime |
| Logistics sorting/dispatch | Logistics sorting and assignment views | DispatchController, shipment/transfer services | Partial; route/controller code has unresolved conflicts and cannot be invoked |
| Logistics local trips | Logistics/local-trips/* | LocalTripController, local trip services | Partial; controller is syntactically valid, but dispatch assignment chain is blocked |
| Logistics transfers/manifests | transfer views | TransferController, TransferManifestService | Partial; code paths exist; role and DB integration unverified |
| Logistics center/service areas/reports | Logistics views | LogisticsPortalController | Broken at controller load due conflict markers |
| Admin protection rules | Admin/protection-rules/index.blade.php | AdminProtectionRuleController, FragileProtectionRuleService | Verified in source; runtime route checks unverified |
| Admin users/compliance/disputes/reports | Admin views | Admin controllers/services | Partial; route groups and code are present; full route registry could not load |

## 4. Feature matrix

| Requirement | Status | Evidence | Problem / recommended fix | Risk |
|---|---|---|---|---|
| PHP/project health | 🔴 Broken | PHP lint: 369 files, 4 syntax errors | Resolve the merge-marker blocks, then rerun full lint | Critical |
| PSR-4 model path casing | 🔴 Broken | 30 declarations under lowercase admin or seller folders | Use directory case matching App/Models/Admin and App/Models/Seller; confirm Linux autoload | Critical on Linux |
| Runtime versions/config | ✅ Verified in code | Composer requires PHP ^8.3; Laravel CLI reports 13.34.0 | No blocker found in inspected version metadata | Low |
| Login/logout/cache | ✅ Verified in code | Session regeneration/invalidation; POST logout; web no-cache middleware | Browser Back behavior still needs manual runtime check | Medium |
| Approval and inactive accounts | ✅ Verified in code | Global web EnsureActiveAccount; seller/provider/rider guards; API token active check | Runtime tests remain unrun | Medium |
| Route registry/security | 🟡 Partial | Role route groups in route files; current route:list --json fails | Re-run after parse fixes and inspect every resolved route/action/middleware | High |
| API authentication/ownership | 🟡 Partial | SHA-256 token authentication, expiry/status checks, buyer and workflow ownership checks | API feature tests were not run | Medium |
| Rider local/inter type separation | ⚠️ Conflict | Local and Inter middleware groups plus vehicle predicates; status variants conflict | Pick one WARNED operational policy and enforce it consistently | High |
| Cart grouped by Seller | ✅ Verified in code | Cart view and service group selected items by seller profile | No runtime DB test | Medium |
| Checkout split by Seller | ✅ Verified in code | One Order; each Seller group creates a SellerOrder and Shipment | Feature tests not run | High |
| Per-Seller voucher experience | 🟡 Partial | Service accepts voucher_codes[seller_id]; current form has one voucher_code | Add one voucher control per Seller group and retain server validation | Medium |
| Seller-specific buyer note | ✅ Verified in code | Notes validated against selected Seller groups, stored on SellerOrder, escaped in views | Additive nullable migration has not been applied/verified | Medium |
| Additional Care | ✅ Verified in code | Rule precedence, server recalculation, selection snapshot, order totals | DB-backed checkout tests not run | High |
| Canonical PSGC routing | 🟡 Partial | Separate Seller-origin and Buyer-destination resolution and service-area checks | Use a Seller-origin/routing validation key and render the error beside that choice | High |
| Exact Buyer pin | ✅ Verified in code | Coordinate pair validation and order-address snapshot update; no fallback map route | Dynamic map behavior unverified | Medium |
| SKU inventory, grouping, threshold | ✅ Verified in code | Active variants grouped by product; centralized threshold 10; stock update is variant-specific | Visual interaction not browser tested; no old <=5 stock threshold found in scanned app/views/JS | Medium |
| Inventory PDF preview/download | ✅ Verified in code | Both call inventoryReportData; preview is HTML; download sets application/pdf | PDF visual/output test not run | Medium |
| Seller pickup assignment rules | 🟡 Partial | Service validates one Seller, one location, center, rider, 1–10 parcels, approved capacity | Assignment route/controller source has parse conflicts; runtime cannot be exercised | Critical |
| Rider pickup scans/exceptions | 🟡 Partial | Batch checklist, single waybill scan, explicit not-collected reason | Workflow service parse failure prevents runtime verification | High |
| Logistics per-parcel intake | 🟡 Partial | Receive view has individual scan forms and expected/received/pending counts | Controller parse failure prevents runtime verification | High |
| Sorting and hub transfers | 🟡 Partial | State checks, manifests, physical capacity and individual receipt logic | Route runtime and feature tests blocked/unverified | High |
| Buyer order details/timeline | ✅ Verified in code | Shipment event timeline, pending steps, seller-grouped detail, escaped note | View not rendered in browser | Medium |
| Proof of delivery privacy | 🔴 Broken | Rider upload uses ImageOptimizationService default public disk; Logistics and Buyer use public URLs | Store proof privately and stream only through an authorized endpoint; move existing files | High |
| Buyer receipt and 72-hour auto receipt | ✅ Verified in code | Locks order, waits from latest delivered event across shipments, excludes disputes/returns, idempotent | Production scheduler runner unverified | High |
| Scheduler deployment | ❓ Unable to verify | Hourly command is registered in routes/console.php | Confirm host runs Laravel scheduler every minute | High |
| Admin pages | 🟡 Partial | Role group, controllers, and views present | Full route-action resolution blocked by PHP parse error | Medium |
| Logistics pages | 🟡 Partial | Role/approval middleware declared | Key controllers and pickup Blade are malformed | Critical |
| Database schema/deployed state | ❓ Unable to verify | Migrations inspected statically only | No DB introspection or migration execution performed | High |
| Responsive/accessibility behavior | ❓ Unable to verify | Static responsive classes, labels, and focus styles reviewed | No browser/device inspection or keyboard/screen-reader test | Medium |
| Automated test coverage | 🟡 Partial | Targeted unit selection executed; relevant Feature tests exist | 18 pass, 4 error from parse failure; database suites not run | High |

## 5. File-by-file findings

### A. Current merge conflicts and parse errors

**Files:**  
app/Services/Fulfillment/ShipmentWorkflowService.php  
app/Models/Rider/RiderProfile.php  
app/Http/Controllers/Logistics/LogisticsPortalController.php  
app/Http/Controllers/Logistics/DispatchController.php  
resources/views/Logistics/pickups/index.blade.php  
resources/views/guest/partials/hero-carousel.blade.php

**Function / line:** ShipmentWorkflowService.php:31-45, 186-198; RiderProfile.php:85-100; LogisticsPortalController.php:387, 474-494; DispatchController.php:78, 246, 376-398; Logistics/pickups/index.blade.php:70-131; hero-carousel.blade.php:3-61.

**Current behavior:** PHP conflict markers cause four parse errors. Laravel route listing fails as soon as Composer loads LogisticsPortalController. The two Blade files contain competing implementations, so their runtime output is not reliable.

**Required behavior:** One syntactically valid implementation per file, with the Seller pickup queue and guest hero page rendering once and preserving intended routes and roles.

**Status:** Broken / critical.

**Fix recommendation:** Resolve each conflict deliberately against the intended Seller pickup, Rider status, finance, and UI behavior. Do not choose a conflict side solely to make markers disappear. Then rerun PHP lint, Blade compilation, route-list, and the blocked tests.

### B. PSR-4 path casing

**Files:** all model classes below are declared in App\Models\Admin or App\Models\Seller, but their directory is lowercase. Composer maps App\ to app/.

**Admin folder app/Models/admin:** AdminAnnouncement.php, AdminAuditLog.php, AdminPolicy.php, Announcement.php, AuditLog.php, CommissionTransaction.php, Dispute.php, DisputeEvidence.php, Notification.php, PlatformPolicy.php, PlatformSetting.php, SellerComplianceAction.php, SellerComplianceCase.php, SellerComplianceResponse.php.

**Seller folder app/Models/seller:** Category.php, Message.php, Product.php, ProductImage.php, ProductOption.php, ProductOptionValue.php, ProductReview.php, ProductVariant.php, ProductVariantOptionValue.php, RefundPayout.php, Seller.php, SellerApplicationData.php, SellerOrder.php, SellerProfile.php, SellerReturnRefundRequest.php, Voucher.php.

**Current behavior:** Directory path casing does not match the namespace on case-sensitive filesystems. Windows development may mask the failure.

**Required behavior:** Class names must resolve under the Composer PSR-4 path on Linux.

**Status:** Broken for case-sensitive deployment.

**Fix recommendation:** Rename directories with a case-only-safe move and verify all 30 classes with Composer autoload diagnostics on a case-sensitive environment.

### C. Public proof files

**Files:** app/Http/Controllers/Rider/RiderShipmentController.php:77-143; app/Http/Controllers/Api/WorkflowApiController.php:70-88; app/Services/Media/ImageOptimizationService.php:21; app/Http/Controllers/Buyer/TrackingController.php:97-120; resources/views/Logistics/parcels/show.blade.php:11-16; resources/views/Buyer/orders.blade.php:143-198.

**Current behavior:** Rider web/API upload calls store(..., 'delivery-proofs'), which defaults to disk public. Buyer and Logistics templates generate direct public storage URLs. TrackingController::proof itself correctly checks Buyer ownership, assigned Rider, or staff role, but a known or guessed /storage/... URL bypasses it.

**Required behavior:** Only authorized users can retrieve proof.

**Status:** Broken privacy boundary.

**Fix recommendation:** Use a private disk and serve both Buyer and Logistics access through a controller that verifies shipment/attempt authorization. Move existing proof files and update stored paths or ensure legacy URLs are disabled.

### D. Rider status policy conflict

**Files:** app/Http/Middleware/EnsureRiderOperationalType.php:11-28; app/Services/Fulfillment/ShipmentWorkflowService.php:186-199; app/Http/Controllers/Logistics/DispatchController.php conflict regions; app/Services/Fulfillment/TransferManifestService.php:86-104, 382-390.

**Current behavior:** Operational middleware requires RiderProfile ACTIVE. Conflict alternatives contain both ACTIVE and ACTIVE|WARNED assignment rules. Pickup batch service requires ACTIVE. Transfer assignment checks RiderProfile status and account type, while later actions require the User itself to remain active.

**Required behavior:** A single explicit moderation rule should govern candidate lists, local assignment services, Inter-Logistics assignment, and operational route middleware. Suspended Users should not receive new work.

**Status:** Conflict / partial.

**Fix recommendation:** Decide whether WARNED Riders may accept new work, then align all four policy points. Add a regression test for active, warned, suspended, local, and Inter-Logistics cases.

### E. Seller voucher field

**Files:** resources/views/Buyer/checkout.blade.php:112-126; app/Http/Controllers/Buyer/CheckoutController.php:154-223, 239-305; app/Services/Marketplace/CheckoutService.php:160-206, 667-740.

**Current behavior:** The UI submits one voucher_code. Service code can apply a code by Seller group and retains a legacy voucher_codes[seller_id] path. One code matching more than one shop errors.

**Required behavior:** Buyer can enter/apply a voucher for each Seller group without applying another Seller's discount.

**Status:** Partial.

**Fix recommendation:** Add Seller-group-scoped inputs and preserve server-side seller ownership, eligibility, minimum total, dates, and redemption limits.

### F. PSGC routing error field

**Files:** app/Services/Fulfillment/PsgcLogisticsRoutingService.php:66-154; resources/views/Buyer/checkout.blade.php:70, 103.

**Current behavior:** Missing/ambiguous Seller pickup hub or service-area conditions are thrown on address_id. Checkout renders address_id beneath Buyer Delivery Address, while Logistics center errors use logistics_center_id.

**Required behavior:** Show destination address failures under Buyer address and Seller-origin/hub-coverage failures under the Seller pickup/routing selection that needs correction.

**Status:** Partial UX/validation mapping.

**Fix recommendation:** Use separate validation keys for buyer address and Seller-origin routing. Keep canonical PSGC checks server-side.

### G. Transfer driver User status

**File:** app/Services/Fulfillment/TransferManifestService.php:86-104, 382-390.

**Current behavior:** assignDriver accepts a RiderProfile with ACTIVE status and RIDER account type without testing linked User status. Later assigned-driver actions require active User status.

**Required behavior:** Reject inactive/suspended User accounts at assignment time.

**Status:** Partial.

**Fix recommendation:** Add linked User status validation to the assignment transaction and retain the action-time check. No migration is required; review and reassign any existing affected manifest.

### H. Migration data effects

**Files:** database/migrations/2026_10_13_000500_normalize_legacy_address_psgc_codes.php; 2026_10_13_000400_add_unique_financial_allocation_constraints.php; 2026_10_14_000100_expand_rider_moderation_statuses.php; 2026_10_13_000600_add_buyer_note_to_seller_orders_table.php; 2026_10_13_000700_add_seller_profile_to_rider_local_trips.php.

**Current behavior:** No migration was run. The PSGC normalizer updates existing address and service-area records in chunks, logs unresolved rows, skips service-area collisions, and has no reversible down. The financial migration checks duplicate allocation rows before adding unique indexes. Rider status rollback changes WARNED to ACTIVE and BANNED to DEACTIVATED. The Buyer note migration is nullable/additive; Seller profile ID on legacy local trips remains null.

**Status:** Schema rollout unable to verify; migration effects identified statically.

**Fix recommendation:** Before a future migration phase, inspect applied migration state, back up, preflight PSGC normalization and duplicate allocations, estimate affected rows, and review rollback semantics. Do not run these migrations as part of this audit.

## 6. Route and security audit

### Route groups found in source

| Route family | Declared protection | Intended access | Important checks / limitations |
|---|---|---|---|
| Guest/web | Laravel web middleware | Public catalog, login, registration, address lookup, tracking | State-changing web requests receive CSRF middleware |
| Buyer | auth, verified, EnsureWorkspaceRole:buyer | Approved Buyer workspace | Order ownership and pin/receipt checks are in controllers |
| Seller | auth, verified, Seller role, seller.approved | Approved Seller | Inventory is scoped to current SellerProfile |
| Rider | auth, verified, Rider role, rider.active | Active Rider account | Local and Inter groups also use rider.operational type middleware |
| Logistics | auth, verified, EnsureWorkspaceRole:logistics, provider.approved | Approved Logistics center | Center-scoping exists in controller/services; controller parse errors block loading |
| Admin | auth, verified, Admin role | Admin workspace | Admin model PSR-4 casing risk remains |
| API | Bearer token middleware and per-controller role/ownership checks | Mobile/API clients | Token hash/expiry/active checks are in source; runtime tests not run |

EnsureActiveAccount and PreventPrivatePageCache are appended to the web middleware group. Login regenerates the session. Logout is POST, calls authentication logout, invalidates the session, and regenerates the CSRF token. Reusable logout confirmation is included by the Buyer, Seller, Rider, Logistics, and Admin layouts. No GET logout action was found.

Public tracking routes are throttled. The authenticated proof endpoint checks record ownership, but public storage URLs remain an unauthorized access path. This is the material confirmed URL exposure.

Rider local routes are nested under LOCAL_BARANGAY; transfer routes are nested under INTER_LOGISTICS. The type guard rejects wrong vehicle class on manually typed operational URLs. WARNED status remains inconsistent and the workflow implementation currently fails to parse.

**Route inventory limitation:** The latest php artisan route:list --json exits with a PHP ParseError in LogisticsPortalController.php:387. Therefore this report does not claim a resolved route-by-route list of method, URI, action, inherited middleware, and CSRF status for all endpoints. Static route source was inspected; a complete route inventory is a required follow-up after conflict resolution.

## 7. Database and migration audit

- No database connection, data inspection, migration, rollback, seed, or schema mutation was performed.
- phpunit.xml forces MySQL on 127.0.0.1:3306 with database likhae_test; .env.testing is absent. The target was not proven disposable, so database-backed suites were not run.
- Additive schema changes include shipment origin/destination routing and parcel dimensions; transfer legs/manifests/scans; Rider approved capacity and local trips; protection/financial allocation tables; SellerOrder buyer_note; RiderLocalTrip seller_profile_id; Logistics application TIN; and Rider moderation enum values.
- buyer_note is nullable and does not backfill existing orders. Its rollback intentionally retains order notes.
- seller_profile_id is nullable on the additive trip migration; legacy rows remain null and cannot be treated as Seller-specific pickup batches without a deliberate backfill.
- The PSGC normalization migration changes stored canonical identifiers and is intentionally irreversible.
- Unique financial allocation migration fails safely when it detects duplicates, but production readiness still requires inspecting duplicate counts before deployment.
- Rider moderation rollback rewrites status values and loses WARNED/BANNED distinctions.
- The actual installed schema and applied migration level are unknown.

## 8. Test report

| Test/check | Ran? | Result | Why / limits |
|---|---|---|---|
| PHP syntax check across authored PHP | Yes | 369 files scanned; 4 parse errors | Conflict markers in four PHP files |
| JavaScript syntax, node --check | Yes | 34 files; 0 syntax errors | Syntax only; no bundle/build |
| php artisan route:list --json | Yes | Failed at LogisticsPortalController.php:387 | Controller conflict marker prevents route registry |
| Selected database-free unit classes | Yes | 22 tests; 18 passed; 55 assertions; 4 errors | All four errors are in TransferCapacityServiceTest and arise while loading the malformed workflow class |
| Buyer/cart/checkout Feature tests | No | NOT RUN | Use MySQL test DB and reset traits; database safety not proven |
| Seller inventory/report Feature tests | No | NOT RUN | DB-backed; no report file was generated |
| Pickup, intake, transfer, delivery Feature tests | No | NOT RUN | DB-backed; current PHP conflicts also block key workflow classes |
| Full PHPUnit suite | No | NOT RUN | Destructive DB reset/migration behavior and target disposability not proven |
| Blade compilation/rendering | No | NOT RUN | Would generate compiled artifacts; two views visibly contain conflict markers |
| Vite production build | No | NOT RUN | Would generate build artifacts; JS syntax check was used instead |
| Browser/device/accessibility checks | No | NOT RUN | No runtime browser session was used |
| Migrations / database tests | No | NOT RUN | Explicitly kept database unchanged |

Relevant test files exist for cart grouping, checkout notes/protection, Seller pickup batches, pickup scans, Rider vehicle types, Seller inventory, proof/order detail, auto receipt, Logistics authorization, and shipment workflow guards. Their presence is not counted as a pass.

## 9. Workflow trace and required scenarios

### End-to-end workflow

1. **Buyer places an order:** CheckoutService groups selected cart items by Seller, computes each Seller group, creates one parent Order, one SellerOrder per Seller, and one Shipment per Seller group.
2. **Seller processes the order:** Seller transition logic updates SellerOrder and Shipment state. Buyer notes are stored on SellerOrder. The primary shipment workflow service cannot currently parse, so runtime progression is blocked.
3. **Logistics assigns Seller pickup:** SellerPickupBatchService enforces one Seller, one pickup address, one Rider, one destination center, 1–10 parcels, and the Rider’s approved capacity. The Logistics controller and pickup view contain conflict markers.
4. **Rider collects from Seller:** Individual waybill scans call the shipment workflow to move READY_FOR_PICKUP to PICKED_UP; the scan itself does not mark AT_SORTING_CENTER. The current workflow service parse error blocks execution.
5. **Logistics intake:** The receive view provides one scan per waybill and tracks expected, received, and pending parcels. DispatchController parse errors block the endpoint.
6. **Sorting and next transport:** Sorting requires center custody. Cross-center route legs and manifests enforce origin/destination custody, vehicle class, and capacity in service code. Runtime paths are not available until conflicts are resolved.
7. **Final mile:** Local Riders are filtered by local vehicle type and assigned area. A Truck/Inter-Logistics Rider is blocked from local routes by the route type middleware. Runtime behavior is not tested.
8. **Proof:** Delivery success requires an image and stores a DeliveryAttempt. Buyer proof controller checks ownership, but proof files are publicly stored.
9. **Buyer receipt:** Manual receipt is allowed only after all shipment deliveries. The auto-confirm command locks eligible orders and waits 72 hours from the latest actual delivery event across Seller shipments; disputes/returns block auto-confirm.
10. **Scheduled execution:** The hourly command is registered in routes/console.php. Production scheduler execution is not verified.

### Scenarios A–N

| Scenario | Static trace | Current result |
|---|---|---|
| A — Buyer carts two Sellers | Cart groups by Seller; Checkout creates one Order, two SellerOrders, two Shipments | Verified in code; DB Feature test not run |
| B — Optional Additional Care | Unselected item IDs default empty; server recalculates and snapshots selected fee | Verified in code; DB Feature test not run |
| C — 15 ready parcels | Trip maximum is 10 and effective capacity is bounded by approved Rider capacity | Verified in pickup service; controller runtime blocked |
| D — Two Sellers in same barangay | Batch service checks one SellerProfile across selected shipments | Verified in service code; runtime blocked |
| E — Rider scans at Seller | Intended transition is READY_FOR_PICKUP → PICKED_UP | Correct transition is present; current workflow class does not parse |
| F — Logistics scans intake | Intended transition is PICKED_UP → AT_SORTING_CENTER per parcel | Receive UI is individual; endpoint controller does not parse |
| G — Truck enters local delivery URL | Local route group requires LOCAL_BARANGAY vehicle class | Static guard denies; runtime test not run |
| H — Motorcycle enters hub-transfer URL | Transfer route group requires INTER_LOGISTICS vehicle class | Static guard denies; runtime test not run |
| I — Buyer views delivered proof | Buyer gets a blue “View Full Photo” link, with no inline image preview | UI matches; underlying file URL is publicly addressable |
| J — Seller shipments delivered on different dates | Auto receipt timer uses maximum delivered-event timestamp | Verified in command/service code; DB test not run |
| K — Stock 10/11 | Central status helper classifies 10 as low and 11 as healthy | Verified in code; no <=5 stock logic found in app/views/JS scan |
| L — Product with 10 SKUs | Inventory groups variant rows under one product card and expands matching variants | Verified in code; browser interaction not checked |
| M — Logout | Modal confirmation, POST logout, invalidated session, rotated CSRF token, private-page no-store headers | Verified in source; browser Back behavior not manually tested |
| N — Old address without coordinates | Map marker returns null; Buyer has explicit order-level pin confirmation path | Verified in source; map UI not browser tested |

## 10. Required fixes by priority

### P0 — Critical

1. **Resolve merge conflicts and restore a loadable application.**  
   **Files:** the six conflict-marked files listed in Section 5A.  
   **Problem:** four PHP parse errors; route listing fails; pickup and hero views contain competing fragments.  
   **Safest implementation:** reconcile the intended code, remove conflict markers, then run PHP lint, Blade compilation, route-list, and relevant tests.  
   **DB migration required:** No.  
   **Existing data affected:** No direct data effect. Preserve staged user changes during resolution.

2. **Fix all 30 PSR-4 directory-case mismatches.**  
   **Files:** all model names in Section 5B.  
   **Problem:** Composer namespace path mismatch causes Linux autoload failures.  
   **Safest implementation:** case-only-safe rename of admin to Admin and seller to Seller; verify on a case-sensitive filesystem.  
   **DB migration required:** No.  
   **Existing data affected:** No.

### P1 — High

3. **Make delivery proof private.**  
   **Files:** Rider web/API upload, TrackingController, Buyer orders view, Logistics parcel detail, filesystem config.  
   **Problem:** direct public storage URL bypasses record-level authorization.  
   **Safest implementation:** private storage plus authorized streaming endpoint for Buyer, assigned Rider, and permitted staff; move legacy files and update references.  
   **DB migration required:** No schema migration by default; a one-time file/path migration is needed for existing proofs.  
   **Existing data affected:** Existing proof files and stored paths need a controlled move.

4. **Resolve Rider status policy.**  
   **Files:** EnsureRiderOperationalType, ShipmentWorkflowService, DispatchController, SellerPickupBatchService, TransferManifestService.  
   **Problem:** WARNED acceptance differs across candidate queries, assignment variants, and route middleware; inactive User assignment check is missing in transfer assignment.  
   **Safest implementation:** choose one written policy and align all list, service, and middleware checks; test each Rider profile/User status combination.  
   **DB migration required:** No new migration expected.  
   **Existing data affected:** Review active assignments/manifests for Riders whose current status would become ineligible.

5. **Add per-Seller voucher UI and fix PSGC error mapping.**  
   **Files:** Buyer/checkout.blade.php, CheckoutController, CheckoutService, PsgcLogisticsRoutingService.  
   **Problem:** current checkout form has one global voucher input and shows Seller routing errors under Buyer address.  
   **Safest implementation:** render inputs keyed by SellerProfile and use distinct validation fields for destination address versus Seller-origin coverage.  
   **DB migration required:** No.  
   **Existing data affected:** No.

### P2 — Medium

6. **Reject inactive linked Users when assigning transfer manifests.**  
   **Files:** TransferManifestService.php.  
   **Problem:** assignment may be created for an inactive User who cannot later operate it.  
   **Safest implementation:** validate User status inside the same assignment transaction; review and reassign any existing such manifests.  
   **DB migration required:** No.  
   **Existing data affected:** Operational reassignment may be needed; no schema change.

7. **Preflight data-changing migrations before a separately authorized DB phase.**  
   **Files:** PSGC normalization, financial allocation unique constraints, Rider moderation enum migration.  
   **Problem:** PSGC values update without reversible down; unique index migration can be blocked by legacy duplicates; moderation rollback rewrites statuses.  
   **Safest implementation:** inspect migration history, back up, compute update/collision/duplicate counts, and approve the affected-row plan before execution.  
   **DB migration required:** Yes, only if these features are to be deployed and migrations are unapplied.  
   **Existing data affected:** PSGC address/service-area values may change; Rider status values change on rollback; unique constraints affect accepted row combinations.

8. **Complete route and workflow verification after the source is loadable.**  
   **Files:** route files, controllers, middleware, tests.  
   **Problem:** current route list and feature paths cannot be resolved against the malformed controllers.  
   **Safest implementation:** generate the full route/action/middleware inventory, then run the existing isolated feature suite against a proven disposable database.  
   **DB migration required:** No code migration; tests require a disposable test DB with migrations.  
   **Existing data affected:** No production data; test DB is destructive.

### P3 — UX and cleanup

9. **Run browser checks for mobile layouts, keyboard operation, map states, inventory expand/collapse, logout modal, proof link, and PDF preview/download.**  
   **Files:** relevant Blade/CSS/JS views.  
   **Problem:** static source cannot confirm actual viewport overflow, focus flow, or rendered PDF.  
   **Safest implementation:** use seeded non-production test data and record desktop/mobile scenarios.  
   **DB migration required:** No.  
   **Existing data affected:** No.

10. **Confirm production scheduler installation.**  
    **Files:** routes/console.php, deployment/host configuration.  
    **Problem:** command registration is present but no host runner was verified.  
    **Safest implementation:** inspect deployment configuration and verify one scheduler invocation in staging.  
    **DB migration required:** No.  
    **Existing data affected:** Auto-confirm will complete eligible production orders once scheduled; validate schedule timing and exclusions before activation.

## Audit result

No source fix, migration, seed, or database change was made. The key Buyer, Seller, inventory, protection, exact-pin, and order-receipt paths are substantially represented in current code, but the current source snapshot is not ready for deployment: unresolved conflicts break PHP loading, Linux autoload casing is inconsistent, and proof images are publicly stored.

**READY FOR FIX PHASE**
