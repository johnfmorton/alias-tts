# Dedicated generation queue — Forge setup

How to move bounded-concurrency chunk rendering onto its own queue with its
own worker pool, so a Studio "Generate remaining" fan-out never competes with
ordinary queued work (async `/v1` speech, voice-clip preparation, Genblaze
runs) and the concurrency cap K is exactly the worker count you provision.

Prerequisites: v0.71.0+, and the concurrency basics from
[GENERATION-CONCURRENCY-OPS.md](GENERATION-CONCURRENCY-OPS.md) — in
particular that real parallelism is the **lower** of
`TTS_GENERATION_CONCURRENCY` and the number of worker processes listening on
the generation queue.

## How the routing works

Setting `TTS_GENERATION_QUEUE=generation` makes exactly one job class —
`GenerateProjectChunkWorkerJob`, the per-chunk worker of a concurrent run —
dispatch onto the `generation` queue. Everything else stays on `default`:

| Job | Queue |
| --- | --- |
| `GenerateProjectChunkWorkerJob` (concurrent chunk rendering) | `generation` |
| `GenerateProjectChunksJob` (serial runs, flag off) | `default` |
| `GenerateSpeechJob` (async `/v1`) | `default` |
| `PrepareVoiceClipJob`, `RunGenblazeJob`, `QueueHeartbeatJob` | `default` |

The queue name is captured when a job is dispatched, so jobs already sitting
in the queue keep their original queue name across an env change (see
"Changing things later" below).

## Step 1 — create the worker first

Create the worker **before** pointing the env at the new queue. The order
matters: if `TTS_GENERATION_QUEUE=generation` goes live with no worker
listening, concurrent runs sit at "Waiting for a queue worker…" forever. A
worker listening on an empty queue, by contrast, is harmless.

In Forge: site → **Queue** → **New Worker**, with:

| Field | Value | Why |
| --- | --- | --- |
| Connection | `database` | Same connection the app already uses |
| Queue | `generation` | Must match `TTS_GENERATION_QUEUE` exactly |
| Processes | `2` | This **is** K — one process per clip in flight |
| Maximum Tries | `1` | Chunk failures are handled inside the run; a job-level retry would re-render and re-charge finished work |
| Sleep | `3` | Matches the default worker |
| Timeout | leave default | The job pins its own 1800s timeout (`TTS_ASYNC_TIMEOUT`), which Laravel prefers over the worker flag |

Forge writes a Supervisor program equivalent to your existing
`worker-926141`, with `--queue=generation` added and `numprocs=2`:

```
command=php8.4 /home/forge/alias.morton.dev/current/artisan queue:work database --queue=generation --sleep=3 --tries=1 ...
numprocs=2
```

Two rules while you're in there:

- **Do not add `generation` to the existing default worker's queue list**
  (e.g. `--queue=generation,default`). K must equal the number of processes
  listening on `generation` and nothing else — a default worker that also
  drains `generation` silently raises the real concurrency above K.
- **Leave the existing default worker exactly as it is.** It still carries
  async speech, clip preparation, Genblaze, the health probe, and serial runs.

Nothing else needs tuning: `retry_after` is set per **connection**, and the
app's database-connection default (`TTS_ASYNC_TIMEOUT + 60` = 1860s) already
exceeds the job timeout for every queue on it, so two processes can never
double-grab one long job.

## Step 2 — point the app at the queue

In Forge → site → **Environment**:

```env
TTS_CONCURRENT_GENERATION=true
TTS_GENERATION_CONCURRENCY=2   # keep equal to the worker's process count
TTS_GENERATION_QUEUE=generation
```

## Step 3 — deploy

Click **Deploy Now**. Saving the env alone is not enough: workers are daemons
running against a cached config, so the change only lands via the deploy's
`artisan optimize` (rebakes config) followed by `$RESTART_QUEUES()` (restarts
every worker for the site, the new one included). A deploy with no code
changes is fine — this is the standard toggle procedure.

## Step 4 — verify

1. Run **Generate remaining** on a multi-chunk project.
2. The status line reads **"Creating clips — N of M done"** and two chunk
   cards show **rendering** at once — the concurrent path is live.
3. The new worker's log (`/home/forge/.forge/worker-<id>.log`) shows job
   activity while a run is going.
4. Optional, while a run is active:
   `php8.4 artisan queue:monitor database:generation` from the site
   directory, or check the `queue` column in the `jobs` table.

If a run instead sits at "Waiting for a queue worker…" on `/admin/jobs`, the
env queue name and the worker's queue name don't match — fix the worker or
the env and deploy again.

## Health-check caveat

The Health page / `tts:doctor --deep` queue probe dispatches its heartbeat to
the **default** queue only — it verifies your original worker, not this one.
A dead generation worker shows up as concurrent runs stuck at "Waiting for a
queue worker…" while everything else looks green. Forge's worker monitoring
(Supervisor `autorestart`) is the primary guard; glance at `/admin/jobs`
after starting a run.

## Changing things later

- **Raising or lowering K:** the worker's process count and
  `TTS_GENERATION_CONCURRENCY` must move together (the lower one wins).
  Forge doesn't edit process counts on an existing worker — delete the
  worker, recreate it with the new count, update the env, deploy. Follow the
  one-step-at-a-time rule from the
  [rollout plan](GENERATION-CONCURRENCY.md): K = 2 → 3 → 4, measuring each.
- **Changing the queue name mid-flight:** worker jobs already queued keep
  their dispatch-time queue name; a checkpoint re-dispatch uses the new
  value. Keep both workers running until active runs settle and both queues
  drain.
- **Deploys during a run:** `$RESTART_QUEUES()` stops workers
  (`stopwaitsecs=15`, then kill). A run interrupted mid-render is marked
  "The run was interrupted by the background time limit… Generate remaining
  picks up where it left off" — nothing re-renders and nothing is
  re-charged, same recovery story as the serial path. Avoid deploying during
  a run you're actively measuring.

## Rollback

In order of decreasing scope:

1. **Abandon the dedicated queue, keep concurrency:** remove
   `TTS_GENERATION_QUEUE`, deploy. Worker jobs go back to the default queue
   (your two default processes become the cap). The `generation` worker
   idles harmlessly; delete it in Forge when convenient.
2. **Switch concurrency off entirely:** set
   `TTS_CONCURRENT_GENERATION=false`, deploy. Runs go back to the serial
   job on the default queue. See the
   [kill switch](GENERATION-CONCURRENCY-OPS.md) notes.

Neither direction needs a code revert, migration rollback, or data cleanup.
