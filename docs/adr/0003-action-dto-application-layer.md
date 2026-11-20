# 0003. Actions with DTOs as the application layer

Date: 2026-11-20
Status: Accepted

## Context

Each module needs a public surface that controllers, console commands, queued
jobs and other modules can call without knowing about Eloquent, HTTP or the
queue. The surface must be easy to test in isolation and must own the
transaction boundary, because most use cases mutate several aggregates
atomically.

## Decision

Every use case is an **Action**: a final, invokable class in
`app/Modules/<Name>/Application/Actions`, receiving one immutable **command
DTO** and returning a domain object, a read model or nothing.

```php
final readonly class PlaceOrder
{
    public function __construct(
        private TransactionRunner $transaction,
        private Clock $clock,
        private OrderRepository $orders,
    ) {}

    public function __invoke(PlaceOrderCommand $command): Order
    {
        return $this->transaction->run(function () use ($command): Order {
            // load aggregates, enforce invariants, persist, record events
        });
    }
}
```

Rules:

- A DTO is a `final readonly class` with constructor promotion and only
  scalars, enums, value objects or other DTOs. It carries no behaviour beyond
  named constructors.
- An action opens exactly one transaction through `TransactionRunner` when it
  writes. Reads that do not write use a **Query** class under
  `Application/Queries` and no transaction.
- Time comes from `Clock`, never from `now()`.
- Authorization is decided before the action runs (policy or middleware) and
  the action may re-check invariants, but it never reads the HTTP request.
- Actions raise `DomainException` subclasses for business failures. They never
  return error codes or `false`.
- Controllers are thin: validate with a FormRequest, build the DTO, invoke the
  action, return a resource.

## Alternatives considered

- **Service classes with many methods.** Grow into god objects and hide the
  dependency list of each use case.
- **Fat Eloquent models.** Couple business rules to persistence and make
  cross-module calls leak model internals.
- **Command bus with handlers.** Same shape with an extra dispatch layer that
  this project does not need yet; actions can be routed through a bus later
  without changing their signatures.

## Consequences

- One class per use case, which keeps diffs small and reviews focused.
- Every dependency is explicit in the constructor and unit tests can replace
  them with fakes.
- Some duplication between a command DTO and a FormRequest; accepted in
  exchange for a request-independent application layer.
