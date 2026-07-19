# Bounded-concurrency generation — operations

How to deploy, enable, tune, and switch off the bounded-concurrency path for
Studio's "Generate remaining". The design rationale, provider assumptions, and
experiment plan live in [GENERATION-CONCURRENCY.md](GENERATION-CONCURRENCY.md);
this page is only about running it.

**The feature is off by default.** Deploying the code changes nothing about
how audio is generated until an operator sets an environment variable.

---

## 1. What deploying this code does (and does not do)

With `TTS_CONCURRENT_GENERATION` unset or `false`:

- "Generate remaining" dispatches the same serial `GenerateProjectChunksJob`
  as before. The serial loop, its pacing, cursor, queue labels, and insertion
  behavior are unchanged code paths, covered by the unchanged test suite.
- The deploy's migration adds two columns to `tts_project_jobs`
  (`concurrency`, `chunks_claimed`). Both are additive — nullable/defaulted —
  and the serial path never reads them. On a Forge push-to-main deploy it runs
  automatically via `migrate --force`.

Nothing else activates. The concurrent worker job (`GenerateProjectChunkWorkerJob`)
is dormant until the flag is set.

## 2. Configuration

Env-only by design — these are operator controls, deliberately **not**
Settings-page keys (users edit their own settings; this knob is account-wide):

| Env var | Default | Meaning |
| --- | ---: | --- |
| `TTS_CONCURRENT_GENERATION` | `false` | Master switch for the claim-based path |
| `TTS_GENERATION_CONCURRENCY` | `1` | Worker jobs dispatched per run (K); capped by the run's outstanding chunk count |
| `TTS_GENERATION_QUEUE` | *(unset)* | Queue name for the worker jobs; unset = the default queue |

Two ceilings apply to real parallelism, and the **lower one wins**:

1. `TTS_GENERATION_CONCURRENCY` — how many worker jobs a run dispatches.
2. How many queue worker **processes** are actually listening on that queue.

With one queue worker (the current production shape), flag-on with `K = 2`
still executes chunks one at a time — the worker jobs simply run one after
another, correctly. That degenerate mode is safe and is itself a reasonable
first canary of the claim path before adding a second worker process.

## 3. Enabling in production (Forge)

1. In the site's Forge environment, add:

   ```env
   TTS_CONCURRENT_GENERATION=true
   TTS_GENERATION_CONCURRENCY=2
   ```

2. Redeploy (or restart PHP + queue workers) so the cached config picks the
   values up. Forge's deploy script rebuilds the config cache and restarts
   workers.

3. For actual K = 2 parallelism, add a second queue worker in Forge
   (Site → Queue), listening on the same queue as the first. To keep chunk
   fan-out from competing with ordinary speech jobs, the intended production
   shape is a dedicated queue instead:

   ```env
   TTS_GENERATION_QUEUE=generation
   ```

   with exactly K workers on `generation` and the original worker left on
   `default`. If you set `TTS_GENERATION_QUEUE` but start no worker for that
   queue, runs will sit at "Waiting for a queue worker…" — the flag and the
   worker topology must move together.

4. Watch the signals in
   [GENERATION-CONCURRENCY.md §10](GENERATION-CONCURRENCY.md): run wall-clock
   vs the serial baseline, chunk failure/reroll rates, 429s, Replicate spend,
   and ASR sidecar health. Raise K only per the experiment plan (§9), one step
   at a time.

## 4. Switching it off (kill switch)

Remove `TTS_CONCURRENT_GENERATION` (or set it `false`) and redeploy/restart.
That is the entire rollback:

- No code revert is needed — the serial job is still in the codebase and
  becomes the dispatch target again immediately.
- No migration rollback is needed — the two columns are inert under the
  serial path.
- No data cleanup is needed — finished runs keep their audio and history
  regardless of which path made them.

Lowering `TTS_GENERATION_CONCURRENCY` back to `1` (flag still on) is the
softer variant: new runs stay on the claim path but are sequential in fact.

## 5. Why flipping the flag mid-flight is safe

- **Runs are stamped at dispatch.** Each run row records how it executes
  (`concurrency` column: NULL = serial, N = claim-based with N workers) at the
  moment "Generate remaining" is clicked. A flag flip never changes an active
  run's semantics — an in-flight concurrent run finishes as concurrent; the
  next run uses whatever the flag says then.
- **A worker restart can't lose work.** Every finished chunk is persisted
  independently the moment it lands. If queued worker jobs are killed by a
  restart mid-run, the run is marked interrupted with "Finished clips are
  kept — Generate remaining picks up where it left off", exactly like the
  serial job's recovery story. Clicking the button again resumes the
  remainder; nothing re-renders and nothing is re-charged.
- **Credit overshoot is bounded.** The owner's balance is re-checked before
  every chunk; with K in flight it can go negative by at most K chunks'
  cost — an extension of the serial path's existing ≤ 1-chunk overshoot, and
  zero/negative balances already only block *new* work.

## 6. Docker single-image installs

The bundled image's Supervisor runs one queue worker on `default`
(`docker/supervisord.conf`). The same two ceilings apply: setting the env vars
alone yields the safe degenerate mode; real parallelism needs additional
worker processes (`numprocs`, or a second program block for a dedicated
`generation` queue) in a customized supervisord config.

## 7. Trying it locally (DDEV)

DDEV autostarts one `queue:listen` worker (`.ddev/config.yaml`). For K = 2:

```bash
# .ddev/.env (then ddev restart)
TTS_CONCURRENT_GENERATION=true
TTS_GENERATION_CONCURRENCY=2

# second worker, in its own terminal
ddev exec php artisan queue:listen --timeout=1810 --tries=1
```

Then run the Phase 0/1 measurements from
[GENERATION-CONCURRENCY.md §9](GENERATION-CONCURRENCY.md) on fixed projects
before considering any production default change.
