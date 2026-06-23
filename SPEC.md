# Spec — The Absence Run

A short, living document. Keep it scoped and testable — it doesn't need to be long.

## Problem

`app:absence:run --date=<Y-m-d>` decides every **pending** leave request for the run
date: it counts the working days each request consumes under the leave policy
(`docs/LEAVE_POLICY.md`), approves or rejects against the employee's remaining
entitlement, updates their leave balance, and posts each decision to the HR API
exactly once — safely re-runnable.

## Rules I'm implementing

- **Process pending only.** Decide requests with status `PENDING`, in `submittedAt`
  then `id` order. Earlier decisions in the same run are visible to later ones
  (depleted balance, newly-approved periods).
- **Working-day counting (§4).** A request consumes the Mon–Fri days in
  `[startDate, endDate]`, minus public holidays for the employee's `federalState`
  (§5), minus 0.5 for each of `halfDayStart` / `halfDayEnd`.
- **Entitlement (§1–§3).** `contractualLeaveDays`, pro-rated for mid-year joiners/
  leavers by *full months employed ÷ 12* (§2), then scaled by `workingDaysPerWeek ÷ 5`
  for part-timers (§3). Round **up to the nearest half-day once, on the final figure**.
- **Carryover (§6–§7).** Add still-valid carried-over days to entitlement; if the
  **run date** is after `carryoverExpiresOn`, treat carryover as zero. On approval,
  deplete valid carryover first, then current-year entitlement.
- **Remaining balance.** `remaining = entitlement + validCarryover − usedDays`. If a
  request's consumed days exceed `remaining`, **reject in full** — never partially
  approve (§11).
- **Absence types (§8).** `VACATION` consumes the balance; `UNPAID` is recorded with
  no balance effect; `SPECIAL` is always approved and never touches the vacation
  balance; `SICK` never consumes the balance and may credit it back (§9).
- **Sick during vacation (§9).** A `SICK` request with `medicalCertificate = true`
  whose dates overlap an already-`APPROVED` `VACATION` credits back the overlapping
  working days to the vacation balance.
- **Overlap (§10).** Reject a request that overlaps any period already approved,
  including one approved earlier in the same run.
- **Cancellations (§12).** `CANCELLED` requests consume nothing and are skipped.
- **Idempotent reporting.** Post each decision with a **stable idempotency key derived
  from the request id**, so a re-run replays rather than duplicating, and persist the
  returned external reference.

## Edge cases

| Case | Decision |
|------|----------|
| Part-time employee books a full Mon–Fri week | Consumes the full Mon–Fri working-day count (literal §4); entitlement is the thing that's scaled, not the request. **Default pending Q1.** |
| Carryover lapsed at run date (Anna) | Carried-over days = 0; only current-year entitlement applies. |
| Half-day flag lands on a weekend/holiday | No 0.5 deduction — the flag only reduces a day that actually counts. |
| Two pending requests overlap (Felix) | Earlier `submittedAt` wins; the later one is rejected as overlapping. |
| Sick note overlaps approved vacation (Dilan) | Credit overlapping working days back to the balance; post the sick decision with the credited days. |
| `usedDays` treated as trusted opening balance | Run adds to / credits from it; we do **not** recompute history. **Default pending Q7.** |
| Insufficient balance by any margin | Reject the whole request. |
| Re-run of an already-decided period | Same idempotency keys → HR API replays; balances are not deducted twice. |

## Out of scope

- Recomputing opening balances from full request history — we trust seeded `usedDays`
  (pending Q7).
- Enforcing a `SPECIAL`-leave allotment cap — not modelled on `Employee` (§8, Q4).
- Six-day-week handling and federal states beyond `BY` / `BE` (only states in §5).
- The one-off Berlin 8 May 2025 holiday unless confirmed (Q11) — implement §5 verbatim.

## Test plan

- One unit-level test per rule above, using the in-memory `FakeHrApiClient`.
- **Counting:** weekend exclusion, holiday exclusion per state, half-day at start/end,
  half-day on a non-working boundary day.
- **Entitlement:** full-time, mid-year joiner (Carla), part-time (Bjarne), and the
  combined joiner+part-time rounding case.
- **Carryover:** valid carryover consumed first; lapsed carryover zeroed at run date.
- **Types:** `UNPAID`/`SPECIAL` no balance effect; `SICK` credit-back (Dilan).
- **Overlap:** same-run overlap rejects the later submission (Felix).
- **Balance:** approve within balance, reject when over by any amount (incl. 0.5).
- **Idempotency:** running twice posts each decision once and does not double-deduct.
- Keep the three existing happy-path tests green; extend, don't replace.

## Operational notes

- **Re-run safety:** decisions carry stable idempotency keys, so retries and a second
  pay-period close replay rather than duplicate. Persist the balance increment and the
  decision together so a half-applied request can't deduct without posting.
- **Partial failure:** decide whether to commit per-request (preferred — a mid-run HR
  failure leaves earlier requests done and a re-run finishes the rest) or roll back the
  whole run (Q10). Either way the idempotency key keeps a re-run correct.
- **Bad data:** a missing `LeaveBalance` for the request year currently throws; log and
  skip that request rather than aborting the whole run, surfacing it in the summary.
- **Monitoring if this ran nightly:** count of approved/rejected per run, HR API
  non-2xx responses, requests skipped for bad data, and any balance that goes negative
  (should be impossible — alert if it happens).

## Open questions

- Carried in from `QUESTIONS.md`: **Q1** (part-time consumption) and **Q7** (`usedDays`
  semantics) are the only two that change logic rather than a constant — both have
  documented defaults above and are isolated behind a single seam each. The rest
  (Q2–Q6, Q8–Q11) proceed on stated assumptions.
