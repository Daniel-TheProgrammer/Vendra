## Summary

<!-- What changes and why, in 2–4 lines. Reference the roadmap phase and task (e.g. "Phase 4, task 2"). -->

## Type of change

- [ ] feat
- [ ] fix
- [ ] refactor
- [ ] test
- [ ] ci / build
- [ ] docs
- [ ] chore

## Checklist

- [ ] Unit / feature tests added or updated
- [ ] Cross-tenant isolation test for every new endpoint
- [ ] OpenAPI spec regenerated and committed; contract tests green
- [ ] Migration is reversible; ran `migrate` and `migrate:rollback` locally
- [ ] Zero-downtime safe (expand/contract), or not applicable
- [ ] `make check` passes locally (Pint, Larastan, Rector, Deptrac)
- [ ] Docs / README / ADR updated
- [ ] No secrets, no debug code, no commented-out blocks

## How I tested it

<!-- Commands run, scenarios covered, anything manual. -->

## Rollback plan

<!-- How to revert safely if this breaks staging or production. -->
