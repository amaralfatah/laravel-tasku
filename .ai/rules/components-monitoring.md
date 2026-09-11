---
paths:
  - 'resources/js/components/monitoring/**'
---

# Components Monitoring

## The person page IS the workbook, not a view of it
`WorkloadSheet` draws `WorkloadExport`'s table cell for cell: the year/group/unit header, the WBS lines, and a pale #DAF2D0 bar with a solid #4EA72E cap on the closing column of finished work. Widths come from the workbook's own character units through `excelWidth()`. Do not restyle it to the app's design language — people diff this page against older copies of `ContohLaporan.xlsx`.

Three deliberate departures, all of them theme or behaviour rather than layout:

- Every fill is read off a `--sheet-*` custom property defined on the sheet's own root, light and `dark:` together. Light is the workbook's palette exactly; dark steps the paper, header band, project line and out-of-scope shading onto the surface ladder in `app.css`. Only #4EA72E is the same in both — it is the mark a reader hunts for.
- `--sheet-rule` is the exception to that ladder and must stay well clear of the paper (#5c5e64 on #242528). Excel has no dark sheet: the paper stays white under a black border, and the app's `--border` hairline (#3a3a3e) is nowhere near that contrast. A hairline separates panels that are legible without it; here the grid *is* what is read. The frame's own `--sheet-frame-rule` stays quieter, as Excel's row and column headers are quieter than a cell border.
- Type is 13px, not the 16px a 12pt cell renders as. `excelWidth()` counts Excel's narrow default face, so at 16px a `W3 08-26` overflows the column sized to hold it. The widths are the file's and stay; the type is what gives.
- The four fixed columns are pinned while the timeline scrolls, and a title opens the task. The file freezes no pane and neither does the printed layout.
- Clicking a row number or a column letter picks that whole row or column out, one at a time, and clicking it again clears it. The wash is a `backgroundImage` gradient laid over the cell's own `background`, never a replacement for it — a bar, the header band and the project line all have to keep showing through. Frame cells are real `<button>`s and stop the click, then a body row's number calls the slide itself: the number and the bar sit at opposite ends of a grid a year wide, so a pick that only tinted the row would light a stretch of empty columns and leave the work off screen.

Its grid is `resources/js/lib/sheet-grid.ts`, a port of `TimelineGrid` + `MonthWeek`: four columns a month, range closing on a December. That is deliberately NOT `@/lib/week`, which lays out real weeks and allows a fifth; the two are not interchangeable.

Tasks are numbered `<project position>.<wbs>`, so the block order has to be the export's. `PersonController::groupByProject()` therefore keeps `TaskOrder::tree()` order and must never be resorted by name — sorting one side renumbers every task on screen against the file. Covered by tests/Feature/MonitoringSheetTest.php.

Two blocks the workbook opens with are dropped from the page: the `PROJECT MANAGEMENT` heading over the person's name, and the green Aplikasi/Ringkasan banner. A sheet carries its own heading and totals because it is read on its own; this page is already headed by the avatar, name and unit. They are still written to the file.

The sheet is read through a spreadsheet frame — row numbers down the left, column letters across the top. Both are the page's own addresses, counted off what is drawn: rows from 1, columns from A on TASK. Neither follows the file, and that is deliberate. The file numbers rows from a heading and summary block the page leaves out, so matching it would open the table at row 13 with nothing above to explain the gap; and the file's blank print margin ahead of TASK only put an empty strip on screen and pushed every letter one along.

Rule every cell on its **right** edge, never its left, with only the first cell of a row carrying a left rule. Body cells were ruled on the left and lost every vertical line the moment the timeline was scrolled, while the header — ruled on the right — kept all of its own. A sticky cell puts the row on its own paint layer, and the left edge of a cell sliding under one is what goes missing on repaint.

`PROGRESS` is the one width not taken from the workbook: 15.18 characters is sized for the header, not for a value that is at most `100%`, so the page uses 10. Every other width stays the file's.

The header is three rows and the four fixed columns are merged down all of them, in the page and in `WorkloadExport::writeColumns()` alike. The blank cell that used to sit above TASK read as a gap torn out of the top left corner.
