# Phase 1 — Correct by the Policy: Implementation Spec

## Goal
Make the processor count the days a request consumes and the leave an employee has earned exactly as the policy defines, so that approve/reject decisions on vacation requests are correct for the sample period.

## In Scope
- **REQ-1:** The operator can run the job for a chosen run date and have it process every leave request currently awaiting a decision.
- **REQ-2:** The job counts only Monday-to-Friday days within a request's date range as leave days.
- **REQ-3:** The job excludes public holidays that fall on a working day, based on the employee's federal state, from the days a request consumes.
- **REQ-4:** The job counts a flagged half-day at the start or end of a request as half a leave day only when that day is itself a working day.
- **REQ-5:** The job calculates a full-time, full-year employee's entitlement as their contractual annual leave.
- **REQ-6:** The job reduces entitlement in proportion to the full calendar months employed for an employee who joins or leaves part-way through the leave year.
- **REQ-7:** The job scales an employee's entitlement — but not the days a request consumes — by their contracted working days per week.
- **REQ-8:** The job rounds a reduced entitlement up to the nearest half-day once, after applying all reductions.
- **REQ-9:** The job includes carried-over days in available leave only when they have not lapsed as of the run date.
- **REQ-10:** The job approves a request whose consumed days fit within the employee's remaining available leave and records the days against their balance.
- **REQ-11:** The job rejects in full any request whose consumed days exceed the employee's remaining available leave, without partially approving it.
- **REQ-12:** The job draws approved days from still-valid carried-over days before drawing from the current year's entitlement.
- **REQ-29:** The job does not raise an employee's earned entitlement to the statutory minimum when pro-rata or part-time scaling reduces it below 20 days.

## Out of Scope for This Phase
- Differentiated handling per absence type (sick/unpaid/special), sick credit-back, overlap rejection, and cancellation handling — **Phase 2**.
- Idempotent re-runs, per-request commit, partial-failure handling, and the run summary — **Phase 3**.
- The automated test suite proving each rule — **Phase 4** (though existing happy-path tests must stay green throughout).
- Federal states beyond `BY`/`BE` and leave years other than 2025 — out of scope entirely per the roadmap.

## Current State
- [src/Service/LeaveRequestProcessor.php](../../src/Service/LeaveRequestProcessor.php) decides requests naively: `decide()` counts every calendar day (`startDate.diff(endDate).days + 1`), treats all types identically, and computes `remaining = contractualLeaveDays + carriedOverDays − usedDays` with no calendar or carryover-expiry awareness. This is the method to rebuild.
- [src/Service/Decision.php](../../src/Service/Decision.php) is a reusable value object holding `status`, `consumedDays`, `reason`.
- [src/Entity/Employee.php](../../src/Entity/Employee.php) exposes `employmentStartDate`, `employmentEndDate` (nullable), `workingDaysPerWeek`, `federalState`, `contractualLeaveDays`.
- [src/Entity/LeaveBalance.php](../../src/Entity/LeaveBalance.php) exposes `carriedOverDays`, `carryoverExpiresOn` (nullable), `usedDays`, plus `addUsedDays()`.
- [src/Entity/LeaveRequest.php](../../src/Entity/LeaveRequest.php) exposes `startDate`, `endDate`, `isHalfDayStart()`, `isHalfDayEnd()`, `getType()`.
- [src/Repository/LeaveBalanceRepository.php](../../src/Repository/LeaveBalanceRepository.php) has `findForEmployeeAndYear()`.
- No holiday calendar, working-day counter, or entitlement calculator exists yet.

## What to Build
1. **Create `src/Service/PublicHolidayProvider.php`.** Hold the §5 public-holiday dates for `BY` (13 dates) and `BE` (10 dates) for 2025, transcribed verbatim from `docs/LEAVE_POLICY.md`. Expose a way to ask whether a given date is a public holiday for a given federal state. Treating the data as a per-state set of ISO dates is sufficient; weekend dates in the set are harmless because the counter excludes weekends first.
2. **Create `src/Service/WorkingDayCounter.php`.** Given a start date, end date, federal state, and the two half-day flags, return the consumed working days as a float: iterate each date in the inclusive range, count it as 1.0 only if it is Monday–Friday and not a holiday for that state; then subtract 0.5 for `halfDayStart` only if the start date counted, and 0.5 for `halfDayEnd` only if the end date counted. Never return a negative number.
3. **Create `src/Service/EntitlementCalculator.php`.** Given an employee and a leave year, compute earned entitlement as `contractualLeaveDays × (fullCalendarMonthsEmployedInYear / 12) × (workingDaysPerWeek / 5)`, then round the result up to the nearest half-day exactly once at the end; apply no statutory minimum floor. A month counts only if the employee is employed for the entire calendar month (employment start on or before the first of the month, and employment end null or on or after the last of the month).
4. **Add a carryover-validity helper.** Provide a single place (a method on `EntitlementCalculator` or a small dedicated helper) that returns the still-valid carried-over days for a balance at a run date: the balance's `carriedOverDays` when `carryoverExpiresOn` is null or the run date is on or before it, otherwise 0.0.
5. **Rewrite `LeaveRequestProcessor::decide()` for vacation.** Replace the calendar-day count with `WorkingDayCounter`, and replace the remaining calculation with `entitlement (from EntitlementCalculator) + validCarryover − usedDays`. Approve when consumed days are less than or equal to remaining and reject in full otherwise. Conceptually deplete still-valid carryover before current-year entitlement; with the current single-`usedDays` balance the observable persisted effect is `usedDays += consumed`, and the carryover-first ordering is reflected in how remaining is composed.
6. **Keep non-vacation types passing through unchanged for now.** Until Phase 2, types other than vacation may retain interim behavior, but the existing happy-path tests (all vacation) must continue to pass.

### Engineering-standards rework (redo, per `TECH_RULES.md` — see cross-cutting-spec.md)
7. **Add the write-side repository interface and serve reads via a query handler (REQ-37).** Add `LeaveBalanceRepositoryInterface`, have the concrete Doctrine repository implement it, bind it in `config/services.yaml`, and change the processor to depend on it. Fetch pending requests through a query handler that queries the `EntityManager`/`QueryBuilder` directly, not through a repository method.
8. **Replace the array summary with DTOs (REQ-34, REQ-38).** Return a run-summary DTO composed of per-request decision-result DTOs from `processPending()` instead of `list<array{...}>`, and update `AbsenceRunCommand` to render from the DTO.
9. **Model the run as a command and pending-fetch as a query (REQ-33).** Add Symfony Messenger and the `$command->execute()` / `$query->ask()` facade, with the absence run as a command and the pending-request fetch as a query, their handlers behind interfaces.
10. **Add error logging (REQ-36).** Inject the logger and log failures at the run and per-request boundaries (including the HR call) with identifying context.
11. **Validate the run input at the edge (REQ-39, REQ-40).** Wrap the command's run date in an input DTO carrying Symfony Validator constraints and validate it before processing.

## Interfaces and Data Structures
- **PublicHolidayProvider:** input = federal state code (`"BY"`/`"BE"`) and a date (or year); output = whether that date is a public holiday, or the set of holiday dates for the state/year.
- **WorkingDayCounter.count:** inputs = start date, end date (inclusive), federal state, halfDayStart flag, halfDayEnd flag; output = non-negative float of consumed working days.
- **EntitlementCalculator.entitlementFor:** inputs = employee, leave year (int); output = float entitlement rounded up to the nearest 0.5.
- **Carryover validity:** inputs = leave balance, run date; output = float of carried-over days still valid.
- **Remaining available leave:** `entitlement + validCarryover − usedDays`, computed per employee for the request's leave year.
- **Decision-result DTO:** request id, outcome, consumed days, reason — one per processed request (the existing `Decision` value object may back the per-request outcome).
- **Run-summary DTO:** the ordered collection of decision-result DTOs returned by `processPending()`, replacing the `list<array{...}>` shape.
- **Write-side repository interface:** `LeaveBalanceRepositoryInterface` (balance by employee and year), implemented by the concrete Doctrine repository. The pending-requests read is a query handler using the `EntityManager`/`QueryBuilder`, not a repository method.
- **Run input DTO:** carries the validated run date, with Validator constraints.

## Acceptance Criteria
1. A vacation request spanning a weekend consumes only the Monday–Friday days within it (REQ-2).
2. A vacation request containing a public holiday for the employee's state consumes one fewer day than its working-day span (REQ-3); e.g. Felix's 26–30 May (Berlin, Ascension on 29 May) consumes 4.0.
3. A half-day flag reduces the count by 0.5 only when its boundary date is a working day (REQ-4).
4. A full-time, full-year employee's entitlement equals their contractual days (REQ-5); a mid-year joiner's is pro-rated by full months (REQ-6); a part-timer's is scaled by working days per week (REQ-7); the final figure is rounded up to the nearest half-day once (REQ-8); e.g. Bjarne = 17.0, Carla = 25.0.
5. A part-time employee's request still consumes the full Monday–Friday working-day count, not a scaled figure (REQ-7).
6. Carried-over days count toward remaining only when not lapsed at the run date (REQ-9); e.g. Anna's 6 carried days are excluded on a 15 Apr run.
7. A request within remaining leave is approved and adds its consumed days to the balance (REQ-10); a request exceeding remaining by any amount is rejected with no balance change (REQ-11).
8. Approved days are drawn from valid carryover before current-year entitlement (REQ-12).
9. An entitlement reduced below 20 days by scaling is left below 20 and not floored (REQ-29).
10. The processor depends on the write-side `LeaveBalanceRepositoryInterface`, and the pending-requests read is served by a query handler using the EntityManager, not a repository (REQ-37).
11. `processPending()` returns a run-summary DTO of decision-result DTOs, and no associative-array record crosses the processor/command boundary (REQ-34, REQ-38).
12. The run is invoked as a command via `execute()` and the pending fetch via a query's `ask()` (REQ-33).
13. Run and per-request failures are logged with context (REQ-36); the run input date is validated on a constrained DTO before processing (REQ-39, REQ-40).

## Depends On
Nothing prior; this is the foundation phase. It assumes the existing entities, repositories, and the `app:absence:run` command remain in place.
