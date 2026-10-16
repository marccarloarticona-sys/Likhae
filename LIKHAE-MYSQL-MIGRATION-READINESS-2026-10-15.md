# LIKHAE MySQL Migration Readiness — 2026-10-15

## 1. Disposable Environment

| Field | Value |
|---|---|
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` | `127.0.0.1` |
| `DB_PORT` | `3306` |
| Configured `DB_DATABASE` (identified only) | `likhae` |
| Disposable database used for all database checks | `likhae_migration_review_disposable_20261015` |
| MySQL version | `8.4.11` |

The configured database name was inspected only to distinguish it from the disposable database. Before each destructive test operation, the active Laravel configuration was checked to resolve to the disposable database and the required safety marker was printed. `.env` was not changed. The repository's default `phpunit.xml` targets `likhae_test`; tests were run with a temporary PHPUnit configuration pointed at the disposable database and loading credentials from the unchanged environment.

## 2. Migrations Reviewed

### Prepared migrations

| Migration | Schema and data review | Rollback behavior and risk |
|---|---|---|
| `2026_10_14_000200_add_platform_voucher_scope.php` | Keeps seller voucher ownership and the seller/code uniqueness rule. Adds nullable platform scope/code fields and permits a platform voucher without a fake seller. Existing seller voucher foreign keys remain. | `down()` refuses to remove the platform fields while platform voucher rows exist. No platform discount funding or settlement rule was added; funding remains **UNRESOLVED — FINANCE REVIEW**. |
| `2026_10_14_000300_add_target_audience_to_announcements.php` | Adds an audience value defaulting to `ALL` and a composite audience/active/published index. Existing announcements were backfilled/defaulted to `ALL`. | `down()` refuses rollback while targeted-audience rows exist. |
| `2026_10_14_000400_add_order_item_to_shipments.php` | Retains the `seller_order_id` foreign-key relationship, removes its one-shipment uniqueness restriction, and adds nullable `order_item_id` with a unique index and restrictive foreign key. Backfill links only an unambiguous one-item/one-shipment legacy case; ambiguous rows remain `NULL`. | `down()` refuses when a seller order has multiple shipments. Even for one shipment, dropping `order_item_id` loses the new line-to-parcel association. Treat as forward-only after item-level shipments or mappings are written. |
| `2026_10_15_000100_create_checkout_attempts_table.php` | Adds checkout idempotency records with a unique hashed key, buyer ownership, request hash, optional unique order reference, and supporting lookup/timestamp indexes. No legacy rows are synthesized. | `down()` drops the idempotency records and their history. |
| `2026_10_15_000200_add_attachment_path_to_messages.php` | Adds nullable `attachment_path`; existing message rows remain valid. | `down()` guards against dropping populated attachment paths. Empty-table rollback and reapply passed on the disposable database. |

### Related schema migrations and constraints reviewed

- Core voucher, commerce, shipping, finance, and platform schema: `2026_09_25_000300_create_vouchers_table.php`, `2026_09_25_000400_create_commerce_tables.php`, `2026_09_25_000500_create_shipping_tables.php`, `2026_09_25_000900_create_finance_tables.php`, and `2026_09_25_001000_create_platform_tables.php`.
- Shipment routing, transfer, scan, manifest, and rider capacity: all five `2026_10_12_*` migrations.
- Local rider trips and shipment finance/protection/refund relationships: all `2026_10_13_*` migrations, including unique financial allocation constraints and PSGC address normalization.
- Rider moderation and the voucher, announcement, shipment-item, checkout-attempt, and attachment migrations listed above.

The review checked that shipment history and dependent rows retain their existing foreign-key paths through parcel scans, shipment events, pickup requests, rider assignments, transfer legs/manifests, financial allocations, COD collections, payments, commission transactions, and seller settlements.

## 3. Legacy Fixtures Tested

The prepared shipment migration was applied to a disposable MySQL database with legacy-shaped data. All cases passed:

| Case | Fixture | Result |
|---|---|---|
| L1 | One order item and one legacy shipment | Linked deterministically to that item. |
| L2 | Two order items and one legacy shipment | Left unresolved (`order_item_id = NULL`); no item was guessed. |
| L3 | Three items and one shipment with scan, event, pickup, assignment, transfer, finance, and COD history | Shipment and historical relationships remained intact; ambiguous item link stayed unresolved. |
| L4 | Delivered/completed historical shipment | Tracking and lifecycle state remained unchanged; no replacement shipment was created. |
| L5 | `READY_FOR_PICKUP` / `PICKED_UP` shipment | Active pickup state remained unchanged. |
| L6 | Shipment already in transfer records | Transfer relationships remained intact. |
| L7 | `ASSIGNED_TO_RIDER` / `OUT_FOR_DELIVERY` shipment | Assignment and lifecycle history remained intact. |
| L8 | Financially settled order | Finance, COD, and settlement relationships remained intact; no duplicate was created. |

No historical shipment was deleted, split, copied, or recreated. Tracking numbers remained unchanged and distinct.

## 4. Before/After Data Integrity

Counts below are from the disposable fixture immediately before and after the shipment-item migration. The checked dependent tables had no orphaned rows after migration.

| Table | Rows Before | Rows After | Orphans | Result |
|---|---:|---:|---:|---|
| `orders` | 9 | 9 | — | PASS |
| `seller_orders` | 9 | 9 | — | PASS |
| `order_items` | 12 | 12 | — | PASS |
| `shipments` | 9 | 9 | — | PASS; no historical shipment added or removed |
| `parcel_scans` | 1 | 1 | 0 | PASS |
| `shipment_events` | 2 | 2 | 0 | PASS |
| `pickup_requests` | 3 | 3 | 0 | PASS |
| `rider_assignments` | 2 | 2 | 0 | PASS |
| `shipment_transfer_legs` | 2 | 2 | 0 | PASS |
| `transfer_manifests` | 2 | 2 | — | PASS |
| `transfer_manifest_items` | 2 | 2 | 0 | PASS |
| `shipment_financial_allocations` | 2 | 2 | 0 | PASS |
| `cod_collections` | 2 | 2 | 0 | PASS |
| `payments` | 1 | 1 | — | PASS |
| `commission_transactions` | 2 | 2 | — | PASS |
| `seller_settlements` | 1 | 1 | — | PASS |
| `financial_ledger_entries` | 1 | 1 | — | PASS |

The new item-to-shipment foreign key and unique constraint were also exercised by real checkout data: each distinct order item received one shipment, and quantity greater than one did not create extra shipments.

## 5. Ambiguous Legacy Shipment Handling

For a legacy seller order with multiple order items and one shipment, the migration preserves the original shipment and all associated history, leaving `order_item_id` nullable and unresolved. It does not attach the parcel to the first or lowest-ID item, duplicate the parcel, or copy its tracking history.

The fulfillment workflow now refuses item-level label creation and handover when it cannot prove the mapping, and it reports a reconciliation error rather than guessing. Seller order details show the preserved legacy tracking/status and explain that reconciliation is needed. The general legacy waybill remains available and describes combined contents; the item-specific waybill returns a conflict until the relationship is resolved. Page and compatibility checks passed without a false product association or HTTP 500.

## 6. MySQL Checkout Concurrency

| Workers | Stock | Results |
|---|---:|---|
| Two separate buyers racing for one unit | 1 | One checkout succeeded; the other received a controlled inventory validation error. |

The winning checkout persisted exactly one order, seller order, order item, shipment, payment, COD collection, and checkout attempt. Final stock was `0`; it never went negative. An initial synchronized run exposed InnoDB error `1213` escaping the transaction. Checkout now retries transactional deadlocks/lock conflicts up to three times and converts an exhausted `1205`/`1213` or SQLSTATE `40001` into a controlled validation response. The final synchronized MySQL test passed.

## 7. Idempotency Concurrency

Two concurrent requests from the same buyer with the same key both resolved to the same logical order. There was one checkout attempt, one order and one set of seller-order, order-item, shipment, payment, and COD effects; stock was decremented once. A second buyer reusing that key was rejected before order or stock writes.

## 8. Parcel Cardinality

The disposable MySQL checkout used two different products, quantity two each, from the same seller:

```text
1 Order
└── 1 SellerOrder
    ├── OrderItem A (Product A, quantity 2) ── Shipment A (unique tracking number)
    └── OrderItem B (Product B, quantity 2) ── Shipment B (unique tracking number)
```

There were two shipments, not four. Each item-specific waybill contained only its matching product. The checkout created one payment and two COD records, consistent with the tested per-shipment COD model.

## 9. Marketing Migration Compatibility

The migrations were tested against pre-existing seller voucher and announcement fixtures on disposable MySQL.

- Existing seller voucher retained its seller foreign key, ID, and code. A platform voucher inserted without a seller. The platform key uniqueness constraint rejected a duplicate, while existing seller-code uniqueness remained. Result: two vouchers, one announcement.
- Existing announcement received audience `ALL`; the audience/active/published index had all three columns.
- No platform discount funding or settlement behavior was invented.

## 10. Page Health

The prepared-schema health checks returned no HTTP 500s. Coverage included Buyer rewards, cart, checkout, orders, and tracking; Seller orders, logistics, waybill, and marketing; Rider pickup, transfer, delivery, trip, and history views; Logistics pickup, parcel, sorting, assignment, transfer, local trip, and tracking views; and Admin marketing, announcements, and messages. Legacy seller order and combined-waybill rendering also passed.

Routing behavior was exercised on disposable fixtures: one authorized Pagsanjan center and one authorized Pila center resolved correctly; no matching Pagsanjan center returned a controlled validation failure; and two eligible centers for either municipality failed closed as ambiguous. No `first()` fallback was introduced.

## 11. Tests

All rows below used the disposable MySQL database. Some suites were rerun after the final source changes, so these are execution results rather than a deduplicated count of unique tests.

| Suite | Database | Tests | Assertions | Result |
|---|---|---:|---:|---|
| 19 requested feature/unit suites plus legacy compatibility and MySQL concurrency suites | Disposable MySQL 8.4.11 | 88 | 551 | PASS |
| `PreparedMigrationPageHealthTest` and updated `LegacyShipmentCompatibilityTest` rerun | Disposable MySQL 8.4.11 | 3 | 35 | PASS |
| `MySqlCheckoutConcurrencyTest`, including item-specific waybill checks | Disposable MySQL 8.4.11 | 1 | 51 | PASS |
| `PsgcLogisticsRoutingServiceTest`, including no matching center case | Disposable MySQL 8.4.11 | 4 | 6 | PASS |
| `MapboxComponentTest` | Disposable MySQL 8.4.11 | 7 | 42 | PASS |

The required suites included checkout flow/grouping, seller note/protection, end-to-end shipment lifecycle, shipment workflow guards, seller and rider pickup, PSGC routing, transfer manifests/capacity, local-trip capacity, barcode scanning, final-mile dispatch, realtime tracking, buyer tracking details, delivered-order confirmation, waybill barcode, and notification privacy. The new MySQL concurrency, legacy compatibility, and prepared-schema page-health tests also passed.

The L1–L8 forward migration and row-count checks were run through a disposable MySQL migration-fixture harness rather than a separate `RefreshDatabase` PHPUnit class; those checks provide the requested legacy and order-item shipment migration coverage.

## 12. Static Checks

| Check | Result |
|---|---|
| `php -l` on changed PHP files | PASS — 96 files |
| `node --check resources/js/shared/mapbox.js` | PASS |
| `php artisan route:list --except-vendor` | PASS — 425 route output lines |
| `composer validate --no-check-publish` | Valid; warnings remain for exact constraints on `endroid/qr-code` (`5.1`) and `picqer/php-barcode-generator` (`3.2`) |
| `php artisan view:cache` | PASS using an isolated temporary compiled-view directory |
| `git diff --check` | PASS; Git emitted line-ending normalization notices for several existing Blade files |

## 13. Files Modified During This Review

Review-specific code and test changes:

- `app/Services/Marketplace/CheckoutService.php` — deadlock retry and controlled exhausted-lock handling.
- `app/Services/Fulfillment/ShipmentWorkflowService.php` — fail-closed handling for unresolved legacy item/shipment mappings.
- `app/Http/Controllers/Seller/SellerOrderController.php` and `app/Http/Controllers/Seller/SellerOperationsController.php` — legacy status and waybill compatibility handling.
- `tests/Feature/PsgcLogisticsRoutingServiceTest.php` — zero-authorized-center controlled-failure regression case.
- `resources/views/Seller/orders.blade.php` — safe unresolved legacy shipment display.
- `database/migrations/2026_10_14_000400_add_order_item_to_shipments.php` — migration clarification comment.
- `resources/js/shared/mapbox.js`, `resources/views/components/shared/mapbox.blade.php`, and `tests/Feature/MapboxComponentTest.php` — clearer routing metric loading/fallback states.
- Added `tests/Feature/LegacyShipmentCompatibilityTest.php`, `tests/Feature/MySqlCheckoutConcurrencyTest.php`, and `tests/Feature/PreparedMigrationPageHealthTest.php`.
- Added this readiness report.

The workspace contained other pre-existing user changes. They were preserved; no reset, clean, restore, or broad revert command was used. `.env` and `storage/framework/vite.hot` were left unchanged.

## 14. Database Safety

**NO MIGRATION OR QUERY WAS RUN AGAINST THE USER'S CONFIGURED LIKHAE DATABASE.**

All migrations, fixture queries, PHPUnit database operations, rollback/reapply checks, and concurrency runs targeted `likhae_migration_review_disposable_20261015`. The normal configured database `likhae` was not queried or modified. `.env` was not changed.

## 15. Deployment Verdict

**READY FOR STAGING MIGRATION**

The prepared migration path and checkout concurrency behavior passed disposable MySQL validation with representative legacy data. This is approval to proceed to a controlled staging migration review, not production readiness. Before production, plan and verify a database backup, a forward recovery strategy for the practically forward-only shipment-item migration, maintenance/deployment sequencing, and post-migration data and page checks.

## Additional UI Fix: Route Metrics

The route metric display no longer presents generic `Unavailable` for estimated time, road distance, and estimated arrival. It shows an em dash when required route inputs are missing, `Calculating…` while a valid directions request is pending, and `Retrying…` after a directions request fails; the route status explains the failure. Mapbox still needs rider GPS coordinates, destination coordinates, and a successful Directions response to calculate real values. The public token was confirmed present without exposing its value. The component tests and JavaScript syntax check passed.
