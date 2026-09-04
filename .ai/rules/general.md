---
paths:
  - vercel.json
  - package.json
---

# General

## Vercel functions must run in sin1, next to Neon
Vercel defaults functions to `iad1` (Washington DC) while the Neon compute is in `ap-southeast-1` (Singapore), so every query crosses the Pacific at ~230ms. One Laravel request makes several (session read, session write, cache, data), which made a warm `/login` take 2.4s — slower than running locally against the same Neon branch.

`"regions": ["sin1"]` in `vercel.json` brings it back to ~0.3s. Verify with the response header: `X-Vercel-Id: sin1::sin1::...` — the second segment is the function region. `sin1::iad1::...` means the setting has not deployed.

## Bun is the package manager, but Node still runs the build
Frontend dependencies are managed by Bun (`bun.lock`), not npm. Use `bun install`, `bun run build`, `bun run dev`, `bunx`. There is no `package-lock.json` and no pnpm workspace.

Bun is only the installer and script runner. Vite still executes under Node, because `node_modules/.bin/vite` carries a `#!/usr/bin/env node` shebang and `bun run` honours it. So CI and any build image need both toolchains — do not drop `actions/setup-node` from the workflow, and never force `bun --bun`.

`.npmrc` keeps `ignore-scripts=true` for npm's benefit; Bun blocks lifecycle scripts by default anyway. `unrs-resolver` shows up in `bun pm untrusted` and is meant to stay untrusted — eslint resolves fine without its postinstall.

The platform binaries pinned in `optionalDependencies` (`@rollup/rollup-*`, `@tailwindcss/oxide-*`, `lightningcss-*`) are an npm optional-dependency workaround. Bun filters them by `os`/`cpu` on its own; leave them until a Linux build proves they can go.
