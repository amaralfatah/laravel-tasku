---
paths:
  - resources/js/components/task/task-ai-chat.tsx
---

# Task

## The assistant is a floating chat panel, and the transcript lives in the client
`TaskAiChat` is a bottom-right launcher plus a non-modal panel (z-40, under shadcn dialogs) so the board stays readable behind it. Do not turn it back into a modal — talking to a board you cannot see is the thing it was fixing.

The server keeps no conversation. Each turn posts the panel's own transcript as `history` (max 10, role/text), which `TaskPlanner::transcript()` writes into the prompt; closing the panel ends the thread.

`useHttp` posts the data it holds at submit time, not what the render closed over: clearing the composer before `post()` sent a blank `instruction` and came back 422. Clear it after the request resolves, and carry anything outside the form (the transcript) with `transform()`. A rejected request resolves with `undefined` rather than throwing, so guard the `.then` and answer from the `onError` callback — `form.errors` is a render behind.
