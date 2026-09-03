---
paths:
  - vercel.json
---

# General

## Vercel functions must run in sin1, next to Neon
Vercel defaults functions to `iad1` (Washington DC) while the Neon compute is in `ap-southeast-1` (Singapore), so every query crosses the Pacific at ~230ms. One Laravel request makes several (session read, session write, cache, data), which made a warm `/login` take 2.4s — slower than running locally against the same Neon branch.

`"regions": ["sin1"]` in `vercel.json` brings it back to ~0.3s. Verify with the response header: `X-Vercel-Id: sin1::sin1::...` — the second segment is the function region. `sin1::iad1::...` means the setting has not deployed.
