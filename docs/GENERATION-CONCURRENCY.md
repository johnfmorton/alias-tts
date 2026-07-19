# Bounded-concurrency generation

**Status: planned design note — not built yet.** Chunk generation is
deliberately serial today (see below). This document sketches a *safe* way to
run a bounded number of chunks in flight to cut wall-clock on multi-chunk
renders, and the guardrails that make it safe. Parked on priority, not merit:
the serial path is correct and reliable, and concurrency buys latency, not
throughput-per-dollar. Nothing here is wired up.

---

## 1. What happens today (and why it is deliberate)

Every chunk is sent to the provider as a single, blocking call, and each loop
waits for one chunk to finish before starting the next. There is no batching,
no `Http::pool`, no `Bus::batch`, and no parallel fan-out anywhere in the
Replicate path.

- **The provider call blocks on one chunk.**
  `ReplicateChatterboxProvider::synthesize()`
  (`app/Services/Tts/ReplicateChatterboxProvider.php:81`) POSTs one prediction
  with `Prefer: wait`, then `awaitCompletion()` (`:260`) polls every 750 ms
  until that single prediction is terminal before returning.
- **Every generation loop is serial.**
  - `/v1` + queued speech: `SpeechService::process()`
    (`app/Services/SpeechService.php:173`) — `foreach` segment, `synthesize()`
    blocks each before the next; `$rawParts[]` accumulate in index order.
  - Studio per-chunk: `ProjectService::generateChunk()`.
  - Background "Generate remaining": `GenerateProjectChunksJob::handle()`
    (`app/Jobs/GenerateProjectChunksJob.php:91`) — `for` loop with an
    `usleep($paceMs * 1000)` **between** chunks.
- **One worker in production.** `docker/supervisord.conf` runs a single
  `queue:work` with no `numprocs`, so exactly one worker drains jobs.

This is not an oversight — it protects against three real forces, all of which
any concurrency design has to answer:

1. **Replicate's burst rate limit.** Prediction creation is capped (e.g.
   "6/min, burst 1") and returns HTTP 429 with a `retry_after` hint. The serial
   stream plus `sendWithRetry()` (`:292`) and `respectRequestGap()` (`:365`)
   keeps us under it.
2. **Cold-GPU transient faults.** A burst of predictions spins up cold GPU
   replicas that fail with transient CUDA asserts / OOM. Serial generation
   keeps the active replica count near one; `predictWithFailureRetry()` (`:208`)
   re-rolls the occasional transient failure. The `GenerateProjectChunksJob`
   docblock calls this out explicitly.
3. **Cost shape.** Replicate bills per input character *and* by GPU time;
   cold-starting extra replicas can *add* cost. **Concurrency buys latency, not
   throughput-per-dollar** — it does not make a render cheaper, and done naively
   it makes it more expensive and less reliable.

---

## 2. Goal and non-goals

**Goal.** Cut wall-clock on a multi-chunk render (e.g. a 40-chunk article) by
running a small, bounded number of chunks in flight — *without* triggering 429
storms, cold-GPU failures, credit overshoot, or any regression to the plugin's
`/v1` path.

**Non-goals.**

- Unbounded parallelism. The ceiling is a small, configurable `K` (start at
  2–3), never "as many as there are chunks."
- Rewriting per-chunk rendering. Each chunk keeps flowing through the exact
  existing `generateChunk()` / `synthesize()` path — retry, ASR QA, credit
  charge, spend counters, seed pin all unchanged. Concurrency wraps *around*
  that code, never inside it.
- Touching the synchronous `/v1` default. The plugin path stays serial unless
  and until a later, separately gated phase (§7) proves otherwise.

---

## 3. Design principles

1. **Bounded.** A configurable `K`; **default `K = 1` is byte-for-byte today's
   behavior**. The feature ships as a no-op.
2. **Globally rate-limited.** One shared token bucket across *all* workers,
   sized to Replicate's real account limit, independent of `K`. This is the
   keystone — see §5. `K` without a shared limiter is a footgun.
3. **Reuse, don't rewrite.** Concurrency is added around the unchanged
   per-chunk path.
4. **Order-independent.** Chunks reassemble by index at stitch time;
   completion order is irrelevant.
5. **Feature-flagged, default-off.** Enabled per-environment, measured, then
   tuned. Never on by default without prod evidence.
6. **Fail-isolated.** One chunk failing never fails its siblings (matches
   today's "record on the chunk and move on").

---

## 4. Recommended architecture — batched per-chunk jobs (Design A)

Apply concurrency to the **queued / background paths first** (Studio "Generate
remaining", and the queued speech job) — never the in-request `/v1` path
first, where the caller is blocked on the response anyway and plugin safety is
paramount.

- Replace the single `GenerateProjectChunksJob` for-loop with a **`Bus::batch`
  of per-chunk jobs** (`GenerateChunkJob`), one per outstanding chunk (or per
  small shard).
- `->allowFailures()` so a bad chunk records and the batch carries on.
- **Concurrency `K` = the number of queue workers** pulling the batch's
  dedicated queue (`supervisord` `numprocs = K`). Worker count is the simplest,
  most Laravel-native lever and needs no new fan-out machinery.
- Each `GenerateChunkJob`: acquire a slot from the **shared limiter** (§5),
  block/retry if none, then call `generateChunk()` **unchanged**.
- **Reassembly** stays keyed by chunk index — takes are already persisted
  per-chunk and the final assembly reads chunks in order, so completion order
  does not matter. Stitch/seal runs from the batch's `then()` completion
  callback.
- **Progress:** `chunks_done` already uses an atomic SQL `increment()`, which
  is concurrency-safe. "Creating clip N of M" becomes "N of M complete" (a
  strict sequence number loses meaning under concurrency).
- **Cancellation:** `cancel_requested` → `$batch->cancel()`; each job re-checks
  the flag before it runs, as the loop does today.
- **Mid-run insert (`queueChunk`):** a dispatched batch is immutable, so an
  inserted chunk is added with `$batch->add()` rather than mutating a cursor.

Why this shape: it isolates each chunk (a failure, a slow render, or a worker
kill touches one chunk, not the run), reuses 100% of the per-chunk logic, and
lets `K` be a pure ops dial (worker count) rather than application code.

---

## 5. The keystone: a shared, distributed rate limiter

Today's `respectRequestGap()` and `sendWithRetry()` are **per-process**. With
`K` workers, each independently believes it is within limits, so together they
issue up to `K×` the request rate — a 429 storm *and* a cold-replica burst,
i.e. exactly the two failure modes the serial design avoids. **The entire
safety of the design rests on moving the throttle from per-process to global.**

- Use a **Redis token bucket** (Laravel's `Redis::throttle` / `RateLimiter`, or
  a small atomic Lua bucket). Size it to Replicate's **actual account rate**
  (predictions/min), *not* to `K`.
- **`K` and the rate limit are different dials and both matter.** `K` bounds
  how many replicas can be warm at once (cold-GPU risk); the rate limit bounds
  how fast we *create* predictions (429 risk). A render can be well under the
  rate limit yet still cold-start too many replicas if `K` is high, and vice
  versa.
- Keep `sendWithRetry()`'s reactive 429 backoff as a **backstop**; the shared
  limiter is the *proactive* primary defense.
- **Infra note:** production is `QUEUE_CONNECTION=database` today with no Redis.
  This design either adds Redis, or implements a DB-backed atomic bucket
  (`SELECT … FOR UPDATE` on a counter row). That choice is an open decision
  (§9).

---

## 6. Credit races

Today the pre-flight `canSpend()` check and the charge-once map are evaluated
sequentially. With `K` chunks in flight, up to `K` can pass `canSpend()` before
any of them charges — overshooting a near-zero balance by up to `K − 1` chunks.

Options, cheapest first:

- **(a) Accept a bounded overshoot ≤ `K`.** With `K` at 2–3 this is a few
  cents at the very end of a balance. Simplest; document it. Recommended for
  Phase 1.
- **(b) Reserve-and-settle.** Hold an estimated debit at batch dispatch, settle
  actuals on completion.
- **(c) Atomic check-and-debit.** The ledger is already append-only; do the
  balance check and debit in one transaction so no two jobs both "see" the last
  dollar.

---

## 7. Rollout phases

- **Phase 0 — limiter only, zero concurrency.** Introduce the shared limiter
  and route the *existing serial path* through it at `K = 1`. Proves the
  limiter under real traffic with no concurrency risk. Adds Redis (or the DB
  bucket).
- **Phase 1 — Studio "Generate remaining" at `K = 2`, behind the flag,
  opt-in per environment.** Measure 429 rate, transient-failure rate, cost per
  render, and wall-clock vs the serial baseline.
- **Phase 2 — tune `K` on prod** from the Phase-1 metrics; consider `K` per
  plan/tier.
- **Phase 3 (optional) — the `/v1` path** via in-process `Http::pool` (Design
  B below), only after the Studio path is proven, and gated so the plugin
  default is untouched.

---

## 8. Alternative — in-process `Http::pool` (Design B, documented, not first)

`Http::pool` fires `K` create-calls concurrently within a single PHP process.
Because `Prefer: wait` mostly returns a finished prediction, you fire `K`
creates and poll only the stragglers.

- **Fits** the in-request `/v1` path, where there is no worker to fan out to and
  you want one request's internal latency reduced.
- **Downsides:** you must reimplement the create/await/retry state machine to
  run `K` predictions concurrently; the shared limiter is *still* required if
  multiple `/v1` requests run at once; and one PHP process holds `K` sockets
  open for up to the wait window each. Good for a single request's internal
  speedup, poor for cross-request safety — hence Phase 3, `/v1` only.

---

## 9. Testing, observability, rollback

**Testing.**
- Unit: the limiter (bucket exhaustion, refill, cross-process behavior via a
  Redis fake).
- Feature: batch dispatch count; `allowFailures` isolation; cancel mid-batch;
  mid-run `add()`; **reassembly order == chunk index regardless of completion
  order**; credit overshoot bounded ≤ `K`.
- Canary: a 40-chunk project at `K = 2` on staging against real Replicate —
  assert no 429 storm, transient-failure rate within noise, final audio
  ordering correct.
- The **entire existing serial suite stays green at `max_concurrency = 1`**
  (the default).

**Observability.** Log effective `K`, per-batch 429 count, transient re-roll
count, a cold-start proxy (prediction-latency distribution), wall-clock vs
serial baseline, and cost per render. These drive Phase-2 tuning.

**Rollback.** `max_concurrency = 1` restores exact current behavior with a
config flip — no deploy. At `K = 1` the shared limiter reduces to today's
pacing. The `Bus::batch` path can live behind the flag alongside the current
for-loop until it is proven.

**New config (defaults preserve today).**

| Key | Default | Meaning |
| --- | --- | --- |
| `tts.generation.max_concurrency` | `1` | chunks in flight; `1` == today |
| `tts.providers.replicate.rate_limit_per_min` | account limit | shared bucket size (proactive) |
| `tts.providers.replicate.min_request_gap_ms` | `0` (existing) | per-process fallback at `K = 1` |
| `tts.studio_generate_pace_ms` | `800` (existing) | inter-chunk pace at `K = 1` |

---

## 10. Open decisions

- **Redis vs DB bucket** for the shared limiter — a new infra dependency vs
  slightly more application code on the existing database queue.
- **Credit overshoot** — accept a bounded ≤ `K` overshoot, or build
  reserve-and-settle.
- **Does `/v1` (the plugin path) ever get concurrency**, or stay deliberately
  serial for plugin safety forever?
