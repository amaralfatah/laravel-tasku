---
paths:
  - resources/css/app.css
---

# Css

## DESIGN.md is applied at the token layer, and loses where it is a catalog
The Apple system in DESIGN.md is applied through `resources/css/app.css` tokens, not per-file classes. Three token tricks do the app-wide work: `--font-weight-medium: 600` erases weight 500 without touching call sites, every `--shadow-*` is `none` so the `shadow-*` classes already scattered through the app compile away instead of having to be chased down, and the `--text-*` ladder carries the documented sizes and tracking.

Four documented rules were deliberately overridden because the document describes a marketing catalog and this is a dense work tool: body is 16px not 17px, panels round at 12px (`--radius-lg`) not 18px, controls sit at 36px, and buttons are 6px rectangles rather than the signature pill (Jira is the agreed density reference). The pill grammar now lives on badges only. Do not "restore" these to the document.

In light mode surfaces are flat and uniform, Jira-style: sidebar, canvas and card are all `--background`, and every boundary in the layout is a hairline. That makes `--border` load-bearing — weaken it and the page loses its structure. Only `--muted`/`--accent` break the single surface there, for table headers, hovers and selected rows. Dark mode is the exception and runs a tone ladder instead — see the section below. Nothing casts a shadow in either mode, including menus, dialogs and sheets; they are told apart by a `border` or a step on the ladder, never a shadow.

Palette rule that still holds: one accent. Jira Blue is the only interactive colour; `--success` and `--warning` exist purely for state the user must not miss and are never used for emphasis. Never introduce a raw Tailwind palette class (`bg-emerald-600`, `text-sky-400`) — a sweep already removed them all.

## Dark mode is a Jira surface ladder, light mode stays one surface
The "one surface everywhere" rule now holds for light mode only. In dark, a card painted the page colour vanished into it and a hairline could not carry a board of them, so `.dark` runs Jira's ladder — see the sampled hexes in the section below. A card also carries a `border`, as Jira's does — the tone step alone is not enough where a card meets the top of a scrolling column. Hover sits at the top of the ladder on purpose: when the overlay outranked it, a hovered menu row was indistinguishable from the menu. Light mode is unchanged — white already separates a card from a grey column.

Still true, and not to be undone: nothing casts a shadow, and one accent. Depth is tone plus hairline only. Keep the steps small and ordered; do not add a sixth level or paint a surface off-ladder.

## Dark surfaces are sampled from Jira; muted, secondary and accent are three different jobs
The dark hexes are eyedropped from Jira and are not to be "harmonised": `--muted` #18191a (sunken wrapper behind a kanban column, progress tracks), `--background` #1f1f21 (page and sidebar), `--card` #242528 (kanban card, inputs, panels), `--secondary`/`--popover` #2c2c2e (table and timeline header rows, menu surface), `--accent` #303134 (hover on any row or menu item), `--border` #3a3a3e.

Because those are three separate values now, `bg-muted` no longer doubles as a header or a hover. A header row is `bg-secondary`, a hover is `hover:bg-accent`, and `bg-muted` is only for something that must read as sunken. A sweep converted every `hover:bg-muted*` in the app; do not reintroduce one.

An active nav item is not a hover: `--sidebar-selected` #123263 with `--sidebar-selected-foreground` #669df1 in dark (#e9f2ff / #1868db in light) is wired into `data-[active=true]` in `resources/js/components/ui/sidebar.tsx` and the header nav. Keep it off the `--sidebar-accent` hover token.

The accent blue is Jira's #1868db (light) / #669df6 (dark), not Apple Action Blue. Light mode is otherwise unchanged: one white surface plus a #e0e0e0 hairline.

## Dark text is #cecfd2, and pure white only survives on a filled blue or red
Jira paints no pure white text in dark mode, so `--foreground`, `--card-foreground`, `--popover-foreground`, `--secondary-foreground`, `--accent-foreground`, `--sidebar-foreground` and `--sidebar-accent-foreground` are all #cecfd2. The only #ffffff left in `.dark` are the ones that sit on a filled swatch — `--primary-foreground`, `--destructive-foreground`, `--sidebar-primary-foreground` — and they stay white for contrast.

Because of that, `dark:text-white` / `dark:bg-white` are gone from the app: a logo mark or an active underline uses `text-foreground` / `bg-foreground` instead. Do not reintroduce a literal white for text. The one exception is the inverted hero panel in `auth-split-layout.tsx`, which is white on its own `bg-zinc-900`.

Modals are their own surface: `--popover` #2b2c2f paints `DialogContent`/`SheetContent` (`bg-popover`, not `bg-background`), and the overlay is `bg-black/50` — /80 buried the board behind it. A panel nested inside a modal is drawn with a border on the modal's surface, never with a darker `bg-background`.
