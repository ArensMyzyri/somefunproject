# Cross-Cutting: Implementation Spec

## Goal
Establish the invariants that hold across every phase: the job decides only what is pending, files every decision before declaring the run complete, and always judges leave against the correct leave year.

## In Scope
- **REQ-26:** The job decides only requests awaiting a decision and leaves already-decided requests unchanged.
- **REQ-27:** The job records every decision to the HR system before reporting the run as complete.
- **REQ-28:** The job determines an employee's available leave against the leave year of the request being decided.

### Engineering standards (from `TECH_RULES.md`)
- **REQ-33:** Each application operation is invoked as an explicit command (state-changing) or query (read-only) through a consistent abstraction (`$command->execute()` / `$query->ask()`).
- **REQ-34:** Data crossing a layer boundary is carried by a dedicated DTO for that boundary.
- **REQ-35:** Every service is referenced through an interface rather than a concrete class.
- **REQ-36:** Errors are logged with enough context to diagnose them, and no exception is silently swallowed.
- **REQ-37:** Write-side database access goes through a repository referenced via a repository interface, while read-side queries are served by query handlers that may query the EntityManager directly.
- **REQ-38:** Structured data is represented as DTOs or value objects, never ad-hoc associative arrays.
- **REQ-39:** Incoming data is validated at the entry point (controller or command) before it flows deeper into the system.
- **REQ-40:** DTO validation is expressed as Symfony Validator constraints declared on the DTO.

## Current State
- The pending-requests read ([src/Message/Query/FindPendingRequestsHandler.php](../../src/Message/Query/FindPendingRequestsHandler.php)) returns only `PENDING` requests, ordered by submission then id, so already-decided requests are not revisited.
- [src/Service/LeaveRequestProcessor.php](../../src/Service/LeaveRequestProcessor.php) currently posts each decision to the HR API within the processing loop and returns a summary only after the loop; `balanceFor()` already selects the balance using the year of the request's start date.
- [src/Command/AbsenceRunCommand.php](../../src/Command/AbsenceRunCommand.php) reports completion only after `processPending` returns.
- **Standards gaps to close:** services are already behind interfaces (`LeaveRequestProcessorInterface`, `WorkingDayCounterInterface`, `EntitlementCalculatorInterface`, `PublicHolidayProviderInterface`, `HrApiClientInterface`), so REQ-35 is met. Not yet met: the write-side balance repository has no interface and reads are not yet served by query handlers (REQ-37); `processPending` returns a `list<array{...}>` summary (REQ-34, REQ-38); there is no Messenger command/query layer (REQ-33); there is no logging (REQ-36); and there are no input DTOs or Validator constraints (REQ-39, REQ-40). Symfony Messenger and Validator are not yet dependencies.

## What to Build
1. **Preserve the pending-only contract.** Keep the pending-requests query as the sole source of requests to decide, and ensure no code path mutates a request that is not `PENDING`; already-decided requests must be read-only context (e.g. for overlap checks) only.
2. **Guarantee decisions are filed before completion is reported.** Ensure every processed request's decision is posted to the HR system before the command reports the run complete; with Phase 3's per-request commit, each decision is posted as it is processed and the summary is emitted only after the loop finishes.
3. **Anchor available leave to the request's leave year.** Keep the leave-year resolution based on the request's start-date year for both the entitlement/balance lookup and any sick credit-back, so each decision draws from the correct year's balance.

### Engineering-standards groundwork (apply once, used by all phases)
4. **Add write-side repository interfaces and read-side query handlers (REQ-37).** Introduce a `LeaveBalanceRepositoryInterface` for the write/command-side balance lookup, have the concrete Doctrine repository implement it, bind it in `config/services.yaml`, and have the processor depend on the interface. Reads (e.g. pending requests) are served by query handlers that query the `EntityManager`/`QueryBuilder` directly rather than through a repository.
5. **Introduce the command/query facade (REQ-33).** Add Symfony Messenger and define a thin abstraction so callers invoke `$command->execute()` for state-changing operations and `$query->ask()` for reads; model the absence run as a command and reads (e.g. fetching pending requests) as queries whose handlers query the EntityManager directly.
6. **Define DTOs for layer boundaries (REQ-34, REQ-38).** Replace loose arrays with dedicated DTOs at every boundary — at minimum the per-request decision result and the run summary returned to the command — and forbid associative-array records elsewhere; reuse the existing `Decision` value object where it already fits.
7. **Add error logging (REQ-36).** Inject the framework logger and log failures at operation boundaries (the run, each request, external HR calls) with identifying context; never catch-and-discard.
8. **Add input validation at the edge (REQ-39, REQ-40).** Where the run accepts input (the command's run date, and any future request-intake DTO), wrap it in a DTO carrying Symfony Validator constraint attributes and validate it before processing.

## Interfaces and Data Structures
- **Pending selection:** the set of requests to process is exactly those with status `PENDING`; all other statuses are context only.
- **Leave year:** the integer calendar year of the request's start date, used to locate the `LeaveBalance` for entitlement, depletion, and credit-back.
- **Completion ordering:** no run is reported complete until every processed decision has been accepted by the HR system.
- **Command:** an object exposing `execute()` that performs one state-changing operation (e.g. the absence run) and returns a result DTO; no read-only caller depends on it.
- **Query:** an object exposing `ask()` that returns data without mutating state.
- **Write-side repository interface:** `LeaveBalanceRepositoryInterface` exposes the balance-by-employee-and-year lookup used during decisioning; the concrete Doctrine repository implements it. Reads are not repository methods — they live in query handlers that use the `EntityManager`/`QueryBuilder`.
- **DTOs:** a per-request decision-result DTO (request id, outcome, days, reason) and a run-summary DTO (the collection of decision results); an input DTO for the run carries the validated run date. Each is a typed object, not an associative array.

## Acceptance Criteria
1. Only `PENDING` requests are decided; requests in any other status are never modified (REQ-26).
2. Every decision produced by a run is posted to the HR system before the run reports completion (REQ-27).
3. An employee's available leave for a request is computed against the balance for the request's start-date year, and credit-backs return to the correct year (REQ-28).
4. The absence run is invoked as a command via `execute()`, and reads are performed via a query's `ask()` (REQ-33).
5. Data returned across the processor/command boundary is a DTO, and no associative-array record is passed between layers (REQ-34, REQ-38).
6. Every service is consumed through an interface, the write-side balance repository through its interface, and reads through query handlers using the EntityManager directly (REQ-35, REQ-37).
7. Failures at the run, request, and HR-call boundaries are logged with context, with no silently swallowed exception (REQ-36).
8. Input accepted by the run is validated against Validator constraints on a DTO before processing (REQ-39, REQ-40).

## Notes
The behavioural invariants (REQ-26–28) are largely satisfied by the starter structure; the risk is regressing them while implementing Phases 1–3. The engineering standards (REQ-33–40) are partly met (service interfaces) and partly new groundwork (repository interfaces, command/query facade, DTOs, logging, validation); building that groundwork is what the Phase 1 redo establishes, and later phases extend it rather than reintroducing arrays or concrete dependencies.
