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

The separate homepage artwork migration adds these nullable/defaulted presentation fields to the same existing `vouchers` table:

| Column | Type/default | Purpose |
| --- | --- | --- |
| `homepage_carousel_enabled` | boolean, `false` | Explicitly include a campaign in Buyer Home's carousel. |
| `homepage_carousel_desktop_image_path` | nullable string | Desktop hero artwork; required before carousel placement can be enabled. |
| `homepage_carousel_mobile_image_path` | nullable string | Optional mobile-specific hero artwork. |
| `homepage_carousel_headline` | nullable string(150) | Optional carousel headline, falling back to campaign name. |
| `homepage_carousel_description` | nullable string(280) | Optional short slide description. |
| `homepage_carousel_cta_label` | nullable string(60) | Optional call-to-action label. |
| `homepage_carousel_cta_destination` | string(20), `EVENT` | Opens the campaign page or the platform offers list. |
| `homepage_carousel_priority` | unsigned small integer, `0` | Display order after the permanent LIKHAE hero. |

Both migrations check before adding columns. They do not rewrite existing records, remove columns, or create tables. Their rollback intentionally leaves campaign and artwork settings and financial history in place. Admin and buyer code checks schema readiness so existing routes continue to work before the migrations are applied.

## Deployment sequence

1. Review the migration and back up the production database using the established operations procedure.
2. Deploy code with schema readiness guards.
3. Apply `2026_11_05_000100_add_homepage_carousel_fields_to_vouchers.php` and `2026_11_05_000200_add_platform_campaign_controls_to_vouchers.php` during a controlled maintenance window. Do not run a reset or seed against production.
4. Verify an existing code campaign, a new claimable campaign, a new auto campaign, campaign budget totals, and the checkout ledger reservation/settlement path before enabling new campaigns.
5. Monitor campaign ledger reconciliation and checkout errors. Pause a campaign through Admin if reconciliation diverges.
