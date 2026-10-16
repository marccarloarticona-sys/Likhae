# LIKHAE END-TO-END FULFILLMENT AUDIT

**Audit date:** 2026-10-15 (Asia/Manila)  
**Scope:** Current working-tree implementation and the three project PDFs; exact scenario is Seller in Pagsanjan, Laguna → Buyer in Pila, Laguna.  
**Overall verdict:** **CRITICAL**

## OVERALL VERDICT

A real order is **not yet proven safe to travel through the exact Pagsanjan → Pila flow**. The most direct blocker is that inter-hub manifest capacity requires positive weight and volume for every parcel, while the checkout and parcel-generation paths leave both fields empty and no measurement screen or write path was found. The manifest cannot be assigned/sealed for a real parcel through the inspected workflow.

There is also a code-level checkout race: concurrent submissions can create two orders from the same already-loaded cart items because the transaction locks inventory but does not lock/re-read/claim the cart lines or enforce a checkout idempotency key. That is a serious duplicate-order/data-integrity risk. It was found by code tracing, not dynamically reproduced.

Other important gaps: the regular final-mile assignment form creates one assignment without attaching it to a local trip, while a delivery rider cannot start until the assignment is loaded onto an active trip; the transfer UI permits release before driver acceptance, after which acceptance is disallowed; and the API receipt endpoint marks the order completed before calling a service that requires it still be processing. Source code contains a migration for per-order-item parcels, but it is untracked in the current workspace and the live schema was not inspected, so deployed per-line parcel support is unverified.

The three PDFs establish the buyer → seller → pickup → sorting center → delivery → buyer-confirmation flow. They do not define Pagsanjan/Pila routing records, vehicle-capacity data capture, or the expanded inter-hub transfer details. Those are assessed against the explicit scenario in the request and the current code.

## AUDIT BOUNDARIES

- This was a read-only code/data audit. No application code, database, migration, or seed was changed or run. This report is a new workspace artifact; the existing `LIKHAE-FULL-IMPLEMENTATION-AUDIT-2026-10-14.md` was left untouched.
- The workspace had pre-existing modified and untracked files. Findings describe the **current working tree**, not a clean commit or a deployed release. In particular, `database/migrations/2026_10_14_000400_add_order_item_to_shipments.php` is present but untracked.
- `.env` points at MySQL database `likhae`; `phpunit.xml` overrides the test database to MySQL database `likhae_test`. `.env.testing` is absent. The separate name is not enough to prove the test database is disposable or safe to reset, so no test using `RefreshDatabase`, `DatabaseMigrations`, or comparable DB-reset behavior was run. No live database query was made; deployed migration state and actual Pagsanjan/Pila center/service-area records remain unverified.
- The 3 PDFs were inspected as functional references. No assumptions were made that the sample center records or expanded vehicle/measurement requirements exist in production data.

## SCORECARD

| Area | Result | Evidence | Missing / risk |
|---|---|---|---|
| Buyer Checkout | MODERATE | Buyer route/middleware, cart ownership and selected-item scoping, buyer-owned address validation, server-side pricing, COD validation, stock recheck under locks, transaction | Concurrent replay can create duplicate orders; no idempotency/locked cart-line claim |
| Order Creation | PASS | `CheckoutService::placeOrder` creates order, address snapshot, COD payment, seller-order groups, line items, shipment(s), events and stock changes in one transaction | Actual DB constraints/migrations not inspected |
| Seller Preparation | PASS | Seller order operations transition through confirm/prepare/ready; shipment waybills are persisted per shipment | No live end-to-end run because DB safety is unproven |
| Multiple Product Parcels | BLOCKED | Current checkout and `ensureItemShipments` create one parcel per `OrderItem`; per-shipment waybill/tracking is real | Base migration has one-shipment-per-seller-order unique key; replacement migration is untracked and live schema unknown |
| Seller-to-Logistics Routing | BLOCKED | `PsgcLogisticsRoutingService::resolve` uses seller business address and buyer address PSGC codes, requiring active approved center/coverage | Actual Pagsanjan/Pila center/address/service-area rows were not read; ambiguous fallback defaults on |
| Seller Pickup Request | PASS | `SellerPickupBatchService::create` checks ready state, seller/center/address, rider eligibility and pending requests | Feature tests not safely run |
| Multi-Parcel Pickup | PASS | Batch supports multiple parcels, up to min(10, approved capacity); one seller, center and pickup area; scans each parcel independently | 2/3/capacity boundary tests exist in feature suite but were not executed |
| Pagsanjan Hub Receipt | BLOCKED | Center-scoped receive path checks tracking/state and advances each parcel to `AT_SORTING_CENTER` | Exact Pagsanjan center mapping and persisted rows not verified |
| Sorting | PASS | Sorting is based on service-area/PSGC codes; wrong area/state rejected; cross-hub parcels await transfer | Exact scenario data not verified |
| Pagsanjan → Pila Transfer | FAIL | Transfer manifest/leg flow and 4-wheel vehicle classification exist | Shipment weights/volumes never get populated; normal manifest path therefore cannot satisfy capacity validation |
| 4-Wheel Transfer Capacity | FAIL | `TransferCapacityService::assertCapacity` enforces van/closed van/truck, parcel, weight and volume limits | Parcel measurements have no capture/write workflow; UI also allows release before driver acceptance |
| Pila Hub Receipt | MODERATE | Destination center ownership is checked; each manifest item can be recorded and custody moves to destination | Per-item receipt is permitted while manifest is still `IN_TRANSIT`; transfer arrival action/state does not gate it |
| Final Sorting | PASS | Local service area and rider eligibility use exact province/municipality/barangay PSGC | Exact Pila barangay area/rider rows not verified |
| Final-Mile Assignment | FAIL | Backend can create a delivery assignment and trip service can group/load it | Standard assignment page does not pass a trip ID; rider start requires a loaded active trip, so the normal path can strand the assignment |
| Multi-Parcel Delivery | MODERATE | Explicit local trips support capacity-limited grouping, up to the configured hard limit of 10, with same exact barangay | Grouping is a separate/manual workflow; cross-barangay batching is rejected |
| Delivered | PASS | Only assigned active rider, in-progress assignment and `OUT_FOR_DELIVERY`; proof required; delivery attempt/event and COD are recorded | DB-backed lifecycle not executed |
| Buyer Confirmation | MODERATE | Web path checks buyer ownership and all parcels delivered before completing parent and seller orders | `WorkflowApiController::confirmReceipt` updates to `COMPLETED` before a service requiring `PROCESSING`, so API call rolls back/fails |
| Failed Delivery | PASS | Failure reason validated by controller; attempt and reason recorded; failed/retry/return states supported | Delivery failure integration tests not run |
| Tracking | PASS | Unique persisted tracking per shipment; scoped live tracking/proof access; lifecycle events emitted | Pagsanjan → Pila live status cannot be demonstrated without the DB-backed transfer path |
| Notifications | MODERATE | Status observers notify buyer/seller/current logistics owner; assignment notifications exist | Transfer departure/arrival scans do not themselves update shipment-facing status until hub receipt |
| Authorization | PASS | Buyer/order, seller ownership, rider assignment, and center ownership checks appear in controllers/services | Transfer driver assignment checks rider profile status but not rider user active status at assignment time; later steps reject inactive users |
| Data Integrity | CRITICAL | Checkout transaction/stock locks and financial idempotency exist | Concurrent checkout replay can duplicate order; deployed per-item schema unknown |
| Tests | BLOCKED | 30 pure/non-reset tests safely run; 29 passed, 1 errored | DB-backed fulfillment/checkout/transfer tests not run; no end-to-end Pagsanjan/Pila test found |

## ACTUAL FLOW DISCOVERED

```text
Buyer cart selection
  → CheckoutController / CartService
  → CheckoutService::placeOrder (transaction, COD, address snapshot)
  → Order
  → one SellerOrder per seller
  → one OrderItem per selected cart line
  → one Shipment per OrderItem in the current code path
  → one persisted tracking number; waybill when seller marks ready
  → PickupRequest(s) / SellerPickupBatchService batch
  → pickup RiderLocalTrip + per-shipment RiderAssignment/scan
  → origin Logistics center receives each parcel
  → ShipmentWorkflowService sorting and transfer leg
  → TransferManifest / TransferManifestItem / inter-hub driver
  → destination center receives each manifest item
  → destination sorting
  → delivery RiderAssignment
  → explicit RiderLocalTrip load/start
  → delivery attempt, proof, COD, DELIVERED
  → buyer confirms all parcels; order/seller orders/shipments COMPLETED
```

This is the code’s intended relationship after the order-item migration: `Order → SellerOrder → OrderItem → Shipment → Waybill`, with each shipment independently scannable. The original shipping migration instead enforces `Shipment.seller_order_id UNIQUE`; the current untracked migration removes that uniqueness and adds a unique nullable `Shipment.order_item_id`. Until the latter is applied in the target database, the source model and the actual schema may disagree.

## EXPECTED VS ACTUAL

| Stage | Expected | Actual / result | File and method | Missing / risk |
|---|---|---|---|---|
| Buyer checkout | Buyer’s own cart, valid product/variant/seller, authoritative total, valid address/payment | Ownership and address scoping are present; variant/product and active seller/stock are rechecked under locks; total is recomputed from locked records; payment is COD only; transaction creates order rows and decrements stock. **MODERATE** | `CheckoutController`; `CartService::selectedItems`; `CheckoutService::placeOrder` | No idempotency key/cart-line claim. Two concurrent submissions can both use stale loaded cart items and create two orders if stock remains sufficient. |
| Seller order grouping | Split selected cart lines into seller-owned orders | Lines group by seller profile; parent order and per-seller totals are created transactionally. **PASS** | `CheckoutService::placeOrder` | Live DB behavior not run. |
| Order address and payment | Snapshot delivery address; record validated payment | Full address fields/PSGC/coordinates copied to `OrderAddress`; creates pending COD `Payment`; rejects non-COD methods. **PASS** | `CheckoutService::placeOrder` | New online payment is intentionally unsupported by current code. |
| Product line → parcel | One real parcel/tracking/waybill per distinct line; quantity stays in that parcel | Checkout calls `createShipmentForOrderItem`; `ensureItemShipments` backfills/safely splits eligible legacy shipments; `order_item_id` unique in the untracked migration; waybill/tracking are persisted per shipment. **BLOCKED on deployed schema** | `CheckoutService::createShipmentForOrderItem`; `ShipmentWorkflowService::ensureItemShipments`; `Shipment`; `Waybill`; `Seller/waybills` | Base migration has unique `seller_order_id`; no live migration state checked. Qty 2 remains one line/one parcel, as requested. |
| Seller pickup routing | Pagsanjan Logistics chosen from the seller’s actual business pickup location | Resolver reads `SellerProfile::businessAddress`, compares province and municipality PSGC to active approved center addresses, then validates exact barangay coverage. **BLOCKED for actual Pagsanjan data** | `PsgcLogisticsRoutingService::resolve`; `uniqueCenterForMunicipality` | Center records are not known. If multiple centers match, default config allows a coverage-based first match by ID and logs a warning; it does not fail closed. Set `LIKHAE_ALLOW_AMBIGUOUS_ROUTING=false` or remove fallback after data cleanup. |
| Buyer destination routing | Pila Logistics determined from buyer address snapshot/canonical codes | Resolver uses buyer address PSGC codes, validates barangay belongs to municipality, and requires matching active center and area. Center ids are persisted on shipment. **BLOCKED for actual Pila data** | `PsgcLogisticsRoutingService::resolve`; checkout route preview | Cannot assert which center will be selected without active center/service-area rows and canonical buyer/seller addresses. |
| Seller preparation/waybill | Seller confirms, prepares, packs, labels, then marks ready | Shipment readiness transition generates/persists a waybill and uses a per-shipment barcode/tracking number; label view prints actual persisted parcel records. **PASS** | `ShipmentWorkflowService::readyForPickup`; seller operations/waybill controller and views | No dynamic DB-backed run. |
| Multi-parcel pickup | 2+ ready parcels from same seller/pickup location to qualified rider within capacity | Batch service limits 1–10 and approved rider capacity, same seller/center/pickup PSGC, active local vehicle/rider, no duplicate active pickup; each tracking scan is checked separately. **PASS by code inspection** | `SellerPickupBatchService::create`, `accept`, `scan`, finish/center receive; `DispatchController` | Requested boundary and 2/3 parcel feature cases were not executed due DB safety. |
| Origin center receipt | Only correct center receives exact picked-up parcel and records separate scan/event | Center scope, exact tracking, `PICKED_UP` state, scan/event and `AT_SORTING_CENTER` transition; duplicate center receipt is idempotent. **PASS by code inspection** | `ShipmentWorkflowService::receiveAtCenter` | Cannot prove actual Pagsanjan-specific routing/data. |
| Origin sorting | Read destination codes, sort into matching area, detect transfer | Only sorting-eligible parcels are advanced; exact destination area/codes are enforced; cross-center parcel gets transfer leg and remains unavailable to final-mile assignment. **PASS by code inspection** | `ShipmentWorkflowService::sortShipment`; `ShipmentTransferService` | No Pagsanjan/Pila integration test/data. |
| Inter-hub vehicle/capacity | Group parcels, assign active 4-wheel driver, validate count/weight/volume, release/depart | Van/closed van/truck and approved count/weight/volume checks exist. **FAIL** | `TransferCapacityService::assertCapacity`; `TransferManifestService::assignDriver`, `seal` | Shipment parcel weight/volume are nullable and no inspected form/controller/service writes them. `createShipmentForOrderItem`/legacy `createItemParcel` do not populate them; capacity validation rejects missing/non-positive measurements. No fake values should be added. |
| Transfer release/depart | Accepted driver transports parcels with validated custody events | Correct sequence can work if driver accepts while sealed, then logistics releases, then driver starts. However logistics release is allowed before acceptance, after which driver acceptance is forbidden; logistics `depart` path calls transfer scan logic requiring a rider actor and fails for logistics actor. **MODERATE** | `TransferManifestService::release`, `driverAccept`, `driverStart`, `depart`; Logistics and Rider transfer controllers/views | Enforce acceptance before release; route departure only through authorized driver or revise actor model. Current scan calls pass stored tracking number rather than a human/device-scanned value, reducing evidence of physical scan. |
| Destination receipt | Pila receives expected parcels after manifest arrival; update custody center | Destination center ownership is checked and received shipment’s current center changes. Item-level receipt is allowed for manifest status `IN_TRANSIT`, `ARRIVED`, `PARTIAL`, or `EXCEPTION`; it can advance an item before `arrive()` records manifest arrival. **MODERATE** | `TransferManifestService::arrive`, `receiveItem`; `ShipmentTransferService::receive` | Decide whether partial physical receipt during transit is intended; otherwise require arrival/validated partial-arrival transition before recording receipt. |
| Pila final sorting / trip grouping | Sort by buyer PSGC area; batch multiple parcels within rider capacity | Service-area exact codes and local capacity checks support same-area (same province/municipality/barangay) trip items; max is min(approved capacity, 10). Different barangays are rejected even in same municipality. **PASS same barangay / MODERATE across barangays** | `LocalRiderTripService::createTrip`, `addAssignment`; `LocalRiderEligibilityService` | Policy in PDFs does not clarify whether one rider may combine adjacent barangays. Current code uses one exact barangay per trip. |
| Final-mile dispatch/start | Assign Pila area rider, load multiple parcels, start delivery | Assignment page posts only rider and `DELIVERY`, no `local_trip_id`; controller creates the assignment. Rider start checks `assertAssignmentMayStart`, which requires an active loaded local trip item. Separate local-trip screens can manually attach/load assignments. **FAIL for normal dispatch path** | `Logistics/assignments/index.blade.php`; `DispatchController::assign`; `LocalRiderTripService::assertAssignmentMayStart` | Integrate dispatch with trip creation/selection and capacity, or make the existing separate workflow explicit and complete in the dispatch UI. |
| Out for delivery / delivered | Assigned rider accepts, starts from destination hub, delivers with parcel proof | Service validates active assigned rider, assignment state and shipment state; delivery requires proof, records attempt, marks delivered, records COD and event. **PASS by code inspection** | `ShipmentWorkflowService` assignment start/complete methods; `CodCollectionService::recordSuccessfulCollection` | No DB-backed end-to-end delivery run. |
| Buyer confirmation | Buyer confirms only after every parcel delivered; then whole order completes | Web action checks buyer ownership, all seller orders/parcels, delivered states, active disputes; updates all rows idempotently. API pre-sets order to `COMPLETED`, then calls service requiring `PROCESSING`, so transaction aborts. **MODERATE** | `BuyerOrderActionService::state`, `completeReceipt`; `WorkflowApiController::confirmReceipt` | Remove premature API update or make completion service accept a locked, validated transition. |
| Failed delivery/return | Reason, attempt, reschedule/reassign or return; failed shipment cannot complete | Controller requires reason; service records attempt and failure state, closes assignment except reschedule, and allows later retry/reassignment; returned state supported. **PASS by code inspection** | `ShipmentWorkflowService::failDelivery`; rider delivery controller | Notifications/tracking on live failure route not exercised. |

## PASS FINDINGS

- Checkout derives product prices, discounts and shipping server-side; checks variant belongs to the product when adding to cart; revalidates active product/seller/variant and stock under row locks; snapshots the buyer address; and wraps order creation/stock decrement in a transaction.
- Order lines are grouped by seller. Current checkout logic creates one persisted shipment per `OrderItem`, not one shipment per unit of quantity. Tracking and waybill records attach to actual shipment rows, not display-only fake labels.
- Pickup batches enforce same seller, origin center, pickup area, rider eligibility, approved capacity and a hard limit of 10. The rider’s item scans are checked against the batch and exact tracking code. Receive-at-center is performed per parcel.
- Routing and sorting use canonical PSGC codes; names are descriptive snapshots. Active/approved center and service-area checks are present, as are center and rider ownership guards.
- Delivery requires the assigned active rider and a valid `OUT_FOR_DELIVERY` transition; proof and a delivery attempt are recorded. COD is per shipment. Financial settlement is per SellerOrder and waits until every shipment for that seller order is delivered/completed, avoiding premature seller settlement for a partial multi-parcel delivery.
- `BuyerOrderActionService`’s web confirmation blocks early confirmation and completes parent order, seller orders, and shipments only after all parcels are delivered. Multi-seller completion does not happen on the first delivered parcel.

## MODERATE FINDINGS

1. **Ambiguous center fallback is enabled by default.** `config/likhae.php` defaults `LIKHAE_ALLOW_AMBIGUOUS_ROUTING` to true. If multiple active approved centers match a municipality, `uniqueCenterForMunicipality` picks the first candidate with a single exact barangay coverage, ordered by id, and logs a warning. This is deterministic rather than random, but it silently resolves ambiguous data rather than returning a routing error.
2. **Final-mile trip grouping is separate from ordinary dispatch.** The code supports a multi-item trip, but the regular assignment page does not attach a trip. The rider’s start guard requires the assignment to be loaded on a trip. This can leave a valid assignment unable to start until an operator completes the separate trip workflow.
3. **One local trip must use one exact barangay.** Several buyers in San Miguel, Pila can share a trip, capacity permitting. A different barangay in Pila cannot be added to that trip. The PDFs do not state whether adjacent barangays may be combined, so confirm this as a business rule before broadening it.
4. **Transfer manifest ordering is unsafe to operator error.** Driver acceptance only works while status is `SEALED`; release changes it to `RELEASED`. If operations releases first, the assigned driver can no longer accept and `driverStart` rejects due missing acceptance. The Logistics depart button’s service path also tries to perform rider-only transfer operations as the logistics actor. The rider-start route is the workable path if acceptance happened in time.
5. **Per-item destination receive can precede manifest arrival.** `receiveItem` accepts an `IN_TRANSIT` manifest. This may be intentional for partial physical arrival, but the API does not require a distinct destination-arrival event first.
6. **API buyer confirmation fails while the web path works.** This affects clients using the workflow API, not the web confirmation route.
7. **Transfer status is not surfaced to buyer/seller as each leg moves.** Transfer scans update transfer records; the shipment-facing state remains sorting/transfer-pending until destination receipt. Users may not see each custody movement in shipment tracking.

## FAIL FINDINGS

1. **Pagsanjan → Pila inter-hub parcels cannot pass the configured capacity gate with current parcel data.** The capacity service rejects missing or non-positive `weight_kg` and `volume_liters`. Shipment fields exist but are nullable. Search of current application code found reads/columns and no operational measurement UI, validation, setter, or population from package/product data. `CheckoutService::createShipmentForOrderItem` and the legacy parcel creation path do not set them. The exact missing workflow is a seller/operations parcel measurement capture and persisted update on each real shipment before transfer assignment/seal (or a documented, trusted source that fills those fields). Do not use invented/default values.
2. **Regular final-mile dispatch does not produce a startable delivery assignment.** The normal UI creates an assignment without a local trip id. The service requires a delivery assignment to be scanned onto an active READY/IN_PROGRESS local trip before departure. A secondary trip workflow exists, but the standard assignment action does not take the parcel through it.

## CRITICAL FINDINGS

1. **Concurrent checkout replay can create duplicate orders.** `CheckoutController` loads selected cart items before `CheckoutService::placeOrder`. The transaction locks inventory/product/seller rows and rechecks stock, but it uses the supplied in-memory item collection; it does not re-query/lock the selected cart lines, check that they still belong to the active cart, claim the cart, or require a unique idempotency token. Under two concurrent requests with sufficient stock, both can create an order from the same cart selection; after the first deletes the cart rows, the second can still proceed using its stale collection. This can create duplicate COD orders, seller orders, parcels, stock decrements and financial expectations. Static code analysis only; not reproduced with a live DB test.

No critical authorization bypass was identified in the paths inspected. This does not certify every application route or deployed policy.

## SCHEMA LIMITATIONS

| Current table / schema | Current relationship | Missing support / limitation | Required change | Database migration needed |
|---|---|---|---|---|
| `shipments` base migration | `seller_order_id` is unique: one shipment per SellerOrder | Does not permit one persisted parcel per distinct order line | Apply and verify the current migration adding nullable unique `order_item_id` and dropping the seller-order unique constraint; reconcile multi-line legacy shipments only where history can be safely split | **YES** if not applied in target DB. Migration file currently exists untracked; no migration was run |
| `shipments` routing-fields migration | Nullable `parcel_weight_kg`, `parcel_volume_liters` | Fields exist in source schema but are not captured/populated | Add validated capture and persistence for actual packed-parcel weight/volume before transfer capacity evaluation | **NO** if these columns exist in target DB; **BLOCKED** until migration state is checked |
| checkout/cart rows | Selected cart rows are consumed after order creation; no checkout claim/idempotency token is visible in this path | Concurrent duplicate submit is not idempotent | Add atomic cart-line/order claim or unique checkout attempt/idempotency key and revalidate selected rows under transaction | **LIKELY YES** if durable idempotency/claim needs a unique key; design can vary |
| transfer manifests/items | Manifest state plus per-item receipt state | Items may be received while overall manifest remains `IN_TRANSIT` | If policy requires whole-manifest arrival first, constrain allowed states or add an explicit partial-arrival transition | **NO** for a service-only state guard; **POSSIBLE** if new event/state is required |
| local rider trips/items | Trip + trip item relationships support grouped assignment and capacity | Existing schema supports manual grouping, but normal dispatch does not create/attach trip | Wire dispatch UI/controller to existing schema/service; no schema change evident for same-barangay batching | **NO** |
| financial allocation/ledger | Per-shipment COD; one unique financial allocation/settlement per SellerOrder after all shipments are delivered | Aggregate seller-order settlement is intentional; not a parcel split defect | Preserve aggregate settlement while testing per-shipment COD and final allocation linkage | **NO** indicated by inspected source |

## TEST RESULTS

Tests were run only where test classes had no Laravel database-reset trait and were suitable for non-DB execution.

| Test group | Result | Notes |
|---|---|---|
| `TransferCapacityServiceTest`, `RiderVehicleTypeTest`, `LocalTripCapacityRulesTest` | **PASSED** — 12 tests, 21 assertions | Pure rule tests, no DB reset |
| `BuyerCheckoutFlowTest`, `TrackingRealtimeTest`, `WaybillBarcodeComponentTest`, `AddressCoordinateValidationTest`, `LocalRiderEligibilityServiceTest`, `FinancialRulesTest` | **FAILED** — 18 tests; 17 passed, 1 errored | `LocalRiderEligibilityServiceTest::test_active_local_rider_can_cover_one_barangay_owned_by_the_center` throws because its service area is not exactly one canonical barangay in the center’s canonical municipality. The fixture uses synthetic address codes; this does not prove real Laguna routing data fails. |
| All safely executed pure tests | **29 passed / 1 errored, 30 total** | No database-backed fulfillment claim is made from these results. |
| Database-backed checkout/fulfillment/transfer feature tests | **NOT EXECUTED — DATABASE SAFETY** | MySQL test DB is named `likhae_test`, but its disposable status is unproven; `.env.testing` is absent. Tests using `RefreshDatabase`/`DatabaseMigrations` were not run. |

`BuyerCheckoutFlowTest` was executed as a non-reset test, but it does not prove transactionally creating a real order with shipment rows. Relevant database-backed tests inspected but not executed include `CheckoutAddressSnapshotTest`, `CheckoutSellerNoteAndProtectionTest`, `SellerPickupBatchTest`, `ShipmentWorkflowGuardTest`, `LogisticsCenterAuthorizationTest`, `EndToEndShipmentLifecycleTest`, and related delivery/receipt tests.

## MISSING TESTS

- A DB-isolated checkout integration test that proves ownership, price/stock revalidation, seller grouping, address snapshot, rollback, and one persisted shipment/tracking/waybill per distinct order line (while quantity 2 remains one parcel).
- A concurrent duplicate-checkout/idempotency test proving one cart selection creates at most one order.
- Canonical Pagsanjan seller and Pila buyer PSGC routing test with active center/service-area fixtures; zero-center and multiple-center cases, including the ambiguous-routing default.
- Pickup batch integration cases for 2 and 3 parcels, approved-capacity boundary and +1 rejection, mixed seller/center/address rejection, exact tracking scans and duplicate receive.
- End-to-end multi-parcel origin receive/sort → transfer manifest → destination receive/sort test across two distinct centers.
- Transfer tests for missing/positive/excess weight and volume, van vs motorcycle rejection, driver acceptance/release ordering, logistics departure authorization, per-item receive vs manifest arrival, and capacity boundary.
- Final-mile dispatch integration test through the actual assignment UI/controller, trip association, capacity loading, rider start, delivery proof, COD and buyer tracking. Include same-barangay multiple buyers and define/test cross-barangay policy.
- Web and API buyer receipt tests on a multi-seller/multi-parcel order, including premature confirmation, partial failure, active dispute and idempotent repeat.
- Delivery failure/reschedule/reassignment/return tests that also verify buyer/seller notifications and no accidental order completion.

The inspected `EndToEndShipmentLifecycleTest` setup appears stale for the current per-item shipment path: it creates a seller order without order items before invoking shipment transitions, while `ensureItemShipments` requires at least one product line. This was not executed because the test uses database reset behavior; treat it as a test-fixture issue to verify in a safe test database.

## RECOMMENDED FIX ORDER

### P0 — Security / data corruption

1. Make checkout atomic and idempotent: claim/re-read selected cart rows inside the transaction, reject rows no longer in the buyer’s active cart, and prevent the same checkout attempt from creating a second order. Add a concurrency regression test in a proven disposable DB.
2. Confirm production schema state before enabling the per-order-item path. Safely plan/apply the existing migration in a controlled deployment after reviewing legacy shipment reconciliation; do not apply it as part of this audit.

### P1 — Core fulfillment blockers

1. Add a real per-parcel packed weight/volume capture and validated persistence step; require measurements before manifest assignment/seal. Do not estimate them silently.
2. Complete the ordinary final-mile dispatch path by associating assignments with a delivery trip and enforcing trip capacity at assignment/load time; make the assignment page carry the parcel through loading/start.
3. Correct the transfer manifest workflow: require driver acceptance before release; make departure actor ownership consistent; do not pass stored tracking values as if they were physical scans.
4. Repair `WorkflowApiController::confirmReceipt` ordering so API receipt follows the same validated completion transition as the web path.
5. Build an isolated integration suite for the exact multi-parcel Pagsanjan → Pila route and its independent custody events.

### P2 — Moderate functional issues

1. Turn ambiguous routing off by default and require one unique active center, unless an explicit, reviewed deterministic fallback is a documented product decision.
2. Decide whether destination item receipt can occur before manifest arrival; implement/test the chosen partial-arrival rule.
3. Decide if a local rider may carry parcels across barangays in the same municipality; update trip grouping and tests if the intended rule allows it.
4. Surface transfer movement to buyer/seller tracking without exposing unrelated parcel data.

### P3 — UX / test improvements

1. Add operator feedback for missing parcel dimensions and driver acceptance sequence.
2. Fix synthetic PSGC fixture data in `LocalRiderEligibilityServiceTest` so the test uses a canonical municipality/barangay record.
3. Update the stale end-to-end fixture to create at least one order item and exercise the current shipment relationship.

## FINAL ANSWER TO THE MAIN QUESTION

**Can a real LIKHAE order safely travel through the exact Pila buyer → Pagsanjan seller → Pagsanjan Logistics → multiple parcel pickup → 4-wheel transfer → Pila Logistics → final-mile delivery → buyer-confirmation flow?**

**Not yet, based on the inspected implementation.** Checkout and much of parcel, pickup, sorting, delivery, COD, tracking and web receipt behavior are represented in code. However, real per-line database support is unverified in the deployed schema; the transfer capacity service has no source of parcel weight/volume and will reject the manifest; the normal final-mile dispatch path omits a required trip load; and API confirmation fails. Actual Pagsanjan/Pila center records and migration state were not queried, so the exact physical route cannot be proven from this workspace audit.
