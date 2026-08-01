# Studio: follow playback (auto-scroll to the playing chunk)

> **Status: shipped.** Written as a design doc first; this document is now the
> feature's reference — the rationale for why the mapping works the way it
> does, and the contract the code keeps. The implementation lives in
> `ProjectService::buildTimeline()` (records the map), the `timeline` route
> (serves it), and `initStudioProject`'s follow-playback section in `app.js`
> (drives the UI). Covered by `tests/Feature/StudioProjectTimelineTest.php`.
>
> **Rollout note:** there is no backfill, by design. Every final built before
> this shipped has a `null` timeline, so Follow and the per-card ▶ stay
> disabled on those projects until the next **Build final** — see
> [How it works](#how-it-works-a-timeline-persisted-at-build-time) below.

While the final (concatenated) audio plays in the Studio's hero player, the
page follows along: the chunk currently being heard is highlighted and kept in
view. Hit pause, and you're already looking at the chunk you want to edit.
The link runs both ways: every chunk card can seek the hero player to its own
spot in the final, so after a fix you can hear the repair in context without
hunting for the timestamp.

## Why

The motivating experience: a project with 147 chunks, assembled into one final
MP3. Listening to the final for review, you notice a problem — one chunk needs
a text tweak or a regeneration. Now you have to *find* it. The hero player
tells you a timestamp; the page shows 147 visually similar cards. Locating the
offending chunk means guessing, scrolling, and spot-playing individual chunk
players until you hit the right one — which breaks the whole review flow.

If the page auto-scrolled with playback, review becomes: listen → hear the
flaw → pause → the right card is already centered on screen → edit or
regenerate → rebuild. The feature turns the final player from a passive
preview into a navigation instrument for the chunk list.

## UX

- **A "Follow" toggle** lives next to the hero player (row 2 of the sticky
  project header). Default **on**. State persists per browser
  (`localStorage`), like other Studio view preferences.
- While the final audio plays and Follow is on:
  - the chunk currently audible gets an **active highlight** on its card
    (e.g. a cyan ring, consistent with the existing accent palette);
  - the page **scrolls to keep that card in view**, centered, using the same
    header-offset-aware jump the "generation failed at chunk #N" hint already
    uses (plain `scrollIntoView` tucks the card under the sticky header — see
    the existing helper around `app.js` `scrollToChunk`).
- **Scrolling only happens on chunk *change*** — one smooth scroll per seam
  crossing, not continuous scrolling. Respect `prefers-reduced-motion`
  (instant jump instead of smooth).
- **Manual scrolling wins.** If the user scrolls (wheel / touch / PageUp etc.)
  while Follow is on, auto-scroll suspends — the highlight keeps updating, but
  the page stays put. A small "↧ Resume following" pill appears (near the
  transport or as a floating chip); clicking it, or seeking in the player,
  re-engages auto-scroll. This avoids the classic fight between the user and
  an auto-scrolling page.
- **Pause does nothing** — that's the point. The page is already at the right
  card; the highlight stays on the last-heard chunk so the target is obvious
  even after scrolling away and back.
- **Seeking/scrubbing** the hero player re-resolves the active chunk
  immediately (and scrolls, if following), so the transport doubles as a
  chunk navigator: drag to a timestamp, land on its card.
- **Reverse navigation — part of the same deliverable, not a follow-up.**
  Each chunk card gets a small "▶ Play in final" affordance (in the card
  header, next to the chunk number) that seeks the hero player to that
  chunk's `start_ms` and starts playback. The timeline makes this a
  one-line seek, and it completes the loop in both directions: *hear a flaw
  → land on the card* (follow), and *edit a card → hear it in context*
  (reverse). After a fix-and-rebuild, that second direction is how you
  verify the repair without re-listening from the top. Cards for chunks
  with no timeline entry (skipped, or added since the last build) show the
  affordance disabled with a "rebuild the final to include this chunk"
  tooltip.

## The hard part: mapping playback time → chunk

The final file is **not** a naive concatenation of the stored takes, so
"sum the chunks' `duration_ms` until you pass `currentTime`" is wrong:

- `AudioConverter::concatenate()` **edge-trims** every chunk before joining
  (silence trim at both edges plus fades — the Chatterbox tail-artifact fix),
  so a chunk's contribution to the final is *shorter* than its stored take's
  `duration_ms` by a variable amount.
- Seams get **inserted digital silence** sized per break
  (`ProjectService::partitionForStitch()` — paragraph seams larger than
  sentence seams), which adds time *between* chunks.
- **Skipped chunks are omitted** from the final entirely, though their break
  still sizes the neighboring seam.

Small per-chunk errors compound: across 147 chunks, an estimate drifting even
~100 ms per chunk lands the highlight several chunks away by the end of the
file. The mapping must come from what the stitch *actually produced*.

### How it works: a timeline persisted at build time

The only place the true post-trim durations exist is inside the stitch
itself, so that is where they get recorded:

1. **`AudioConverter::concatenate()`** already writes each trimmed chunk to a
   temp WAV before the ffmpeg concat. It measures each one
   (`wavDurationSeconds()` on the trimmed bytes — cheap, header math, no
   ffprobe run) and returns a per-input duration list alongside the output
   bytes.
2. **`ProjectService::rebuild()`** walks that list plus the `seamGapsMs` it
   already computed and builds a timeline for the *included* chunks
   (`buildTimeline()`):

   ```json
   [
     { "chunk_id": "0f1c…-a1", "start_ms": 0,    "end_ms": 4210 },
     { "chunk_id": "0f1c…-a2", "start_ms": 4560, "end_ms": 9105 }
   ]
   ```

   `start_ms` of chunk *n+1* = `end_ms` of chunk *n* + that seam's gap.
   Times inside a seam gap resolve to the *preceding* chunk (you're hearing
   its pause). `chunk_id` is the chunk's UUID **as a string** — the frontend
   matches entries against `data-chunk-id` with `===`, so the cast in
   `buildTimeline()` is load-bearing, not decoration.
3. **Persisted** as a nullable JSON column on `tts_projects`
   (`final_timeline`), written in the same `update()` that sets
   `final_audio_path`, and **cleared everywhere the final is invalidated**
   (the existing `final_audio_path => null` sites). The timeline describes a
   specific final file; it must never outlive it.
4. The lossy MP3 encode happens *after* concatenation of exact WAV durations,
   so encoder padding shifts the whole file by at most a few tens of
   milliseconds — imperceptible at "which card is this?" granularity. No
   correction needed.

**Existing projects** (final built before this shipped) simply have a `null`
timeline: the Follow toggle renders disabled with a tooltip — "Rebuild the
final to enable follow-along." No backfill, no estimation fallback; the next
rebuild fills it in. (An estimation fallback from take `duration_ms` + seam
gaps could ship later if the rebuild requirement proves annoying, but per the
drift math above it should never silently substitute for a real timeline.)

Note: `ProjectService::adoptSpeech()` (a final carried over from the Inspector
flow) has no per-chunk stitch to measure, so adopted finals also get a `null`
timeline until their first Studio rebuild — same disabled-toggle behavior.

### Frontend wiring

- Serve the timeline from a small JSON endpoint
  (`GET /{project}/timeline`) rather than embedding it in the page: "Build
  final" is an async stitch that refreshes the hero player's `src` without a
  page reload, so the page must be able to refetch the map that matches the
  new bytes. Fetched once on load and again each time a stitch settles.
- Listen to `timeupdate` on `#project-final-audio` (fires ~4×/s — plenty;
  no rAF loop needed while paused). Resolve the active entry by binary
  search; `timeupdate` plus a `seeked` handler covers scrubbing.
- Map `chunk_id` → card via the existing
  `.studio-chunk[data-chunk-id="…"]` lookup. An entry whose card no longer
  exists (chunk deleted after the build — the project is Stale) is skipped:
  highlight nothing, don't scroll, never throw.
- On chunk change: move the highlight class, and — if following and not
  suspended — scroll with the header-offset-aware helper.
- Suspension: any user-initiated scroll event during playback sets a flag;
  programmatic scrolls set/clear a guard so they don't trip it (the usual
  pattern: ignore scroll events for ~150 ms after our own scroll, or compare
  against the destination).
- Reverse navigation: the per-card "Play in final" control looks up its
  card's timeline entry (the same `chunk_id`-keyed map, inverted), sets
  `audio.currentTime = start_ms / 1000`, and calls `play()`. Seeking from a
  card counts as an explicit navigation, so it also clears any manual-scroll
  suspension — you asked to go there, so following resumes from there. If
  the final `<audio>` hasn't loaded far enough to seek (`preload="metadata"`
  usually has), `currentTime` is set from a one-shot `loadedmetadata`
  listener — after a `load()` first if the element is sitting on an error.

### Staleness

If chunks are edited or regenerated after the final was built, the project
goes Stale but the final bytes — and therefore the timeline — still match
*each other*. Following continues to work and correctly maps the audio you're
hearing to the card it came from; the card's *text* may have moved on, which
is exactly the situation the existing Stale messaging already explains. Only
a deleted chunk breaks an entry, and that entry is just skipped (above).

## Out of scope

Deliberately not built, and not planned:

- **Seam-preview players** (the per-gap "Preview stitch" players) — short,
  two-chunk clips; no navigation value.
- **Word- or sentence-level position within a chunk** — would need forced
  alignment (ASR timestamps); chunk-level is what the edit workflow needs.

## Coverage

`tests/Feature/StudioProjectTimelineTest.php` holds the automated side:

- after `rebuild()`, `final_timeline` is ordered, starts at 0, spans forward,
  covers exactly the non-skipped chunks, and its last `end_ms` lands within
  tolerance of the final file's real duration;
- a converter that can't measure its inputs yields a `null` timeline rather
  than a partly-guessed one, and final-invalidation paths null it out;
- the endpoint serves the current map, returns `null` with no final, and is
  access-controlled; `duplicate` re-keys the map to the copy's chunks;
- **the DOM contract** — the page emits every hook the frontend binds
  (`data-timeline-url`, `#follow-toggle`, `#follow-resume`,
  `#project-final-audio`, `.chunk-play-final`, `.studio-chunk[data-chunk-id]`)
  and `app.js`/`app.css` still bind those exact literals. Follow-playback
  fails *silently* by design — the JS degrades quietly when a hook is
  missing — so without this pairing a one-word rename on either side would
  kill the feature with a green suite and a clean console.

What still needs a human, on a many-chunk project:

- follow tracks through paragraph seams, seeking resolves correctly near seam
  boundaries, manual scroll suspends and the Resume chip restores, and
  reduced-motion jumps instead of gliding;
- "Play in final" on a mid-document card starts at that chunk's first audible
  moment (not inside the preceding seam gap); it stays disabled on a skipped
  chunk and on a chunk added since the last build; using it while
  scroll-suspended re-engages follow.
