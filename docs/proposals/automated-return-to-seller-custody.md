# Automated Return to Seller custody proposal

## Current state

Delivery attempts support failed, rescheduled, and returned values, but `RETURNED` would falsely claim a completed return if set at the third failure or buyer refusal. Shipment and event statuses use an enum with no Return to Seller in progress state. Existing `ReturnCustodyService` records custody against a buyer initiated refund request, so manufacturing such a request for an undelivered refusal would incorrectly create a refund case.

The application now records a `RETURN_TO_SELLER_INITIATED` note on the existing `DELIVERY_FAILED` event for a clear buyer refusal or third failed attempt. The parcel remains `DELIVERY_FAILED`; no COD collection, successful delivery, or returned custody is recorded. A verified physical reverse handoff is still required.

## Additive schema required to complete the reverse route

Propose a shipment return case linked one-to-one to a shipment, plus custody events linked to that case. The case should track the initiation reason, return state, seller pickup snapshot, responsible origin/current hub, assigned return rider, and timestamps. Custody events should capture scan-verified rider pickup, hub intake, inter-hub handoff when needed, and seller receipt with actor, location, and tracking code. Add a `RETURN` operational assignment type or a dedicated return assignment table so existing seller pickups and final-mile deliveries remain semantically correct.

The migration must be additive and preserve existing buyer refund/return cases. No migration or schema-dependent return dispatch code has been added.

## Approval needed

Approve the additive return-case/custody schema and the dedicated return assignment approach before implementation. After approval, wire the existing transfer capacity and manifest services for any reverse inter-hub leg and set shipment status `RETURNED` only after a verified seller receipt scan.
