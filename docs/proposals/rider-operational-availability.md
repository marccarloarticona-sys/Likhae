# Rider operational availability migration proposal

## Why this needs schema approval

`rider_profiles` currently stores account approval and vehicle/capacity data, but it has no operational Online/Offline field. Notification preferences are not an operational availability source. The requested filter cannot be implemented safely by overloading either field.

## Additive migration proposed

Add to `rider_profiles`:

- `is_online` boolean, non-null, default `false`;
- `availability_updated_at` nullable timestamp;
- index on `(logistics_center_id, status, is_online)` for dispatch candidate queries.

No existing account activation or approval status changes. Existing riders start Offline until they explicitly set Online in Rider Settings.

## Follow-up code after approval

1. Add an authenticated rider-only Online/Offline switch at `/rider/settings` and record an audit timestamp.
2. Filter only *new* pickup, inter-hub driver, and final-mile assignment candidates by `is_online = true`.
3. Keep existing accepted and in-progress work visible regardless of Online state. Switching Offline must not cancel assignments or release custody.
4. Add tests for each assignment class, existing active work while Offline, and unauthorized availability updates.

## Not performed

No migration file or schema-dependent application code has been added, and no database operation has been run. Implementation is pending explicit approval of this additive schema change.
