---
paths:
  - resources/js/components/app-sidebar.tsx
---

# Components

## The roster is not behind the scale gate
`WorkspaceScale` drives progressive disclosure in the sidebar, but "Anggota" (`members.index`) is gated on `membership.can_monitor` alone, never on `showsOrganisation`.

Hiding it while a workspace is solo was a dead end: inviting people happens on that page, so the owner had no way to add the second member that ends solo. docs/about-app.md also gives a freelancer a Viewer seat for their client, which needs the same page.

Monitoring and Struktur organisasi stay behind the scale gate — those need an organisation to look at. Settings > Workspace is likewise ungated: an Owner renames a solo workspace.
