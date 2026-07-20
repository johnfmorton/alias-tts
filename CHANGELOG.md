# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **You can now tune the pause between sentences and paragraphs.** Two new
  Settings controls under Speech generation — *Pause between sentences (ms)* and
  *Pause between paragraphs (ms)* — set the silence inserted where clips are
  joined in the final audio. They apply everywhere: projects, Studio, and your
  API keys. Left at **0 (Auto)**, the sentence pause is now mode-aware — 120 ms
  with Packed chunking, and a slightly roomier **200 ms with Per-sentence
  chunking**, which turns every sentence boundary into a joined seam and so
  benefits from a little more air (previously every seam was a flat 120 ms
  regardless of mode). Enter any value above 0 to fix a pause yourself; it then
  applies in both chunking modes.
- **Changed settings are obvious at a glance.** Any setting whose value differs
  from its shipped default now carries a **Modified** badge next to its name and
  a **Reset to default** control beside it — previously only the Advanced
  transcript-QA thresholds could be reset, and nothing told you which of your
  settings you had actually customised. (A checkbox is its own reset, so those
  get the badge but no extra button.)
- **The Settings page warns before you lose unsaved edits.** Change a value and
  then try to leave — closing the tab, reloading, Back, or following a link —
  and you're asked to confirm first. The prompt drops away the moment you save,
  or revert the field back to where it started.

### Changed
- **Per-sentence chunking is the new default.** Long text is now split one
  sentence per generation call by default (previously packed greedily up to the
  size budget), so each sentence is independently re-rollable and editable in
  Studio out of the box. This applies everywhere the setting is read — projects,
  Studio, and /v1 API keys — for any account that hasn't picked its own Chunking
  mode; accounts that already chose a mode are untouched.
- **Spoken quote marks options read in order of how much they say** — Off, Open
  only, Quote and close, Open and close.

## [0.73.0] - 2026-07-20

### Changed
- **Regenerate can't time out anymore.** Clicking Regenerate (or Generate) on a
  chunk now renders in a background run — the same machinery as "Generate
  remaining" — instead of inside the web request, where a long render plus its
  quality-check re-rolls could outlive the server's 60-second gateway limit and
  come back as an HTTP 504 even though audio was still being made. The card now
  shows an honest queued → rendering → done progression, Stop works on it, and
  the fresh take lands on the card automatically (it no longer autoplays —
  press play when you're ready).
- **Long runs share the queue fairly.** A background run now hands its worker
  back every couple of minutes (`TTS_GENERATION_SLICE_SECONDS`, default 120)
  instead of holding it until done, so API speech jobs and other users'
  regenerates interleave with a long run rather than waiting it out. Operators
  can also route one-clip regenerates to a dedicated fast lane with
  `TTS_INTERACTIVE_QUEUE` plus a worker listening on that queue — a quick fix
  then never waits behind bulk work at all.

### Fixed
- **Build final waits for unsaved edits.** Editing a chunk's text (or voice or
  tuning) without regenerating left Build final clickable — and it would stitch
  the old audio while the screen showed your new text. Build final now steps
  aside until the edited chunk is regenerated or reverted, a status line names
  the chunk holding it, and that chunk's Regenerate button lights up in the
  accent color so the resolving action is unmissable. Skipped chunks are exempt
  — they're not in the stitch.
- **A one-clip run's finish message no longer claims the whole project.** After
  regenerating a single chunk, the header used to announce "All 1 chunk(s)
  generated" — on a 30-chunk project that read as "everything's done." It now
  says which clip landed: "✓ Clip 29 generated — build the final to include
  it."

## [0.72.0] - 2026-07-19

### Changed
- **The pronunciation review screen makes each choice unmistakable.** The tiny
  pre-checked checkbox is now an explicit **Apply / Skip** control per row, with
  a live tally ("2 will be applied · 1 skipped") so you can see exactly what
  will happen before you commit. Apply still teaches the respelling to your
  dictionary and uses it now; Skip leaves the term as written and remembers not
  to pre-check it again. The control is the submitted source of truth and works
  with JavaScript off.

### Added
- **See — and remove — respellings already in your dictionary.** Terms you
  approved before are applied to new projects automatically and used to be
  filtered out of the review screen entirely, so a respelling could show up in
  your audio with nothing on screen to explain it. The review screen now lists
  every already-approved term that applies to the current text under **Already
  in your dictionary**, each with a **Remove** button that drops it from this
  project and every future one — plus a **Manage dictionary** link to the full
  list.

## [0.71.0] - 2026-07-19

### Added
- **Bounded-concurrency generation, off by default.** A background **Generate
  remaining** run can now keep several clips in flight at once instead of
  rendering strictly one at a time, cutting the wall-clock wait on multi-chunk
  projects. It's an operator experiment, enabled per install with
  `TTS_CONCURRENT_GENERATION=true` and `TTS_GENERATION_CONCURRENCY` (with a
  matching number of queue workers; an optional `TTS_GENERATION_QUEUE` keeps
  chunk fan-out off the main queue) — with the flag unset, generation behaves
  exactly as before. Concurrent runs stay honest in the UI: the status line
  counts landings ("Creating clips — 3 of 12 done") instead of pretending a
  sequence, every clip actually being synthesized reads **rendering**, the wait
  line still shows each clip's place, and a mid-run Regenerate still slots in
  next. Failures stay per-clip, Stop winds down after the clips in flight, and
  an interrupted run resumes without re-rendering or re-charging anything.
  `docs/GENERATION-CONCURRENCY-OPS.md` covers enabling, tuning, and the
  one-variable kill switch; the design note and measured rollout plan live in
  `docs/GENERATION-CONCURRENCY.md`.

## [0.70.0] - 2026-07-19

### Changed
- **A chunk waiting in a background run now says so.** While **Generate
  remaining** works through a project, every waiting chunk's badge shows its
  place in line — **queued · next in line**, **queued · 2nd in line**, … — and
  the clip being synthesized reads **rendering**, all in the run's cyan. The
  chunk's render button reads **⏳ Queued** for the wait, and reloading the page
  mid-run shows the same picture. Previously a chunk you re-queued mid-run
  showed only an unexplained amber "stale" badge and a Generate button that
  appeared to do nothing until the run finished.
- **Regenerating a clip during a run puts it next in line, not last.** A
  mid-run Regenerate used to append the clip to the end of the run, behind
  every chunk that hadn't rendered yet; the clip you're actively fixing now
  slots in right after the one being rendered.
- **The mid-run Regenerate message now says your edits were saved.** The click
  has always saved the text and Delivery panel before queueing, but nothing
  said so; the card now reads "Saved — clip 4 will regenerate next in this
  run," so an edit never looks lost while it waits.

### Fixed
- **A clip that finished while waiting in line no longer swallows a
  regenerate.** If a chunk completed while it sat in the run's line (say, you
  selected one of its takes), clicking Regenerate was a silent no-op the worker
  then skipped as already-generated; the queued entry is now re-armed so the
  regenerate actually happens. And clicking the clip being rendered at that
  moment answers honestly — "rendering right now" — instead of booking a
  duplicate render.

## [0.69.0] - 2026-07-19

### Added
- **`tts:doctor` now checks for the GD image extension.** Avatar uploads decode
  and re-encode through GD, so a host that never installed `php-gd` would fatal
  on every profile-photo upload while the rest of the service looked healthy.
  The health report (and the admin Health page) now fail loudly with the fix
  when GD is missing.

### Changed
- **The "you're editing someone else's project" warning now colour-codes its
  choices.** When a SuperAdmin opens another user's project, the guard dialog
  makes the safe option — **Keep read-only** — the green, focused default (press
  Enter to take it), marks **Duplicate instead** in caution yellow, and paints
  **Edit their project** in hazard red, so the riskiest action no longer looks
  like the primary button.
- **A take's tuning now reads as its Delivery name instead of a knob dump.** In
  the Studio takes list, a take rendered at a preset shows **Steady** /
  **Balanced** / **Expressive**; anything off-preset reads **Custom: …** with the
  knobs spelled out (engine-aware — classic prints exaggeration/cfg-pace, Turbo
  prints top-p/top-k/rep-penalty). An inherited take now reads **Balanced** (its
  effective neutral default) rather than a bare "inherited". The redundant
  "selected" word is gone (the ✓ Selected button already says so), and the seed
  only appears when one was actually pinned — no more "seed random" noise.
- **Take rows line up into columns across chunks.** The takes list is now a
  fixed-track grid, so the scrubber, time, tuning label, and Select/Delete
  buttons land at the same position in every row and every chunk regardless of
  how long a take's label is; long "Custom: …" labels wrap inside their column
  instead of shoving the layout sideways.
- **A chunk's voice, Delivery, and fine-tuning are now unsaved edits you can
  revert.** Changing a chunk's voice, a Delivery preset, or a fine-tune knob now
  marks the chunk **unsaved** — with a **Revert** that restores the whole panel
  (text, voice, Delivery, knobs, and seed) in one click — instead of the voice
  saving on its own and flipping the chunk to "stale". The chosen voice is
  written with your next **Regenerate**, matching how text edits already work, so
  a change you didn't mean to keep is one click from undo. The seed hint copy was
  reworded to explain that reusing a number gives a similar — not identical — take.
- **Chunk actions now confirm themselves on the chunk, not in the header.**
  Selecting a take, skipping or re-including a chunk, changing a chunk's voice,
  and any chunk-level error now report on a small notice line inside that
  chunk's card — right where you clicked — instead of up in the main player
  area, which read as a disconnect when the action happened far down the page.
  Confirmations quietly retire after a few seconds; errors stay until the next
  action. Redundant messages ("Take deleted", "QA flag dismissed") are gone —
  the row vanishing or the badge swapping already says it. The header status
  line is reserved for project-wide news (builds, background runs, renames).

### Fixed
- **Selecting an earlier take restores the voice it was rendered with.** Takes now
  remember which voice made them, so choosing an earlier take in the Studio moves
  the voice picker (and its engine's tuning knobs) back to that voice, and a
  following Regenerate uses it — previously the picker was left on the current
  voice. A take made with a different voice than the chunk's current one now names
  that voice in the takes list so cross-voice takes are easy to spot.
- **A failed chunk Regenerate now says why.** The error reason was silently
  swallowed — the chunk just flipped to "failed" with no message anywhere. The
  failure now shows on the chunk's notice line.
- **`npm run build`/`dev` self-install the right rolldown binding.** The
  node_modules folder is shared between the host and the DDEV container, but
  npm only installs rolldown's native binding for the platform that ran
  `npm install`, so building from the other side died with a module-not-found
  error deep inside Vite. A small pre-step now checks for this platform's
  binding (version-matched to rolldown) and installs it `--no-save` when
  missing, so builds work from either side without hand-fixing node_modules.

## [0.68.0] - 2026-07-19

### Added
- **Every main page now greets new users with a short intro.** Studio, Voices,
  Pronunciations, and API Keys each get their own dismissable "Getting Started"
  message — the same welcome pattern the Dashboard already had — explaining what
  the page is for the first time you land on it. Each one hides independently
  when you dismiss it, so clearing Studio's message leaves the others in place.
  Bring any you've dismissed back in one click from the **Interface** section of
  your Account page, or toggle each page's message individually in Settings.
  Each message can also be pinned on or off instance-wide via its own
  `TTS_SHOW_GETTING_STARTED_*` environment variable.
- **Profile photos are resized for you.** Upload any JPG, PNG, or WebP up to
  4 MB and it's cropped to a neat square and scaled down automatically — there's
  no need to prepare the image yourself, and a hint on the Account page spells
  out the format and size. Photos are stored as compact WebP, so they load fast
  everywhere they appear.

### Changed
- **The Jobs page now paginates.** Background "Generate remaining" runs are
  listed 50 to a page, newest first, instead of a single capped list that let
  older runs age out of view. The live status poll follows the page you're on
  (the `?page=` carries through), so it refreshes exactly the rows on screen
  and every run stays reachable.

### Security
- **Set-password and invite links are now single-use.** A link stops working
  the moment it's used to set a password — and any older link for the same
  person is retired automatically when a newer one is issued (create, invite,
  or force-reset). Previously a link kept working for its full 7-day window and
  could be replayed. A spent or superseded link now sends the visitor to the
  sign-in page with a note to ask an admin for a fresh one.
- **Uploaded profile photos are re-encoded server-side.** Every avatar is
  decoded and re-written as a fresh square WebP, so anything that might be
  hidden inside the original file — metadata-embedded code, payloads appended
  after the image data — is discarded rather than stored or served. Uploads are
  now bounded by pixel dimensions as well as file size (rejecting
  "decompression-bomb" images that are tiny on disk but enormous when decoded),
  and avatars are served with an `X-Content-Type-Options: nosniff` header.

## [0.67.0] - 2026-07-18

### Changed
- **Regenerate is now the one button that does it all.** Picking a Delivery
  preset and clicking Regenerate used to render with the *previously saved*
  tuning unless you remembered to press Save tuning first — the settings on
  screen could silently disagree with what rendered. Now the Regenerate click
  saves the whole panel (Delivery, fine-tune knobs, seed) *and* any pending
  text edit, then renders: what you see is always exactly what renders. Want
  another take of the same settings? Leave the seed blank and Regenerate
  again.
- **Selecting a take now restores everything it was made from.** Choosing an
  older take used to swap only the audio, leaving the text box and tuning
  panel describing something else. Select now brings back the take's text,
  knobs, and seed along with its sound, so the panel — and a sealed receipt —
  always tell the truth about the audio you're hearing. If you have an
  unsaved text edit, selecting warns before replacing it.
- Take rows no longer repeat "rendered with Generate" on every line — only
  the exceptions are labeled (QA auto-fix, Inspector carry-overs, copies, and
  takes from the retired flows below).

### Removed
- **The Preview, Use this take, Save tuning, Re-roll, and Save text buttons
  are gone**, along with their endpoints. Every render is already kept as a
  selectable take (byte-for-byte what you heard), so the takes list *is* the
  preview; a blank-seed Regenerate *is* the re-roll; and text or tuning can
  no longer be saved without rendering — which is how saved words could sit
  next to stale audio. Old preview/re-roll takes still show and prune
  normally.

## [0.66.0] - 2026-07-18

### Changed
- **The About tour now speaks to two audiences — and arrives like the home
  page.** The About page is split into a creator tour (clone, prepare, direct,
  seal) and a developer tour (compatible APIs, API→Studio handoff,
  verification), linked by a toggle at the top of each. Both pages now open
  with the home page's staggered entrance — header, audience toggle, headline,
  and intro rise in sequence — and visitors who prefer reduced motion see
  everything instantly.

### Fixed
- **"Open Studio" on the creator About page now opens Studio.** Both buttons —
  the header and the closing call-to-action — sent signed-in users to the
  Dashboard instead of the Studio projects list they were promised.
- **Reference-clip guidance now says 15–20 seconds everywhere.** Chatterbox
  only ever hears the head of a reference clip — about the first 15 seconds on
  turbo, less on classic — so the old "15–30 seconds" advice had you recording
  audio the engines never use, and longer clips only slow the cleanup step.
  All copy, docs, the recorder's length meter, and the sample script now aim
  for 15–20 seconds.

## [0.65.0] - 2026-07-18

### Added
- **A "Get started" guide now welcomes you on the Dashboard.** New users landed
  on a page of zero-count cards with no hint of what to do first. The Dashboard
  now opens with a hard-to-miss welcome panel offering the two starting paths:
  **Clone your voice** (record ~30 seconds and get a voice that sounds like
  you) or **Start with a built-in voice** (paste a script into a Studio
  project), plus a pointer to the connection details for anyone hooking up an
  app or the Bespoken plugin. The guide stays on every visit until you hide it,
  and you can bring it back any time — from the small "Show getting started"
  link on the Dashboard, your Account page's new **Interface** section, or the
  Settings page. Hosts can pin it on or off instance-wide with
  `TTS_SHOW_GETTING_STARTED`.
- **The voice pages now coach you toward a great recording.** The reference-clip
  section on **Add a voice** and the voice edit page opens with recording tips —
  starting with the most common mistake, speaking too softly — covering volume,
  mic distance, room echo, pacing, and clip length. Your clone can only ever
  sound as good as the clip it learns from.

### Changed
- **Redesigned the Studio project header.** The toolbar for a project is now a
  cleaner three-row card: the identity row (← Projects, title, Rename) carries
  compact **chunks / spend / credit** stat chips, the status pill, and the
  project menu (⋯) all grouped together; the final-audio player is the hero
  underneath; and the voice/format pickers sit alongside the audio-output
  buttons on their own row. Buttons that can't do anything in the current state
  are now **hidden rather than greyed** — while chunks still need generating,
  "Generate remaining" leads alone and Build final / Download / Approve stay out
  of the way until there's a final to act on. The time estimate and progress
  messages now sit right under the action buttons, and **"Download draft
  version" is now "Download preview."**

## [0.64.0] - 2026-07-18

### Added
- **See your remaining credit on the Dashboard.** Accounts with a prepaid
  balance now get a credit readout on their Dashboard — the amount available, a
  note that it spends down as you generate and pauses at $0, and a "Need more?"
  contact link when a support address is configured. Unlimited accounts see no
  change.

### Fixed
- **Cleaning up a voice reference clip no longer times out on a longer
  recording.** "Use this recording" (and **Preview cleanup** on an upload) ran
  the resemble-enhance cleanup while you waited on the request, so a longer clip
  could sit past the server's limit and fail with a gateway error — with nothing
  in the logs to explain it. The cleanup now runs in the background: the page
  stages your clip immediately and waits for the result to appear, however long
  the cleanup takes. A cleanup that can't finish still falls back to your
  original take, as before.
- **The Studio Inspector's credit badge now updates as you spend.** Generating a
  chunk — or the full **Preview final audio** — charges your balance right away,
  but the "credit" badge only refreshed on Preview, so it looked like nothing had
  happened. It now repaints from each render, tracking your spend live. (A single
  render costing a fraction of a cent still rounds to the same dollars-and-cents
  figure, so tiny chunks may not appear to move it.)

## [0.63.0] - 2026-07-18

### Added
- **Creating a user now hands you a set-password link, not just a password.**
  **+ Create user** surfaces two things at once: a signed, 7-day link the new
  person clicks to choose their own password (and get signed in), plus the
  temporary password kept as a fallback. Invite a friend by texting or emailing
  them the link — no need to relay a generated password. Each has its own Copy
  button.
- **Restore a default with one click on the Advanced settings.** Every threshold
  under **Settings › Advanced — detection thresholds** now has a **Reset to
  default** button that puts the field back to its shipped value (a bad number
  here can quietly wreck generation). Like any change on the page it's staged
  until you click **Save settings**. Available to everyone, since anyone can
  fat-finger a threshold.

### Changed
- **"Invite by email" is now "Invite by link."** The app sends no email — it
  hands you a link to share yourself — so the old label was misleading. The
  button, its submit action, and a new hint make clear that you copy the link
  and send it however you like.
- **The Health page is now SuperAdmin-only.** It reports instance-wide
  diagnostics — database, queue, scheduler, and provider readiness — that only an
  administrator acts on, so it no longer appears in the nav or on the dashboard
  for regular users, and visiting it directly returns 403.
- **Two instance-wide switches are hidden from regular users' Settings.** The
  **ASR transcript QA** master switch and the **Detection LLM provider** are
  administrator concerns, so a regular user no longer sees (or can save) them.
  Everyone still controls **Studio remediation**, **API remediation**, **Max
  re-rolls**, the **Advanced** thresholds, and the **Pronunciation
  pre-processor**.

## [0.62.0] - 2026-07-18

### Changed
- **QA badges now tell the whole story on hover.** Each generated chunk's QA
  badge is now one of three states — a quiet green **✓** when it passed, an amber
  **fixed** when the automatic check re-rolled or trimmed the audio to clear a
  problem, and a red **check** when something was flagged that needs your ear.
  Hovering (or keyboard-focusing) a badge opens a card that names the problem,
  says what happened, states any auto-fix that changed the audio, and offers the
  matching undo — **Re-roll again** / **keep original** on a re-rolled clip,
  **Restore full take** after a trim, or **Re-roll · Play · Dismiss** on a flag
  left for you. The pills in **Takes & tuning** use the same card. This replaces
  the standing explanatory paragraph above the chunk list and the terse tooltip
  that only repeated the problem code.

### Added
- **Dismiss a QA flag you've checked.** Once you've listened to a flagged clip and
  it's fine, **Dismiss** quiets the badge to a muted **reviewed** without touching
  the audio; regenerating the chunk re-checks it from scratch.
- **First-run QA hint.** A one-line, dismiss-for-good banner introduces the QA
  badges the first time you open a project — replacing the paragraph every
  returning user scrolled past.

## [0.61.1] - 2026-07-18

### Fixed
- **Clearer "declined" label on the Pronunciations page.** In the APPROVED
  column, a respelling you turned down now reads as a neutral gray **No** instead
  of an amber **Pending**. "Pending" wrongly implied the suggestion was still
  awaiting a decision, when these are LLM suggestions you've already declined so
  future runs stop re-proposing them.

### Security
- **Suspended accounts can no longer regain access through a set-password link.**
  Completing an invite or force-reset link (valid for up to seven days) no longer
  reactivates or signs in an account that has since been suspended — the link now
  bounces to the login screen with the standard "suspended" notice, matching how
  suspension is enforced across login, 2FA, SSO, and every panel request. Setting
  a password on an active or freshly-invited account is unchanged.

## [0.61.0] - 2026-07-18

### Changed
- **Cleaner chunk seam.** The zone between two chunks on a project page is now a
  single quiet connector line that pairs **Preview stitch** with **Insert chunk**
  as borderless text actions — replacing the bulky uppercase pill, the separate
  insert row below it, and the heavy bordered box that used to drop in on preview.
  Preview stitch stays in place but only lights up once both neighbors have
  audio, with a short reason shown underneath until then; the stitched clip now
  plays inline in the same audio player used everywhere else, and the button
  turns cyan with a pause mark while it sounds.
- **Takes & tuning, presets first.** The per-chunk tuning panel now leads with a
  **Delivery** row — **Steady / Balanced / Expressive** chips that set the whole
  knob group in one click — and collapses the raw sliders behind a **Fine-tune**
  toggle that remembers whether you keep it open. The wall of help text is gone:
  each control carries a one-line hint plus an ⓘ that opens a short explanation on
  demand. Dragging any slider off a preset quietly switches to "Custom", **Reset
  all** returns everything to the project's inherited tuning, and the seed pin
  stays in view. Nothing is lost — the same knobs, seed, Preview / Save tuning /
  Re-roll, and full take history are all still there, just calmer. The chip values
  are engine-aware (classic vs Turbo) and re-point live when a chunk's voice
  changes.

### Fixed
- **Stale stitch previews are discarded when a neighbor's take changes.** A
  Preview stitch clip is built from each neighbor's currently-selected take, so
  selecting a different take for either chunk (or otherwise changing its audio)
  now drops the preview instead of leaving the old, no-longer-accurate join on
  screen. The seam stays ready, so you can re-stitch the new pairing in one
  click; previews on unrelated seams are left untouched.

## [0.60.1] - 2026-07-17

### Fixed
- **Readable muted text and visible form fields (WCAG AA).** The dim gray used
  for help text, empty states, table headers, timestamps, and footnotes
  measured below the 4.5:1 accessibility contrast floor on the app's dark
  surfaces — the dimmest tier as low as 2.4:1. Both gray tiers are brightened
  at the design-token level so every page picks the fix up at once, and the
  type hierarchy keeps its order (primary → secondary → muted → faint). Text
  inputs, selects, textareas, and checkboxes also move to a shared border tone
  so a resting field shows a visible boundary (≥3:1) instead of blending into
  the panel behind it — focus styling is unchanged. The tiny min/max labels
  under tuning sliders go from 10px to 11px. Verified with a full-interface
  contrast audit (16 admin pages plus login): zero remaining AA text failures
  and zero form-control boundaries under 3:1.

## [0.60.0] - 2026-07-17

### Added
- **Clean up project.** A new ⋯-menu action deletes every take except each
  chunk's selected one — rows and audio files — so an old project keeps only
  the clips actually in use. Nothing that plays changes: the selected takes,
  the built final, chunk statuses, and an existing approval all survive. The
  action sits behind a confirm dialog and is refused while a background
  generation run is working on the project.
- **Download archive.** A new ⋯-menu download packages an approved project
  into a single .zip for offline keeping: the approved audio, the provenance
  receipt, and a clips/ folder holding every saved take, with the selected
  one marked. The manifest lists each clip with its SHA-256, source, seed,
  duration, and the text it read, so the alternates stay verifiable offline.
  Run Clean up first to archive only the selected clips. Together the two
  actions make it easy to keep a local record of a finished project and then
  delete it from the site.
- **Estimated time to generate the remaining clips.** As each clip renders, the
  app now records how long it took and learns a per-model average — a Chatterbox
  Turbo voice and a classic Chatterbox voice keep separate rates, and the cost of
  any automatic re-rolls is folded in. From that history the project page shows an
  up-front "About 2 min to generate the N remaining clips" before you start, and a
  live "· about 2 min left" that ticks down as a background run works (also on the
  Jobs page and the `/v1` API status poll). The pre-run estimate stays current as
  you edit, skip, or re-voice clips, and self-corrects against the run's own pace
  once it's underway. It's only ever an estimate — re-rolls are unpredictable — but
  it gets steadier the more you generate.

## [0.59.1] - 2026-07-17

### Fixed
- **Markdown bullet cleanup no longer rewrites unrelated line endings.** The
  0.59.0 bullet-stripping step canonicalized CRLF newlines across the whole
  input, which disturbed CRLF text that was not part of a list. The step now
  handles CRLF only within the list items it rewrites, leaving the newlines of
  surrounding text untouched.

## [0.59.0] - 2026-07-17

### Added
- **Skip the pronunciation check when creating a project.** The new-project
  screen now runs the pronunciation check as an interruptible step with a
  **Skip** button beside it. Clicking Skip ends the check and goes straight to
  the Studio project page with the text already split into chunks — the check is
  optional, but chunking is not. Any respellings already in your dictionary are
  still applied. If the check finds nothing to review, or you let it finish, the
  flow is unchanged (a direct create, or the review screen). Behind the scenes
  the check now runs as an abortable request that creates nothing until you
  commit, so skipping can never leave a half-made or duplicate project.

### Fixed
- **Pasted bullet lists no longer leak stray "*" into the audio.** Markdown list
  markers (`*`, `-`, `+` at the start of a line) are now stripped during text
  normalization, so a pasted bullet list can no longer reach the TTS engine as
  literal asterisks that get spoken or corrupt a clip. Each item also gets its
  own sentence-ending period, so bullets read as separate sentences instead of
  running together, and a hard-wrapped item (with hanging-indent continuation
  lines) is rejoined into one sentence rather than being split at the wrap. A
  blank line still ends the list, and emphasis (`*note*`), a leading negative
  number (`-5 degrees`), and inline math (`3 * 4`) are left untouched.

## [0.58.1] - 2026-07-17

### Fixed
- **A long "Generate remaining" run no longer dies mid-way.** A background run
  is bounded by a fixed worker time budget per attempt; a big or slow run
  could outlive it, getting killed mid-render and then refused a retry —
  surfacing as `has been attempted too many times` with the run stuck
  incomplete. The run now checkpoints itself as it nears the time limit and
  hands the remaining chunks to a fresh attempt, so it always finishes
  regardless of size. If a run is ever still interrupted (e.g. a hard worker
  kill), the error now reads as "The run was interrupted by the background
  time limit — finished clips are kept, Generate remaining picks up where it
  left off" instead of the raw internal exception message.

## [0.58.0] - 2026-07-17

### Added
- **Fix a bad clip without stopping the run.** While a background "Generate
  remaining" run is working, each chunk's **Regenerate** button now adds that
  clip to the run instead of being locked out — it regenerates after the clips
  already in line, and the run's clip count grows to match. Queueing the same
  clip twice is a no-op, and a run that's stopping won't take new work.

### Fixed
- **Re-rolls on the local Chatterbox sidecar are genuinely random.** An
  unpinned request used to continue the sidecar's random state from wherever
  the previous seed-pinned render left it, so a QA re-roll after a pinned take
  drew the same "alternative take" every time. Unpinned requests now re-seed
  from fresh entropy. (Replicate renders always rolled fresh; restart the
  sidecar to pick this up.)
- **QA re-rolls that don't beat the flagged take no longer save a duplicate.**
  When every automatic re-roll scored at or below the flagged take, the
  original audio was re-recorded as a new "remediate" take — a byte-identical
  copy in the take history. The flagged take now simply stays in place with
  its QA badge, inviting a manual re-roll.
- **Pasted headings now end with a period.** A block with no terminal
  punctuation (a heading like "About 7× cheaper") could be merged straight
  into the next paragraph and read as one run-on sentence. Every block now
  ends in terminal punctuation before chunking — a bare block gets a period,
  and a trailing colon or semicolon is swapped for one. Text from the
  Bespoken plugin is unaffected (its blocks already arrive terminated).

## [0.57.0] - 2026-07-17

### Added
- **The Inspector now shows the whole story before you spend.** The Studio
  Inspector's preview runs the same text pipeline projects use — cleanup and
  normalization, your approved pronunciation respellings, and your spoken-quotes
  setting — so the chunks on screen are exactly what a project would read. The
  breakdown now includes an **estimated cost** for rendering every chunk once,
  quoted at your account's rates (SuperAdmins see the actual provider figure,
  plus what users are billed when a markup is configured) alongside your
  remaining credit. When the pronunciation pre-processor is enabled, new LLM
  respelling suggestions appear in the preview with a one-click **Add to
  dictionary**.
- **Create a project from the Inspector — renders included.** The Inspector's
  closing action is now **Create project**: it turns the inspected text into an
  editable Studio project, and any chunks you already generated in the Inspector
  carry over as real takes (labeled "carried over from the Inspector") — the
  audio you paid for and auditioned is kept, not thrown away, and nothing is
  billed twice. A render only attaches when its text and voice still match the
  project's chunk, so stored audio can never contradict its script.

- **Watch a long generation progress clip by clip.** While an async job is
  `processing`, the status endpoint (`GET /v1/text-to-speech/jobs/{id}`) now
  carries a `progress` object — `stage` (`generating` or `stitching`),
  `chunks_done` / `chunks_total`, a `percent`, and a ready-made message like
  "Creating clip 25 of 50" — so a polling client (the Bespoken plugin, a
  script) can show a real progress bar instead of an indeterminate spinner.
  Strictly additive to the ElevenLabs-compatible surface: the field is `null`
  before the worker starts and after any terminal status, and clients that
  ignore it behave exactly as before.
- **"Generate remaining" keeps working after you leave the page.** Studio's
  generate-everything button used to drive each chunk from the open browser
  tab, so navigating away silently killed the rest of the run. It now
  dispatches a background run to the queue worker: the project page follows
  along live ("Creating clip 4 of 12", chunk cards filling in as clips land),
  a **■ Stop** button winds the run down cleanly (the clip being rendered
  finishes and is kept), and reopening the project mid-run picks the progress
  back up right where it is. Clicking again while a run is active simply joins
  it — never a second, competing run.
- **A Jobs page to watch and manage background runs.** **Jobs** (in the
  account menu) lists every "Generate remaining" run with its status, live
  progress, and — when something goes wrong — the reason it failed, plus a
  Stop for anything still queued or running. You see your own runs; a
  SuperAdmin sees everyone's. A run stuck on *queued* here is the telltale
  that no queue worker is draining the queue.

### Changed
- **Manual chunk actions wait their turn during a background run.** While a
  "Generate remaining" run is active, the per-chunk Generate/Re-roll buttons
  and Build final are refused with a clear message (and disabled on the page) —
  they'd race the worker over the same chunks, and the same money.
- **Chunks no longer autoplay as they generate.** During a background run you
  may not be on the page (or even in the room) when a clip lands, so finished
  chunks now appear ready to play instead of starting themselves.
- **Inspector copy no longer references the Bespoken plugin.** The normalized-
  text summary now reads "Cleaned and normalized text of N chars, split into
  M chunk(s), as shown below."
- **One clear render button in the Inspector.** "Stitched (production)" is now
  **Preview final audio** — the render that sounds exactly like what a project
  delivers, with a tooltip saying so. The old "Whole (single call)" button is
  gone: it sent the entire text as one engine call, which no delivery path ever
  produces, so it only billed money for unrepresentative audio. Seam
  diagnostics are still covered by the concatenate bar, which stitches the
  exact chunk renders you heard.
- **The per-chunk "include" checkbox is now "stitch test".** It only ever
  selected chunks for the "Concatenate selected" seam test, but next to the new
  Create-project action it read like project inclusion. The new label and its
  tooltip make the scope explicit: it has no effect on Create project — every
  render you make in the Inspector carries over regardless.

## [0.56.0] - 2026-07-16

### Added
- **Give a user a prepaid credit allotment.** Every account now carries an
  optional dollar balance (the default is unlimited — nothing changes for
  existing users). From the Users page a SuperAdmin can grant credit ("here's
  $5 to try it"), adjust it down, or clear it back to unlimited; the table
  shows every balance, and the user's drawer adds a ledger of recent charges
  and grants plus lifetime billed-vs-actual totals. Every render — Studio
  takes and previews, `/v1` calls in both dialects, Inspector experiments,
  Genblaze runs — books an append-only ledger entry, so spend is accountable
  per user even on unlimited accounts.
- **Running out of credit pauses new generation, and only new generation.**
  A drained balance stops fresh renders with a clear message — Studio buttons
  explain it in a toast, the ElevenLabs-dialect API answers 402, the OpenAI
  dialect answers the `insufficient_quota` error real OpenAI SDKs already
  understand, and queued jobs fail cleanly instead of silently. Everything
  already generated keeps working: playback, stitching, seal, receipts, and
  downloads never check the balance. Work that started under budget finishes
  (the balance may dip slightly negative), and the hourly API-key rate limit
  still applies independently.
- **Charge users a markup while seeing your actual costs.** A single
  `TTS_CREDIT_MARKUP` multiplier (env-only, so users can't edit it) sets what
  limited users are charged and shown — their est.-spend readouts, balance,
  and Account page all quote their price, while SuperAdmins keep the actual
  provider figures with a "users are billed N×" note. Limited users also get
  a live remaining-credit readout in the Studio header and a balance card on
  their Account page.

### Changed
- **Turbo sound tags are readable now.** The Studio chip row (`[sigh]`,
  `[cough]`, `[laugh]`…) no longer fades into the card: chips get a real fill
  and monospace tag text with the brackets dimmed like editor syntax, so the
  words scan at a glance, and hovering shifts a chip to the cyan action accent
  to make "click to insert at the cursor" obvious.

### Fixed
- **Long voice IDs no longer center when they wrap.** The click-to-copy
  voice_id in the Voices table is a button, and buttons center their text by
  default — invisible until a slug like `default-turbo-male` wrapped to two
  lines. Wrapped IDs now stay left-aligned.

## [0.55.0] - 2026-07-16

### Added
- **Pick your microphone right in the voice recorder.** Once the mic is
  enabled, an input-device picker appears beside the recorder controls showing
  every available microphone — switching re-points the live level meter
  instantly, the choice is remembered for next time (falling back cleanly if
  that device is gone), and the list refreshes as devices come and go (USB mic
  plugged in, AirPods connecting). The picker is locked while a take is
  recording, and if the in-use microphone disconnects mid-session the recorder
  stops safely, keeps the partial take for review, and offers to re-enable.
- **Reject & re-record from the take chooser.** When a recorded clip's
  cleaned-up/original preview is up, a new "Reject & re-record" button
  discards the take and drops straight back into the armed recorder — mic
  still live, meter running — instead of "Start over"'s full reset (which
  remains for switching to upload).

### Changed
- **"Use this recording" now shows its work.** The button goes busy
  ("Cleaning up…") and can't double-fire while the clip is being prepared,
  progress and errors are reported right next to the button (not only in the
  footer status line), the "takes about a minute" message only appears when
  cleanup will actually run, and Re-record is frozen mid-flight so a retake
  can't be yanked away when the preview arrives.
- **The teleprompter hint tells the truth.** "We'll clean up room noise and
  normalize loudness after." now tracks the processing checkboxes — each half
  of the promise appears only when the matching step (cleanup checkbox,
  Store-raw off) will actually run.

### Fixed
- **The Enable-microphone button disappears once the mic is granted.** It was
  meant to all along, but a CSS display conflict kept it visible alongside
  Record; the mic-ready state now reads unambiguously.
- **Clickable things look clickable again.** Tailwind v4 quietly dropped the
  pointer (hand) cursor on buttons; it's restored app-wide for enabled
  buttons, and the Studio tuning sliders got the pointer the voice-page dials
  already had.

## [0.54.0] - 2026-07-16

### Added
- **Spoken quote marks — quoted passages announced aloud, news-narration
  style (opt-in).** A new per-user setting (Settings → Speech generation →
  "Spoken quote marks") voices paired double quotes as words, three ways:
  "open quote … close quote", "quote … close quote", or "quote" at the opening
  only. Detection is deliberately conservative: curly marks are trusted as
  written, a straight `"` is read from context, and only marks that confidently
  pair are altered — a stray inches mark (`5' 10"`) or an unclosed quote is
  always left byte-for-byte as typed. Long quotations that continue across
  paragraphs (re-opened each paragraph, closed once at the end) are announced
  once at the true start and closed once at the true end. Applies after the
  pronunciation dictionary in Studio projects and the Genblaze demo (which
  reports a `quotes` pipeline step); direct `/v1` API calls — including the
  Bespoken plugin — are never affected, even when the key's owner has opted
  in. See `docs/SPOKEN-QUOTES.md`.

### Fixed
- **Skipping a chunk no longer shortens the pause it leaves behind.** The
  stitcher sizes each seam from the preceding chunk's break, so skipping a
  paragraph-ending chunk used to collapse that paragraph pause (400 ms) to the
  previous chunk's sentence gap (120 ms) — audibly rushing the join. A skipped
  chunk's break now folds into the preceding seam (the larger break wins) in
  both the rebuilt final and the stitch preview, so the pause where the chunk
  used to be still matches the text boundary that remains.

## [0.53.0] - 2026-07-15

### Added
- **Fully-local pronunciation detection via Ollama (`ollama` provider).** The
  pronunciation pre-processor's detection LLM can now be a model served by a
  local [Ollama](https://ollama.com) instance — no API key, no per-call cost,
  and no text leaves the machine, pairing with `TTS_PROVIDER=local` for an
  end-to-end offline dev stack. Select `ollama` as the detection provider and
  point `OLLAMA_HOST` at the server as seen from the runner
  (`http://host.docker.internal:11434` from the DDEV runner container;
  `http://127.0.0.1:11434` beside a host runner); the default model is
  `gemma4:26b`. Structured output uses Ollama's native `format` parameter, so
  the substitution-map JSON is grammar-enforced for any pulled model. The
  Health page names `OLLAMA_HOST` (with per-topology values) as the fix when
  the keyless provider isn't configured, instead of asking for an API key.
- **ElevenLabs voice-ID aliasing (`elevenlabs_voice_aliases`).** The ElevenLabs
  dialect gains the same opt-in alias map the OpenAI endpoint has had: map real
  ElevenLabs voice IDs (e.g. `21m00Tcm4TlvDq8ikWAM`) to your own voices in
  `config/tts.php` and an existing ElevenLabs client works with zero
  client-side changes — across `POST /v1/text-to-speech/{voice_id}` (plus
  `/stream` and `/jobs`) and `POST /v1/projects`. Keys match exactly
  (ElevenLabs IDs are case-sensitive), unlisted IDs pass through unchanged,
  aliasing never widens voice visibility beyond the key owner's set, and 404s
  keep echoing the client's original ID. The full resolution/fallback
  procedure (alias map → owner-scoped slug → UUID → dialect-shaped 404) is now
  documented for both dialects in docs/VOICES.md ("How a voice ID resolves").
- **Skip a chunk in the final assembly (Studio).** Each chunk card gains a
  🔊/🔇 toggle next to the trash can: a skipped chunk stays in the project —
  text, tuning, and takes intact, still playable and regenerable — but is left
  out of the stitched final and stitch previews, and Generate all and the
  Build-final readiness check ignore it (an ungenerated skipped chunk no
  longer blocks the build). The row dims to make the exclusion visible, and
  toggling marks a built final stale (clearing its seal) so the next build
  reflects the change. Sealed receipts and the /verify page list skipped
  chunks labeled "skipped — not in final audio" rather than omitting them.

### Fixed
- **A stressed final word is no longer clipped at a stitch seam.** The
  per-chunk tail trim's voiced-coda rescue compared each word-final voiced
  window against the chunk's mean RMS — a reference every pause dilutes — so
  an ordinary stressed sentence-final word ("…to love what you *do*") could
  measure "louder than speech", be mistaken for a re-swell swoosh artifact,
  and get hard-cut mid-vowel right before concatenation (heard as the word
  clipped at the seam; ASR QA can't catch it, since Whisper reconstructs the
  word from the surviving onset). The gate now references the chunk's peak
  speech window: nothing tapering off a word beats everything the speaker
  said, while a genuinely appended swoosh still trips it. Drone, blip, and
  swoosh removal are unchanged.
- **Studio's 🎲 seed button now rolls a visible random seed.** It previously
  cleared the field back to blank (= inherit), which looked like a no-op
  whenever the project pinned a seed — the placeholder showed the same
  inherited number. Clicking the dice now drops a fresh random seed into the
  field so the pin is visible and re-usable; clearing the field by hand still
  means inherit/random.

## [0.52.0] - 2026-07-14

### Added
- **Run Chatterbox locally for development (`TTS_PROVIDER=local`).** A new
  in-repo FastAPI sidecar (`chatterbox-sidecar/`) serves both engines —
  classic Chatterbox and Chatterbox Turbo — on the developer's own machine,
  lazy-loading each model on first use. The new local provider drives it
  through the same model catalog, so input caps, sound tags, tuning knobs,
  and seed pins behave as on Replicate; no credits, no rate limits, and it
  works offline once the ~3.8 GB-per-engine models are cached. A clip-less
  Turbo voice speaks the model's built-in voice locally (named presets are a
  Replicate deployment feature). Setup for macOS, Linux, and Windows — with
  machine requirements — in `docs/CHATTERBOX-LOCAL.md`.
- **`ddev chatterbox` command + opt-in autostart.** `ddev chatterbox
  start|stop|status|logs` manages the sidecar as a background host process;
  copying `.ddev/config.local.yaml.dist` to the git-ignored
  `.ddev/config.local.yaml` makes `ddev start`/`ddev stop` bring it up and
  down automatically, per developer. A containerized alternative ships as
  `.ddev/docker-compose.chatterbox.yaml.dist` (CPU-only; opt-in so `ddev
  start` never forces a multi-GB torch image build on developers who don't
  use it).
- **`tts:chatterbox:health [--deep]` and Health-page awareness.** The command
  reports sidecar reachability and per-engine load state; `--deep` proves a
  full synthesis round-trip returns WAV. `tts:doctor` and the admin Health
  page now check the sidecar when the local provider is active instead of
  warning about an unknown provider.

## [0.51.0] - 2026-07-14

### Added
- **Owner filter on the Voices page (SuperAdmin).** The same control as the
  Studio projects list: it lands on your own voices (yours plus the shared
  built-ins), picking another user shows what *they* see — their voices plus
  the built-ins — and "All owners" shows every voice, owner-labeled. Regular
  users' scope is unchanged; the parameter never widens what they can see.

### Changed
- **Studio's owner filter now lands on your own projects.** The dropdown lists
  the signed-in admin first ("(you)"), then "All owners", then the remaining
  project owners alphabetically — instead of defaulting to everyone's projects
  mixed together. Widening is an explicit `?owner=all`; note that ownerless
  projects (e.g. API-failure recoveries whose key had no owner) only appear
  under "All owners".

## [0.50.0] - 2026-07-13

### Added
- **Social/SEO link previews on the public pages.** A shared
  `partials/social-meta` head block emits Open Graph and Twitter Card tags
  (plus a canonical URL and description), wired into the landing, about, and
  verify pages so a pasted link previews with a title, description, and
  1200×630 image in Slack, iMessage, LinkedIn, Discord, and X. Image URLs are
  absolute via `secure_asset()`, so keep prod `APP_URL` correct.
- **Branded pages for nginx-level failures.** Errors nginx raises before the
  app can respond (deny-rule 403s, php-fpm unreachable/timeout 5xx) used to
  show nginx's bare default page; self-contained static pages under
  `public/errors/` now match the app's designed error views. Laravel-level
  errors still render their Blade views; prod needs matching `error_page`
  directives in the Forge panel.

### Changed
- **About page: credit classic Chatterbox as the expressive engine.** The
  Voices section previously listed only Turbo's advantages; it now names
  Chatterbox (the default) as the expressive original with its exaggeration
  dial, so the more-expressive default engine isn't undersold next to Turbo's
  speed.

### Fixed
- **Support edits resolve against the project owner's data, not the
  visiting admin's.** A SuperAdmin editing another user's project used to
  pick from and resolve their OWN voice list when switching the project's (or
  a chunk's) voice, silently stamping a voice row the owner couldn't see onto
  the owner's project — which a later duplicate then had to clone back as a
  confusing "Voice 2" copy. Voice pickers and both voice-change endpoints now
  scope to the project owner, and "Start over" re-chunks with the owner's
  approved pronunciation dictionary rather than the admin's (lexicons are
  strictly per-writer).
- **Duplicating a project no longer mints a redundant voice clone.** When the
  duplicator already has a voice that sounds identical to the source
  project's — same engine, same tuning, byte-identical reference clip — the
  copy now points at that voice instead of cloning a "-2" duplicate. Near
  misses (same clip, different tuning) still clone so the copy keeps
  generating exactly like the source, and the success message now names only
  voices that were genuinely copied in.

## [0.49.0] - 2026-07-12

### Added
- **Chatterbox Turbo as a second speech engine, chosen per voice.** A new
  model catalog (`config tts.models`) runs classic `resemble-ai/chatterbox`
  and `resemble-ai/chatterbox-turbo` side by side, each with its own pinned
  version and input contract. Every voice picks its engine on the voice page
  (`voices.model`; existing voices keep classic); projects inherit the voice's
  engine and a per-chunk voice override switches engines mid-project — the
  Genblaze pipeline resolves it from the voice it's already handed.
  - **Turbo's own tuning dials** — top-p, top-k, and repetition penalty join
    the shared temperature dial and seed pin, and every knob surface (voice
    edit, A/B bench, Studio inspector, per-chunk rows) shows exactly the
    effective voice's knob set. Named presets now belong to the engine they
    were authored on and only appear where they apply. For ElevenLabs-dialect
    callers on a turbo voice, `stability` still means steadier-vs-varied (it
    maps inversely onto temperature); `style` is accepted and ignored.
  - **Built-in Turbo voices** — a turbo voice can speak through one of the
    model's 20 presets (Andy, Laura, …) instead of a reference clip; clips,
    when used, must be longer than 5 seconds (validated at save).
  - **Paralinguistic sound tags** — `[laugh]`, `[sigh]`, `[cough]` and friends
    pass through to turbo, are stripped from classic payloads (which would
    read them aloud), and are excluded from ASR-QA expectations so tagged
    chunks don't false-flag. Studio chunk cards on a turbo voice show a row
    of clickable tag chips that insert at the cursor, and the Takes & tuning
    help text now explains whichever engine's knobs are on screen.
  - **Rendered tags survive stitching.** A chunk ending in a sound tag puts
    loud, wanted non-speech exactly where the tail-artifact cleanup hunts
    Chatterbox's drone — it was cropping the laugh/sniff right off. Every
    stitching surface (final builds, stitch previews, the /v1 paths, the
    inspector, and the Genblaze runner) now spares the tail of a tag-ending
    turbo chunk, keeping the safe silence trim and seam fades; and ASR-QA
    excuses its tail/gap signals on tagged chunks (a tag at the end is not
    junk to trim, a mid-text gasp is not a pause to re-roll) while still
    catching genuinely dropped words.
  - **Per-engine spend metering** — lifetime characters are now counted per
    model (`tts_spend_counters`, existing spend backfilled as classic) and
    priced at per-model rates (`TTS_COST_PER_1K_CHARS`,
    `TTS_COST_PER_1K_CHARS_TURBO`); the est.-spend readouts show one total
    with a per-engine breakdown in the tooltip.
  - **The OpenAI dialect's `model` field now means something** — `chatterbox`
    / `chatterbox-turbo` switch the engine per request; OpenAI's own names
    (`tts-1`, …) stay ignored unless opted in via `tts.openai_model_aliases`,
    so stock clients never switch engines by surprise.
  - **Guard rails** — turbo's 500-character per-call cap is enforced when
    saving Studio chunk text, when chunking for a turbo voice, and again in
    the provider before any credit is spent; `tts:doctor`/health warn on an
    unpinned model version or a chunk budget over a model's cap.
- **The Dashboard's Connect card now shows how the pieces fit together.** A
  new "How it fits together" section renders complete, ready-to-run cURL
  commands for both dialects — ElevenLabs (`/v1/text-to-speech/{voice}` with
  `xi-api-key`) and OpenAI (`/v1/audio/speech` with a Bearer token) — built
  from your own Base URL, API key, and first voice, with the dynamic values
  highlighted. Clicking any voice ID chip swaps that voice into both examples
  (and still copies the ID), and each example's Copy button copies exactly
  what's on screen. Users without an API key yet see a readable
  `YOUR_API_KEY` placeholder instead of a broken command, and a caption
  explains how the OpenAI `model` field picks the engine.
- **Duplicating another user's project now brings its voices along.** Voices
  are personal, so when a SuperAdmin duplicated a user's project for support,
  the copy referenced voices the SuperAdmin couldn't reach — and switching to
  one of their own marked every generated chunk stale. Duplicate now copies
  any out-of-reach voice (project-level and per-chunk overrides) into the
  duplicator's account verbatim — same voice_id, name, tuning, and an
  independent copy of the reference clip — and points the copy at the new
  voices, so generated chunks stay ready to regenerate. If the duplicator
  already uses that voice_id, the copy is minted as "voice-2" (name suffixed
  to match) and their existing voice is left untouched. The success message
  names the voices that came along.

## [0.48.0] - 2026-07-11

### Added
- **Take and chunk players show the clip's length without playing it.** Every
  take now records its duration at synthesis time (measured from the audio
  itself), so the Studio players print "0:00 / 0:08" the moment the page
  loads instead of "0:00 / 0:00" until you press play — and selecting a
  different take updates the chunk readout instantly. Take players still
  load no audio up front, so a project full of takes stays as light as
  before. A migration backfills the durations of existing takes; any take
  whose file can't be read simply keeps the old show-on-play behavior.

## [0.47.2] - 2026-07-11

### Changed
- **Take rows now say where each take came from in plain language.** The raw
  source tokens ("generate", "use", "remediate", "duplicate") became
  "rendered with Generate", "kept from a preview", "QA auto-fix", and
  "copied from the original project" — so a take carried over by Duplicate
  project reads as provenance, not as a button. "QA auto-fix" is deliberately
  outcome-neutral; the take's QA badge says whether the fix recovered.

## [0.47.1] - 2026-07-11

### Changed
- Hovering a QA badge now shows the help (question-mark) cursor instead of the
  text I-beam, signaling "hover for details" the same way the est. spend chips
  do.

## [0.47.0] - 2026-07-11

### Added
- **Studio warns a SuperAdmin before they edit someone else's project.** A
  SuperAdmin can open any user's project for support, which made an accidental
  edit of someone else's work one mis-click away. The editor now shows an
  always-visible "⚠ {owner}'s project" badge, keeps the text fields read-only,
  and intercepts the first mutating action with a warning dialog offering
  Keep read-only / Duplicate instead / Edit their project. Listening,
  downloads, and copying text stay free; the opt-in lasts for the tab. The
  "Start over" page carries the same owner warning on its reset confirmation.

### Changed
- **The Studio QA badges now speak plain language.** Scorer codes are shown as
  human labels ("possible cut-off", "boundary hum", "loud tail"…), and a
  recovered re-roll ("QA ✓ — fixed by re-roll") reads clearly apart from an
  unrecovered one ("re-rolled ×3, still flagged" — the auto-recovery gave up,
  listen to it). The hover tooltip became one plain sentence per finding
  ("Speech recognition heard 63% of the script — words may be missing or
  garbled.") with the exact measurements kept inline for threshold tuning. The
  raw codes are unchanged in the stored QA reports and the docs.
- **Confirmations are now real in-app dialogs, not browser popups.** Every
  destructive action (delete project/voice/API key/preset/take/pronunciation,
  key reset/regenerate, Start over, insert-with-unsaved-edits) previously used
  the browser's native `confirm()` box — unstylable, and subject to Chrome's
  "prevent this page from creating additional dialogs" checkbox, which could
  silently switch those safety checks off. They all now share one app-styled
  dialog with a clear title, consequence copy, and a red (destructive) or
  amber (warning) action button. The voice-test failure popup became an inline
  message on the button itself.
- **The Studio download buttons now tell you what's happening.** Both
  "Download draft version" and "Download approved version" used to sit silent
  while the server built the file (the receipt zip gathers the approved audio,
  fingerprints every chunk, renders the provenance receipt, and zips it up) —
  long enough to look broken. The buttons now show a spinner and the status
  line narrates each stage of the build with an elapsed-time heartbeat, then
  reports transfer progress and confirms the saved filename.

### Fixed
- A failed download (e.g. asking for the final audio before it's built) now
  shows the actual error message on the page instead of silently downloading a
  `.json` error file.

## [0.46.0] - 2026-07-10

### Added
- **SuperAdmins can filter the Studio projects list by owner.** A new Owner
  dropdown in the header narrows the list to a single user's projects (it only
  offers users who actually own projects). Regular users are unaffected — the
  filter never widens their view beyond their own work.

### Changed
- **The Owner column only renders for SuperAdmins.** For a regular user every
  project on the list is their own, so the column (and the filter) stay hidden
  and the table tightens up to Name / Chunks / Updated / Status.

## [0.45.0] - 2026-07-10

### Added
- **Studio now shows what your audio actually costs.** Each chunk carries a
  small cost chip and the project header keeps a running "est. spend" total,
  priced the way Replicate meters Chatterbox — per input character of the text
  (reference clips and tuning knobs are free). Every real render counts:
  Generate, Re-roll, Preview, auto-remediation, and takes carried over from an
  API call. The numbers update live as you work, and hovering either readout
  spells out the math.
- The counters are **lifetime spend, not a sum of what's still on disk**:
  deleting a take, pruning old takes, or deleting a whole chunk never lowers
  the estimate, because that money is already spent. "Use this take" adds
  nothing (the preview it keeps was already paid for), and a duplicated
  project honestly starts at 0¢ — its audio was billed to the original.
- The rate is configurable via `TTS_COST_PER_1K_CHARS` (default 0.025, the
  Replicate Chatterbox rate). Set it to 0 to hide the readouts entirely, e.g.
  when running a self-hosted provider that costs nothing per call.

## [0.44.0] - 2026-07-10

### Changed
- **"Build final" now waits until every chunk is current.** Editing and saving a
  chunk's text leaves the chunk's old audio in place until you regenerate it,
  and the stitcher would happily build a final with that outdated audio under
  the new words. The button now greys out while any chunk still needs
  generating, and a gentle pulse points at the one step that brings the project
  back in sync — **Generate remaining** while saved text is ahead of its audio,
  then **Build final** once every chunk is current but the stitched final is
  behind. The pulse is deliberately subtle, never nags a brand-new project, and
  stays off for reduced-motion users.

### Fixed
- **A running action button can no longer be clicked again mid-run.** While
  "Generate remaining" worked through a project, each finished chunk refreshed
  the button cluster and quietly stripped the running button's dimmed,
  non-clickable state, so a second click could start a parallel run. The busy
  state now survives those refreshes, and a failed generation updates the
  cluster immediately (a failed re-roll of a completed chunk switches "Build
  final" back off).

## [0.43.0] - 2026-07-09

### Added
- **A real mobile navigation menu.** On phones the top bar had room only for the
  logo and account avatar, leaving Dashboard and Studio with nowhere to go. It
  now collapses below the `md` breakpoint to a single labelled **Menu** button
  that opens a full-screen sheet: an identity header (avatar, name, host) with a
  Close button, the Genblaze Demo chip, large Dashboard/Studio targets, the
  secondary items (Account, API Keys, Voices, Pronunciations, Health, Settings,
  and Users for SuperAdmins), and a pinned **Log out** with the version string.
  The sheet locks page scroll, traps focus, and closes on the Close button,
  Escape, tapping a destination, or widening back to desktop. The desktop bar is
  unchanged.

## [0.42.0] - 2026-07-09

### Added
- **Studio projects show where they came from.** A project created through the
  API now carries a violet badge in the Studio list, so it's no longer
  indistinguishable from one you made by hand. The two API surfaces are told
  apart: **API** marks a project persisted by a text-to-speech call, and **API
  project** marks one created by the `/v1/projects` endpoint (previously
  untagged, so it looked hand-made).

### Changed
- **The Studio home is now two tabs instead of one long scroll.** Projects and
  the Inspector used to stack in a single column, so a growing project list kept
  pushing the paste-and-preview Inspector further down the page. They're now a
  segmented tab control — only one view is on screen at a time — with the active
  tab remembered in the URL (`?tab=`) so refresh and back behave. The Projects
  tab carries a count of your total projects, and its list is a proper
  Name · Chunks · Updated · Owner · Status table (the API-origin badges still
  sit before the status). The Inspector is unchanged in function.
- **The Studio project list is paginated.** It shows a fixed page of projects
  (default 10 per page, set with `TTS_STUDIO_PROJECTS_PER_PAGE`) with a
  "1–10 of N" footer and page controls, so a large library no longer renders as
  one enormous list. The tab's count always reflects the full total, not the
  current page.
- **API-generated projects are named by their text, not a prefix.** A successful
  API generation is now titled by its opening snippet alone — the badge already
  says it came from the API, so the redundant `API generation:` prefix is gone.
  A *failed* generation still keeps its `API failure: …` auto-name so it reads as
  needing attention. Existing projects keep their stored titles.

## [0.41.0] - 2026-07-09

### Added
- **A Temperature dial across the tuning surface.** Chatterbox's native sampling
  temperature — lower is flatter and steadier, higher is livelier but less
  predictable — is now a first-class knob everywhere you tune: per chunk in a
  Studio project, the Inspector's per-preview knobs, a voice's default tuning,
  the "Tune by ear" A/B bench, and named presets. It sits alongside Exaggeration
  and CFG/Pace with a practical 0.5–1.5 range (neutral 0.8). The public /v1 API
  is unaffected — it has no temperature knob and keeps sending the model's own
  default.
- **A per-chunk Seed pin is back in the Studio.** Blank rolls a fresh random
  draw; type a number to pin it, and every take now shows the seed it rendered
  at (a number when pinned, "random" otherwise) so a good take can be spotted
  and re-pinned. It's an honest tool, not a reproduce button: Chatterbox isn't
  bit-for-bit reproducible even with a seed, so a pin only gets you close — the
  UI says as much, and Re-roll still forces a fresh random take.

### Changed
- **The per-chunk tuning area moved to two rows.** With four controls
  (Exaggeration, CFG/Pace, Temperature, Seed), the settings now sit on their own
  row above the Preview / Use this take / Save tuning / Re-roll actions, so
  nothing crowds onto one line.
- **API generations now create an editable Studio project by default.** The
  *API → Studio project* setting defaults to **Always** (was *Never*), so every
  /v1 generation — ElevenLabs `/v1/text-to-speech` and OpenAI `/v1/audio/speech`
  alike — also lands a ready-to-edit project in the Studio, carrying the finished
  audio across. Its dropdown now reads **Always / On Error / Never** instead of
  the raw values. The explicit *Create project* endpoint is unaffected, and a
  choice you've already saved still overrides the default.

## [0.40.0] - 2026-07-08

### Added
- **Hear a pronunciation before you approve it.** Every pronunciation screen
  gains a *▶ Test* button: each suggestion row on the review screen (spoken
  with the project's voice, and it reads the field live, so an edited
  respelling is what you hear), the Respelling field on the add/edit forms,
  and each row of the dictionary table (spoken with your default voice). The
  respelling is spoken inside a short carrier sentence — "Your pronunciation
  will sound like this: …" — which mirrors how it will be heard inside real
  text and avoids a hard model failure on very short inputs. Re-testing an
  unchanged respelling replays instantly from the speech cache instead of
  generating again.
- **Branded error pages.** 404, 403, 419, 500, and 503 no longer show the
  framework's bare white default. Each renders in the app's dark style with
  the status code presented as a pronunciation-dictionary entry (404 →
  "four oh four"), a plain-language explanation, and a way home — the
  Dashboard when signed in, the home page otherwise. API responses under
  /v1 are unaffected and stay JSON.

### Changed
- **Duplicating a project now shows its progress.** *Duplicate project*
  byte-copies every audio file before it finishes, which could take long
  enough to make the page look frozen. The menu button now switches to a
  spinner with "Duplicating…", a status line announces what's happening, and
  repeat clicks can't create a second copy.

## [0.39.0] - 2026-07-08

### Changed
- **Signing in always lands on the Dashboard.** Previously, when the Genblaze
  runner was configured, every sign-in (password, two-factor, social, and
  invitation acceptance) landed on the Genblaze demo page instead. The
  Dashboard is the app's front door; the demo page no longer hijacks it.
- **The Genblaze runner is part of a standard install, and the app now
  assumes it.** `TTS_GENBLAZE_RUNNER_URL` defaults to
  `http://127.0.0.1:8800` — where the setup guide's daemon runs — so a
  default install gets the pronunciation pre-processor and QA-gated
  generation without extra configuration. Set it empty to run without the
  runner.
- **The "Genblaze Demo" nav page is now opt-in.** It exists for hackathon
  judging, so it's hidden unless `TTS_GENBLAZE_DEMO=true` — configuring the
  runner alone no longer surfaces it.

## [0.38.0] - 2026-07-07

### Added
- **Duplicate a project in the Studio.** The project page's ⋯ menu gains a
  *Duplicate project* action that makes a fully independent copy — its own
  text, chunks, and audio files, byte-copied into the copy's own storage
  space. Nothing is shared between the two projects, so regenerating,
  deleting chunks, pruning takes, or deleting either project never touches
  the other. The copy carries each chunk's currently selected take (its
  take history starts fresh), keeps the built final so it's playable
  immediately, and always starts unapproved — an approval belongs to the
  exact project it was granted on, so a copy must earn its own. A chunk
  whose audio file has gone missing from storage doesn't block the
  duplicate; it comes over as ungenerated, ready to regenerate.

## [0.37.0] - 2026-07-07

### Changed
- **One "Add voice" entry point on the Voices page.** The toolbar's stray
  Choose File → Import pair is gone; adding a voice is now a single
  split-button. Its main segment jumps straight to the New voice screen (the
  common path), while the caret opens a menu offering *Record or upload a
  clip* or *Import a voice file…* — the latter opens the file picker directly
  and imports on selection. The menu closes on outside click or Escape and
  supports arrow-key navigation.
- **Importing a voice now lands you on the imported voice.** Instead of
  bouncing back to the list, a successful import opens the restored voice's
  edit page — clip and tuning already in place — ready to rename or retune.
- **Voice IDs are now yours, not global.** A voice_id only has to be unique
  within your own voices (plus the shared built-ins), so importing a voice
  archive — or creating a voice — no longer fails just because another user
  already has one with the same voice_id. Each user's reference clip is
  stored in their own space so identically-named voices can never overwrite
  each other's audio, and the built-in voice_ids remain reserved for
  everyone. Admin voice URLs now use the voice's ID, since a voice_id no
  longer names a single voice across users; `voice:export` accepts a
  voice_id or ID and lists the owners when a voice_id matches more than one.

### Added
- **A per-user "Chunking" setting: packed or per sentence.** A new Speech
  generation section on the Settings page chooses how text is split into the
  pieces sent to the voice model. *Packed* (the default, unchanged) groups
  sentences up to the chunk budget for fewer, longer generation calls;
  *Per sentence* gives every sentence its own chunk — more calls, but each
  sentence becomes its own Studio segment you can re-roll or edit
  independently. Either way, very short sentences still merge with a neighbor
  so they are never synthesized alone (the input Chatterbox tends to garble),
  and over-long sentences still fall back to clause/word splitting. The
  setting follows you everywhere you generate — projects, the Studio
  inspector, your API keys, and Genblaze runs (the runner forwards your
  choice, since the internal pipeline endpoints run without a user) — and can
  be pinned instance-wide with `TTS_CHUNK_MODE`.
- **A workflow to publish the Docker image.** A manual Actions run builds the
  single-image package for amd64 + arm64 and pushes it to the private GitHub
  Container Registry (`ghcr.io/johnfmorton/alias-tts`, tagged `X.Y.Z` +
  `latest`). Manual by design — versions are cut far more often than image
  users need them, so an image is published per chosen release, not per tag;
  docs/DOCKER.md explains granting pull access and logging in. The asset and
  PHP-dependency build stages are pinned to the build platform so CI's
  emulated arm64 leg only pays for the runtime stage.

## [0.36.0] - 2026-07-06

### Added
- **A single-image Docker package.** One `docker run` now stands up the whole
  service: the web app (FrankenPHP), the database-queue worker, the scheduler,
  the Whisper ASR sidecar (transcript QA with auto re-roll/trim), and the
  Genblaze runner — supervised in one container, with all state (SQLite
  database, generated audio, voice clips, secrets, ASR models, TLS
  certificates) on a single `/data` volume. First boot generates `APP_KEY` and
  the app↔runner shared secret, migrates, seeds the admin login from
  `ADMIN_EMAIL`/`ADMIN_PASSWORD`, and pre-seeds the bundled Whisper model so
  no network is needed. ffmpeg ships in-image as a checksum-pinned static
  8.1.2 build (the `tts:doctor` minimum), and setting `SERVER_NAME` to a
  domain turns on automatic Let's Encrypt HTTPS. See the new
  [docs/DOCKER.md](docs/DOCKER.md).

### Changed
- **`tts:doctor` understands co-located Genblaze runners.** A runner whose
  callback target is a loopback listener that actually answers (the Docker
  package's layout) now passes instead of warning about an
  `ALIAS_BASE_URL`/`APP_URL` mismatch; a dead loopback target still warns.
  The no-bucket warning also explains where the runner gets its storage
  config in each layout.

### Fixed
- **A failed final-audio write can no longer masquerade as success.** If the
  storage write of the stitched audio fails (permissions, full disk), the
  speech is now marked Failed with a clear error instead of Completed with a
  missing file — which previously surfaced as a bare 500 on download.

### Changed
- **The landing page now tells the whole ladder — APIs in, Studio at the
  center.** The legend under the waveform is a signal-flow junction: the two
  API entries sit side by side, each dropping a hairline that converges — cyan
  and magenta fading into the gradient's violet — on a Studio plate ("Any call
  can land as an editable project — fix a sentence, re-roll a take, seal the
  final"). The lines draw themselves in as the page loads, and the closing
  line now sequences the pitch: point an app at it first, graduate to Studio
  when a take needs directing.
- **The About page now bridges the APIs to Studio.** The API section closes by
  noting a call doesn't have to end at audio, linking down to Studio; the
  Studio section leads with the bridge — set `api_project_mode` to `always`
  and the same call an app already makes lands as an editable project, or
  `on_error` to turn a failed call into a fixable project instead of a dead
  end — via a new lead card. The seam-preview card merged into "Hear it three
  ways" to make room.
- **The About page walks the waveform.** The landing page's waveform reappears
  after the hero as a clickable map of the tour — five labeled stops (The API,
  Voices, Quality, Studio, Provenance), each dot in its section's gradient
  color, each anchoring to its section — captioned "a call enters at the cyan
  end and leaves the magenta end as a sealed file." Section kickers carry
  stage phrases ("the signal enters" … "it leaves, sealed"), and the landing
  junction mark appears in miniature where the API section hands off to
  Studio. The "Your team" ownership card is parked as the least compelling of
  the grid.

## [0.34.0] - 2026-07-06

### Added
- **A public About page.** `/about` is a feature tour of the whole product —
  the two API dialects, thirty-second voice cloning, QA that listens to every
  take, chunk-level Studio editing, sealed receipts with public verification,
  and the self-hosted "yours, all the way down" pitch — with screenshot slots
  that fill in automatically once captures are dropped at
  `public/images/about/`. The landing page hero now links to it ("About →").

### Changed
- **Alias TTS is now proprietary software; the MIT license is removed.** The
  `LICENSE` file is a "Copyright © 2026 John F. Morton. All rights reserved."
  notice, `composer.json` declares `proprietary`, and the README's License
  section matches. The About page's "MIT licensed" card is gone.
- **GitHub links are hidden while the repository is private.** The "View on
  GitHub" buttons and the nav/footer GitHub links on the landing and About
  pages are commented out in the templates, ready to restore if the repo goes
  public again.

## [0.33.0] - 2026-07-06

### Changed
- **Pinned the Save action on the Add-a-Voice and Edit-voice pages.** The header
  — title plus Cancel and Save — now sticks to the top while you scroll, matching
  the Studio project command bar, so the Save button stays reachable no matter
  how far down the long voice forms you are.
- **Labeled the reference clip "optional, but recommended."** The hint on the
  Reference clip section now makes clear that a clip is optional but strongly
  improves the resulting voice.
- **The reference-clip control now defaults to "Upload a file"** instead of
  "Record with mic"; the in-browser recorder is still one tab away.
- **Refined the landing-page brand lockup and API legend.** The "Alias TTS"
  wordmark now reads as the brand — a larger waveform mark alongside "Alias" in
  bold with "TTS" trailing as a quiet, muted descriptor. The waveform's two
  endpoints are now labeled with the products they speak: a glowing cyan dot for
  the **ElevenLabs-compatible** `POST /v1/text-to-speech/{voice_id}` and a
  glowing magenta dot for the **OpenAI-compatible** `POST /v1/audio/speech`, so
  the "two APIs" headline is spelled out where it counts.

## [0.32.0] - 2026-07-06

### Added
- **Record a reference clip in the browser.** The Add-a-Voice and Edit-voice
  pages now let you record a voice sample directly with your microphone — read
  one of three built-in scripts on an on-screen teleprompter, watch a live
  input-level meter with length guidance, and review the take — as an
  alternative to uploading a file. Progressive enhancement: it appears only when
  the browser supports it, and file upload still works everywhere.
- **Optional AI cleanup of reference clips.** Recorded and uploaded clips can be
  cleaned up automatically — denoise + enhance via
  [resemble-enhance](https://github.com/resemble-ai/resemble-enhance) on
  Replicate — with an Original-vs-Cleaned-up preview before saving; the take you
  pick is exactly what gets stored. Cleanup is on by default, reuses the
  existing `REPLICATE_API_TOKEN`, and degrades safely to the original clip if it
  can't run (turn it off with `TTS_ENHANCE_ENABLED=false`). A daily
  `voices:prune-clips` task clears abandoned previews.

### Changed
- **Redesigned the Dashboard into a launchpad.** The three inert stat cards
  (Voices / API keys / Generations) that left the right half of the page empty
  are replaced by four live destination cards — Voices, API Keys,
  Pronunciations, and Projects — each pairing its count with a one-line
  description, a primary "Manage … →" action (the whole card is the link), and a
  "+ Add" quick action. This gives a visible home to destinations that
  previously lived only in the account dropdown. Generations, being a usage
  metric rather than a page, is now an honestly labeled read-only strip; Health,
  Settings, and Users move to a quiet System row (Users is SuperAdmin-only).
  Connect-your-app is preserved. Health remains a plain link with no status
  indicator, so the dashboard never triggers its test suite on load.
- **Redesigned the Add-a-Voice and Edit-voice pages.** A wider, sectioned layout
  (Identity, Default tuning, Reference clip, Tune by ear) with the primary Save
  action moved to the top-right so it's reachable without scrolling. The
  reference-clip area is a segmented Upload / Record control with a large
  teleprompter for the reading script; the default-tuning knobs pair a number
  field with a slider that are two views of one value; and "Tune by ear" is a
  scannable table.
- **The bundled default voices are replaced with LibriVox-derived recordings.**
  The previous VCTK-derived clips had to be withdrawn for license reasons. The
  built-in `default` (male) and `default-female` voices now use short excerpts
  of public-domain LibriVox audiobook recordings (see `CREDITS.md`), processed
  with the app's own reference normalization so they match uploaded clips. A
  migration rolls the new audio out to existing installs — it replaces a stored
  clip only when its content hash matches the old bundled assets, so a custom
  clip an admin attached to a built-in voice is never overwritten, and it clears
  the local cache copy so the old voice can't keep playing from cache.

### Fixed
- **A missing built-in reference clip now restores itself instead of silently
  changing the voice.** If a built-in voice's stored clip disappears (for
  example after moving storage or changing `TTS_STORAGE_ROOT`), the app
  re-copies it from the bundled seed asset at synthesis time. Previously the
  request went to Chatterbox with no reference prompt at all, and a warm
  container would answer in whatever voice it cloned last — audibly wrong
  output with no error. Any other voice whose reference clip is missing now
  logs a warning naming the voice, path, and disk instead of failing silently.

## [0.31.1] - 2026-07-05

### Fixed
- **Home-page wordmark sizing.** After the rename, "Alias TTS" rendered lighter
  and smaller than "Mimic TTS" did — its letterforms are narrower, so at the
  same size it lost presence. The landing wordmark is now `text-xl`/bold with a
  slightly larger icon, restoring the weight it had and matching the app
  header's wordmark.

## [0.31.0] - 2026-07-05

### Changed
- **The project is renamed from "Mimic TTS" to "Alias TTS."** "Alias" keeps the
  original double meaning — an *alias* is both a drop-in alternate endpoint (the
  ElevenLabs/OpenAI dialect compatibility) and an assumed identity that is still
  you (the voice cloning) — while avoiding a name clash with an existing TTS
  engine. This is a branding change only: the `/v1` API surface, request and
  response shapes, and authentication are unchanged, so existing clients keep
  working. The GitHub repository and Composer package are now
  `johnfmorton/alias-tts` (the old URL redirects).

### Changed — action required when upgrading a deployment
- **Genblaze runner environment variables are renamed `MIMIC_*` → `ALIAS_*`**
  (`ALIAS_BASE_URL`, `ALIAS_INTERNAL_SECRET`, `ALIAS_STORAGE_ROOT`,
  `ALIAS_API_KEY`). Update your runner daemon's environment. The legacy
  `BESPOKEN_*` fallbacks are unchanged.
- **The Genblaze connector package is renamed `genblaze-mimic` → `genblaze-alias`**
  (module `genblaze_mimic` → `genblaze_alias`, classes `Mimic*Provider`/
  `MimicClient` → `Alias*`, provider keys `mimic-*` → `alias-*`). Reinstall it
  (`pip install ./connectors/genblaze-alias`) and update the daemon's
  `PYTHONPATH`.
- **`APP_NAME`** should be set to `"Alias TTS"`. The runner `/health` response
  key `mimic` is now `alias`; brand assets are `alias-icon*.svg`; the
  pronunciation spec is `docs/ALIAS-PRONUNCIATION-PREPROCESSOR.md`; sealed-final
  and voice-export filenames now use the `alias-` prefix.

## [0.30.1] - 2026-07-05

### Changed
- **The `/verify` page now fingerprints files in your browser instead of
  uploading them.** It computes the SHA-256 locally with Web Crypto and sends
  only the 64-character fingerprint, so the audio never leaves your device and a
  large file can't load the server. A matching fingerprint shows the full
  "Verified" result with the provenance; a `?sha=…` link still opens a sealed
  record directly.

### Security
- **File uploads to `/verify` are disabled by default.** Because verification is
  now client-side, the public endpoint no longer needs to accept uploads: the
  `POST /verify` route returns 404 and no upload form is rendered, removing that
  attack surface. Set `TTS_VERIFY_ALLOW_UPLOAD=true` to restore a server-side
  hashing fallback for browsers without Web Crypto (non-secure contexts); it is
  throttled and capped by `TTS_VERIFY_MAX_UPLOAD_KB`.

## [0.30.0] - 2026-07-04

### Added
- **Object-storage finals now carry an embedded provenance manifest.** When a
  Genblaze run uploads its stitched final to object storage (e.g. Backblaze B2),
  the runner downloads the just-uploaded file, embeds the provenance manifest
  into it (an ID3v2 TXXX frame for MP3), and re-uploads it in place — so the
  delivered file is self-describing rather than merely accompanied by a sidecar
  `manifest.json`. It's best-effort: if embedding can't run, the sidecar
  manifest remains and the run is unaffected. (Requires the runner's `[audio]`
  extra, which pulls in mutagen.)

### Changed
- **The "is this the approved final?" verifier is now server-side.** Uploading a
  file to `/verify` hashes it on the server and matches it against the sealed
  approval, replacing the previous page that hashed the file in your browser. A
  fingerprint link (`/verify?sha=…`) opens the authoritative record for a known
  hash — showing the seal panel and the per-chunk provenance (including each
  take's text), and re-confirming the stored snapshot is intact. The approved-
  version `.zip` no longer ships a client-side verifier page; its `receipt.html`
  is now a static provenance record that links out to `/verify`. Upload size is
  capped by the new `TTS_VERIFY_MAX_UPLOAD_KB` (200 MB default).
- **Mimic is now described as a dual-dialect TTS server** — one that speaks both
  the ElevenLabs and OpenAI TTS API dialects, so existing clients of either work
  by changing only the base URL and key — rather than around a single client, in
  the package metadata and roadmap.

## [0.29.0] - 2026-07-04

### Added
- **Studio chunks can now be deleted.** Each chunk card has a delete control next
  to Regenerate; because deletion is destructive, it uses a two-step inline
  confirm (the button expands to **Delete chunk? · Confirm · Cancel**) rather than
  a browser dialog. Deleting a chunk removes it and its takes (rows and audio
  files), shifts the remaining chunks up to stay contiguous, and marks the final
  out of date. The control is hidden for a one-chunk project — a project needs at
  least one chunk — and the server refuses the last-chunk delete as a backstop.
- **The per-chunk Save/Regenerate buttons now reflect the edit state.** **Save
  text** is disabled while the text matches what's saved (nothing to save), and
  **Regenerate** is disabled while the text is unsaved — only saved text is ever
  generated, so the two are never actionable at once. Editing enables Save;
  saving (or Revert) re-enables Regenerate.
- **A project's final audio format (MP3/WAV) can now be changed after the
  project is created.** A new **Format** picker sits next to the Voice picker in
  the project header; switching it re-encodes the final on the next build.
  Previously the format was fixed at creation from the per-user "Final audio
  format" default, which now only seeds new projects. Because chunk audio is
  stored format-independently, changing the format regenerates nothing — it just
  marks the built final out of date so a single **Build final** re-encodes it in
  the new format (and un-approves a sealed cut, as any edit does).

### Changed
- **The Health page now paints instantly and runs its checks in the background.**
  Previously the ~20 diagnostics (ffmpeg, a storage read/write probe, Whisper and
  Genblaze sidecar pings, and — in live mode — a Replicate token check, a queue
  probe, and an upload-ceiling test) all ran server-side *before* any HTML was
  sent, so the page looked frozen for several seconds. The page now loads as a
  shell showing a "Running diagnostics…" indicator, then fetches the rendered
  results; **Run checks** / **Run live checks** re-fetch in place instead of
  reloading. The CLI `php artisan tts:doctor` is unchanged.
- **Adding or importing a voice now shows a loading state on submit.** Both were
  full-page POSTs that blocked while the server ffmpeg-normalized the reference
  clip (add) or unpacked the archive (import), which could read as a hang. The
  submit button now shows a spinner + label ("Saving voice…" / "Importing…"), and
  the add form adds a "keep this page open" note — the same treatment the New
  Project form uses.

### Fixed
- **A sealed receipt now prints the text each chunk's chosen take actually read,
  not the chunk's latest text.** Every take now snapshots the text it was
  synthesized from, and the receipt/manifest use the *selected* take's snapshot.
  Previously a take carried no text of its own, so re-selecting an earlier take
  after editing the words left the receipt's "Script" showing the current text
  while the sealed audio spoke the older version — a silent mismatch (the audio
  hash was always correct; only the printed script could drift). Pre-existing
  takes with no snapshot fall back to the chunk's current text.

## [0.28.0] - 2026-07-03

### Changed
- **The Genblaze runner's provenance storage is now provider-agnostic.** It
  reads the app's own `AWS_*` config (bucket, endpoint, region, keys) and writes
  provenance to the **same bucket the app uses, on any S3-compatible provider**
  (AWS S3, Backblaze B2, Cloudflare R2, …) — a blank `AWS_ENDPOINT` means AWS S3,
  a set one means B2/R2/MinIO. Previously it was Backblaze-only (`B2_*` +
  `for_backblaze`), so a non-B2 install had a runner that uploaded nothing, and
  even a B2 install had to bridge two disjoint config sets. The legacy `B2_*`
  vars remain a fallback. `run-genblaze.sh.example` and GENBLAZE-SETUP.md are
  updated, and the setup guide now states plainly that the runner is a
  long-running background process (not a cron job).
- **`tts:doctor` / Health treat the Genblaze runner as integral, with louder,
  actionable guidance.** An unconfigured runner is now a **WARN** (was a silent
  green PASS) explaining that it powers the pronunciation pre-processor and the
  QA-gated "Generate via Genblaze" pipeline, with the exact steps to enable it.
  The pronunciation warning is likewise stronger — it states detection is
  silently skipped and gives both the fix and the off-switch
  (`TTS_PRONUNCIATION_ENABLED=false`). Every Genblaze/pronunciation row now
  links a **Setup guide** (new `TTS_GENBLAZE_DOCS_URL`). DEPLOYMENT.md gains a
  "Background processes at a glance" table mapping each daemon (scheduler, queue
  worker, Genblaze runner, Whisper ASR sidecar) to the Health check that reports
  it. `tts:doctor` still exits non-zero only on a hard FAIL.

## [0.27.0] - 2026-07-03

### Added
- **Trust configurable reverse proxies (`TRUSTED_PROXIES`).** When the app runs
  behind a TLS-terminating proxy or CDN (Cloudflare, a load balancer, nginx),
  set `TRUSTED_PROXIES` so it reads the real visitor IP — used by the per-IP
  login rate-limiter and logs — and the true https scheme from the proxy's
  `X-Forwarded-*` headers instead of the proxy's own. Opt-in and safe by
  default: unset trusts nothing (correct for a directly-exposed install); `*`
  trusts any upstream (lock the origin firewall to the proxy's IPs), or give a
  comma-separated IP/CIDR list. The trusted list is read at request time, so it
  resolves correctly under a cached production config. `.env.example` and
  DEPLOYMENT.md also now document `SESSION_SECURE_COOKIE` and the Cloudflare
  "Full (strict)" origin-certificate setup.

## [0.26.0] - 2026-07-03

### Fixed
- **Bare `php artisan admin:create` no longer dead-ends a fresh install.**
  Following `.env.example` (set `ADMIN_EMAIL` / `ADMIN_PASSWORD`, run the
  command with no arguments) used to fail with `Not enough arguments (missing:
  "email")`. The command now falls back to those variables — read through
  config, so they still resolve after the deploy's `artisan optimize` caches
  config (the same trap that silently broke the `AdminSeeder` path on
  production) — prompts interactively for anything still missing, and fails
  with the exact remedy in the message when run non-interactively. The
  `.env.example` also now notes that `FILESYSTEM_DISK` is unused by this app —
  storage is selected with `TTS_STORAGE_DISK`.

### Added
- **The sign-in page now tells a locked-out user how to recover.** Password
  recovery is admin-mediated by design (a SuperAdmin issues a signed reset link
  from the Users page; the app sends no email). The login card now says so —
  and when the new `TTS_SUPPORT_EMAIL` is set, it links a pre-drafted "send me
  a reset link" email to that address, carrying the instance URL and the
  account email from the failed attempt. A full self-service (mail-based)
  reset is specced in ROADMAP.md's new Accounts section; `.env.example` now
  notes the `MAIL_*` block is unused today.
- **`tts:doctor` now checks the Genblaze runner.** A dedicated "Genblaze
  runner" health check (CLI and the dashboard Health page): unset
  `TTS_GENBLAZE_RUNNER_URL` passes as "not configured", but a configured runner
  that is down, missing `TTS_INTERNAL_SECRET`, or disagreeing with the app's
  `TTS_STORAGE_ROOT` fails loudly with the fix in the message — previously a
  dead runner only surfaced as a broken Studio page after doctor reported
  green. Softer misconfigurations (callback URL not matching the app, no B2
  sink) warn. The runner's `/health` now reports its `storage_root` so the two
  sides can be compared.

## [0.25.0] - 2026-07-03

### Added
- **Shared-bucket storage root (`TTS_STORAGE_ROOT`).** Optionally scope
  everything an install stores (`speech/`, `voices/`, `avatars/`, `genblaze/`)
  to a subfolder of the storage disk, so several apps can safely share one S3/B2
  bucket. The prefix is applied at the disk layer — database rows keep their
  relative paths — so adopting it on an existing install only means moving the
  objects in the bucket and setting the variable. The Genblaze runner honors the
  same root (uploading provenance under `<root>/genblaze/`), reading
  `TTS_STORAGE_ROOT` directly from its own environment; the app's provenance
  proxy understands the prefixed runner URLs. A tracked
  `genblaze-runner/run-genblaze.sh.example` wrapper prototype sources these
  shared values straight from the site's `.env`, so secrets and the storage root
  are defined once, and `MIMIC_API_KEY` is documented as optional (orchestrated
  runs authenticate through the internal secret).
- **Orphan-file sweep for `speech:cleanup` (`--orphans`).** Removes files under
  the speech storage path that no database row references — leftovers from
  crashed jobs or rows deleted outside the app. The sweep never leaves that
  path (voices, avatars, Genblaze provenance, and other apps on a shared disk
  are untouched) and spares files younger than `--orphan-age` hours (default
  24) so an in-flight generation is never caught. Preview with `--dry-run`;
  it is not scheduled by default.
- **Per-user "Final audio format" setting (MP3 or uncompressed WAV).** A new
  "Audio output" section on the Settings page lets each user choose the format
  their projects build to: the default MP3 (44.1 kHz, 128 kbps) or uncompressed
  WAV (44.1 kHz, 16-bit) for editing and archival. The choice applies to
  projects created from then on — panel "New project" and `POST /v1/projects`
  without an explicit `output_format` — and is stamped at creation, so existing
  projects keep their format. Direct `/v1/text-to-speech` calls are deliberately
  unaffected (clients like the Bespoken plugin rely on the fixed MP3 default);
  an explicit `output_format` on any API request still wins. Managed-settings
  enum options can now carry human-readable labels in the registry
  (`option_labels`), so the dropdown reads "WAV — 44.1 kHz, 16-bit
  (uncompressed)" rather than `wav_44100`.

## [0.24.0] - 2026-07-02

### Added
- **OpenAI-compatible text-to-speech endpoint (`POST /v1/audio/speech`).** Apps
  that speak OpenAI's TTS API now work against Bespoken by swapping only the base
  URL and key — a thin adapter over the same engine, voices, cache, and audio
  pipeline the ElevenLabs surface uses. Authenticate with a Bespoken key via
  `Authorization: Bearer`; `voice` is a Bespoken voice slug (with an optional
  `openai_voice_aliases` config map for OpenAI's fixed preset names). Errors use
  the OpenAI `{"error":{…}}` shape. Supported formats are `mp3` and `wav` (`pcm`
  returns a WAV container); streaming, `opus`/`aac`/`flac`, and `speed`/
  `instructions` are not supported yet. Full support contract in
  [docs/OPENAI-COMPAT.md](docs/OPENAI-COMPAT.md).

## [0.23.1] - 2026-07-02

### Changed
- **The per-chunk quality-check badges now read "QA" instead of "ASR."** The
  acronym was jargon; "QA" reads as what it does — a per-chunk quality check.
  The badge text, the Studio explainer (now expanded as "quality assurance"
  while still noting it works by transcribing with speech recognition), and the
  Settings and Health labels all use the new wording. Nothing functional
  changed — the underlying transcript-QA behavior and its configuration are the
  same.
- **The dashboard's connection panel is no longer plugin-specific.** It now
  leads with "Connect your app" and explains that Bespoken speaks the
  ElevenLabs v1 API, so any ElevenLabs-compatible app works — with the Bespoken
  Craft CMS plugin named as one example rather than the whole audience.

### Fixed
- **Renaming a Studio project works again.** After the project header was
  reworked to drop its `<h1>`, the Rename button threw a JavaScript error and
  did nothing; it now resolves the title element correctly and renames in place.

## [0.23.0] - 2026-07-02

### Added
- **Unapprove.** An approval made by accident can be undone: the project's ⋯
  menu gains an "Unapprove" item (shown only while approved) that clears the
  approval and returns the project to its editable, re-approvable state. The
  final audio is untouched, so you can re-approve the same cut in one click.

### Changed
- **The project action bar reads as a draft→approved progression.** The
  top-of-project buttons were reordered and renamed: *Generate remaining* (was
  "Generate all remaining"), *Build final* (was "Rebuild final" — nothing to
  rebuild on the first stitch), *Download draft version* (was "Download"), and
  *Approve as final* (was "Seal as final"). Approving now swaps that button in
  place for a primary *Download approved version* — the full package (audio +
  provenance + offline verify page), promoted out of the ⋯ overflow menu — while
  the draft download hides, so there's one clear download whose label always
  says which version you're getting. The sealed badge and its copy now read
  "Approved" to match (routes and stored fields are unchanged).
- **The approved package names its audio for the project.** The audio inside the
  receipt `.zip` is now `‹project›-sealed-‹fingerprint›.mp3` (matching the `.zip`
  and the folder it unzips to) instead of a bare `final.mp3`; the receipt page
  and the manifest reference the same name.
- **The receipt reads "Approved."** The provenance receipt page — title, banner,
  and labels — now says "Approved final" instead of "Sealed," matching the rest
  of the app.
- **The receipt's provenance table was rebuilt.** It dropped the Seed and Source
  columns (seed is no longer surfaced in the app; both remain in
  `manifest.json`) and switched to a fixed layout, so the long source hash and QA
  notes wrap inside their columns instead of overflowing the page.

### Fixed
- **"Generate remaining" hides once every chunk is generated.** A CSS
  `hidden`/`inline-flex` conflict had left it visible on fully-generated
  projects; it now disappears when nothing is pending and reappears if you
  insert a new chunk.
- **No duplicate approval confirmation.** Approving a project no longer prints an
  "Approved as the final" status line beneath the badge that already says so.

## [0.22.0] - 2026-07-02

### Changed
- **Voice tuning now lives with the voice.** The A/B tuning bench and named
  presets moved from the Studio Inspector to the voice edit page as a "Tune by
  ear" section: hear the voice read a sample line at different settings (row
  one is the voice's current defaults), pick the winner, and save it as the
  voice's defaults — the preview gap the old edit form never closed. Creating
  a voice now lands on that page, the Add-a-voice form dropped its tuning
  fields entirely, and both voice forms now speak Chatterbox's native knobs
  (Exaggeration, CFG/Pace) instead of the abstract Stability/Style pair, so
  every tuning surface uses one vocabulary. The Inspector keeps its transient
  per-preview knobs, now labeled as such, with a pointer to the voice page.
- **Presets are per-user and actually applicable.** Tuning presets belong to
  the user who saved them (existing ones go to the first SuperAdmin), preset
  names only need to be unique within your own set, and nobody can delete
  yours. They also gained the two places you'd want to use them: a "Delivery"
  pick on the New Project form that seeds the project's tuning, and an "Apply
  preset" pick in each chunk's Takes & tuning panel that fills the knobs for
  that one line — an "excited" or "languid" read per piece without minting
  voice variants.

### Added
- **Duplicate a voice.** Every voice row on the Voices page gained a
  Duplicate action that clones the reference clip and tuning into a voice you
  own — the sanctioned way to get a tunable personal copy of a shared
  built-in.
- **The real Bespoken mark, everywhere.** The waveform icon replaces the
  placeholder "B" tile in the panel header, the auth pages, and the landing
  page, and it's now the favicon too — a crisp SVG for modern browsers, a
  real multi-size `favicon.ico` (the shipped one was an empty file), and an
  `apple-touch-icon` for home screens. In-page logos use a lightened
  on-dark variant of the mark so the deep-blue bars stay legible on the
  black UI; the favicon keeps the original brand colors.

### Changed
- **Panel test generations run as the user who clicks.** The Voices "Test"
  button and the health page's provider tests used one shared API key named
  "dashboard" (created unowned), so every user's test ran under — and was
  attributed to — whoever happened to own that key, and any signed-in user
  could poll another user's test audio by id. Each user now gets their own
  dashboard key on first use, and the health-test status/audio endpoints only
  serve the initiating user's tests. An old shared "dashboard" key is left
  untouched; deactivate it from the API keys page if one lingers.

### Security
- **The tuning bench can no longer retune a shared voice for everyone.** The
  bench's "save to voice defaults" endpoint skipped the shared-voice rule the
  edit form enforces, so any user could write tuning onto a built-in voice and
  change what every user (and their API calls) hears. It now requires the same
  ownership as the edit form; regular users are pointed at Duplicate instead.

### Deploy
- Run `php artisan migrate` — adds `tuning_presets.user_id` (existing presets
  are assigned to the first SuperAdmin) and makes preset names unique per user.

## [0.21.0] - 2026-07-02

### Added
- **Voices can be reordered — and the order is yours.** Drag the handle on the
  Voices page to arrange your list (built-ins included). The order drives every
  voice dropdown in the panel, and the first voice is what New Project
  pre-selects, so dragging is how you choose your effective default voice. Each
  user's order is their own.
- **New Project links to the Voices panel** ("Manage voices →" beside the
  voice picker).

### Security
- **Voices are now per-user.** Custom voices used to be visible to every
  signed-in user, who could also edit, delete, or export them — and any API
  key could generate with any voice. A custom voice now belongs to the user
  who created it: others never see it, the panel guards edit/update/delete
  behind the owner (or a SuperAdmin), and an API key resolves only its
  owner's voices plus the shared built-ins. Registering a voice_id that
  belongs to someone else is refused, so a re-register can no longer replace
  another user's reference clip. Existing custom voices are assigned to the
  first SuperAdmin by the migration; a SuperAdmin's Voices page still lists
  everyone's voices, labeled by owner.
- **Settings are now per-user.** The Settings page used to write one global
  set of values, so any signed-in user could change ASR QA behavior, API
  project mode, and pronunciation options for the SuperAdmin and every other
  user. Each user now has their own settings, applied only to their panel
  session, their API keys' requests, and their queued generations; a queue
  worker resets between jobs so one user's values never bleed into another's.
  Values pinned in `.env` remain instance-wide and read-only. The migration
  hands the old global values to SuperAdmins; regular users start from the
  server defaults, the same as any new user. ASR QA's "defaults on if
  available" also became per-user: the health page enables it for the visitor,
  `tts:doctor` for every user who hasn't chosen.

### Changed
- **Clearer Settings help text.** The Detection LLM provider help now names
  all four options — "anthropic" was a valid (and prod-deployed) choice the
  text didn't mention — and says which API key each one needs. The
  pronunciation pre-processor help now mentions that approved respellings
  persist to your pronunciation dictionary, the ASR QA master switch describes
  what turning it on actually does, and the PAUSE/TRUNC threshold wording was
  tightened.
- **The sealed receipt now verifies itself.** The receipt .zip's
  `receipt.html` embeds the drop-to-verify widget directly — the sealed
  SHA-256 is baked into the page, so double-clicking the receipt and dropping
  the audio on it confirms the file offline, with nothing to configure. The
  zip no longer ships a separate `verify.html`, which invited opening the
  verifier without the expected fingerprint and answered "nothing to compare
  against." The hosted `/verify` page (used by "Copy verify link") is
  unchanged.
- **Genblaze demo: the final audio plays in the Studio hero player.** The
  demo's bare `<audio>` element is reskinned with the same transport used on
  the Studio project page.

### Fixed
- **Genblaze demo: the Chunk step no longer goes silent for short text.** When
  the input fits in a single segment (no splitting needed), the pipeline's Chunk
  step now says so explicitly ("no chunking needed — short enough for a single
  segment") instead of leaving the step blank. Fixed at the source (the runner's
  live progress ping) and in the panel's final provenance render, with matching
  wording so the detail doesn't flicker on completion.

## [0.20.0] - 2026-07-01

### Security
- **Studio projects are now private to their owner.** Opening the control
  panel to every active user (0.19.0) had left Studio unscoped: any signed-in
  user could list, open, edit, regenerate, seal, or delete anyone's projects.
  Every project now records an owner — panel projects get the signed-in user,
  API-created projects the API key's owner, and existing rows are backfilled
  by the migration — and every project route requires the owner (or a
  SuperAdmin). The Studio list shows only your own projects; a SuperAdmin
  still sees everyone's, labeled by owner.
- **Removed the auto-login ("magic") project link.** `POST /v1/projects` used
  to return an `edit_url` that logged the visitor straight into the panel — but
  always as the SuperAdmin, so once the panel opened to regular users, any of
  them could mint their own API key, call the endpoint, and open the link to
  gain a SuperAdmin session. The whole feature is gone: no token is minted, the
  `edit_url`/`edit_url_expires_at` response fields and the `/projects/open/{token}`
  route are removed, and the `magic_login_tokens` table is dropped. The response
  now returns only a plain `url` the project's owner opens after a normal login.

### Removed
- The `TTS_MAGIC_LOGIN_TTL_MINUTES` setting (the feature it configured is gone).

## [0.19.0] - 2026-07-01

### Added
- **Account screen.** A self-service `/admin/account` where any signed-in user
  manages their profile (display name, email, avatar), changes their password,
  and can delete their own account. Avatars live on the private object-storage
  disk (B2 in prod) and stream through an authenticated proxy — the same pattern
  as generated audio.
- **Two-factor authentication (TOTP).** Set up an authenticator app from the
  Account screen (the QR is rendered locally, so the secret never leaves the
  server), confirm a code, and save one-time recovery codes. Login gains a
  second step for protected accounts. No configuration required.
- **Single sign-on (Google & GitHub).** Connect a provider from the Account
  screen and sign in with it thereafter. Invite-only — SSO links and
  authenticates existing accounts, it never creates them — and each provider
  stays dormant (buttons read "Not configured") until its credentials are set.
  See `docs/SSO-SETUP.md`.
- **Users admin (SuperAdmin only).** A `/admin/users` screen — a table of
  everyone with access plus a click-in detail drawer — to create users (with a
  one-time temporary password), invite by email (a signed set-password link),
  change a user's role, suspend/reactivate, force a password reset, sign in as a
  user (impersonate, with a persistent banner to return), and delete. Guarded so
  the last SuperAdmin can't be demoted, suspended, or deleted into a lockout.
- **Role-gated navigation.** The flat top bar collapses to three primary items
  (Genblaze Demo, Dashboard, Studio) plus an account menu grouped into Manage /
  System, with an Admin → Users section shown only to SuperAdmins.
- **Studio: one pinned command bar.** The project page's action toolbar and the
  separate "Final audio" card merge into a single sticky, two-row header, so the
  final audio (now a custom player) is always one tap away as you scroll the
  chunk list. The action set is state-aware — the lit primary always names the
  next step (Generate → Rebuild → Download) and Seal stays visibly disabled
  until a current final exists — and rare/destructive actions (Start over,
  Delete) move into an overflow menu.

### Changed
- **The control panel is open to any signed-in user, not just the admin.**
  Previously every `/admin` page required the super-admin; now any active user
  can use the panel, only the Users screen is SuperAdmin-gated, and suspended
  accounts are signed out on their next request. This is the multi-user access
  model the Users screen manages — the two roles (User and SuperAdmin) map onto
  the existing `is_super_admin` flag, so there is no separate roles table.
- **API keys are strictly per-user.** On the dashboard and the API Keys page each
  user sees, resets, and manages only their own connection key — a keyless user no
  longer inherits a shared or legacy key, and nobody can rotate or delete someone
  else's. Any pre-existing unowned keys are reassigned to the primary admin on
  upgrade.

## [0.18.0] - 2026-07-01

### Added
- **The Genblaze page is now a self-contained live demo.** "Generate via
  Genblaze" shows a pipeline checklist that lights up **as the run happens** —
  Pronunciation → Chunk → Generate & QA (re-rolls surfaced live) → Stitch →
  Seal + verify → Upload to B2 — driven by real per-stage progress the runner
  reports while it works, with a **Replay walkthrough** to re-watch it at any
  time at no cost.
- **Automatic pronunciation on the Genblaze page.** Every run now begins with
  the Genblaze CHAT pronunciation pass (always on here, regardless of the global
  toggle), respelling tricky terms before synthesis and showing exactly what it
  changed as the first step.
- **Download the sealed final audio.** A one-click download of the verified
  final deliverable — the way a real client receives it — streamed through the
  app's authenticated proxy so it works even from a private bucket, with a
  hash-stamped filename.

### Changed
- **Genblaze is the headline landing.** It's now the first nav item
  ("Genblaze Demo") and the page you land on after logging in when the runner is
  configured (falling back to the dashboard otherwise), so the flagship flow is
  front-and-center for evaluators.
- Added a storage note on the page explaining that the demo bucket is public for
  convenience, while the app is private-bucket-ready via the authenticated proxy.

### Fixed
- The nav no longer highlights both "Studio" and "Genblaze Demo" when viewing the
  Genblaze page — its route lives under `admin.studio.*` but now only its own tab
  lights up.

## [0.17.1] - 2026-06-30

### Fixed
- **Manually re-rolled chunks now get a junk tail auto-trimmed.** With ASR
  auto-remediation on, a manual re-roll still won't auto-re-roll (you asked for
  exactly one new take, so it never spends extra generation behind your back) —
  but a flagged `TAIL`/`TAILNOISE` (junk strictly after the speech) is now
  precise-trimmed on that take, the same lossless cut a first-generation chunk
  gets. Previously a manual re-roll skipped remediation entirely, so a long
  trailing artifact could survive.

## [0.17.0] - 2026-06-30

### Added
- **Seal a project's final and prove it later.** A ready project now has a
  **🔒 Seal as final** button that freezes the current stitched audio as the
  approved cut: it records who approved it, when, and the SHA-256 of the exact
  bytes, and snapshots those bytes to an immutable copy (so a later rebuild can't
  silently change what "approved" pointed at). A **✓ Sealed final** badge shows
  the approver, date, and short fingerprint, with a "Copy verify link". Any edit,
  rebuild, voice change, or reset automatically clears the seal so an "approved"
  claim can never outlive the audio it described.
- **Downloadable provenance receipt (.zip).** A sealed project can download a
  self-verifying receipt: the final audio, a human-readable `receipt.html`
  (per-chunk script text, **per-chunk voice**, seed, takes, QA, and source-audio
  hashes), a machine-readable `manifest.json`, and an offline verify page.
- **Drag-to-verify page** at `/verify` (and bundled in the receipt): drop the
  audio file and it confirms — entirely on your device, no upload, works offline
  from `file://` — whether it's the untouched approved final (✅) or has been
  edited (❌). The expected fingerprint travels in the link, never the request.
- **A built-in female default voice** (`voice_id` = `default-female`) ships
  alongside the existing default, so a fresh install offers both a neutral US
  male and female voice out of the box. Both are protected from deletion.

### Changed
- **Audio downloads are named by content fingerprint.** The final-audio and
  receipt downloads now include the first 8 characters of the audio's SHA-256 in
  the filename (e.g. `my-project-3f9a1c08.mp3`), so each distinct build downloads
  under its own name instead of the operating system appending ` (1) (2) …`. The
  tag is the same short code shown by the seal badge and verify page.
- **The default voice now ships with a real reference clip** instead of falling
  back to Chatterbox's native voice. The unconditioned native voice isn't anchored
  to a speaker, so it drifted between runs and could resemble a cloned voice. The
  built-in `default` is now a neutral US male voice with a bundled reference clip
  (from the VCTK corpus, CC BY 4.0 — see `CREDITS.md`), giving a consistent,
  distinct result. Existing audio generated under the old default isn't
  auto-updated — re-roll a chunk to adopt the new voice.

## [0.16.0] - 2026-06-29

### Added
- **Reset a leaked API key from the Dashboard.** The "Connect Bespoken" panel now
  has a **Reset** button beside the API key that issues a new secret and revokes
  the old one immediately (with a confirmation, since it breaks any client still
  using the old value). The rotation resolves the current user's key server-side,
  so it's per-user-safe — one user can't reset another's — and a legacy unowned
  key is claimed by the user when reset so future rotations stay owner-scoped.

### Fixed
- **Studio "Create project" no longer looks frozen.** Clicking **Create project**
  kicks off a synchronous request that normalizes the text and runs the LLM
  pronunciation check (up to ~a minute for a long article) before the review
  screen can render — with no feedback, the page read as an error. The button now
  disables and shows a spinner with honest step labels ("Normalizing text…" →
  "Checking pronunciations…") plus a "this can take up to a minute for long
  articles" note, so it's clear the app is working. Resets cleanly on back/forward
  navigation, and an empty form still falls through to native validation.

## [0.15.0] - 2026-06-28

### Added
- **Studio keeps every take.** Every render of a chunk — Generate, Re-roll,
  Preview, "Use this take", and ASR auto-remediation — is now saved as its own
  immutable clip in a new **"Takes & tuning"** panel below each chunk (renamed from
  "Tune this chunk"). Audition any prior take, **Select** the one that sounded best
  to make it the chunk's audio, or **Delete** the duds. Older takes are auto-pruned
  per chunk (config `tts.takes.keep` / `keep_preview`); the selected take is always
  kept, and previews are pruned harder than committed takes. Previously each render
  overwrote the single stored clip, so a better earlier take was lost forever.
  Existing projects backfill one "legacy" take per generated chunk on migrate.
- **Native Chatterbox knobs across the Studio.** The tuning controls (per-chunk
  panel, single-shot inspector, and A/B bench) now expose Chatterbox's own
  **Exaggeration** (0.25–2.0, neutral 0.5) and **CFG/Pace** (0.2–1.0) as
  slider + number box + reset (↺), matching the Hugging Face Chatterbox demo,
  instead of the abstract 0–1 Stability/Style fields and a derived readout.

### Changed
- The shared `VoiceSettingsResolver` and the Replicate provider now accept the
  native `exaggeration`/`cfg_weight` keys directly (native wins), falling back to
  deriving them from `stability`/`style`. The public `/v1` API is unchanged — it
  still speaks ElevenLabs-style `stability`/`style`, which the provider maps. Named
  tuning presets and saved voice defaults were migrated to the native knobs.

## [0.14.7] - 2026-06-28

### Added
- **Studio: "Use this take" keeps the exact clip you just previewed.** Auditioning
  a per-chunk tuning with **Preview** generated a clip that was then discarded —
  and because the voice model is non-deterministic even with a fixed seed,
  regenerating could never reproduce a good take. A new **✓ Use this take** button
  (next to Preview) saves the exact previewed audio as the chunk's audio, along
  with the stability/style it was previewed at, with no re-generation. It appears
  once you preview and retires itself the moment you change the text, tuning, or
  voice, so a kept clip always matches its settings.

### Changed
- **Per-chunk tuning fields now show what "inherit" resolves to.** The Stability
  and Style overrides displayed a bare `inherit` placeholder with no hint of the
  underlying value; they now show the inherited project setting (e.g.
  `inherit (0.50)`) and were widened to fit it. The Generate/Re-roll tooltips and
  the tuning help text were reworded to describe **Generate** as a render and
  **Re-roll** as simply "another take."

### Removed
- **"Seed" is no longer surfaced anywhere in the admin UI.** Because a fixed seed
  doesn't guarantee an identical take, exposing it promised a reproducibility the
  model can't deliver and added needless complexity. The seed inputs were removed
  from the Studio create form, the Studio inspector, and the voice create/edit
  forms, and the Seed column was dropped from the voices list. The underlying seed
  plumbing is unchanged (database, API, voice-manifest import); the voice edit form
  preserves any existing seed in a hidden field so saving doesn't wipe it.

### Fixed
- **A word ending in a soft "n"/"m"/"ng" no longer gets its last sound clipped.**
  When a chunk ended on a voiced nasal (e.g. "…with proof built in"), the tail
  cleanup mistook the quiet, low-pitched nasal release for trailing noise and
  trimmed into the real word — so the final word sounded cut off, most visibly
  where chunks were joined. The cleanup now recognises a short voiced word-ending
  as part of speech and keeps it, while still trimming genuine hums, drones, and
  hiss after the voice stops. The check is purely acoustic (pitch + loudness +
  length), so it isn't tied to any one language. Tunable via
  `TTS_CHUNK_TAIL_VOICED_CODA_MAX_MS` (default 300 ms). Affects newly generated
  audio; clips already saved before this fix need re-generating.

## [0.14.6] - 2026-06-26

### Fixed
- **Generated clips no longer lose their last word.** A single-chunk Studio or
  "Generate via Genblaze" run could ship a final MP3 with the last word chopped
  off mid-syllable at full volume (reported: a 2.52s clip cut to 1.59s, a 2.96s
  clip to 2.25s). The tail-artifact detector's voicing path decided where speech
  ended using a pitch check, then hard-cut any unvoiced run longer than ~400ms
  after it — but a genuine word-final unvoiced sound (a sustained *s*/*f*/*sh* or
  a soft, devoiced ending) has no pitch and routinely runs 600–900ms, so it was
  mistaken for an appended hiss tail and removed. Duration alone can't tell the
  two apart; loudness can — a real word ending tapers *off* the word (no louder
  than the speech before it), whereas a hiss/swoosh artifact is markedly *louder*.
  The voicing cut now only fires when the trailing run is at least 6 dB louder
  than the speech body (new `TTS_CHUNK_TAIL_VOICING_OVER_SPEECH_DB`, default 6.0),
  the same over-speech test the ASR tail-noise check already used. Genuine hiss
  tails are still trimmed; ordinary clips keep every word.

## [0.14.5] - 2026-06-26

### Added
- **The Genblaze panel now shows whether the provenance manifest verifies.** Every
  "Generate via Genblaze" run already wrote a SHA-256 provenance manifest to
  Backblaze B2; the runner now also calls Genblaze's `manifest.verify()` and
  returns the result, so the Studio panel renders a green **✓ verified · SHA-256**
  badge next to the manifest hash (red if it fails to verify, hidden if the runner
  can't compute it). The run's verifiability is now visible at a glance instead of
  implied by the hash alone.

## [0.14.4] - 2026-06-26

### Changed
- **`api_project_mode=always` now keeps the audio a successful `/v1` call already
  generated.** Previously every Studio project auto-created from a successful API
  generation opened empty — all chunks `pending`, the final marked `draft` —
  forcing a full regeneration of work the API had just done. A successful call now
  hands its result across intact: each synthesized segment is persisted as a
  `completed` chunk holding its raw audio (so per-chunk playback, editing, and
  re-roll work immediately), and the API's concatenated final file is carried over
  so the project opens **Ready** with the same audio the call returned. The chunks
  are the exact segments `/v1` read, so a later edit still re-rolls only that one
  chunk. The failure-recovery path (`api_project_mode=on_error`) is unchanged — a
  failed generation has no usable audio, so it still seeds a bare project to repair.

## [0.14.3] - 2026-06-26

### Added
- **Dismiss the "API failure" flag on a recovered project.** Projects auto-created
  from a failed `/v1` generation (`api_project_mode=on_error`) were permanently
  marked as failures — a red *"Recovered from a failed API generation"* banner on
  the project page and a red **API failure** badge on the Studio index — with no
  way to clear it once you'd dealt with the failure. The banner now has a
  **Dismiss** button that converts the project into a regular entry: the banner
  and badge disappear, the auto-pruning TTL is cleared (so it's no longer reaped),
  and the auto-generated `API failure: ` title prefix is stripped (a title you've
  renamed yourself is left untouched).

## [0.14.2] - 2026-06-26

### Changed
- **"Generate via Genblaze" now runs asynchronously.** The Studio button dispatches
  a queued job and the panel polls for the result, so a long multi-attempt run no
  longer holds an HTTP request open past the web server's read timeout (was
  surfacing as HTTP 502). Its provenance audio is also streamed back through the
  app (authenticated `s3` read), so takes play in-browser even when the Backblaze
  B2 bucket is **private**.

### Fixed
- **Cloned voices work again under S3/B2 storage.** Generating with a voice that
  has a reference clip (preview, `/v1`, Studio, *and* Genblaze) failed when
  `TTS_STORAGE_DISK=s3` — the clip was resolved with `Storage::disk('s3')->path()`,
  which isn't a real file, surfacing as "Preview failed" / "the runner returned
  HTTP 500". A voice's reference is now resolved to a real local path regardless
  of disk (a local clip is used in place; an S3 clip is cached down first), so
  clips uploaded before a switch to S3 keep working.

## [0.14.1] - 2026-06-26

### Fixed
- **S3-compatible object storage now works as the audio disk.** `TTS_STORAGE_DISK=s3`
  previously failed at runtime because the Flysystem S3 adapter wasn't bundled;
  `league/flysystem-aws-s3-v3` is now a dependency, so generated audio + voice
  clips can live in AWS S3, **Backblaze B2**, Cloudflare R2, MinIO, Wasabi, etc.
- **Backblaze B2 (and other ACL-less providers) accepted as the storage disk.**
  Laravel's S3 driver stamped a canned object ACL on every upload, which B2
  rejects ("Unsupported value for canned acl 'private'") — silently failing every
  write. The `s3` driver is re-registered to strip the ACL parameter. Also
  documents the previously-undocumented `AWS_ENDPOINT` (+ path-style) needed for
  non-AWS S3 providers, in `.env.example` and the deployment guide.

## [0.14.0] - 2026-06-26

### Added
- **Genblaze on Backblaze B2 — provenance-tracked, QA-gated audio generation.** A
  new Studio action, **"Generate via Genblaze"** (`/admin/studio/genblaze`), runs
  the whole pipeline through a Genblaze orchestrator: generate each chunk →
  **Whisper ASR scores it → re-roll a flagged chunk → stitch**, writing **every
  take (including rejected re-rolls) and a verifiable provenance manifest to B2**.
  The panel shows per-chunk attempts/scores, the B2 take URLs, the final MP3, and
  the manifest hash.
  - Ships the Genblaze-owned orchestrator (a Python "runner"), a
    `genblaze-bespoken` provider connector, and stateless `/v1/internal/*`
    pipeline primitives the runner calls.
  - The provenance bucket can stay **private** — reads are authenticated (SigV4).
  - New config: `TTS_GENBLAZE_RUNNER_URL`, `B2_*` (key/region/endpoint/bucket),
    `TTS_INTERNAL_SECRET`.
- **Pronunciation pre-processor — respell mispronounced terms before they reach
  the voice.** Before chunking, an LLM proposes plain-spelling respellings for
  likely-mispronounced terms (`DDEV` → "dee dev", `nginx` → "engine ex") on a
  review screen; approved terms are applied to the project text and saved to a
  per-writer dictionary that accumulates over time.
  - Detection runs as a **provider-agnostic Genblaze chat step** — swap the LLM
    between **Replicate** (default, Llama 4 Scout), **Anthropic** (Claude Haiku),
    **Gemini**, or **OpenAI** from the **Settings** page.
  - A new **Pronunciations** admin screen manages each writer's dictionary
    (add / edit / approve / delete). Dictionaries are **strictly per-user**.
  - New read API **`GET /v1/pronunciations`** (scoped to the calling key's owner)
    so the Bespoken Craft plugin can sync the lexicon and apply it upstream of any
    TTS backend.
  - New config: `TTS_PRONUNCIATION_ENABLED` (default **off**),
    `TTS_PRONUNCIATION_LLM_PROVIDER`, `TTS_PRONUNCIATION_MODEL`,
    `TTS_PRONUNCIATION_TEMPERATURE`, `TTS_PRONUNCIATION_TIMEOUT`, and
    `ANTHROPIC_API_KEY` / `GEMINI_API_KEY` / `OPENAI_API_KEY`. The whole feature
    degrades safely — a missing runner or LLM just skips straight to chunking and
    never blocks a generation.
- **API keys now have an owner.** A nullable `user_id` ties a key to a user and
  scopes the pronunciation dictionary it syncs — groundwork for multiple writers,
  each with their own lexicon and keys.

### Changed
- **Dev environment:** the Genblaze runner and Whisper ASR sidecars now run as
  **DDEV add-on services**, with auto-started `queue:listen` + `schedule:work`
  daemons — `ddev restart` builds and wires them.

### Fixed
- **Studio "Start over" restores your original text.** It previously re-opened the
  *respelled* text (e.g. "dee dev") instead of what you typed; the original
  submission is now preserved as the project source, with respellings applied only
  to the spoken/chunked text (and re-applied consistently on reset).
- **Genblaze/B2 robustness:** normalize `B2_REGION` when given in the
  `s3.<region>` endpoint-host form; authenticate B2 reads so the provenance bucket
  can be private; correct the off-the-shelf Genblaze `chat()` argument order and
  bundle the Gemini/OpenAI chat adapters.

## [0.13.0] - 2026-06-24

### Added
- **A failed (and, optionally, every) `/v1` generation can hand off to an editable
  Studio project.** A new setting — `tts.api_project_mode` (`never` | `on_error` |
  `always`, default `never`, on the admin **Settings** page) — controls it:
  - `on_error` turns a failed generation into a **recovery project**: the source
    text seeded as an editable project, badged "API failure" in the Studio list,
    with the provider's actual error (e.g. a Replicate CUDA assert) and the chunk
    that failed shown on the project page so an admin can fix that sentence and
    rebuild. The failure response also carries a `recovery_url` pointing at the
    project — a plain panel URL the admin opens after a normal login, never a
    credential.
  - `always` creates a project on every call; `never` keeps the API stateless.
  - Auto-created recovery projects carry a TTL and are pruned by a new scheduled
    command, `projects:prune-recovery` (daily), when never opened or worked on;
    `always`-mode and panel-made projects are kept.
- New config: `TTS_API_PROJECT_MODE` (default `never`) and `TTS_API_PROJECT_TTL_HOURS`
  (default `168` — 7 days).

## [0.12.3] - 2026-06-24

### Security
- **Hardened the ffmpeg/audio pipeline against the FFmpeg "PixelSmash" class of
  decoder flaws (CVE-2026-8461).** Three defense-in-depth changes:
  - **Uploaded reference clips are screened for video.** A new `ffprobe`-backed
    validation rule rejects a voice-reference upload that carries a real
    (non-cover-art) video stream — closing the container-overlap seam (m4a/mov are
    both ISO-BMFF; Ogg can carry Theora) that the `mimes:` rule alone can't.
    Ordinary embedded cover art still passes.
  - **Every ffmpeg call that opens an input file now passes `-vn`** (drop video),
    so a smuggled video stream can never be decoded to output — most importantly
    on the only untrusted path, reference-clip normalization.
  - **The health check enforces a minimum ffmpeg version.** `php artisan tts:doctor`
    and the admin **Health** page now **fail** when ffmpeg is older than **8.1.2**,
    the release that fixes the MagicYUV "PixelSmash" decoder bug. On a distro that
    backported the fix without bumping the version number, set
    `TTS_FFMPEG_MIN_VERSION` to the installed version to acknowledge it and clear
    the check.

### Added
- New config: `TTS_FFPROBE_PATH` (default `ffprobe`) and `TTS_FFMPEG_MIN_VERSION`
  (default `8.1.2`). `docs/DEPLOYMENT.md` and the DDEV ffmpeg config now document
  the ffmpeg ≥ 8.1.2 requirement.

### Fixed
- **Studio audio players now work in iOS Safari.** The per-chunk and final players
  (and the admin Health test player) serve audio with HTTP range support
  (`206 Partial Content` + `Accept-Ranges`), so iOS can determine the duration and
  seek — instead of showing "Live Broadcast" with a dead scrubber on the final MP3
  or failing to play a WAV chunk. Desktop playback and downloads are unchanged, and
  the `/v1` API path is untouched.
- **The admin navigation no longer overflows on phone-width screens.** The header
  stacks and the nav wraps below the `sm` breakpoint instead of clipping the
  right-hand links.

## [0.12.2] - 2026-06-22

### Added
- **ASR transcript QA enables itself when the sidecar is available.** The master
  switch still ships off, but the admin **Health** page and `php artisan tts:doctor`
  now turn it on automatically the first time they find the Whisper sidecar
  reachable, persisting the choice as an editable setting (the Health page shows a
  notice; `tts:doctor` prints a line). It is a one-shot that never overrides a switch
  pinned in `.env` or a choice saved in **Settings**, and it probes only on those
  admin surfaces — never on the generation path.

### Changed
- **The unattended API path now self-heals by default.** `TTS_ASR_API_ACTION`
  defaults to `auto` (previously it inherited the shared `TTS_ASR_ACTION`), so a
  flagged segment on the `/v1` + queued path is re-rolled/trimmed with no human in
  the loop. The interactive Studio still defaults to `log` — it inherits the shared
  action — so an admin triages flagged chunks by hand from the per-chunk ASR badge.
  Set `TTS_ASR_API_ACTION=log` to opt the API back into ship-as-is.
- **Default maximum automatic re-rolls raised from 2 to 3** (`TTS_ASR_MAX_REROLLS`),
  biasing toward re-rolling a suspect take rather than shipping it.

## [0.12.1] - 2026-06-22

### Added
- **In-Studio explanation of the ASR badges.** The Studio project page now explains
  the per-chunk ASR badges — expanding "ASR (Automatic Speech Recognition)" and
  describing each state (`TRUNC` / `TAIL` / `TAILNOISE` / `PAUSE` / `BNDNOISE`) — so the
  feature isn't unexplained on the page. Shown only when ASR is enabled.

### Changed
- **README + package metadata.** Documented the opt-in ASR quality check and the
  energy-aware `TAILNOISE` / `BNDNOISE` signals in the README, and replaced the
  Laravel-skeleton `composer.json` name/description/keywords with this project's own.

## [0.12.0] - 2026-06-22

### Added
- **Energy-aware ASR scrutiny at the tail and at sentence/comma boundaries
  (`TAILNOISE` / `BNDNOISE`).** The existing ASR signals are duration-based
  (`trail_s` / `max_gap_s`), so a *short but loud* defect sails through — a brief
  tail "swoosh" under the TAIL time threshold, or a tonal hum filling a
  punctuation-boundary gap under the PAUSE threshold. (A bad take with both passed
  QA in 0.11.0 when it shouldn't have.) Two new signals measure the actual energy
  in those zones, aligned to the Whisper word timings: **TAILNOISE** flags a tail
  whose peak loudness — measured past the final word's natural release — exceeds
  `TTS_ASR_TAIL_ENERGY_DBFS_MAX` **and is louder than the chunk's own speech by
  `TTS_ASR_TAIL_OVER_SPEECH_DB`** (the relative gate keeps a soft final word-coda
  that Whisper under-times — e.g. the "n" in "2019"→"nineteen" — from being clipped),
  and lossless-trims it; **BNDNOISE** flags a
  punctuation-boundary gap that is both not-silent (`TTS_ASR_BOUNDARY_ENERGY_DBFS_MAX`)
  and tonal/low-frequency (`TTS_ASR_BOUNDARY_ZCR_MAX_HZ`) — a hum, distinct from a
  clean breath or normal speech residue — and re-rolls it (the defect is mid-speech,
  so it can't be trimmed). Both are off unless ASR QA is enabled and degrade safely;
  thresholds are configurable in `.env` and the admin Settings page. Validated on the
  labeled corpus; the boundary thresholds are conservative and should be re-checked
  against the production sidecar. See docs/ASR-SETUP.md.

  The tail trim is verified not to clip soft word-endings: validated end-to-end against
  recovered untrimmed Replicate originals (takes ending in "2019"→"nineteen", whose
  voiced nasal Whisper under-times), the relative gate spares the real word while still
  catching a genuine swoosh.

### Changed
- **Clearer `AdminSeeder` guidance when `ADMIN_EMAIL` / `ADMIN_PASSWORD` aren't
  readable.** On production the config is usually cached (e.g. `artisan optimize` on
  deploy), so `env()` returns null outside config files and the seeder silently
  skipped. The skip message now explains this and points to `php artisan admin:create`,
  which doesn't depend on `env()` / cached config and works on production.

## [0.11.0] - 2026-06-21

### Added
- **Independent ASR remediation policy for the Studio vs. the API.** `TTS_ASR_ACTION`
  was a single switch shared by both generation paths, so you couldn't keep manual
  triage in the Studio while the API self-healed. It can now be scoped per path with
  `TTS_ASR_STUDIO_ACTION` and `TTS_ASR_API_ACTION` — each inherits `TTS_ASR_ACTION`
  when unset, so existing single-value setups are unchanged. The intended split: the
  interactive Studio stays `log` (an admin sees the per-chunk badge and re-rolls by
  hand) while the unattended API / full-MP3 path runs `auto` to self-heal, since no
  human can intervene there.
- **Admin settings page (`/admin/settings`).** UI-editable service configuration for
  the ASR feature — the master switch, the Studio/API remediation split, max
  re-rolls, and (under "Advanced") the detection thresholds — backed by a new generic
  settings store. Precedence is **`.env` (locked) → saved value → config default**: a
  value pinned in `.env` is shown read-only with a "Set in .env" note, while every
  other key is editable in the panel and layered onto config at boot, so `.env`
  always wins and `config:cache` is respected. Built to extend — future setting
  groups just register more keys.

### Removed
- **"Sentence seam" / "paragraph seam" chunk badges in Studio.** These per-chunk
  markers exposed an internal chunking detail that wasn't actionable to the user.
  Removed from both the project editor and the chunk inspector. The "Preview
  stitch" feature (which actually tests the trim + seam join) is unchanged.

## [0.10.0] - 2026-06-21

### Added
- **ASR transcript quality checking (opt-in).** A local Whisper sidecar transcribes
  each generated chunk and compares the transcript to the source text to catch the
  failure modes the DSP tail-trim cannot: **truncation** (Chatterbox stops before
  finishing the line — no acoustic artifact to detect), **speech-like / "ghostly
  singing" tails**, and **mid-stream pauses**. Off by default (`TTS_ASR_ENABLED`).
  The sidecar (`asr-sidecar/`, FastAPI + faster-whisper, run as a Forge Daemon)
  exposes `/health` + `/transcribe`; the app talks to it over localhost and never
  blocks generation if it's down. Two modes via `TTS_ASR_ACTION`: **`log`** records
  a per-chunk verdict (surfaced as a badge in Studio), **`auto`** also remediates —
  re-rolling truncated/paused/empty takes with a fresh seed (best-of-N, up to
  `TTS_ASR_MAX_REROLLS`, default **2**) and precise-trimming junk tails at the ASR
  speech end. Runs on both the Studio/project path and the synchronous + queued
  `/v1` path. Verify with `php artisan tts:asr:health [--deep]`, or the ASR row on
  the admin Health page. Setup: `docs/ASR-SETUP.md`.
- **Pure-PHP voicing gate for the tail detector.** Closes a known blind spot — a
  loud, high-ZCR but *aperiodic* tail (broadband hiss/noise with no fundamental)
  cleared the speech gate and was not a low-ZCR/tonal artifact either. A peak
  normalized autocorrelation in 75–600 Hz now finds the last loud **voiced** window
  and, when a trailing unvoiced run of ≥ `chunk_tail_unvoiced_min_ms` (default
  **400**) follows, cuts back to it plus `chunk_tail_fricative_allowance_ms`
  (default **250**, so a genuine word-final fricative survives). It combines with
  the existing ZCR/tonal cut (takes the earlier), so it only ever trims more and
  never clips a voiced word. No Python/Praat dependency. `chunk_tail_voicing_*`,
  default on.

### Changed
- **Clearer ASR health diagnostics.** Health-check results can now carry a help
  link. The ASR sidecar's "unreachable" message drops the raw cURL noise for an
  actionable one-liner and links the setup guide (`TTS_ASR_DOCS_URL`) — rendered as
  a clickable "Setup guide" on the admin Health page and shown in `tts:doctor`.

## [0.9.3] - 2026-06-20

### Fixed
- **Long tonal "swell" at the end of a chunk survived trimming.** Chatterbox
  sometimes appends a sustained tone that ramps up for over a second after the
  speech ends (separated by a brief quiet gap). It clears the speech gates (loud,
  mid-ZCR) and is too long for the re-swell "blip" peel, so it remained in the
  stitched audio. The tail detector now also peels a longer isolated run when its
  per-window ZCR is near-constant (`chunk_tail_tonal_cv_max`, default **0.35**) —
  real speech swings between voiced and unvoiced, a tone does not — so the swell is
  cut at the speech end while genuine speech is untouched. `0` disables this path.

## [0.9.2] - 2026-06-20

### Added
- **Retry transient Chatterbox prediction failures.** Replicate occasionally fails
  a prediction with a transient GPU fault (observed: `CUDA error: device-side assert
  triggered` while embedding the reference clip, and CUDA OOM) on a flaky/cold
  worker — re-running the same request usually succeeds. The provider now re-rolls
  such a failure up to `REPLICATE_PREDICT_MAX_RETRIES` times (default **2**, same
  backoff as the 429 path, bounded by the request timeout). Deterministic failures
  (bad input) still fail fast. Set `REPLICATE_PREDICT_MAX_RETRIES=0` to disable.
- **Pace Studio "Generate all remaining".** Generation was already sequential, but
  gapless — a chunk that failed fast (~300 ms) fired the next prediction almost
  immediately, and a burst of back-to-back predictions can spin up cold GPU replicas
  on Replicate (which is what throws the transient CUDA asserts above). The loop now
  waits a short gap between chunks (`TTS_STUDIO_GENERATE_PACE_MS`, default **800**;
  0 = back-to-back).

## [0.9.1] - 2026-06-20

### Added
- **Per-chunk voice override in Studio projects.** Each chunk now has its own voice
  picker. By default a chunk inherits the project voice (the picker mirrors it, and
  follows when you change the project voice), but you can pin an individual chunk to
  a different voice and generate just that chunk with it. Changing the project voice
  only stales chunks that inherit it; explicitly-voiced chunks keep their audio.
  Backed by a nullable `tts_chunks.voice_id` (`PATCH …/chunks/{chunk}/voice`).

### Fixed
- **New Studio project bound to the wrong voice.** The "New project" form only
  marked a `<select>` option selected on validation-error repopulation, so a fresh
  page let the browser auto-select the first voice by name — binding new projects to
  an arbitrary cloning voice instead of the built-in default. The form now
  pre-selects the configured default voice.
- **Voices "Test" button replayed a stale preview.** The preview reused a cached
  result, so a default-voice test could keep playing audio captured while the voice
  briefly had a different reference. The Test button now always regenerates live.
- **Tail-end "decay-then-blip" artifact that slipped past 0.9.0.** Chatterbox
  sometimes follows the quiet decay tail with a brief, loud, mid-band "re-swell"
  that is neither quiet nor tonal, so it cleared both of the long-tail detector's
  gates. Sitting at the very end of the chunk, it reset the detected speech end to
  ~EOF, the trailing-silence run collapsed to near zero, and the whole multi-second
  tail survived. The detector now peels an isolated short trailing run (shorter than
  `TTS_CHUNK_TAIL_BLIP_MAX_MS`, default 400 ms) when a long **quiet** (sub-floor) gap
  isolates it from earlier audio, then measures the speech end and hard-cuts as
  before. The gap must be genuinely silent, not merely low-ZCR — so a quiet final
  word like "will be" (loud but low-ZCR voiced sound) is never mistaken for an
  artifact and trimmed. Set `TTS_CHUNK_TAIL_BLIP_MAX_MS=0` to disable. See
  `docs/AUDIO-CLEANUP.md`.

## [0.9.0] - 2026-06-20

### Added
- **Built-in default voice.** A "Default voice" (`voice_id` = `default`) is now
  seeded on install and uses Chatterbox's native voice (no reference clip), so a
  fresh install can generate audio without uploading a custom voice. It shows up in
  Studio and the `/v1` API, is protected from deletion, and its `voice_id` is
  configurable via `TTS_DEFAULT_VOICE_SLUG`. The **Add voice** form's reference clip
  is now optional, so you can also create additional reference-less voices.
- **Change a project's voice.** The Studio project editor has a voice picker in its
  toolbar (`PATCH /admin/studio/projects/{project}/voice`). Switching the voice
  marks already-generated chunks stale so you can regenerate them with the new
  voice; tuning, seed, and per-chunk overrides are preserved.

### Fixed
- **Chatterbox's long tail-end distortion is now removed.** Some generations append
  a multi-second, speech-level, low-frequency drone after the speech ends — too loud
  for the silence trim and too long for the bounded tail window, so it survived into
  production audio. A new detector flags it by zero-crossing rate (the drone is
  tonal/low-frequency) gated by an RMS floor and hard-cuts at the speech end, while
  normal clips and soft final words keep the existing bounded trim. All thresholds
  are env-tunable (`TTS_CHUNK_TAIL_*`). See `docs/AUDIO-CLEANUP.md`.

## [0.8.2] - 2026-06-19

### Added
- The version number in the admin footer now links to that version's GitHub
  release (`{APP_SOURCE_URL}/releases/tag/v{version}`), opening in a new tab. The
  base URL is configurable via `APP_SOURCE_URL`; set it empty to drop the link.

### Security
- Bumped `guzzlehttp/guzzle` (7.11.2 → 7.12.1) and `guzzlehttp/psr7`
  (2.11.1 → 2.12.1) to patched releases, resolving three moderate Dependabot
  advisories (HTTPS-proxy cleartext downgrade, dot-only cookie domain matching,
  and CRLF injection in HTTP start-line serialization).

## [0.8.1] - 2026-06-19

### Added
- **Unsaved-edit safeguards in the Studio editor.** A chunk whose text has been
  edited but not saved now shows an amber "● unsaved" badge and an amber textarea
  border, and reveals a **Revert** button that restores the last-saved text. The
  editor also warns before you navigate away from (or reload) the page with any
  unsaved chunk edits, so they can't be lost silently — and inserting a chunk
  (which reloads the list) confirms first if there are unsaved edits.

## [0.8.0] - 2026-06-19

### Added
- **Insert a chunk into a Studio project.** A subtle "+ insert chunk" control at
  every gap (and at the top/bottom) inserts a new empty, ungenerated chunk at that
  position, shifting the rest down (`POST /admin/studio/projects/{project}/chunks`).
  Type into it, Save, and Generate like any other chunk.
- **Auto re-chunk an over-long edit.** Saving a chunk whose text now exceeds the
  standard chunk budget re-splits it the same way new projects are chunked: the
  edited chunk keeps the first segment and the remainder become new pending chunks
  inserted right after it. The surrounding chunks' generated audio is preserved
  (audio is keyed by chunk id, not position); a trailing paragraph seam is kept on
  the last new piece.
- **Start over from the original text.** A **Start over** action opens an editable
  editor pre-filled with the project's original text; on confirm it re-chunks the
  (possibly edited) text and **permanently deletes all generated audio**, returning
  the project to a fresh draft with the same voice/settings
  (`GET/POST /admin/studio/projects/{project}/edit` + `/reset`).
- **Rename a Studio project.** A **Rename** control next to the project title in
  the editor edits the title inline and saves it without a full page reload
  (`PATCH /admin/studio/projects/{project}`). Useful for telling apart projects
  that were created with the same default title.
- **Create-project API.** `POST /v1/projects` creates an editable Studio project
  from text instead of generating audio — the non-generating sibling of
  `POST /v1/text-to-speech/{voice_id}`. It takes `voice_id` and `text` in the body
  (plus optional `title`, `model_id`, `voice_settings`, `seed`, `output_format`),
  normalizes and chunks the text into a project (no provider calls), and returns
  the project's id, an auto-generated title when none is given (e.g.
  `Audio project #47 - June 19, 2026`), and a single-use `edit_url` that logs the
  user into the control panel and lands them on the project. Like the generation
  endpoints, it requires a valid API key (`xi-api-key`); it is not counted against
  the per-key generation rate limit, since it does no generation. Projects created
  this way record the calling API key (new nullable `tts_projects.api_key_id`).
- Single-use, expiring auto-login links (`GET /projects/open/{token}`), backed by
  a new `magic_login_tokens` table. The raw token lives only in the link; its
  sha256 hash is stored, redemption is atomic (single use), and the link expires
  after `TTS_MAGIC_LOGIN_TTL_MINUTES` (default **60**).

## [0.7.0] - 2026-06-19

### Added
- **Studio** — a new admin area (under **Studio** in the nav) for inspecting and
  editing text-to-speech output:
  - **Inspector.** Paste a block of text to see it normalized and split into
    chunks using the same normalization and chunking the generation pipeline
    uses, then hear it three ways: the whole text as a single Chatterbox call,
    each chunk on its own (raw provider output, so seam artifacts are audible),
    or the full production stitch. A concatenation preview re-stitches chunks you
    have already generated to audition a seam without re-synthesizing (Chatterbox
    is non-deterministic, so re-synthesizing would not reproduce the same audio).
  - **Editable projects.** Save a project, generate or regenerate individual
    chunks, edit a single sentence and re-synthesize only that chunk, then
    rebuild the stitched file — no full regeneration and no re-billing the whole
    file. An inline "Preview stitch" control between two adjacent generated chunks
    auditions their join, and the final file can be downloaded.
- `TTS_CHUNK_TRIM_TAIL_WINDOW_MS` (default **300**) bounds the trailing-silence
  trim to the last N ms of a chunk, so the trim removes Chatterbox's swoosh tail
  without reaching back and swallowing a quiet final word (see Fixed).
- `TTS_SHORT_TRAILER_WORDS` (default **3**) moves a sentence of this many words
  or fewer off the end of a chunk and onto the start of the next one (see Fixed).
  Set **0** to disable.

### Changed
- Studio audio players now play one at a time — starting any player pauses the
  others — and the actively-playing player stays highlighted while the rest dim
  to gray, so it is clear which clip is sounding. The redundant "playing now"
  status text was removed in favor of this visual cue.

### Fixed
- A short word at the very end of a chunk — most visibly a one-word "Why?" — is
  no longer dropped from the generated audio. Two independent causes were fixed:
  - The per-chunk edge-trim that removes Chatterbox's trailing "swoosh" was far
    more aggressive than its nominal threshold and could remove a quiet final
    word along with the trailing silence. It is now bounded to the last
    `TTS_CHUNK_TRIM_TAIL_WINDOW_MS` of each chunk — the swoosh sits after the
    word, so a short end-window strips the noise while the word is preserved.
  - Chatterbox itself tends to drop a short trailing utterance by ending
    generation early, yet renders a short *leading* phrase reliably, so the
    chunker now relocates a short sentence (≤ `TTS_SHORT_TRAILER_WORDS` words)
    that would end a chunk to the start of the next chunk. This applies only
    within a paragraph; a paragraph- or document-final short sentence is left in
    place (regenerate it from the Studio editor if it drops).

## [0.6.1] - 2026-06-18

### Fixed
- Very short standalone chunks — a one-word paragraph like "Why?", or a short
  heading like "The to-do list." — are no longer dropped from the generated
  audio. Headings and short paragraphs each become their own block, and thus
  their own Chatterbox generation; Chatterbox returns silence or garbage for
  inputs under ~25 characters, so those words went missing (heard as an extra
  pause). Such chunks are now merged into a neighbor before synthesis so nothing
  too short is ever sent on its own.

### Added
- `TTS_MIN_CHUNK_CHARS` (default **30**) sets the length below which a chunk is
  merged into an adjacent chunk — forward into the following chunk when it fits
  (the short line leads its content and the pause before it is preserved),
  otherwise back into the previous one. Set **0** to disable merging.

## [0.6.0] - 2026-06-18

### Added
- **Paragraph-aware pacing.** Long text is split into blocks on blank lines
  **or** runs of `TTS_BLOCK_SPACE_RUN` (default **4**) spaces, and a longer
  silence is inserted at block/paragraph seams (`TTS_PARAGRAPH_GAP_MS`, default
  **400**ms) than at sentence seams (`TTS_CHUNK_GAP_MS`, default **120**ms). The
  space-run rule recovers paragraph structure from clients that flatten a post to
  a single line and mark blocks with runs of spaces.
- **Configurable chunk edge-trimming.** `TTS_CHUNK_TRIM_THRESHOLD` (default
  **-40dB**; raise toward -30dB to cut a louder tail) and `TTS_CHUNK_FADE_MS`
  (default **8**ms) control the per-chunk trim + fade applied before joining.

### Changed
- Chunk concatenation now trims each chunk and rejoins the pieces with true
  digital silence instead of a fixed crossfade, producing clean, click-free seams
  and natural pacing.

### Removed
- `TTS_CHUNK_CROSSFADE_MS` / `chunk_crossfade_ms`, superseded by the trim +
  silence-gap join above.

### Fixed
- Long-form articles no longer have noisy "swooshy" pauses at paragraph and
  sentence breaks. Chatterbox appends a low-level noise/hiss tail to nearly every
  generation; because long text is synthesized as many short chunks and the tail
  was never trimmed, it landed at every concatenation seam — which fall exactly at
  sentence and paragraph boundaries. Each chunk is now edge-trimmed before it is
  joined, and the seams carry clean silence.
- Messy upstream text is normalized before synthesis: a space before terminal
  punctuation (`word .`) and spaced double-terminators (`word. .`) — which reach
  Chatterbox as awkward pauses or near-empty fragments and amplify its noise
  artifacts — are cleaned up, while real ellipses (`...`) are preserved.

## [0.5.0] - 2026-06-18

### Added
- `TTS_MAX_ASYNC_TEXT_LENGTH` (default **40000**) caps text on the async jobs
  endpoint independently of the synchronous `TTS_MAX_TEXT_LENGTH` (5000). ~40k
  characters is roughly 6,000–7,000 words, or about 40–50 minutes of narration —
  comfortably more than any single article. Raise it only alongside
  `TTS_ASYNC_TIMEOUT` and provider throughput (see the README's async section).

### Fixed
- The async jobs endpoint (`POST .../jobs`) no longer rejects text longer than
  the synchronous `max_text_length` (5000). That cap is bounded by the ~300s
  synchronous request budget, but async generation runs in a background worker
  (bounded instead by `TTS_ASYNC_TIMEOUT`) and chunks server-side — so long
  articles, the whole reason the async path exists, were being rejected with
  "The text field must not be greater than 5000 characters." before any chunking
  ran. The endpoint now validates against `max_async_text_length`.

## [0.4.1] - 2026-06-18

### Fixed
- The database queue's `retry_after` now defaults to `TTS_ASYNC_TIMEOUT + 60`
  (1860s) instead of Laravel's 90s, so a long async job can no longer be released
  back to the queue mid-generation and run twice. The health page's **Queue timing**
  check now passes out of the box; set `DB_QUEUE_RETRY_AFTER` only to override.

### Changed
- Clarified that the queue worker's `--timeout` flag is **optional**:
  `GenerateSpeechJob` pins its own timeout (`TTS_ASYNC_TIMEOUT`), which Laravel uses
  in preference to the worker flag, so a long job is never killed early regardless.
  Updated the deployment guide, `.env.example`, and config comments to match.

## [0.4.0] - 2026-06-18

### Added
- **Admin health page (`/admin/health`)** — a web view over the same checks as
  `tts:doctor`, rendering PASS/WARN/FAIL badges for PHP, database, migrations,
  cache, ffmpeg, storage, disk space, provider, queue, queue timing, failed jobs,
  scheduler, expired-audio cleanup, voices, API keys, app key/debug, and APP_URL.
  Includes a **Run live checks** (`--deep`) action and **interactive live provider
  tests** — short (synchronous) and long (async chunking + queue worker +
  concatenation) — that generate real audio and play it inline; the long test
  fails fast when no worker is running.
- **Liveness checks, not just configuration.** `tts:doctor` and the health page now
  detect whether the **scheduler cron** and a **`queue:work` worker** are actually
  running — via heartbeats (a per-minute scheduled task, and a key the worker
  stamps on each loop) — instead of only confirming they're configured. `--deep`
  also validates the Replicate token live and dispatches a queue probe job.
- **Additional checks**: pending migrations, a persistent (non-`array`) cache store,
  disk free space, `failed_jobs` count, `DB_QUEUE_RETRY_AFTER` vs `TTS_ASYNC_TIMEOUT`,
  expired-audio backlog, at least one active voice and API key, and production
  `APP_URL` sanity.
- **Version footer** in the admin panel showing the app name and version
  (`config('app.version')`, read from `composer.json`).

### Changed
- Refactored `tts:doctor`'s checks into a shared `HealthReport` service, so the CLI,
  the web page, and future surfaces (e.g. a status endpoint) render the same results
  from one source instead of drifting.

## [0.3.0] - 2026-06-18

### Added
- **`speech:cleanup` command + daily schedule** — deletes expired generated audio
  (database rows **and** the files on the configured disk, including **S3**) once
  past its `TTS_TTL_HOURS` TTL. Scheduled to run daily, so production only needs a
  cron calling `php artisan schedule:run`. Supports `--dry-run` and `--before`.
  (Previously, expired audio and rows accumulated forever.)
- **`tts:doctor` command** — verifies the install (PHP/extensions, database,
  ffmpeg, the storage disk, the provider + Replicate token, the queue, and the
  cleanup schedule) with PASS/WARN/FAIL output; exits non-zero on any failure so
  it's usable in CI / a deploy script. `--deep` validates the Replicate token live.

### Changed
- Rewrote the README to be value-first for Bespoken plugin users, made the
  **Replicate account + credit** requirement explicit, documented every artisan
  command, and corrected the stale roadmap (async shipped in 0.2.0).
- Documented **S3 storage** and the **scheduler / queue-worker** requirements in
  the deployment guide, and replaced the two `NEXT_STEPS_*.md` working notes with a
  concise `ROADMAP.md`.

## [0.2.0] - 2026-06-18

### Added
- **Async generation for long text.** A Bespoken extension that lifts the ~300s
  synchronous ceiling: `POST /v1/text-to-speech/{voice_id}/jobs` returns **202**
  with a job `id` + `status_url`/`audio_url` and runs generation in a queued
  `GenerateSpeechJob`; `GET /v1/text-to-speech/jobs/{id}` polls status and
  `GET /v1/text-to-speech/jobs/{id}/audio` streams the MP3 once complete. Poll
  and audio reads are authenticated but **not** rate-limited (only the generating
  POST is) and are scoped to the calling API key. Identical requests already
  cached return **200** immediately; an identical request already in flight joins
  the running job instead of starting a duplicate. Needs a running queue worker
  (`queue:work`, timeout ≥ `TTS_ASYNC_TIMEOUT`, default 1800s); with
  `QUEUE_CONNECTION=sync` it degrades to synchronous. The synchronous
  `POST /v1/text-to-speech/{voice_id}` is unchanged. (Phase 4 of
  `NEXT_STEPS_17JUN2026.md`.)
- **Survive Replicate's burst rate limit.** Replicate throttles prediction
  creation (e.g. "6/min, burst 1") with an HTTP **429** + `retry_after` hint;
  previously a single throttled chunk failed the whole article with a 502. Every
  Replicate request (create, poll, audio download) now retries on 429 — honoring
  `retry_after` from the body or `Retry-After` header, falling back to
  exponential backoff, and bounded by `request_timeout` so it can't outlive the
  synchronous budget. Tunable via `REPLICATE_MAX_RETRIES`,
  `REPLICATE_RETRY_BASE_MS`, and `REPLICATE_RETRY_MAX_MS`. An optional
  `REPLICATE_MIN_REQUEST_GAP_MS` spaces calls out proactively to avoid 429s
  up front. (Phase 1 of `NEXT_STEPS_17JUN2026.md`.)
- `NEXT_STEPS_17JUN2026.md` — the implementation plan for the service-owned
  chunking, 429 retry, and async generation work delivered in this release.
- Deployment guide (`docs/DEPLOYMENT.md`) covering Laravel Forge (step by step)
  and generic hosts, including the ffmpeg requirement and the dashboard asset build.
- Bespoken plugin integration guide (`docs/BESPOKEN.md`).
- Voice **export / import** — move a voice (manifest + reference clip) between
  installs as a portable `.zip`, from the dashboard's Voices page or via the
  `voice:export` / `voice:import` commands.
- Automatic **text chunking** for long input — text is split into short,
  sentence-aware chunks (Chatterbox is short-form), each generated separately and
  the audio concatenated into one file. Tunable via `TTS_CHUNK_CHARS` (default 280).
- **Edit voices** from the dashboard — rename the `voice_id` (slug), change the
  default seed, or replace the reference clip (renaming moves the stored clip to
  match).
- **Regenerate (rotate) an API key** from the dashboard — issues a new secret
  while keeping the key's name, rate limit, and usage history; the old value
  stops working immediately.
- **Crossfade between generated chunks** (~25 ms, `TTS_CHUNK_CROSSFADE_MS`; set 0
  for a hard join) for click-free seams when long text is split and concatenated.

### Fixed
- Logged-in users now land on the dashboard from the landing page and `/login`
  instead of bouncing back to the homepage (the "Open dashboard" link points
  authenticated users to `/admin`, and `/login` redirects them there too).

## [0.1.0] - 2026-06-17

### Added
- Initial self-hosted, **ElevenLabs-compatible** TTS service (Laravel) — a
  companion server for the Bespoken Craft plugin.
- `POST /v1/text-to-speech/{voice_id}` returning MP3 audio, with `xi-api-key`
  authentication and ElevenLabs-shaped JSON errors (`{"detail":{"message":…}}`).
  `{voice_id}` resolves by slug, then UUID.
- Pluggable inference backend via `config('tts.provider')`: **Replicate /
  Chatterbox** (default, version-pinned) and a `fake` driver for local/tests.
- API-key authentication and per-key hourly rate limiting; management commands
  `apikey:create` and `apikey:list`.
- Voice registry with zero-shot cloning from a short reference clip
  (`voice:create`, `voice:list`).
- Automatic reference-audio normalization on registration (downmix to mono,
  trim silence, loudness-normalize, true-peak limit). Opt out per voice with
  `--raw` or globally with `TTS_NORMALIZE_REFERENCE=false`.
- `seed` support for reproducible output — per request and as a per-voice
  default (`voice:create --seed=`).
- ffmpeg-based output conversion. Defaults to a fixed mono MP3 (44.1 kHz /
  128 kbps) so chunked audio concatenates cleanly; ElevenLabs `output_format`
  tokens are supported.
- Response caching keyed on voice + reference fingerprint + text + settings +
  seed; re-recording a voice automatically invalidates its cached audio.
- Feature and unit tests; Pint code style.
- MIT license.
- GitHub Actions CI running Pint and the test suite (with ffmpeg in the runner;
  builds the dashboard assets).
- **Password-protected web dashboard** (Tailwind): minimal public landing,
  session login (single seeded admin via `ADMIN_EMAIL`/`ADMIN_PASSWORD` or
  `admin:create`), API-key management, voice management ("train" = upload a
  reference clip → instant zero-shot registration + normalization), and a
  Test-voice preview. Home page surfaces copy-paste Bespoken connection details.
  Built on an `is_super_admin` flag + `EnsureUserIsAdmin` middleware as the seam
  for future multi-user roles. Shared `VoiceService` backs both the dashboard and
  the `voice:create` command.

### Notes
- Chatterbox inference on Replicate is not bit-for-bit deterministic even at a
  fixed seed; the response cache guarantees stable output for repeated identical
  requests.

[Unreleased]: https://github.com/johnfmorton/alias-tts/compare/v0.23.0...HEAD
[0.23.0]: https://github.com/johnfmorton/alias-tts/compare/v0.22.0...v0.23.0
[0.22.0]: https://github.com/johnfmorton/alias-tts/compare/v0.21.0...v0.22.0
[0.21.0]: https://github.com/johnfmorton/alias-tts/compare/v0.20.0...v0.21.0
[0.20.0]: https://github.com/johnfmorton/alias-tts/compare/v0.19.0...v0.20.0
[0.19.0]: https://github.com/johnfmorton/alias-tts/compare/v0.18.0...v0.19.0
[0.18.0]: https://github.com/johnfmorton/alias-tts/compare/v0.17.1...v0.18.0
[0.17.1]: https://github.com/johnfmorton/alias-tts/compare/v0.17.0...v0.17.1
[0.17.0]: https://github.com/johnfmorton/alias-tts/compare/v0.16.0...v0.17.0
[0.16.0]: https://github.com/johnfmorton/alias-tts/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/johnfmorton/alias-tts/compare/v0.14.7...v0.15.0
[0.14.7]: https://github.com/johnfmorton/alias-tts/compare/v0.14.6...v0.14.7
[0.14.6]: https://github.com/johnfmorton/alias-tts/compare/v0.14.5...v0.14.6
[0.14.5]: https://github.com/johnfmorton/alias-tts/compare/v0.14.4...v0.14.5
[0.14.4]: https://github.com/johnfmorton/alias-tts/compare/v0.14.3...v0.14.4
[0.14.3]: https://github.com/johnfmorton/alias-tts/compare/v0.14.2...v0.14.3
[0.14.2]: https://github.com/johnfmorton/alias-tts/compare/v0.14.1...v0.14.2
[0.14.1]: https://github.com/johnfmorton/alias-tts/compare/v0.14.0...v0.14.1
[0.14.0]: https://github.com/johnfmorton/alias-tts/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/johnfmorton/alias-tts/compare/v0.12.3...v0.13.0
[0.12.3]: https://github.com/johnfmorton/alias-tts/compare/v0.12.2...v0.12.3
[0.12.2]: https://github.com/johnfmorton/alias-tts/compare/v0.12.1...v0.12.2
[0.12.1]: https://github.com/johnfmorton/alias-tts/compare/v0.12.0...v0.12.1
[0.12.0]: https://github.com/johnfmorton/alias-tts/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/johnfmorton/alias-tts/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/johnfmorton/alias-tts/compare/v0.9.3...v0.10.0
[0.9.3]: https://github.com/johnfmorton/alias-tts/compare/v0.9.2...v0.9.3
[0.9.2]: https://github.com/johnfmorton/alias-tts/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/johnfmorton/alias-tts/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/johnfmorton/alias-tts/compare/v0.8.2...v0.9.0
[0.8.2]: https://github.com/johnfmorton/alias-tts/compare/v0.8.1...v0.8.2
[0.8.1]: https://github.com/johnfmorton/alias-tts/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/johnfmorton/alias-tts/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/johnfmorton/alias-tts/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/johnfmorton/alias-tts/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/johnfmorton/alias-tts/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/johnfmorton/alias-tts/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/johnfmorton/alias-tts/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/johnfmorton/alias-tts/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/johnfmorton/alias-tts/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/johnfmorton/alias-tts/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/johnfmorton/alias-tts/releases/tag/v0.1.0
