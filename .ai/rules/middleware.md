---
paths:
  - app/Http/Middleware/HandleInertiaRequests.php
---

# Middleware

## The sidebar drops a project a month after it is finished
`sidebarProjects()` hides archived projects, and completed ones once `completed_at` is older than a month — the list holds six, and finished work was pushing live work off it. A recently finished project stays, because that is when people still open it, and the currently open project is always appended whatever its age.

`projects.completed_at` is written only by `Project::booted()`: stamped on the transition into `completed`, cleared on the way out. It is not fillable, so a factory or request cannot set it — backdate it with `forceFill(...)->saveQuietly()` in tests. Do not substitute `updated_at`; renaming a finished project would make it look freshly closed. Rows finished before the column exists carry null and are treated as fresh.

Covered by tests/Feature/SidebarProjectsTest.php.
