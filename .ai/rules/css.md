---
paths:
  - resources/css/app.css
---

# Css

## DESIGN.md is applied at the token layer, and loses where it is a catalog
The Apple system in DESIGN.md is applied through `resources/css/app.css` tokens, not per-file classes. Three token tricks do the app-wide work: `--font-weight-medium: 600` erases weight 500 without touching call sites, every `--shadow-*` is `none` so the `shadow-*` classes already scattered through the app compile away instead of having to be chased down, and the `--text-*` ladder carries the documented sizes and tracking.

Four documented rules were deliberately overridden because the document describes a marketing catalog and this is a dense work tool: body is 16px not 17px, panels round at 12px (`--radius-lg`) not 18px, controls sit at 36px, and buttons are 6px rectangles rather than the signature pill (Jira is the agreed density reference). The pill grammar now lives on badges only. Do not "restore" these to the document.

In light mode surfaces are flat and uniform, Jira-style: sidebar, canvas and card are all `--background`, and every boundary in the layout is a hairline. That makes `--border` load-bearing — weaken it and the page loses its structure. Only `--muted`/`--accent` break the single surface there, for table headers, hovers and selected rows. Dark mode is the exception and runs a tone ladder instead — see the section below. Nothing casts a shadow in either mode, including menus, dialogs and sheets; they are told apart by a `border` or a step on the ladder, never a shadow.

Palette rule that still holds: one accent. Action Blue is the only interactive colour; `--success` and `--warning` exist purely for state the user must not miss and are never used for emphasis. Never introduce a raw Tailwind palette class (`bg-emerald-600`, `text-sky-400`) — a sweep already removed them all.

## Dark mode is a Jira surface ladder, light mode stays one surface
The "one surface everywhere" rule now holds for light mode only. In dark, a card painted the page colour vanished into it and a hairline could not carry a board of them, so `.dark` runs Jira's ladder in ~4% lightness steps: `--muted` #161618 (sunken: board columns, table headers, progress tracks) < `--background` #1d1d1f < `--card` #2b2b30 (raised: cards, inputs, panels) < `--accent`/`--secondary` #37373c (hover, selected) < `--popover` #35353a (menus over a card). A card also carries a `border`, as Jira's does — the tone step alone is not enough where a card meets the top of a scrolling column. Light mode is unchanged — white already separates a card from a grey column.

Still true, and not to be undone: nothing casts a shadow, and one accent. Depth is tone plus hairline only. Keep the steps small and ordered; do not add a sixth level or paint a surface off-ladder.
