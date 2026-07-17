---
name: verify
description: How to verify a change end-to-end in this app — build, log in, drive the admin panel in a real browser, clean up.
---

# Verifying changes in Alias TTS (this repo)

The surface is the admin panel at **https://tts.ddev.site/admin** (DDEV must be
up; it usually is — `ddev describe` to check). Run everything through `ddev`,
never host PHP/npm.

## Build + backend checks

- `ddev npm run build` — compiles resources/js/app.js + Tailwind into
  public/build (needed for the browser to see JS/Blade changes; the Vite dev
  server is usually NOT running for automated sessions).
- `ddev exec php artisan test --filter=SomeTest` — targeted; full suite ~60s.

## Getting a browser session

1. Create a throwaway SuperAdmin:
   `ddev exec php artisan admin:create verify-bot@example.test --password="SomePass#123" --name="Verify Bot"`
2. Drive with the Playwright MCP tools: navigate to
   `https://tts.ddev.site/login`, fill Email/Password, submit. TLS is trusted
   (mkcert) — no cert flags needed.
3. Studio Inspector lives at `/admin/studio?tab=inspector`; projects under
   `/admin/studio/projects/{uuid}`.

## Real renders

`TTS_PROVIDER=local` on this machine — the Chatterbox sidecar answers at
`http://127.0.0.1:8766/health` (check `busy:false`, models loaded). A short
chunk renders in ~10–20s on CPU; wait, don't assume failure. Renders charge the
credit ledger (dev data, harmless).

## Cleanup (always)

Tinker chokes on multi-line `--execute` through ddev quoting — write a temp
`.php` include file instead and run `ddev exec php artisan tinker file.php`:
delete the bot's projects via `ProjectService::deleteProject()` (removes
storage files), then its CreditTransaction/UserSetting rows, then the user.
Verify pre-existing project count is unchanged.

## Gotchas

- Per-user managed settings (e.g. `tts.spoken_quotes`, `tts.chunk_mode`) are
  reset-then-overlaid per request by ApplyUserSettings — a bare `config([...])`
  in a feature test gets clobbered; create a `UserSetting` row instead.
  Env-only keys (`tts.credit.markup`) are safe to `config([...])`.
- Admin AJAX endpoints must return JSON 422s explicitly (inline
  `Validator::make`) — the app only auto-renders JSON for api/* and v1/*.
