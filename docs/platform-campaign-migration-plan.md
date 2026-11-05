# Platform campaign additive migration plan

Prepared 2026-11-05. This plan describes the repository migration only; production schema changes must be reviewed and applied through the normal deployment process.

## Existing schema reused

- `vouchers` remains the campaign record. `benefit_scope`, `platform_code_key`, schedule, limits, budget, artwork, and active flag remain authoritative.
- `buyer_voucher_claims` continues to associate a buyer with a voucher. A claim has no financial effect.
- `financial_ledger_entries` continues to record budget allocation, reservation, release, and settled expense with unique idempotency keys.
- Existing order, seller order, and order item funding snapshot columns are used for centavo allocation. No new finance table is introduced.

## Proposed columns on `vouchers`

| Column | Type/default | Purpose |
| --- | --- | --- |
| `distribution_mode` | string(16), `CODE` | `CODE`, `CLAIMABLE`, or `AUTO`; existing campaigns retain code behavior. |
| `target_mode` | string(16), `ALL` | `ALL`, `CATEGORIES`, `PRODUCTS`, or `SELLERS`. Shipping campaigns use `ALL`. |
| `target_category_ids` | nullable JSON | Selected active category and subcategory IDs. |
| `target_product_ids` | nullable JSON | Selected active product IDs. |
| `target_seller_ids` | nullable JSON | Selected active seller profile IDs. |
| `buyer_audience` | string(16), `ALL` | `ALL`, `FIRST_TIME`, or `EXISTING`. |
| `stack_with_seller` | boolean, true | Permit a product campaign alongside seller vouchers. |
| `stack_with_shipping` | boolean, true | Permit a product or shipping campaign alongside the other platform scope. |
| `campaign_status` | string(16), `ACTIVE` | Explicit `DRAFT`, `ACTIVE`, `PAUSED`, or `ENDED`; the schedule also derives `SCHEDULED` and `ENDED`. Existing inactive rows still remain paused via `is_active`. |

The migration checks for each column before adding it. It does not rewrite existing records, remove columns, or create tables. Its rollback intentionally leaves these campaign settings and financial history in place. Admin and buyer code checks schema readiness so existing routes continue to work before the migration is applied.

## Deployment sequence

1. Review the migration and back up the production database using the established operations procedure.
2. Deploy code with schema readiness guards.
3. Apply additive migrations during a controlled maintenance window. Do not run a reset or seed against production.
4. Verify an existing code campaign, a new claimable campaign, a new auto campaign, campaign budget totals, and the checkout ledger reservation/settlement path before enabling new campaigns.
5. Monitor campaign ledger reconciliation and checkout errors. Pause a campaign through Admin if reconciliation diverges.
