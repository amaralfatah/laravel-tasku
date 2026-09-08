---
paths:
  - 'resources/js/pages/monitoring/**'
---

# Monitoring

## A tab that loads another page is not a tab
Moving between the agenda (`monitoring.me`) and the gantt (`monitoring.person`) is a sidebar row, "Timeline saya", never a tab row on the page. Atlassian's own guidance is that tabs switch views inside one context and must not navigate to another page, and Jira puts project views in the side navigation for exactly that reason. The `MyWorkTabs` component that used to do this was deleted; do not bring it back.

"Timeline saya" sits beside "Task saya" in `app-sidebar.tsx`, outside the gated "Monitoring" group. That is deliberate: the group is behind the scale gate and `can_monitor`, and someone who leads nobody is refused `monitoring.people` while still reaching their own timeline. It is built from `tenancy.membership.id`, which `HandleInertiaRequests` shares for this purpose.

The segmented control on the agenda filters in the browser, not on the server. `monitoring.me` must keep sending every open task whatever `?signal=` says, because the count beside each label is counted over all of them — filter server-side and the numbers describe a list nobody can see. The choice still lands in the URL via `router.replace` (a client-side visit, no request) so the view can be shared and survives a reload.

Covered by tests/Feature/MonitoringAccessTest.php.

## The agenda orders by family, not by task
Inside a bucket on `monitoring.me`, a sub task stands under its parent (indented `8 + depth * 14`px, the step the timeline and the project tree use). Families are placed by their most urgent member — due date, then priority, then reference — so a parent never drifts away from work that belongs to it.

Only a parent that lands in the same bucket counts as a parent there. Pulling one in from another heading would file it under a date it does not have, and the count beside the heading would stop matching the rows under it; a child whose parent is elsewhere simply leads its own family.
