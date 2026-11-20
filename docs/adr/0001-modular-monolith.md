# 0001. Modular monolith over microservices

Date: 2026-11-20
Status: Accepted

## Context

Vendra is a multi-tenant commerce and billing API with several distinct business
areas: tenancy, identity, catalog, orders, ledger, payments, webhooks, search and
reporting. It is built and operated by a single engineer. The areas share one
transactional boundary in several critical flows (placing an order reserves stock
and posts ledger entries atomically), and the team cannot afford distributed
transactions, per-service pipelines or network partitions between services.

## Decision

Build one deployable Laravel application organised as a modular monolith.

- Each business area is a module under `app/Modules/<Name>` with its own
  `Domain`, `Application`, `Infrastructure`, `Http`, `Database` and `Tests`
  directories.
- A module's public surface is its `Application` layer (actions, queries, DTOs)
  and its `Domain/Events`. Other modules may depend only on that surface.
- Shared building blocks (`Money`, `Clock`, outbox, HTTP error envelope) live in
  `app/Shared` and never depend on a module.
- Deptrac enforces these rules in CI (`deptrac.yaml`).

## Alternatives considered

- **Microservices per domain.** Correct boundaries, but distributed transactions
  for order placement, N deploy pipelines and a service mesh are unaffordable
  for one engineer and add no product value at this scale.
- **Conventional Laravel layout (`app/Models`, `app/Http`, `app/Services`).**
  Fastest to start, but the ledger, payments and tenancy code would blur into
  one namespace with no enforceable boundary, and extraction later would be a
  rewrite.

## Consequences

- One codebase, one pipeline, one database transaction for cross-module flows.
- Module boundaries are explicit and machine-checked, so a later extraction of a
  module into a service is a packaging exercise rather than a redesign.
- Every module must expose use cases through `Application`, which adds a small
  amount of ceremony compared with calling Eloquent models directly.
