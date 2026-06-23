# Technical Rules

Engineering conventions for this project. These are binding for new code and should
be applied to existing code as it is touched. They build on Symfony and Doctrine best
practices.

## 1. Commands and queries via Messenger

Use the Messenger component for application operations, split into **commands** (which
change state) and **queries** (which read state). Access them through a thin
abstraction rather than dispatching the bus directly:

- Commands expose `$command->execute()`.
- Queries expose `$query->ask()`.

This keeps call sites intention-revealing and hides the bus wiring behind a consistent
facade. A command must not return read models; a query must not mutate state.

## 2. A DTO for every layer boundary

Whenever data crosses a boundary between layers (controller/command → application →
domain → persistence, and back), move it as a dedicated Data Transfer Object. Do not
let entities, request payloads, or loose arrays leak across layers. Each boundary gets
its own DTO shaped for that exchange.

## 3. Interfaces for all services

Every service depends on and is referenced by an interface, not a concrete class.
Consumers type-hint the interface; the concrete implementation is bound to it in the
service container. This applies to domain services, calculators, clients, and
processors alike.

## 4. Logging to catch errors

Add logging so failures are observable. Catch and log errors at meaningful boundaries
(operation entry points, external calls, batch steps) with enough context to diagnose
them — never swallow an exception silently. Use the framework logger via dependency
injection.

## 5. Repositories behind repository interfaces (write side); query handlers for reads

Write-side database access — loading and persisting domain entities in command flows —
goes through repositories, and every such repository is referenced via a repository
interface, never a concrete Doctrine class directly, so the persistence layer can be
substituted or faked in tests. Read-side access is handled differently, following CQRS:
query handlers serve reads and may query the `EntityManager` / `QueryBuilder` directly
rather than through a repository, keeping read models out of the write-side abstractions.

## 6. No raw arrays for structured data

Do not pass structured data around as associative arrays. Always use a DTO (or a value
object / enum where appropriate). Arrays are acceptable only for genuinely homogeneous
collections, never as ad-hoc records with string keys.

## 7. Validate input at the edge

Validate all incoming data at the entry point — in the controller or the command that
receives it — before it flows deeper into the system. Reject invalid input there with
a clear error rather than letting it propagate.

## 8. Validate DTOs with the Validator component

Use Symfony's Validator (constraint attributes on DTO properties) to express and
enforce validation rules on DTOs, per Symfony best practice. Validation lives as
declarative constraints on the DTO, not as scattered imperative checks.
