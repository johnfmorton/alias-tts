# Bounded-concurrency generation

**Status: implemented behind a flag (off by default) — not yet measured.**
The recommended architecture in §5 is built on the
`feature/bounded-concurrency-generation` branch: with `TTS_CONCURRENT_GENERATION`
unset the production path remains the serial `GenerateProjectChunksJob`,
byte-for-byte unchanged. See §13 for what exists and how to run the
experiment, and [GENERATION-CONCURRENCY-OPS.md](GENERATION-CONCURRENCY-OPS.md)
for the deploy/enable/kill-switch runbook.
This note grounds its provider assumptions in Replicate's current
documentation and in the operating constraint that the Replicate account will
remain a paid account with more than $20 in credit.

The short conclusion: bounded concurrency is a credible way to reduce the
wall-clock time of multi-chunk renders. Start with Studio background generation
at `K = 2`, measure it against the serial baseline, and increase only while the
latency benefit remains clear and errors remain flat.

---

## 1. Verified provider assumptions

These assumptions are specific to the endpoints used by the application:

- `resemble-ai/chatterbox`
- `resemble-ai/chatterbox-turbo`
- `qwen/qwen3-tts` (added 2026-07-23)

As of 2026-07-19, all are **official Replicate models**. Replicate documents
official models as always on and warm, with stable APIs and predictable
pricing. Both Chatterbox endpoints are currently priced at **$0.025 per 1,000
input characters**; qwen3-tts at **$0.02 per 1,000**. The concurrency design
below applies unchanged to qwen renders — same prediction API, same retry
paths.

Consequences for this design:

1. **Cold starts are not an expected operating state for these endpoints.** A
   concurrency experiment does not need to provision or warm replicas.
2. **Parallelism does not change the price of the same successful inputs.**
   Sending 20 chunks serially or with bounded overlap sends the same number of
   billable characters. Retries and duplicate predictions can still add cost.
3. **The normal prediction-creation limit is 600 requests per minute.** The
   much lower limit of one request per second and six per minute applies to
   granted-credit accounts without a payment method. Replicate may strengthen
   limits as purchased credit runs low; this application will keep the account
   above $20, avoiding that documented low-credit condition.
4. **A 429 remains possible.** Account-specific limits, service-side policy
   changes, or future traffic growth still justify reactive retry and
   observability. They do not make a distributed limiter a prerequisite for a
   `K = 2` experiment.

Primary sources:

- [Official models](https://replicate.com/docs/topics/models/official-models)
- [Rate limits](https://replicate.com/docs/topics/predictions/rate-limits)
- [Chatterbox model and pricing](https://replicate.com/resemble-ai/chatterbox)
- [Chatterbox Turbo model and pricing](https://replicate.com/resemble-ai/chatterbox-turbo)
- [Qwen3 TTS model and pricing](https://replicate.com/qwen/qwen3-tts)
- [Synchronous and asynchronous predictions](https://replicate.com/docs/topics/predictions/create-a-prediction)

Provider status, pricing, and rate limits are external facts and must be
rechecked immediately before implementation.

**Last verified 2026-07-19** against the live model pages: both endpoints show
the *Official* and *Warm* badges and price at *$0.025 per thousand input
characters* ("40,000 characters for $1"); the 600 requests/minute creation
limit and the granted-credit-without-payment-method exception (1/second, 6/minute)
are current in Replicate's rate-limit documentation.

---

## 2. What the application does today

A render is serial **within each generation operation**:

- `ReplicateChatterboxProvider::synthesize()` creates one prediction using
  `Prefer: wait`, polls it if necessary, downloads its output, and returns only
  after that prediction finishes.
- `SpeechService::process()` iterates through speech segments with `foreach`.
  Each call to `synthesize()` finishes before the next begins.
- `GenerateProjectChunksJob::handle()` iterates through project chunks with a
  `for` loop and normally sleeps 800 ms between chunks.
- `ProjectService::generateChunk()` renders one persisted chunk at a time.
- Production Supervisor launches one queue worker, so queued speeches and
  Studio runs also wait behind one another.

There is no `Http::pool`, promise fan-out, or per-chunk job batch in this
default path. (The claim-based path of §13 exists behind
`TTS_CONCURRENT_GENERATION`; with the flag off — the default — none of it
runs and the description above remains exact.)

One qualification matters: the app is not necessarily globally serial.
Separate FrankenPHP web requests can overlap. For example, two users manually
generating chunks through separate requests may create simultaneous Replicate
predictions even though each request is internally serial. The current
`min_request_gap_ms` state is per provider process, not a global account-wide
limit.

---

## 3. Goal, scope, and non-goals

**Goal.** Reduce end-to-end wait time for multi-chunk generation by keeping a
small, configurable number of independent chunks in flight.

**Initial scope.** Studio's queued **Generate remaining** operation. It already
persists each result independently and is the safest place to measure the
benefit.

**Later scope.** Queued `/v1` speech and, only if justified separately,
synchronous `/v1` requests.

**Non-goals.**

- Unbounded fan-out.
- Changing chunking, model inputs, audio cleanup, ASR behavior, or stitching.
- Assuming concurrency improves quality or reduces price.
- Deploying the feature before the current release review.
- Moving synchronous API generation to concurrency as part of the first phase.

---

## 4. Expected benefit

For `N` similarly sized chunks with concurrency `K`:

```text
serial provider time   ~= N × average chunk time
bounded provider time  ~= ceil(N / K) × average chunk time
```

For 20 chunks averaging eight seconds each, the ideal provider-time envelope
is approximately:

| `K` | Ideal time | Ideal speedup |
| ---: | ---: | ---: |
| 1 | 160 s | 1.0x |
| 2 | 80 s | 2.0x |
| 3 | 56 s | 2.9x |
| 4 | 40 s | 4.0x |

Actual speedup will be lower because chunks differ in length and the local ASR,
downloads, persistence, retries, and final assembly still consume time. The
slowest prediction in each wave also limits completion. Provider-side queuing
may cause the benefit to flatten as `K` increases.

The change primarily buys **lower latency for one render**. Whether it also
improves total multi-user throughput depends on Replicate capacity and local
worker resources.

---

## 5. Recommended architecture: per-chunk queued jobs

Use a parent run record plus one queue job per outstanding chunk. Keep the
existing serial implementation available behind a feature flag during rollout.

```text
Studio run
   |
   +-- chunk job 1 -- existing generateChunk() path -- persisted take
   +-- chunk job 2 -- existing generateChunk() path -- persisted take
   +-- chunk job 3 -- existing generateChunk() path -- persisted take
   |                  ... at most K jobs executing ...
   +-- completion coordinator -- final run status / optional assembly
```

Implementation shape for future work:

- Dispatch one job for each eligible chunk, associated with the existing
  `TtsProjectJob` run.
- Put these jobs on a dedicated generation queue. Run exactly `K` workers for
  that queue; start with `K = 2`.
- Each job re-fetches the run and chunk, checks cancellation and eligibility,
  then calls `ProjectService::generateChunk()` without changing its provider,
  ASR, take, spend, or timing logic.
- A chunk failure remains isolated: record it on that chunk and increment the
  run's failure count without canceling successful siblings.
- Persist results by chunk identity and assemble by project position, never by
  completion order.
- Mark the parent run complete only when all scheduled chunk jobs have reached
  a terminal state.

Laravel `Bus::batch` is a reasonable coordinator, but it is not mandatory. A
batch introduces framework batch metadata and requires careful handling of
chunks inserted into an active run. The existing `TtsProjectJob` counters can
also coordinate ordinary per-chunk jobs. Choose between them during
implementation after testing cancellation, dynamic insertion, and recovery
semantics—not merely because batching provides fan-out syntax.

Why queue jobs rather than `Http::pool` first:

- They reuse the complete existing per-chunk transaction boundary.
- One failed or killed worker affects one chunk rather than an in-process set.
- Worker count provides an understandable concurrency ceiling.
- Persisted chunks naturally tolerate out-of-order completion.
- The web request does not remain open while several external calls run.

---

## 6. Concurrency control and rate limiting

`K` is the primary initial safety control. It caps simultaneous predictions,
local ASR work, downloads, and database writes. With `K = 2` or `K = 3`, the
application is far below the documented 600 prediction creations per minute
under ordinary traffic.

For the first canary:

- Keep the existing 429 retry/backoff behavior.
- Record every prediction creation, 429 response, retry delay, and final
  outcome.
- Keep the Replicate account above $20 as an operating requirement.
- Do not add Redis solely for this experiment.

A **shared account-wide limiter** becomes appropriate when any of these become
true:

- multiple app instances are deployed;
- generation worker count grows materially;
- synchronous web traffic and queued traffic together approach the provider
  limit;
- 429s occur at healthy credit despite low `K`;
- Replicate assigns a lower account-specific limit.

At that point use a Redis token bucket or a database-backed atomic limiter.
Configure it from the verified account limit and keep reactive 429 handling as
a backstop. The limiter governs *creation rate*; `K` independently governs
*work in flight*.

The current per-process `min_request_gap_ms` can remain as a serial fallback,
but it must not be mistaken for an account-wide limiter once several workers
exist.

---

## 7. Reliability and correctness constraints

Concurrency changes ordering and race behavior even when provider behavior is
unchanged. Future implementation must explicitly preserve these invariants:

- **Ordering:** final audio follows project chunk position, not finish time.
- **Idempotency:** a retried queue job cannot create an unintended second take
  or double-charge local credit for the same attempt.
- **Claiming:** two workers cannot generate the same chunk simultaneously.
  Claim work atomically with a state transition or database lock.
- **Cancellation:** queued jobs observe cancellation before prediction
  creation; in-flight Replicate predictions should be canceled when practical.
- **Deletion and edits:** jobs re-fetch state and reject deleted, skipped,
  completed, or superseded chunks.
- **Progress:** expose completed/failed counts. A sequential label such as
  “Creating clip 7 of 20” is misleading when several clips are in flight.
- **Run completion:** only one coordinator transitions the parent run to its
  terminal state.
- **Recovery:** a worker termination leaves the chunk safely retryable without
  losing already persisted siblings.

### Local credit race

The guaranteed Replicate balance removes the provider's low-credit throttle;
it does **not** remove the application's own customer-credit race. Several
chunk jobs could all pass `canSpend()` before any one records its debit.

Before enabling customer-facing concurrency, choose one policy:

1. Accept and document a bounded overshoot at small `K`.
2. Reserve estimated customer credit before dispatch and settle afterward.
3. Atomically check and debit customer credit in one database transaction.

This issue concerns the application's ledger, not the Replicate account.

---

## 8. Remaining risks

Cold-starting official Chatterbox replicas is not an expected risk. The
remaining risks are:

- Replicate-side queuing reduces the theoretical speedup.
- Concurrent predictions expose an existing model or infrastructure fault more
  frequently even though cold boot is not its assumed cause.
- Retries or duplicate claims synthesize the same characters more than once.
- Concurrent ASR work can contend for the local sidecar's CPU/GPU capacity and
  erase some provider latency gains.
- Concurrent downloads and writes increase local memory, network, and storage
  pressure.
- Multiple workers complicate cancellation, run completion, dynamic chunk
  insertion, and credit enforcement.
- Future changes in Replicate model status, pricing, or limits invalidate the
  assumptions in §1.

The existing transient CUDA retry remains useful because observed failures are
real application evidence. The design should stop attributing those failures
to official-model cold starts unless production measurements demonstrate that
causal relationship.

---

## 9. Rollout and experiment plan

### Phase 0 — establish the serial baseline

Use representative projects—for example 10, 20, and 40 chunks—with both
classic and Turbo. Capture:

- total wall-clock time;
- prediction `starting`, queued, processing, and total durations when exposed;
- per-chunk input length and generation duration;
- 429 count and retry delay;
- prediction failure and transient reroll count;
- ASR duration and reroll count;
- Replicate characters and charge;
- local CPU, memory, and ASR utilization.

### Phase 1 — Studio canary at `K = 2`

Enable only for an administrator or test environment. Run the same fixed
projects and compare medians and tail latency with Phase 0. Validate final
audio order and every reliability invariant in §7.

Proceed only if:

- median wall-clock improves materially (a provisional target is at least
  30%);
- p95 failure and reroll rates remain within baseline noise;
- no duplicate takes or local credit charges occur;
- no unexplained Replicate charge increase occurs;
- local ASR and storage remain healthy.

### Phase 2 — tune `K`

Test `K = 3`, then optionally `K = 4`, one change at a time. Stop when speedup
flattens, p95 latency worsens, failures rise, or local contention appears. Do
not assume the largest safe `K` is the best operational setting.

### Phase 3 — broaden background use

After Studio has enough production evidence, consider queued `/v1` speech.
That path currently accumulates in-memory audio parts and has different
failure, concatenation, progress, and charging behavior, so it needs a
separate design review.

### Phase 4 — optional synchronous `/v1`

Only consider in-request concurrency if real client latency requires it.
`Http::pool` or an asynchronous prediction state machine would require a
provider-level rewrite and would hold more request resources. It should not be
coupled to the background rollout.

---

## 10. Testing, observability, and rollback

**Automated testing**

- Atomic chunk claiming and idempotent retry.
- Completion in a deliberately shuffled order followed by correctly ordered
  assembly.
- One failed chunk does not cancel siblings.
- Cancellation before dispatch, while queued, and while predictions are in
  flight.
- Project/chunk deletion and edits during a run.
- Worker termination and retry after provider success but before persistence.
- Dynamic insertion or regeneration during a run.
- Parent counters and final state under simultaneous updates.
- Customer-credit behavior selected in §7.
- Existing serial behavior remains unchanged at `K = 1`.

**Production signals**

- effective `K` and number of active generation workers;
- run wall-clock and per-chunk latency distributions by model;
- Replicate queued/starting/processing time where available;
- prediction creates, 429s, retries, failures, and cancellation outcomes;
- duplicate/idempotency suppression count;
- billed input characters and application cost per successful character;
- ASR duration, utilization, and rerolls;
- queue wait time and depth by queue.

**Rollback**

- A feature flag selects the current serial loop.
- `max_concurrency = 1` is the safe default.
- Keep the serial implementation until the concurrent path has meaningful
  production history.
- Reducing worker count must take effect without a data migration.

---

## 11. Configuration (as implemented)

All env-only by design (like `TTS_CREDIT_MARKUP`) — an operator experiment,
never a Settings-page key:

| Config key | Env var | Default | Meaning |
| --- | --- | ---: | --- |
| `tts.generation.concurrent_enabled` | `TTS_CONCURRENT_GENERATION` | `false` | Select the claim-based background path |
| `tts.generation.max_concurrency` | `TTS_GENERATION_CONCURRENCY` | `1` | Worker jobs dispatched per run (capped by outstanding chunks) |
| `tts.generation.queue` | `TTS_GENERATION_QUEUE` | `null` | Queue for chunk-worker jobs; null = default queue |
| `tts.generation.interactive_queue` | `TTS_INTERACTIVE_QUEUE` | `null` | Queue for the single-chunk run a Regenerate starts; workers must listen `--queue=interactive,default` |
| `tts.generation.slice_seconds` | `TTS_GENERATION_SLICE_SECONDS` | `120` | Fairness slice: a run hands off to a fresh queue job this often so co-queued jobs interleave; `0` = off |
| `tts.studio_generate_pace_ms` | `TTS_STUDIO_GENERATE_PACE_MS` | `800` | Inter-chunk delay, now applied per worker |

The two queue-fairness knobs apply to BOTH background paths (serial and
claim-based), not just the experiment: `interactive_queue` routes the
single-chunk run a per-chunk Regenerate starts (unset = safe default queue; a
name no worker listens on would strand every Regenerate), and `slice_seconds`
caps how long any run occupies one worker before its continuation re-queues at
the back of the FIFO line — that hand-off is what lets an API speech job or
another user's regenerate interleave with a long run instead of waiting it out.

Do not hard-code 600 requests/minute into the application merely because it is
the current public default. If a distributed limiter is later required, make
its configured value an operational setting verified against the account.

Do not hard-code 600 requests/minute into the application merely because it is
the current public default. If a distributed limiter is later required, make
its configured value an operational setting verified against the account.

---

## 12. Decisions to revisit at implementation time

- `Bus::batch` versus ordinary per-chunk jobs coordinated by `TtsProjectJob`.
- Exact atomic chunk-claim and idempotency mechanism.
- Customer-credit reservation, atomic debit, or bounded overshoot.
- Whether the local ASR sidecar supports `K > 1` effectively.
- How a chunk inserted into an active run joins the outstanding work set.
- Whether prediction IDs should be persisted to support cancellation and
  recovery.
- When traffic justifies a shared distributed rate limiter.
- Whether synchronous `/v1` should remain permanently serial.
- Reverification of official-model status, pricing, and account limits.

---

## 13. Implementation notes (feature branch)

Built on `feature/bounded-concurrency-generation`; §12's open decisions were
resolved as follows.

**Coordinator: `TtsProjectJob` counters, not `Bus::batch`.** The existing run
row already carries counters, cancellation, polling, and the dynamic-insertion
contract; batching would have added metadata without covering insertion into
an active run.

**Claim mechanism.** `tts_project_jobs` gained two columns:

- `concurrency` (nullable) — how the run executes: `NULL` = the legacy serial
  `GenerateProjectChunksJob`, `N ≥ 1` = N claim-based
  `GenerateProjectChunkWorkerJob`s. Stamped at dispatch so a flag flip cannot
  change an active run's semantics.
- `chunks_claimed` — the claim cursor into `chunk_ids`. Entries below it are
  claimed (in flight or landed); entries at or above it are unclaimed. It
  moves only inside a `lockForUpdate` transaction on the run row — the same
  lock `queueChunk()` inserts under, so no two workers can hold the same list
  entry and insertions always land on unclaimed ground (at the cursor, which
  is why a re-queued clip renders next).

Each worker loops: claim → eligibility re-check (deleted/skipped/completed
count as done; empty text counts as failed) → owner-credit check →
`ProjectService::generateChunk()` → atomic counter increment. Provider, ASR,
take, spend, and credit behavior are untouched — only the number of chunks in
flight changes. Results persist by chunk identity; final assembly orders by
project position, so landing order never matters.

**Single coordinator.** Every worker exit path "settles" under the run-row
lock: while `chunks_claimed − done − failed > 0` a sibling still holds work
and the run is left alone; once nothing is in flight, cancellation wins, then
full claim coverage completes the run. Exactly one worker performs the
terminal transition. An insertion racing a worker's exit is re-claimed by that
worker instead of stranded (the settle transaction sees it and resumes
claiming); an insertion after the terminal transition is refused by
`queueChunk`'s own locked active-check — which closes the serial path's
documented "unwinnable race".

**Credit policy: bounded overshoot (§7 option 1).** `canSpend()` is checked
before every claim's render and an exhausted balance still stops the run cold;
with K in flight the owner's balance can go negative by up to K chunks. The
serial path already allows exactly this overshoot at K = 1 (balances may go
negative by design; zero/negative only blocks new work), so this extends an
existing, documented behavior rather than adding a new one.

**Failure and recovery.** Chunk failures stay isolated (recorded on the chunk
and counted, siblings unaffected). A killed worker's `failed()` backstop marks
the whole run interrupted with the same friendly, resumable message as the
serial job; siblings stop at their next claim, persisted chunks are kept, and
"Generate remaining" resumes the remainder. Each worker also checkpoints
itself near the queue timeout by re-dispatching a fresh worker job.

**Progress honesty (§7).** Runs with `concurrency > 1` report
"Creating clips — N of M done" instead of the sequential "Creating clip N of
M"; the status poll labels every in-flight chunk "rendering" (several at
once), and the wait line is measured from the claim cursor. The up-front ETA
seed is divided by K (ideal envelope); the live running average self-corrects
because it already measures wall-clock per landed chunk.

**Tests.** `tests/Feature/ConcurrentGenerationTest` covers flag-gated
dispatch, claim-loop semantics (failure isolation, cancellation before and
during work, credit stop, ineligible entries, mid-render insertion),
checkpoint handoff, the kill backstop, sibling-aware settling with
single-coordinator completion, concurrent status/labels, and `queueChunk`
against the claim cursor. True multi-process interleaving cannot run inside
one test process; interleavings are simulated by staging the run row exactly
as an in-flight sibling would leave it. `ProjectJobsTest` still passes
untouched — the serial path is unchanged.

### Running the K = 2 experiment locally

Parallelism is capped by BOTH `max_concurrency` and the number of queue
worker processes actually listening — DDEV autostarts exactly one
(`queue:listen`, see `.ddev/config.yaml`), so with only it running, a K = 2
run degrades safely to serial execution of the worker jobs.

1. In `.ddev/.env` (or the web environment): `TTS_CONCURRENT_GENERATION=true`
   and `TTS_GENERATION_CONCURRENCY=2`; restart so the env lands.
2. Start a second worker alongside the autostarted one:
   `ddev exec php artisan queue:listen --timeout=1810 --tries=1`
3. Run the Phase 0/1 measurements from §9 on fixed projects, serial vs
   concurrent, before considering any default change.

In production the intended shape is a dedicated queue
(`TTS_GENERATION_QUEUE=generation`) with exactly K workers on it, so ordinary
speech jobs never queue behind chunk fan-out. That requires a Supervisor
change (today: one worker on `default`) and stays out of scope until the
Studio canary has evidence.
