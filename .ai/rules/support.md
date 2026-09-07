---
paths:
  - app/Support/TaskFilters.php
---

# Support

## Task search matches a whole branch, and never with ilike
`applySearch()` keeps every task on a branch that holds a title match: the match itself, its ancestors and its descendants. A plain `where('title', ...)` loses a matching sub task in the UI — the board only draws depth 0, so it came back empty, and the list drew the row with no parent above it. The branch test is a `path` prefix comparison in both directions (`path` carries the task's own id, `/12/45/78/`).

Use `lower(col) like ?` with an already-lowercased needle, not `ilike`. Production is Postgres but phpunit.xml runs the suite on SQLite in memory, and SQLite has no `ilike` — it fails with `near "ilike": syntax error`.

Covered by tests/Feature/TaskSearchTest.php.
