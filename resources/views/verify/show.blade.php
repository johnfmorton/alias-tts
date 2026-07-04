<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Verify the approved final — Mimic TTS</title>
<link rel="icon" href="/favicon.ico" sizes="32x32">
<link rel="icon" href="/mimic-icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh;
    font: 16px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    background: #09090b; color: #e4e4e7;
    display: flex; align-items: flex-start; justify-content: center;
  }
  main { width: 100%; max-width: 760px; padding: 2.5rem 1.25rem 4rem; }
  h1 { font-size: 1.35rem; margin: 0 0 .25rem; }
  .lede { color: #a1a1aa; margin: 0 0 1.75rem; }
  a { color: #67e8f9; }

  form.verify { margin: 0 0 1.5rem; }
  .drop {
    border: 2px dashed #3f3f46; border-radius: 1rem;
    padding: 2rem 1.5rem; text-align: center; cursor: pointer;
    transition: border-color .15s, background .15s; display: block;
  }
  .drop:hover, .drop.over { border-color: #22d3ee; background: rgba(34,211,238,.06); }
  .drop strong { color: #e4e4e7; }
  .drop span { color: #a1a1aa; font-size: .9rem; }
  .drop input[type=file] { display: none; }
  .actions { display: flex; align-items: center; gap: .85rem; margin-top: 1rem; flex-wrap: wrap; }
  .btn {
    appearance: none; border: 0; border-radius: .6rem; cursor: pointer;
    background: #22d3ee; color: #06232a; font: inherit; font-weight: 600;
    padding: .6rem 1.1rem;
  }
  .btn:disabled { opacity: .6; cursor: progress; }
  .picked { color: #a1a1aa; font-size: .88rem; word-break: break-all; }
  .err { color: #fb7185; font-size: .88rem; margin: .6rem 0 0; }

  .result { margin: 0 0 1.75rem; border-radius: 1rem; padding: 1.25rem 1.4rem; }
  .result.match    { background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.35); }
  .result.nomatch  { background: rgba(244,63,94,.1);  border: 1px solid rgba(244,63,94,.35); }
  .result.info     { background: rgba(34,211,238,.08); border: 1px solid rgba(34,211,238,.3); }
  .verdict { font-size: 1.2rem; font-weight: 600; margin: 0 0 .35rem; }
  .match .verdict   { color: #34d399; }
  .nomatch .verdict { color: #fb7185; }
  .info .verdict    { color: #67e8f9; }
  .detail { color: #a1a1aa; margin: 0; font-size: .92rem; }
  .hashes { margin-top: 1rem; font-size: .8rem; }
  .hashes div { margin-top: .4rem; }
  .hashes .lbl { color: #71717a; display: block; }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; }

  .seal { border: 1px solid rgba(16,185,129,.3); background: rgba(16,185,129,.06); border-radius: 1rem; padding: 1.25rem 1.4rem; margin: 0 0 1.5rem; }
  .seal dl { display: grid; grid-template-columns: max-content 1fr; gap: .35rem 1rem; margin: 0; }
  .seal dt { color: #6ee7b7; }
  .seal dd { margin: 0; }
  .integrity { margin: .9rem 0 0; padding-top: .8rem; border-top: 1px solid rgba(16,185,129,.2); font-size: .85rem; color: #a1a1aa; }
  .integrity.warn { color: #fcd34d; }

  h2 { font-size: 1.05rem; margin: 2rem 0 .75rem; }
  table { width: 100%; border-collapse: collapse; font-size: .85rem; table-layout: fixed; }
  th, td { text-align: left; vertical-align: top; padding: .55rem .6rem; border-bottom: 1px solid #27272a; overflow-wrap: anywhere; }
  th { color: #a1a1aa; font-weight: 600; border-bottom: 2px solid #3f3f46; }
  td.num { color: #a1a1aa; }
  .chash { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .72rem; color: #71717a; word-break: break-all; }
  .muted { color: #71717a; font-size: .82rem; }
  footer { margin-top: 2.5rem; color: #71717a; font-size: .82rem; }
  footer code { color: #a1a1aa; }
</style>
</head>
<body>
<main>
  <h1>Verify the approved final</h1>
  <p class="lede">Upload the audio file to confirm it's the exact cut that was approved, untouched. Mimic hashes it on the server and matches it against the sealed approval.</p>

  {{-- ---- Result banner (upload matched / no match / record lookup) ---- --}}
  @if($state === 'match')
    <div class="result match">
      <p class="verdict">✅ Verified — this is the approved final</p>
      <p class="detail">These exact bytes were approved and sealed. The file has not been edited or re-exported since.</p>
      <div class="hashes">
        @if($uploadedName)<div><span class="lbl">Your file</span><span class="mono">{{ $uploadedName }}</span></div>@endif
        <div><span class="lbl">SHA-256 (matches the sealed fingerprint)</span><span class="mono">{{ $uploadedHash }}</span></div>
      </div>
    </div>
  @elseif($state === 'nomatch')
    <div class="result nomatch">
      <p class="verdict">❌ No match</p>
      <p class="detail">No sealed approval matches these bytes — the file has been edited, re-exported, or was never approved here.</p>
      <div class="hashes">
        @if($uploadedName)<div><span class="lbl">Your file</span><span class="mono">{{ $uploadedName }}</span></div>@endif
        <div><span class="lbl">SHA-256 of your file</span><span class="mono">{{ $uploadedHash }}</span></div>
      </div>
    </div>
  @elseif($state === 'record')
    <div class="result info">
      <p class="verdict">🔒 A sealed approval exists for this fingerprint</p>
      <p class="detail">Here is what was approved. To prove your own copy matches it byte-for-byte, upload the file below.</p>
      <div class="hashes"><div><span class="lbl">Fingerprint</span><span class="mono">{{ $querySha }}</span></div></div>
    </div>
  @elseif($state === 'record_missing')
    <div class="result nomatch">
      <p class="verdict">No approval found</p>
      <p class="detail">No sealed approval matches that fingerprint. It may have been unapproved, rebuilt, or never sealed on this server.</p>
      <div class="hashes"><div><span class="lbl">Fingerprint</span><span class="mono">{{ $querySha }}</span></div></div>
    </div>
  @endif

  {{-- ---- Provenance (only when a sealed record was found) ---- --}}
  @if($project)
    <div class="seal">
      <dl>
        <dt>Approved by</dt><dd>{{ $project->sealed_by_name ?: '—' }}</dd>
        <dt>Approved on</dt><dd>{{ optional($project->sealed_at)->toDayDateTimeString() ?? '—' }}</dd>
        <dt>File</dt><dd>{{ $finalName }}@if($project->final_bytes) · {{ number_format($project->final_bytes) }} bytes @endif @if($project->mime_type)· {{ $project->mime_type }}@endif</dd>
        <dt>Voice</dt><dd>{{ $project->voice?->name ?? '—' }}</dd>
        <dt>SHA-256</dt><dd class="mono">{{ $project->final_sha256 }}</dd>
      </dl>
      @if($snapshotVerified === true)
        <p class="integrity">✓ Snapshot integrity: server-confirmed — the frozen copy stored here still hashes to this fingerprint.</p>
      @elseif($snapshotVerified === false)
        <p class="integrity warn">⚠ The frozen copy stored here no longer matches this fingerprint — contact the project owner.</p>
      @endif
    </div>

    @include('partials.seal-provenance', ['chunks' => $chunks, 'finalName' => $finalName])
  @endif

  {{-- ---- Upload form (server-side hashing; shown for every state) ---- --}}
  <form class="verify" method="POST" action="{{ route('verify.check') }}" enctype="multipart/form-data" id="verify-form">
    @csrf
    <label class="drop" id="drop">
      <strong>Drop the audio file here</strong><br>
      <span>or click to choose it</span>
      <input type="file" name="file" id="file" accept="audio/*,.mp3,.wav,.m4a,.ogg" required>
    </label>
    <div class="actions">
      <button type="submit" class="btn" id="submit">Verify file</button>
      <span class="picked" id="picked"></span>
    </div>
    @error('file')<p class="err">{{ $message }}</p>@enderror
  </form>

  <footer>
    Verification runs on the server: your file is hashed with SHA-256 and matched against the sealed
    approval — it is not stored. Files up to {{ $maxUploadMb }} MB. The machine-readable record travels
    in each receipt's <code>manifest.json</code>.
  </footer>
</main>

<script>
  // Progressive enhancement only — NO hashing here. Drag-drop fills the file
  // input and the server does the SHA-256 compare; keeps the drop-zone feel.
  (function () {
    var drop = document.getElementById('drop');
    var file = document.getElementById('file');
    var form = document.getElementById('verify-form');
    var submit = document.getElementById('submit');
    var picked = document.getElementById('picked');

    function named() {
      var f = file.files && file.files[0];
      picked.textContent = f ? f.name : '';
    }
    file.addEventListener('change', named);

    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
    });
    drop.addEventListener('drop', function (e) {
      var dt = e.dataTransfer;
      if (dt && dt.files && dt.files.length) { file.files = dt.files; named(); }
    });

    form.addEventListener('submit', function () {
      if (file.files && file.files.length) { submit.disabled = true; submit.textContent = 'Verifying…'; }
    });
  })();
</script>
</body>
</html>
