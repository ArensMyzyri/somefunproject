# Phase 2 — The Full Rulebook: Implementation Spec

## Goal
Handle every absence type and the policy edge cases — special, unpaid, sick (with credit-back), overlaps, and cancellations — so a realistic mixed period resolves the way the policy intends.

## In Scope
- **REQ-13:** The job approves a special-leave request, reporting the counted working days, without reducing the employee's vacation balance.
- **REQ-14:** The job records an unpaid-leave request without reducing the employee's vacation balance.
- **REQ-15:** The job records a sick-leave request as approved with zero vacation days and without reducing the employee's vacation balance.
- **REQ-16:** The job credits the working days a certified sick request overlaps with an already-approved vacation back to the balance of the year in which that vacation falls.
- **REQ-17:** The job rejects a request whose dates share at least one day with a leave period already approved, including one approved earlier in the same run.
- **REQ-18:** The job treats a cancelled request as consuming no leave.
- **REQ-30:** The job does not enforce any allotment cap on special leave.

## Out of Scope for This Phase
- Idempotent keys, per-request commit, partial-failure skips, and the summary — **Phase 3**.
- The automated tests demonstrating these rules — **Phase 4**.
- Re-deriving opening balances from history — out of scope entirely (the recorded `usedDays` is trusted, per the resolved decisions).

## Current State
- After Phase 1, [src/Service/LeaveRequestProcessor.php](../../src/Service/LeaveRequestProcessor.php) decides vacation requests correctly but does not branch on `LeaveType`; sick/unpaid/special are not yet handled distinctly.
- [src/Enum/LeaveType.php](../../src/Enum/LeaveType.php) defines `VACATION`, `SICK`, `UNPAID`, `SPECIAL`; [src/Enum/LeaveStatus.php](../../src/Enum/LeaveStatus.php) defines `PENDING`, `APPROVED`, `REJECTED`, `CANCELLED`.
- [src/Entity/LeaveRequest.php](../../src/Entity/LeaveRequest.php) exposes `hasMedicalCertificate()`, `getType()`, `getStartDate()`, `getEndDate()`.
- The pending-requests read is a query handler ([src/Message/Query/FindPendingRequestsHandler.php](../../src/Message/Query/FindPendingRequestsHandler.php)) using the `EntityManager`/`QueryBuilder`; there is no query yet for already-approved overlapping leave.
- `WorkingDayCounter` from Phase 1 can count the working days of any date range and is reused here for overlap-credit math.

## What to Build
1. **Add an approved-overlap query handler.** Add a query (e.g. `FindApprovedOverlapping`) and its handler that, via the `EntityManager`/`QueryBuilder`, returns the employee's requests with status `APPROVED` whose date range shares at least one day with a given `[start, end]` range. Exclude `CANCELLED`, `REJECTED`, and `PENDING`. It serves both overlap rejection and sick credit-back (the latter filtering to `VACATION`), and is invoked through `$query->ask()`.
2. **Branch `LeaveRequestProcessor::decide()` on `LeaveType`.** Route each request to type-specific handling: special, unpaid, sick, and vacation (the Phase 1 path).
3. **Implement special-leave handling.** Approve unconditionally, set the decision's reported days to the counted working days of the range, and apply no vacation-balance change and no allotment check.
4. **Implement unpaid-leave handling.** Record as approved with no vacation-balance change.
5. **Implement sick-leave handling.** Record as approved with zero vacation days consumed. When the request has a medical certificate and overlaps an already-approved vacation, compute the working days of the overlapping date range (the intersection of the sick and vacation ranges, counted with `WorkingDayCounter` using the employee's state, ignoring half-day flags) and credit that amount back by reducing `usedDays` on the `LeaveBalance` for the year in which the overlapped vacation falls.
6. **Add overlap rejection to the vacation path.** Before the balance check, reject a vacation request whose range shares at least one day with any already-approved leave period — including periods approved earlier in the same run — using the new query handler plus an in-memory record of this run's approvals.
7. **Track in-run approvals.** Maintain, within a single `processPending` invocation, a per-employee list of the date ranges approved so far this run, and consult it alongside the database query so two pending requests that overlap are not both approved (the earlier submission wins, consistent with the existing submission-order processing).
8. **Confirm cancellation handling.** Ensure cancelled requests never enter processing (already excluded by the pending-requests query) and are excluded from the overlap query, so a cancelled period blocks nothing.

## Interfaces and Data Structures
- **Approved-overlap query (handler via EntityManager):** inputs = employee, start date, end date; output = list of approved `LeaveRequest`s sharing ≥1 day; an optional type filter selects only approved vacations for credit-back.
- **Overlap predicate:** two inclusive ranges overlap when `startA ≤ endB` and `startB ≤ endA`; ranges that merely touch at distinct endpoints (one ends the day before the next begins) do not overlap.
- **Credit-back amount:** working days in the intersection `[max(sickStart, vacStart), min(sickEnd, vacEnd)]`, counted state-aware with no half-day adjustment; applied as a reduction of `usedDays` on the overlapped vacation's year balance.
- **In-run approvals:** a per-employee collection of approved `[start, end]` ranges accumulated during the current run.
- **Decision reported days by type:** vacation = consumed working days; special = counted working days; unpaid = recorded with no balance effect; sick = zero vacation days (credit-back affects the balance, not the reported consumed days).

## Acceptance Criteria
1. A special-leave request is approved, reports its counted working days, and leaves the vacation balance unchanged (REQ-13, REQ-30); e.g. Anna's 2 June special is approved.
2. An unpaid-leave request is recorded with no vacation-balance change (REQ-14); e.g. Eva's 5–9 May unpaid.
3. A sick request is recorded as approved with zero vacation days and no balance reduction (REQ-15).
4. A certified sick request overlapping an approved vacation credits the overlapping working days back to that vacation's year balance (REQ-16); e.g. Dilan's 24–26 March sick credits 3.0 back (used 10 → 7).
5. A request sharing at least one day with an already-approved period is rejected, including when the conflicting period was approved earlier in the same run (REQ-17); e.g. Felix's 28 May–3 June is rejected against his approved 26–30 May.
6. Ranges that only touch at adjacent endpoints are not treated as overlapping (REQ-17).
7. A cancelled request consumes nothing and blocks no other request (REQ-18); e.g. Eva's cancelled February vacation does not reduce her available leave.

## Engineering Standards (from `TECH_RULES.md` — see cross-cutting-spec.md)
This phase extends the Phase 1 groundwork and must uphold REQ-33–40. In particular: the new approved-overlap lookup is a read served by a query handler using the `EntityManager`/`QueryBuilder` and invoked through `$query->ask()`, returning typed results rather than arrays (REQ-37, REQ-38); any data the type-handling paths pass back to the processor/command stays in DTOs/value objects (REQ-34, REQ-38); and credit-back failures or unexpected states are logged with context (REQ-36).

## Depends On
Phase 1 — the working-day counter, entitlement calculator, carryover-validity helper, the rebuilt vacation decision path, and the repository-interface / DTO / command-query groundwork must be in place and correct.
