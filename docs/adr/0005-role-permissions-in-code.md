# 0005. Role permissions defined in code

Date: 2026-11-22
Status: Accepted

## Context

Members of a tenant need different capabilities: owners administer the account,
admins run the team and integrations, staff operate the shop, read-only members
observe. Machine clients (API keys) need the same checks through scopes. The
roadmap allowed either database-backed roles and permissions (for example
spatie/laravel-permission in teams mode) or a lean in-house design.

Custom, tenant-defined roles are not a product requirement. What is a
requirement is that a change to who can do what is reviewed, tested and
deployed like any other code, and that every request answers the permission
question cheaply.

## Decision

- Four fixed roles (`MembershipRole`) and an enumerated set of permissions
  (`Permission`).
- One class, `RolePermissions`, maps each role to its permissions. The matrix is
  mirrored in `docs/permissions.md`; a test fails if the document and the code
  disagree.
- Each permission optionally maps to an API scope. An API key holds a
  permission only through that scope; permissions without a scope are reserved
  for people.
- Routes declare what they need with `permission:<name>`. The middleware calls
  `Authorizer`, the single place that answers the question for both kinds of
  principal. Business rules that depend on who is acted upon (only owners touch
  owners, at least one owner remains) live in the actions.

## Alternatives considered

- **spatie/laravel-permission with teams.** Mature, but it puts the matrix in
  rows that must be seeded per tenant, migrated when it changes and cached
  across requests, for flexibility nobody asked for.
- **Laravel policies per model only.** Good for "may this user edit that
  record", but they do not give a declarative, reviewable picture of what each
  role can do across the API.

## Consequences

- The full matrix is visible in one file and in the docs; changing it is a pull
  request with a failing test until the docs are updated.
- No permission tables, seeding or permission cache; a request costs one
  membership lookup, which tenant resolution already performs.
- Tenant-defined custom roles would need a new design. If that requirement
  appears, the `Authorizer` interface stays and only its source of truth
  changes.
