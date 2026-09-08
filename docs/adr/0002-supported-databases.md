# 0002. Support MySQL 8 and MariaDB, tested in CI

**Status:** accepted, 2026-09-07 (owner decision)

## Context

The specification said MySQL only and warned against advertising MariaDB merely because a
shared host offers it. The first deployed instance runs on a shared host whose database is
MariaDB, and the intended production deployment uses MySQL 8.

## Decision

Support both MySQL 8.x and current MariaDB. Both run in the CI matrix against the migration
and feature suites. Migrations stay portable; a feature available on only one engine is not
used until CI proves the alternative on the other. SQLite remains the development and unit
test database.

## Consequences

- Avoid engine-specific column types, JSON functions, and collation assumptions without a
  compatibility test.
- Compare-and-swap and locking code (envelope state machine, artifact publication) gets tests
  on both engines.
