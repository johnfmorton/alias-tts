# Swappable TTS Backend (Whole-Render) — design note

> **Status:** Proposed future feature — captured for later. **Not scheduled,
> not started.** Worth pursuing because the Genblaze provider layer makes an
> engine swap nearly free to build: published adapters plug in behind the same
> Pipeline/Step API we already use, so the cost is a registry and a dropdown,
> not a new integration. This document records the design so it can be picked
> up intact.

> **One-sentence summary:** add an admin setting that swaps the entire TTS engine
> for a render (default = our custom Chatterbox-via-Replicate path; alternative =
> an off-the-shelf engine such as LMNT), where the swap happens *at the Genblaze
> provider layer* and every render's manifest records which engine produced it.

---

## 1. The idea

A dropdown in the admin settings selects the TTS backend for a render:

- **Default:** our existing custom solution (the Genblaze runner's
  `AliasChunkProvider` → `/v1/internal/generate` →
  `ReplicateChatterboxProvider` → Replicate / Chatterbox). Unchanged; it works
  well today.
- **Alternative(s):** a second engine — e.g. **LMNT** — reachable through a real
  off-the-shelf Genblaze adapter (`genblaze-lmnt`).

Motivating scenario: **Replicate goes offline for a week.** The admin opens
settings, switches the backend to LMNT, and the product keeps shipping audio with
no code change and no redeploy.

---

## 2. The hard design decision: swap granularity = whole render, never per-chunk

An earlier idea was per-chunk fallback (try Chatterbox, fall back to another
engine for *individual* chunks that fail ASR QA). **We are rejecting that** for a
concrete acoustic reason:

> Two TTS engines fed the *same* reference sample still differ in timbre, loudness
> curve, prosody/pacing, breath and room-tone, and codec/sample-rate coloration.
> Splicing them inside one final file makes the speaker audibly "change"
> mid-paragraph — the exact artifact that makes audio feel broken.

So the rule is **one engine per final file**:

- The backend is chosen **once per render** and is **immutable for that render**.
- Every chunk in a given deliverable comes from the same engine — which is already
  how the Chatterbox path behaves today, so there is **no new seam risk**.
- Swapping the backend on an existing project behaves like our existing
  **"switch a project's voice → mark chunks stale"** rule: it forces a re-render
  rather than blending engines. Reuse that invalidation pattern — with one
  adjustment: `changeVoice` deliberately stales only chunks that *inherit* the
  project voice (`whereNull('voice_id')`), but a backend swap changes the engine
  for **every** chunk, so the backend-swap variant must drop that filter or it
  would leave per-chunk-voice chunks un-staled and violate this section's rule.
- This is also the honest answer to "won't the listener notice?": *within* any
  single deliverable, never.

---

## 3. Why the swap belongs at the Genblaze layer, not the PHP layer

A dropdown that swaps between two TTS SDKs could be built as a plain **Laravel
feature** — we already have a `TtsProvider` interface
(`ReplicateChatterboxProvider` implements it); adding an LMNT implementation
beside it and gating on a setting needs *zero* Genblaze. But built that way,
**we** write and maintain every new engine integration ourselves.

Putting the swap at the Genblaze provider layer is what makes the feature
cheap, and two things follow from that:

1. **The swap lives at the Genblaze provider layer, not the PHP layer.**
   The runner holds a provider registry keyed by backend name; the dropdown value
   selects which Genblaze provider object the pipeline uses;
   `Pipeline.step(provider=registry[backend], ...)` *is* the swap. This turns
   Genblaze's "swap one line" claim into a literal UI control.

2. **The alternative engine is a real off-the-shelf Genblaze adapter.**
   Keep Chatterbox as our **custom** `AliasChunkProvider`; add LMNT via the
   **published** `genblaze-lmnt` adapter (`pip install genblaze-lmnt`). That
   puts a bespoke engine *and* an off-the-shelf engine behind the **same**
   Pipeline/Step API and the **same** manifest shape — with zero LMNT
   integration code written by us. This is Genblaze's "ten adapters, one API"
   design working in our favor (genblaze-core 0.3.2 ships ten provider
   adapters): each additional engine we might ever want is a `pip install`,
   not an integration project.

---

## 4. Lead with provenance — the part a plain dropdown can't do

The resilience story ("Replicate down → flip to LMNT → keep shipping") is good but
not unique. The Genblaze-specific win is the **audit trail of the swap**:

- Every render's manifest already records the engine per step — the step's
  `provider` field, alongside `model` (the voice), `seed`, `params`, and run
  timestamps — all SHA-256-covered in B2.
- Six months later you can answer **"which of my N audio files were produced by
  the fallback engine during the Replicate outage, and can I reproduce them?"** by
  querying B2 manifests, and `genblaze-cli`'s replay re-runs any of them from the
  captured params.

The two wins, paired:

| Value | What carries it |
|---|---|
| **Resilience** — one provider abstraction, one config flip, no outage downtime | The provider registry + dropdown |
| **Auditability / replay** — every file self-describes the engine + params that made it | The Genblaze manifest in B2 |

---

## 5. Two things to nail down before building

- **Voice identity is per-backend.** LMNT's clone of our reference sample ≠
  Chatterbox's voice, even from the same WAV. The dropdown should pair
  **backend + voice** (or map our voice slug → a per-backend `voice_id`).
  Practically: enroll the reference sample into LMNT once, store the returned
  `voice_id`. Do not let an admin pick "LMNT" with a Chatterbox-only voice and get
  a silent mismatch.
- **Immutability + staleness.** A render records its backend in the manifest;
  re-rendering under a new backend is a *new* render with its own provenance —
  old files keep theirs.

---

## 6. Concrete components to change (future checklist — not started)

- **Runner:** a provider registry keyed by backend name
  (`{"chatterbox": AliasChunkProvider(...), "lmnt": …}` — the `lmnt` entry is
  the `genblaze-lmnt` adapter's provider class; exact class name unverified until
  the package is installed). The orchestrator selects the provider object per
  render instead of hard-coding `self.chunk_provider`. Alternatively, build the
  registry from genblaze-core's entry-point discovery
  (`genblaze_core.providers.registry.discover_providers()`) instead of a
  hand-keyed dict.
- **`genblaze-lmnt`:** `pip install` into `.venv-genblaze`; wire its provider into
  the registry; enroll the reference sample → `voice_id`.
- **`genblaze-cli`:** `pip install genblaze-cli` into `.venv-genblaze` (not
  currently installed) for manifest extract / verify / replay.
- **Manifest:** verify/surface `backend` (recorded today as the step's `provider`
  field) and the per-backend `voice_id` / params in the recorded step params so
  they are SHA-covered and replayable.
- **PHP → runner:** thread a `backend` field from the Studio dropdown through
  `GenblazeController` → `GenblazeRunnerClient` → the runner's `/run` payload.
- **Settings UI:** the admin dropdown (default `chatterbox`), plus the
  backend+voice pairing/validation.
- **Staleness:** reuse the existing project-voice-switch invalidation so changing a
  project's backend marks its chunks stale and forces a re-render — but drop
  `changeVoice`'s `whereNull('voice_id')` filter for backend swaps (see §2): every
  chunk changes engine, including those with an explicit per-chunk voice.

---

## 7. Open questions

- Is LMNT the right second engine, or do we want an ordered set
  (e.g. `[lmnt, elevenlabs]`) offering several cloning engines off one sample?
- Per-render vs. a global default-with-override: where does the setting live
  (system default, per-project, or both)?
- Do we ever want to *re-render an existing project* under a new backend (explicit
  user action), and how do we present that the voice will differ?

---

## 8. Why this is parked

Nothing is wrong with the idea — it's parked on priority, not merit. Other
Genblaze-layer work (the pronunciation pre-processor, live pipeline progress)
shipped first, and this feature has no forcing deadline: the current
Chatterbox-via-Replicate path works well today. The case for eventually
building it stands on its own — resilience (an outage becomes a settings
change, not an incident) and auditability (every file self-describes the
engine that made it), at the low cost the Genblaze provider layer enables.
Revisit §5's open questions before starting. **Do not implement yet.**
