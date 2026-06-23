# Questions & Assumptions

Read against the brief (`README.md`), the leave policy (`docs/LEAVE_POLICY.md`), the
seeded period (`src/DataFixtures/AppFixtures.php`), and the current processor
(`src/Service/LeaveRequestProcessor.php`).

## Questions

_Things I'd want a real product owner to clarify before committing to a design._

1. **How many days does a part-time request consume?** Entitlement is scaled down
   for part-timers (§3: Bjarne's 28 → 16.8 → 17), but §4 counts every Mon–Fri in the
   range. A 3-day/week employee booking a full Mon–Fri week would then spend 5 days
   out of a 17-day budget — double-penalised. Should a part-timer's request consume
   only their contracted working days per week, or the full Mon–Fri count?

2. **When both pro-rata and part-time apply, do we round once or twice?** §2 and §3
   each say "round up to the nearest half-day." Applied together, do we round after
   each factor or only once on the final figure? The result can differ by half a day.

3. **What decision do we post for a `SICK` request, and what about sick leave that
   does _not_ overlap an approved vacation, or has no certificate?** §9 only describes
   the credit-back case. Is a plain sick request "approved" with 0 vacation days? Is a
   sick-during-vacation request without a medical certificate rejected, ignored, or
   recorded with no balance effect?

4. **`SPECIAL` leave — what `days` value do we post, and is there an allotment to
   enforce?** §8 says treat it as always approved and drawn from a separate special
   allotment, but nothing in the model holds that allotment. Do we post the counted
   working days or 0, and do we need to cap it at all?

5. **Is carryover expiry keyed to the run date or the leave date?** §6 says lapse the
   carryover if the *run date* is after the expiry. So a request for leave taken in
   February but processed in April would lose its carryover. Is that intended, or
   should carryover be judged against the dates the leave is actually taken?

6. **Does a half-day flag still deduct 0.5 when the boundary day is a weekend or
   public holiday?** If `halfDayStart` falls on a Saturday or a holiday (already a
   zero-day), do we still subtract 0.5, or is the flag a no-op there?

7. **What is the source of truth for `LeaveBalance.usedDays` at the start of a run —
   is it already net of cancellations and sick credit-backs, or do we recompute it
   from request history?** This determines whether §9 and §12 are corrections we apply
   during the run or facts already baked into the opening balance. (See the Eva and
   Dilan rows below.)

8. **Overlap scope (§10):** does a pending request get rejected only when it overlaps
   an already-*approved* period, or also when it overlaps another still-pending
   request? And do adjacent ranges that merely touch (one ends the day the next
   starts) count as overlapping?

9. **What should the idempotency key be derived from** so a re-run replays rather than
   re-posts? The README requires that re-running posts no duplicate decisions and
   deducts no days twice. Is request id alone the right key, or request id plus a
   content hash of the decision so a *changed* decision posts anew?

10. **Within a single run, are decisions committed per-request or all-or-nothing?**
    The README mentions retries and partial failures. If the HR API call for request
    #3 fails mid-run, should requests #1–#2 already be persisted (so a re-run only
    redoes #3), or should the whole run roll back?

11. **Is the §5 Berlin holiday table meant to mirror the real 2025 calendar?** If so
    it's missing **8 May 2025 (Thu)** — the one-off "Tag der Befreiung" (80th
    anniversary of the end of WWII) that Berlin enacted for 2025 only. Should I add it,
    or is the policy a deliberately simplified fixture I should implement verbatim?
    (See the data-flag below for the one seeded request this touches.)

## Assumptions I'm making (until told otherwise)

- The run only decides `PENDING` requests; `APPROVED`, `REJECTED`, and `CANCELLED`
  rows are read as context but never re-decided.
- A "working day" is Monday–Friday; weekends never count regardless of state.
- Pending requests are processed in `submittedAt` then `id` order, and decisions made
  earlier in a run (balance depletion, approved periods) are visible to later requests
  in the same run.
- The leave year is the calendar year of the request's start date, and that is the
  `LeaveBalance` year to draw from.
- `VACATION` consumes the vacation balance; `UNPAID` never does; `SICK` never does
  except for the §9 credit-back; `SPECIAL` is always approved and never touches the
  vacation balance.
- Approvals draw from still-valid carryover first, then current-year entitlement (§7),
  and an over-budget request is rejected in full, not partially (§11).
- The idempotency key is a stable function of the request so re-running posts no
  duplicates; `usedDays` is treated as the trusted opening balance to which the run
  adds.
- All dates are handled in a single timezone (no DST/offset arithmetic on date-only
  values).

## Things in the data that look surprising

- **Eva's cancelled February vacation but `usedDays = 5.0`.** Feb 10–14 is 5 working
  days and the request is `CANCELLED`, yet her opening balance shows 5 used. If those
  5 are the cancelled days, §12 says they should not count — so either the seed is
  inconsistent or the run is expected to recompute the balance from history. Need the
  answer to Q7 to know which.
- **Bjarne is part-time (3 days/week) — see Q1.** His entitlement rounds to 17, used
  is 14, leaving 3; his pending Mon–Fri week counts as 5 under a literal §4 reading,
  which would reject it. Feels like a deliberate probe of the part-time rule.
- **Anna's carryover has already lapsed at the run date.** 6 carried-over days expire
  2025-03-31, run date is 2025-04-15, so §6 zeroes them — likely intentional, but
  worth confirming it's not seed drift.
- **Felix has two overlapping pending vacation requests** (May 26–30 and May 28–Jun 3).
  Looks like a deliberate §10 same-run overlap test; flagging so we treat the earlier
  submission as the winner.
- **Dilan's sick note (Mar 24–26, with certificate) overlaps his already-approved
  March vacation.** A clean §9 credit-back case; confirming the credit goes back to
  the 2025 balance and the posted decision reflects it.
- **Nothing in `Employee` models a special-leave allotment** even though §8 references
  one — relevant to Q4.
- **The §5 Berlin table omits 8 May 2025**, a real one-off Berlin public holiday (see
  Q11). All other dates and weekdays in both tables check out against the real 2025
  calendar. The only seeded request it overlaps is Eva's `UNPAID` May 5–9 — and
  `UNPAID` doesn't touch the balance, so no current decision changes — but a Berlin
  *vacation* spanning that day would be over-counted by one under the policy as
  written.
