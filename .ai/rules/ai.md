---
paths:
  - 'app/Services/Ai/**'
  - app/Services/Ai/PlanScope.php
---

# Ai

## The task assistant plans with the local Claude CLI and never writes itself
`ClaudeCli` shells out to the `claude` binary that is signed in on the machine — no API key is read, and the binary does not exist on the Vercel function runtime, so `config('ai.enabled')` defaults to off in production and both routes 404 there.

Two steps on purpose: `TaskPlanner` only asks for JSON, `TaskPlanApplier` validates and executes. The model never touches the database; every operation is re-validated against the same rules as `TaskStoreRequest`/`TaskUpdateRequest` (keep the two in step) and authorized through `TaskPolicy`, and the whole plan runs in one transaction so a rejected operation takes the rest back.

The CLI is run with its working directory set to an empty `storage/app/ai`. Running it in the repository root auto-loads the project's CLAUDE.md into every request — 23k tokens the planner has no use for.

`AiTaskController::apply()` reads `$request->array('operations')`, not `validated()`: the controller only rules on `op`, so the validated copy arrives stripped of every field the applier checks.

## Two planner drivers behind TextModel: local Claude CLI or Gemini with a key
`config('ai.driver')` picks the model and `AppServiceProvider` binds `TextModel` to it: `claude` (`ClaudeCli`, the signed-in CLI — no key, absent from the Vercel runtime) or `gemini` (`GeminiApi`, HTTP + `GEMINI_API_KEY`, works in production). Everything above the interface is driver-agnostic; add a driver by implementing `TextModel` and extending that match.

`config('ai.enabled')` guesses per driver — CLI off in production, Gemini off with no key — and `AI_ASSISTANT` overrides it. The board button reads the same flag through `can.ai`.

Gemini is asked with `responseMimeType: application/json` and `temperature: 0`, so it answers bare JSON; `TaskPlanner::stripFences()` stays for the CLI, which sometimes fences.

## A plan's reach is PlanScope, never the request
The assistant runs on project pages (board/list/timeline) and monitoring pages (person, me). `PlanScope::forProject()` is one project — creates land there. `PlanScope::forAssignee()` is one person's work across projects — every `create` must name a `project_id`, and `PlanScope::projects($user)` limits that to projects the work touches AND the viewer may contribute to.

Everything the model sees (`TaskPlanner`) and everything the applier may touch (`TaskPlanApplier::taskInScope`) comes from the scope. Never widen it from request input; seeing somebody's work on a monitoring page is not permission to change it, so each operation is still authorized through `TaskPolicy`.

Routes: `tasks.ai.plan|apply` (project) and `monitoring.ai.plan|apply` (member, gated by `viewMember`). Aggregate monitoring pages — people, divisions — carry no chat: they hold no task list to talk about.
