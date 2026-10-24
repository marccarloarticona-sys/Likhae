# Hub Transfer Rider Payroll Proposal

## Current finding

The Logistics codebase has no durable attendance, regional wage-rate, payroll-run, or wage-payment receipt model. Hub transfer manifests and assignments track parcel custody, but they do not prove an employee's hours or authorize salary. The shipping allocation code keeps Hub Transfer Rider wages outside the buyer-funded Pickup/Delivery Rider Pool.

## Required policy inputs

Before payroll can be calculated, an authorized owner must supply the effective wage schedule for each applicable region and employer/job classification, the recognized working-time rules, and the approved evidence and approval roles. The application must not substitute a nationwide rate, infer attendance from parcels or manifests, or calculate overtime, rest-day, or holiday premiums without approved rules.

If the effective wage schedule cannot be resolved for a work date, that payroll line must stay in `POLICY_RATE_REQUIRED` and must not be approved or paid.

## Additive design for approval

1. `rider_wage_policy_versions`: region, employer classification, job classification, source/wage-order identifier, base daily wage, standard paid hours, approved premium multipliers, effective dates, approver, and immutable version metadata. Reject overlapping active dates for the same classification.
2. `rider_attendance_entries`: Logistics employer, Rider, work date, clock-in/out, approved break minutes, work location/route, recorded source, evidence reference, submitter, reviewer, and review timestamps. Manifest membership may be a reference but never the source of hours.
3. `rider_payroll_runs` and `rider_payroll_lines`: pay period, responsible Logistics company, rate-version reference, approved regular/overtime/rest-day/holiday hours, gross payable, adjustments, status, approval actors, and immutable calculation snapshot.
4. `rider_payroll_receipts`: external payment channel/reference, proof path, payer, paid amount/time, and Rider acknowledgment. Payout retries must be idempotent and each correction must create an auditable adjustment rather than edit a paid line.

## Separation from shipping funds

Payroll is a Logistics operating expense. It must post to a separate operating-expense ledger account and must never create a `HUB_TO_HUB` participant in the Rider shipping pool. Parcel count and shipping percentage are not payroll inputs.

## Approval boundary

This document is a proposal only. It adds no tables, migrations, wage values, attendance, or payment records. Implementation requires approval of the wage-policy source and payroll design, followed by separate approval to create the additive migration and another explicit approval to execute that migration on a named database.
