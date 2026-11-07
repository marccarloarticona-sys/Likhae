# Seller pickup automatic matching rollout

Automatic Seller pickup matching is enabled by default when its required schema is installed. Marking parcels Ready for Pickup no longer chooses a provider or creates a pickup request. The Seller opens Seller Logistics, chooses an authorized center, then submits the pickup request; matching checks route and Rider capacity only at that center. Set `LIKHAE_AUTOMATIC_SELLER_PICKUP_MATCHING=false` to keep the same Seller-selected center in the manual Logistics pickup queue. Checkout continues to route by the existing PSGC workflow and does not ask a Buyer to choose a center when automatic routing schema is ready.

## Before rollout

1. Back up the application database and rehearse the additive migrations against an isolated staging copy. The offer-history migration is `2026_11_07_000100_create_seller_pickup_center_offers_table.php`; the Seller-selected center field is added by `2026_11_07_000600_add_preferred_logistics_center_to_seller_pickup_requests.php`.
2. Confirm each pickup center is active and approved, and configure its exact Seller pickup barangays under Logistics → Delivery Areas. When automatic matching is enabled, managers may add exact remote PSGC service areas there. Geographic proximity alone never grants service authorization.
3. Confirm eligible centers have online local Riders with recent GPS positions and enough approved parcel capacity. Destination areas also need authorized local Rider capacity.
4. Confirm existing destination PSGC coverage or an approved, usable route can carry each Buyer destination. Cross-hub plans preserve the existing planned transfer leg, manifest, and custody flow. Missing or full routes leave the request pending with the Seller-selected center; the Seller can cancel and create a new request to select another center.
5. Road estimates use Geoapify when configured. Without it, the matcher stores a clearly labeled straight-line geographic estimate; it is not road distance or travel time.
6. Exercise checkout, Ready for Pickup, accept, decline, timeout, retry, capacity loss, transfer, and rollback in staging using an isolated test database.

The selected-center migration leaves historical requests with a null center so their records are preserved. Open legacy requests without a Seller choice are not reassigned automatically after rollout; Sellers should cancel those requests and create a new one to select a center.

The Admin **Smart Coverage Routes** and **PSGC Routing Review** pages and routes are removed from this release. Existing route rows, shipment snapshots, planned transfer legs, and custody history remain stored and readable by fulfillment services. Exact center service-area management remains available in Logistics → Delivery Areas. The removed Admin pages are not needed for direct primary coverage, but new fallback routes cannot be created or approved through those pages.

## Enable or disable

The feature defaults to `LIKHAE_AUTOMATIC_SELLER_PICKUP_MATCHING=true`. Confirm the preflight items before production rollout. For an existing deployment, set the variable to `true` and refresh cached configuration. The existing `likhae:expire-rider-assignment-offers` scheduler runs every minute to expire offers and retry pending requests at the Seller-selected center. Offers expire after 15 minutes. Declines and timeouts do not switch the request to another Logistics center.

## Roll back

Set `LIKHAE_AUTOMATIC_SELLER_PICKUP_MATCHING=false` and refresh cached configuration. The next scheduled expiry run returns open requests to `REQUESTED`, marks live offers `SUPERSEDED`, and leaves offer history intact so Logistics can use the manual pickup queue. Visiting the Logistics pickup page applies the same rollback immediately.

Do not roll back or drop the offer-history table. Existing shipments, route snapshots, manifests, custody scans, and financial records stay unchanged. Re-enable the flag only after service areas, destination paths, and capacity are ready.

## Test database safety

Feature tests using Laravel `RefreshDatabase` may recreate their configured test schema. Run them only when `DB_DATABASE` points to an isolated test database. Do not use a production database or a database containing records that need preservation.
