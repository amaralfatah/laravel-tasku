---
paths:
  - resources/css/app.css
---

# Css

## DESIGN.md is applied at the token layer, and loses where it is a catalog
The Apple system in DESIGN.md is applied through `resources/css/app.css` tokens, not per-file classes. Three token tricks do the app-wide work: `--font-weight-medium: 600` erases weight 500 without touching call sites, every `--shadow-*` is `none` so the `shadow-*` classes already scattered through the app compile away instead of having to be chased down, and the `--text-*` ladder carries the documented sizes and tracking.

Four documented rules were deliberately overridden because the document describes a marketing catalog and this is a dense work tool: body is 16px not 17px, panels round at 12px (`--radius-lg`) not 18px, controls sit at 36px, and buttons are 6px rectangles rather than the signature pill (Jira is the agreed density reference). The pill grammar now lives on badges only. Do not "restore" these to the document.

Surfaces are flat and uniform, Jira-style: sidebar, canvas and card are all `--background` in both modes, and every boundary in the layout is a hairline. That makes `--border` load-bearing — weaken it and the page loses its structure. Only two things break the single surface: `--muted`/`--accent` for table headers, hovers and selected rows, and `--popover`, which is a step lighter in dark mode so a menu is not the same colour as the page it covers. Nothing casts a shadow, including menus, dialogs and sheets — they are told apart by a `border`. Do not reintroduce a tone step or a shadow to "add depth"; add a border instead.

Palette rule that still holds: one accent. Action Blue is the only interactive colour; `--success` and `--warning` exist purely for state the user must not miss and are never used for emphasis. Never introduce a raw Tailwind palette class (`bg-emerald-600`, `text-sky-400`) — a sweep already removed them all.
