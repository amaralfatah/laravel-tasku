---
paths:
  - scripts/vercel-install.sh
---

# Scripts

## Pin the Bun version — the build image ships an older one
Vercel's build image already has Bun (1.3.14 as of Sep 2026), older than the local one. A lockfile written by a newer Bun is unreadable to it — `bun install` dies with "Unknown lockfile version" at `bun.lock:2`, then `--frozen-lockfile` turns that into a failed build.

So `scripts/vercel-install.sh` always downloads a pinned `BUN_VERSION` and copies it to `/usr/local/bin/bun`; it never reuses whatever is preinstalled. `buildCommand` in `vercel.json` calls that absolute path, because the buildCommand shell is a separate one whose PATH may still resolve `bun` to the preinstalled copy.

Keep three places on the same version: `BUN_VERSION` in the script, `bun-version` in `.github/workflows/tests.yml`, and the Bun that regenerates `bun.lock` locally. Bumping one alone reintroduces the failure.
