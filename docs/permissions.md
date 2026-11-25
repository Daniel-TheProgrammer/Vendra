# Roles and permissions

Every member of a tenant has exactly one role. What each role may do is defined
in code, in `App\Modules\Identity\Domain\RolePermissions`, and enforced on routes
with the `permission:<name>` middleware. This table mirrors that class;
`PermissionMatrixTest` fails if the two drift apart. ADR 0005 explains why the
matrix lives in code.

| Permission           | Owner | Admin | Staff | Read-only | API key scope     |
|----------------------|:-----:|:-----:|:-----:|:---------:|-------------------|
| `tenant.view`        |   ✓   |   ✓   |   ✓   |     ✓     | `tenant:read`     |
| `members.view`       |   ✓   |   ✓   |   ✓   |     ✓     | people only       |
| `members.manage`     |   ✓   |   ✓   |       |           | people only       |
| `api_keys.manage`    |   ✓   |   ✓   |       |           | people only       |
| `catalog.view`       |   ✓   |   ✓   |   ✓   |     ✓     | `catalog:read`    |
| `catalog.manage`     |   ✓   |   ✓   |   ✓   |           | `catalog:write`   |
| `orders.view`        |   ✓   |   ✓   |   ✓   |     ✓     | `orders:read`     |
| `orders.manage`      |   ✓   |   ✓   |   ✓   |           | `orders:write`    |
| `ledger.view`        |   ✓   |   ✓   |   ✓   |     ✓     | `ledger:read`     |
| `ledger.post`        |   ✓   |       |       |           | people only       |
| `payments.manage`    |   ✓   |   ✓   |       |           | `payments:write`  |
| `payments.configure` |   ✓   |   ✓   |       |           | people only       |
| `webhooks.manage`    |   ✓   |   ✓   |       |           | `webhooks:manage` |
| `reports.view`       |   ✓   |   ✓   |   ✓   |     ✓     | `reports:read`    |

## Rules beyond the matrix

- Only owners can grant the owner role, demote an owner or remove an owner.
  Admins manage everyone below owner.
- A tenant always keeps at least one owner. Demoting or removing the last one is
  refused with `409 last-owner-required`.
- Removing a member deletes their access tokens for that tenant immediately.
  Their account and other memberships are untouched.
- API keys are checked against their scopes, never against a role. A permission
  marked "people only" can never be exercised with an API key.
- Cross-tenant access always fails before permissions are evaluated: the tenant
  comes from the credential, not from the request.
