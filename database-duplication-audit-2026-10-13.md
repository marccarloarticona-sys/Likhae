# LIKHAE Database Duplication and Schema-Overlap Audit

Audit date: 2026-10-13 (Asia/Manila)

Scope: connected MySQL database `likhae`, all migration files, Eloquent models, foreign keys/indexes, key services/controllers, and read-only duplicate-record checks.

## Executive result

- The connected `likhae` database has **86 physical tables**: **85 application tables + `migrations`**.
- All 41 migrations are marked **Ran**; there are **no pending migrations**.
- The 85 migration-defined application table names are unique. The only database table not in the application-table set is Laravel's `migrations` table.
- No duplicate table was created, dropped, truncated, or deleted by this audit.
- No duplicate business records were found in the checked buyer, COD, remittance, finance, routing, assignment, manifest, review, or service-area checks.
- The schema-integrity drift on `shipment_financial_allocations.shipment_id` and `seller_order_id` was remediated with the additive safety migration `2026_10_13_000400_add_unique_financial_allocation_constraints`. Both database-level unique indexes are now present; the table has 0 rows and the preflight found no duplicate data.

## Complete table inventory

`Rows` are exact read-only counts from the connected `likhae` database. `FK children` lists tables that reference the table through a foreign key; an em dash means no FK child was found.

| Table | Purpose | Model | Creating migration | FK children | Rows | Status |
|---|---|---|---|---|---:|---|
| users | Account identity and role | `User` | 09/25 000000 accounts | addresses; orders; profiles; applications; operational/audit tables | 14 | CANONICAL |
| addresses | Saved user/business addresses | `Buyer\Address` | 09/25 000000 accounts | logistics_application_data; logistics_centers; seller_profiles | 16 | CANONICAL |
| registration_applications | Account onboarding applications | `Auth\RegistrationApplication` | 09/25 000000 accounts | application_documents; seller/logistics/rider application data | 6 | CANONICAL |
| application_documents | Onboarding document evidence | `Auth\ApplicationDocument` | 09/25 000000 accounts | — | 7 | CANONICAL |
| categories | Product/category hierarchy | `seller\Category` | 09/25 000100 profiles/service area | categories; products; seller profiles; protection rules | 75 | CANONICAL |
| seller_application_data | Seller onboarding detail | `seller\SellerApplicationData` | 09/25 000100 profiles/service area | — | 0 | CANONICAL |
| logistics_application_data | Logistics onboarding detail | `Logistics\LogisticsApplicationData` | 09/25 000100 profiles/service area | — | 1 | CANONICAL |
| seller_profiles | Seller business profile | `seller\SellerProfile` | 09/25 000100 profiles/service area | products; seller_orders; vouchers; compliance/refund/settlement tables | 2 | CANONICAL |
| logistics_centers | Logistics hub/provider | `Logistics\LogisticsCenter` | 09/25 000100 profiles/service area | service/routing/shipping/finance tables | 3 | CANONICAL |
| rider_application_data | Rider onboarding detail | `Rider\RiderApplicationData` | 09/25 000100 profiles/service area | — | 0 | CANONICAL |
| rider_profiles | Approved rider identity | `Rider\RiderProfile` | 09/25 000100 profiles/service area | assignments; area; trip; earnings; COD/remittance tables | 2 | CANONICAL |
| service_areas | Barangay coverage per logistics center | `Logistics\ServiceArea` | 09/25 000100 profiles/service area | service_area_locations; rider_area_assignments; shipments | 4 | CANONICAL |
| service_area_locations | PSGC locations in a service area | `Logistics\ServiceAreaLocation` | 09/25 000100 profiles/service area | — | 5 | PIVOT/SUPPORT |
| rider_area_assignments | Rider-to-service-area coverage | `Rider\RiderAreaAssignment` | 09/25 000100 profiles/service area | — | 4 | CANONICAL |
| products | Seller catalog product | `seller\Product` | 09/25 000200 catalog | variants; images; options; order/protection/compliance/wishlist tables | 5 | CANONICAL |
| product_images | Product image assets | `seller\ProductImage` | 09/25 000200 catalog | product_variants; product_option_values | 11 | CANONICAL |
| product_options | Product variation option | `seller\ProductOption` | 09/25 000200 catalog | product_option_values | 4 | CANONICAL |
| product_option_values | Values for product options | `seller\ProductOptionValue` | 09/25 000200 catalog | product_images; product_variant_option_values | 9 | CANONICAL |
| product_variants | Buyable SKU/stock/price snapshot source | `seller\ProductVariant` | 09/25 000200 catalog | cart_items; order_items; option links | 14 | CANONICAL |
| product_variant_option_values | Variant-to-option-value pivot | `seller\ProductVariantOptionValue` | 09/25 000200 catalog | — | 22 | PIVOT |
| vouchers | Seller voucher rules | `seller\Voucher` | 09/25 000300 vouchers | seller_orders | 0 | CANONICAL |
| carts | Buyer cart | `Buyer\Cart` | 09/25 000400 commerce | cart_items; orders | 10 | CANONICAL |
| cart_items | Cart-to-variant pivot | `Buyer\CartItem` | 09/25 000400 commerce | — | 3 | PIVOT |
| orders | Parent buyer/commercial order | `Buyer\Order` | 09/25 000400 commerce | seller_orders; payments; address; disputes/returns/finance | 11 | CANONICAL |
| order_addresses | Immutable order delivery-address snapshot | `Buyer\OrderAddress` | 09/25 000400 commerce | — | 11 | CANONICAL/SNAPSHOT |
| seller_orders | One seller-specific fulfillment order per parent order | `seller\SellerOrder` | 09/25 000400 commerce | shipments; items; commission/settlement/finance/returns | 12 | CANONICAL |
| order_items | Immutable order-line product/price snapshot | `Buyer\OrderItem` | 09/25 000400 commerce | reviews; return items; protection audit | 13 | CANONICAL/SNAPSHOT |
| payments | Buyer payment method/order payment state | `Buyer\Payment` | 09/25 000400 commerce | — | 11 | CANONICAL |
| reviews | One buyer review per order item | `Buyer\Review` | 09/25 000400 commerce | — | 2 | CANONICAL |
| shipments | Physical parcel lifecycle and custody pointer | `Logistics\Shipment` | 09/25 000500 shipping | assignments; scans/events; transfer/manifest/finance/COD | 12 | CANONICAL |
| pickup_requests | Seller/logistics pickup request | `Logistics\PickupRequest` | 09/25 000500 shipping | — | 9 | CANONICAL |
| rider_assignments | Specific rider-to-shipment pickup/delivery assignment | `Rider\RiderAssignment` | 09/25 000500 shipping | scans/events/attempts/trips/earnings | 20 | CANONICAL |
| waybills | Shipment label/version | `Logistics\Waybill` | 09/25 000500 shipping | — | 9 | CANONICAL |
| parcel_scans | Parcel scan/audit event | `Logistics\ParcelScan` | 09/25 000500 shipping | — | 14 | AUDIT/HISTORY |
| shipment_events | Shipment status history | `Logistics\ShipmentEvent` | 09/25 000500 shipping | — | 133 | AUDIT/HISTORY |
| delivery_attempts | Final-mile attempt history | `Logistics\DeliveryAttempt` | 09/25 000500 shipping | — | 6 | AUDIT/HISTORY |
| conversations | Conversation thread | `Communication\Conversation` | 09/25 000600 messaging | participants; messages | 8 | CANONICAL |
| conversation_participants | Conversation-to-user pivot | `Communication\ConversationParticipant` | 09/25 000600 messaging | — | 16 | PIVOT |
| messages | Conversation message | `Communication\Message` | 09/25 000600 messaging | — | 151 | CANONICAL |
| disputes | Buyer/seller dispute case | `admin\Dispute` | 09/25 000700 disputes | evidence; return/refund workflow | 1 | CANONICAL |
| dispute_evidence | Dispute evidence | `admin\DisputeEvidence` | 09/25 000700 disputes | — | 0 | AUDIT/HISTORY |
| seller_compliance_cases | Seller/product compliance case | `admin\SellerComplianceCase` | 09/25 000800 compliance | actions; responses | 2 | CANONICAL |
| seller_compliance_actions | Compliance action history | `admin\SellerComplianceAction` | 09/25 000800 compliance | — | 2 | AUDIT/HISTORY |
| commission_transactions | Operational commission record, one per seller order | `admin\CommissionTransaction` | 09/25 000900 legacy finance | — | 3 | CANONICAL/FINANCE |
| rider_earnings | Legacy per-assignment rider earning fallback | `Rider\RiderEarning` | 09/25 000900 legacy finance | — | 13 | LEGACY — PRESERVE |
| announcements | Platform announcements | `admin\Announcement` | 09/25 001000 platform | — | 1 | CANONICAL |
| platform_policies | Published platform policies | `admin\PlatformPolicy` | 09/25 001000 platform | — | 0 | CANONICAL |
| platform_settings | Platform settings, including commission setting | `admin\PlatformSetting` | 09/25 001000 platform | — | 2 | CANONICAL |
| notifications | User notifications | `admin\Notification` | 09/25 001000 platform | — | 431 | CANONICAL |
| audit_logs | General application audit log | `admin\AuditLog` | 09/25 001100 audit | — | 14 | AUDIT/HISTORY |
| sessions | Laravel session storage | — | 09/25 001200 Laravel infrastructure | — | 0 | INFRASTRUCTURE |
| password_reset_tokens | Laravel password reset storage | — | 09/25 001200 Laravel infrastructure | — | 1 | INFRASTRUCTURE |
| cache | Laravel cache storage | — | 09/25 001200 Laravel infrastructure | — | 0 | INFRASTRUCTURE |
| cache_locks | Laravel cache lock storage | — | 09/25 001200 Laravel infrastructure | — | 0 | INFRASTRUCTURE |
| jobs | Laravel queue storage | — | 09/25 001200 Laravel infrastructure | — | 0 | INFRASTRUCTURE |
| failed_jobs | Laravel failed queue storage | — | 09/25 001200 Laravel infrastructure | — | 0 | INFRASTRUCTURE |
| wishlist_items | Buyer wishlist-to-product pivot | `Buyer\WishlistItem` | 10/04 000100 wishlist | — | 4 | PIVOT |
| return_refund_requests | Buyer-level return/refund request | `Buyer\ReturnRefundRequest` | 10/05 000000 return/refund request | seller workflow; items/evidence/events/cash refund | 1 | CANONICAL |
| return_refund_request_items | Return/refund request-to-order-item detail | `Buyer\ReturnRefundRequestItem` | 10/05 000000 return/refund request | return_inspections | 1 | PIVOT/DETAIL |
| rider_assignment_locations | Assignment location telemetry | `Rider\RiderAssignmentLocation` | 10/05 000200 rider assignment locations | — | 1 | AUDIT/HISTORY |
| seller_compliance_responses | Seller response to compliance case | `admin\SellerComplianceResponse` | 10/11 000100 compliance responses | — | 0 | CANONICAL |
| seller_return_refund_requests | Seller-specific child review per seller order | `seller\SellerReturnRefundRequest` | 10/11 000200 seller-first refund workflow | payout/custody/inspection/evidence/events/cash refund | 1 | CANONICAL/WORKFLOW |
| return_refund_evidence | Buyer/seller return/refund evidence | `Buyer\ReturnRefundEvidence` | 10/11 000200 seller-first refund workflow | — | 1 | AUDIT/HISTORY |
| refund_payouts | Seller-reported payout evidence | `seller\RefundPayout` | 10/11 000200 seller-first refund workflow | — | 1 | CANONICAL/PAYOUT |
| return_refund_events | Return/refund status history | `Buyer\ReturnRefundEvent` | 10/11 000200 seller-first refund workflow | — | 8 | AUDIT/HISTORY |
| shipment_transfer_legs | Planned/actual hub-to-hub leg | `Logistics\ShipmentTransferLeg` | 10/12 000200 transfer legs | parcel_scans; transfer_manifest_items | 0 | CANONICAL |
| transfer_manifests | Physical consolidated hub transport batch | `Logistics\TransferManifest` | 10/12 000400 transfer manifests | transfer_manifest_items | 0 | CANONICAL |
| transfer_manifest_items | Manifest-to-transfer-leg pivot | `Logistics\TransferManifestItem` | 10/12 000400 transfer manifests | — | 0 | PIVOT |
| rider_local_trips | Actual local rider run/load | `Rider\RiderLocalTrip` | 10/13 000100 local trips | rider_local_trip_items | 0 | CANONICAL |
| rider_local_trip_items | Local trip-to-rider-assignment pivot | `Rider\RiderLocalTripItem` | 10/13 000100 local trips | — | 0 | PIVOT |
| cod_collections | Physical cash collected by final-mile rider | `Finance\CodCollection` | 10/13 000100 Step 6/7 finance | remittance items | 0 | CANONICAL/FINANCE |
| rider_remittances | Rider cash remittance batch | `Finance\RiderRemittance` | 10/13 000100 Step 6/7 finance | remittance items | 0 | CANONICAL/FINANCE |
| rider_remittance_items | Remittance-to-COD-collection pivot | `Finance\RiderRemittanceItem` | 10/13 000100 Step 6/7 finance | — | 0 | PIVOT |
| shipment_financial_allocations | One shipment-level financial allocation snapshot | `Finance\ShipmentFinancialAllocation` | 10/13 000100 Step 6/7 finance | ledger; seller/logistics/rider settlement pools | 0 | CANONICAL/FINANCE |
| logistics_earning_pools | Logistics payable pool per shipment allocation | `Finance\LogisticsEarningPool` | 10/13 000100 Step 6/7 finance | — | 0 | CANONICAL/FINANCE |
| rider_earning_pools | Rider payable pool per shipment allocation | `Finance\RiderEarningPool` | 10/13 000100 Step 6/7 finance | participants | 0 | CANONICAL/FINANCE |
| rider_earning_pool_participants | Rider-to-pool allocation detail | `Finance\RiderEarningPoolParticipant` | 10/13 000100 Step 6/7 finance | — | 0 | PIVOT/FINANCE |
| seller_settlements | Seller payout/settlement record | `Finance\SellerSettlement` | 10/13 000100 Step 6/7 finance | — | 0 | CANONICAL/FINANCE |
| financial_ledger_entries | Idempotent accounting entries | `Finance\FinancialLedgerEntry` | 10/13 000100 Step 6/7 finance | — | 0 | CANONICAL/LEDGER |
| finance_audit_events | Finance audit/history events | `Finance\FinanceAuditEvent` | 10/13 000100 Step 6/7 finance | — | 0 | AUDIT/HISTORY |
| fragile_protection_rules | Authoritative protection rule source | `Protection\FragileProtectionRule` | 10/13 000200 Step 8 protection | order_items; protection_selection_audits | 0 | CANONICAL |
| protection_selection_audits | Immutable order-time protection selection audit | — (`DB::table`) | 10/13 000200 Step 8 protection | — | 0 | AUDIT/HISTORY |
| return_custody_events | Physical return custody history | `Returns\ReturnCustodyEvent` | 10/13 000300 Step 9 cash refund | — | 0 | AUDIT/HISTORY |
| return_inspections | Returned-item inspection evidence | `Returns\ReturnInspection` | 10/13 000300 Step 9 cash refund | — | 0 | AUDIT/HISTORY |
| cash_refund_obligations | One approved cash-refund obligation per seller request | `Returns\CashRefundObligation` | 10/13 000300 Step 9 cash refund | — | 0 | CANONICAL/FINANCE |
| migrations | Laravel migration ledger | — | Laravel framework | — | 41 | INFRASTRUCTURE |

## Exact duplicate-table audit

| Check | Result | Evidence |
|---|---|---|
| Repeated `Schema::create('same_name')` | NO DUPLICATION | 75 explicit create names are unique. |
| Repeated `createTableIfMissing('same_name')` | NO DUPLICATION | 10 finance names are unique and match existing table names. |
| Migration-defined application tables vs live `likhae` tables | NO DUPLICATION | 85 expected names match; only `migrations` is extra. |
| Same table under a slightly different name | NO CONFIRMED DUPLICATION | No second table for the same buyer/order/shipment/COD/refund concept was found. |
| Multiple models mapped to one table | VALID ALIASES | Deprecated `seller\ProductReview` -> `reviews` and `seller\Message` -> `messages`; admin/provider/event subclasses are intentional aliases. |

The `createTableIfMissing()` pattern is not itself a duplicate table, but it can silently accept a pre-existing table whose indexes do not match the migration definition. That occurred for `shipment_financial_allocations` and was remediated by the additive unique-constraint migration recorded below.

## Semantic overlap decisions

| Concept | Current structures | Decision | Canonical source / action |
|---|---|---|---|
| Buyer payment vs physical COD | `payments`; `cod_collections` | VALID SEPARATE RESPONSIBILITIES | `payments` owns order payment state; `cod_collections` owns final-mile physical cash. |
| COD remittance | `cod_collections`; `rider_remittances`; `rider_remittance_items` | VALID SEPARATE RESPONSIBILITIES | Collection is parcel cash; remittance is a rider batch; pivot prevents reusing one collection. |
| Commission | `commission_transactions`; `shipment_financial_allocations.platform_commission`; `financial_ledger_entries` | VALID SNAPSHOT/TRANSACTION/LEDGER ROLES | `FinancialRules` is the calculation source; transaction is operational, allocation is shipment snapshot, ledger is accounting history. |
| Seller payable | `shipment_financial_allocations.seller_payable`; `seller_settlements` | VALID SNAPSHOT/PAYOUT ROLES | Allocation is calculated source; settlement is authorization/payment workflow. |
| Logistics payable | `shipment_financial_allocations.logistics_allocation`; `logistics_earning_pools` | VALID SNAPSHOT/POOL ROLES | Allocation is source amount; pool is operational payout queue. |
| Rider payable | legacy `rider_earnings`; `rider_earning_pools`; `rider_earning_pool_participants` | LEGACY — PRESERVE | New ready path uses pool participants; legacy per-assignment table remains fallback/historical. No new pool rows currently exist. |
| Service-area assignment | `service_areas`; `service_area_locations`; `rider_area_assignments` | VALID SEPARATE RESPONSIBILITIES | Coverage, PSGC membership, and rider coverage are distinct. |
| Shipment rider assignment | `rider_assignments`; `rider_local_trips`; `rider_local_trip_items` | VALID SEPARATE RESPONSIBILITIES | Parcel assignment is in `rider_assignments`; local run/load is in trips and its pivot. |
| Routing/custody | shipment center fields; `shipment_transfer_legs`; `transfer_manifests`; `transfer_manifest_items`; `parcel_scans` | VALID SEPARATE RESPONSIBILITIES | Current custody, route leg, physical batch, and scan history are distinct. |
| Return/refund request | `return_refund_requests`; `seller_return_refund_requests` | VALID PARENT/SELLER-CHILD WORKFLOW | Buyer request is parent; seller request is one seller-specific review for that parent. |
| Refund payout vs cash obligation | `refund_payouts`; `cash_refund_obligations` | VALID SEPARATE RESPONSIBILITIES | Seller-reported payout evidence differs from approved new COD cash obligation. |
| Return history | `return_refund_events`; `return_custody_events`; `return_inspections`; evidence tables | AUDIT/HISTORY — PRESERVE | These are event/evidence/inspection records, not competing request tables. |
| Protection | `fragile_protection_rules`; order-item protection snapshot columns; `protection_selection_audits` | VALID RULE/SNAPSHOT/AUDIT ROLES | One rule source plus order-time snapshot and audit. |

## Column/source overlap findings

| Business value | Current sources | Finding |
|---|---|---|
| Shipping fee | `config('likhae.standard_shipping_fee')` = 50.00; `seller_orders.shipping_fee`; `shipment_financial_allocations.shipping_fee`; `logistics_centers.shipping_fee` = 65.00 in all 3 rows | CANONICAL BASE = **PHP 50.00**. Any additional fee must be a separate additive fee after the PHP 50 base, with its own approved source/snapshot. The logistics-center column is currently overridden/ignored by checkout and must not replace the PHP 50 base. |
| Product price | `product_variants.price`; `order_items.original_unit_price`; `order_items.unit_price` | NO DUPLICATION. Catalog price, original checkout snapshot, and charged snapshot have different scopes. |
| Commission | `config('likhae.seller_commission_rate')`; `platform_settings.commission_rate`; `FinancialRules::COMMISSION_RATE`; persisted transaction/allocation/ledger amounts | VALID CURRENT ALIGNMENT, but multiple policy entry points exist. Current stored setting and constants are all 10%. New financial records use `FinancialRules`; do not add another calculator. |
| Rider identity | `rider_profile_id` in assignment, pool, COD and remittance structures | NO DUPLICATION. The same foreign key identifies the rider in different business records. |
| Logistics center | `shipments.logistics_center_id`, `origin_logistics_center_id`, `destination_logistics_center_id` | NO DUPLICATION. Current/operating custody is distinct from route endpoints. |

## Duplicate business-record checks

All checks below were read-only `GROUP BY ... HAVING COUNT(*) > 1` or relationship-count checks against `likhae`:

| Check | Result |
|---|---|
| Active service-area PSGC mapped more than once | NO DUPLICATION |
| Active rider-to-service-area pair repeated | NO DUPLICATION |
| Active rider assignment repeated for shipment + assignment type | NO DUPLICATION |
| More than one COD collection per shipment | NO DUPLICATION |
| More than one remittance item per COD collection | NO DUPLICATION |
| More than one shipment financial allocation per shipment | NO DUPLICATION; table currently empty |
| More than one shipment financial allocation per seller order | NO DUPLICATION; table currently empty |
| More than one commission transaction per seller order | NO DUPLICATION |
| More than one seller settlement per seller order | NO DUPLICATION; table currently empty |
| More than one manifest item per transfer leg | NO DUPLICATION; table currently empty |
| Duplicate transfer-leg sequence per shipment | NO DUPLICATION; table currently empty |
| More than one review per order item | NO DUPLICATION |
| More than one payment per order | NO DUPLICATION |

## Critical buyer-flow record check

Current live counts:

- Orders: 11
- Seller orders: 12
- Shipments: 12
- Payments: 11
- Commission transactions: 3
- Rider assignments: 20
- Reviews: 2
- COD collections, remittances, financial allocations, pools, and seller settlements: 0

No order-flow anomaly was found: every order has one or more seller orders, seller-order count equals shipment count, and each order has exactly one payment. Each seller order has exactly one shipment. No duplicate commission or settlement relationship was found.

The new checkout path uses one fixed shipping charge per seller-order/shipment and calls the same server-side `FinancialRules` calculation path for commission and allocations. A separate idempotency key for the checkout request was not found; a client retry could therefore create a new order rather than reuse an existing request. This is a prevention-risk finding, not evidence of an existing duplicate.

## Migration decision table

`php artisan migrate:status` reports all 41 migrations as **Ran**. There are **no pending migrations** after applying the additive safety migration.

| Migration state | Decision |
|---|---|
| Pending migrations | None — no action |
| `2026_10_13_000100_prepare_step6_step7_finance_tables` | Already ran. No duplicate tables found. Its intended unique indexes were restored by the additive safety migration below. |
| `2026_10_13_000200_prepare_step8_protection_tables` | Already ran. Protection rule/snapshot/audit separation is valid. |
| `2026_10_13_000300_prepare_step9_cash_refund_tables` | Already ran. Custody, inspection, and cash obligation are separate concepts. |
| `2026_10_13_000400_add_unique_financial_allocation_constraints` | Applied successfully after duplicate preflight. Added unique indexes for `shipment_id` and `seller_order_id`; no rows were changed. |
| `2026_09_27_000100_add_rider_feedback_to_reviews_table` | Already ran. Its `down()` method deletes rows with null ratings; do not roll back without explicit approval because rollback is destructive. |

## Remaining recommended follow-up

1. Preserve PHP 50.00 as the base shipping fee. If an additional fee is introduced, add it separately and explicitly after the base; do not let `logistics_centers.shipping_fee` silently replace the base.
2. Add checkout-request idempotency or a locked checkout token if repeated client requests must never create a second order.
3. Keep `rider_earnings` as legacy until historical fallback behavior is formally retired and separately approved for consolidation.

## Database safety confirmation

- DATABASE NOT RESET.
- DATABASE NOT REFRESHED.
- DATABASE NOT WIPED.
- NO EXISTING TABLE DROPPED.
- NO EXISTING TABLE TRUNCATED.
- NO DUPLICATE TABLE WAS AUTOMATICALLY DELETED.
- ALL EXISTING TABLES WERE INSPECTED FOR SEMANTIC OVERLAP.
- The unique-constraint migration ran only after duplicate preflight; it added two safe additive indexes and changed no existing rows.
- ONLY NECESSARY SAFE ADDITIVE TABLES/COLUMNS/INDEXES WERE reviewed or added; no table was replaced, dropped, or truncated.
- HISTORICAL DATA WAS PRESERVED.
