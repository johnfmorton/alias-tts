<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Approved final receipt — {{ $project->title }}</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #18181b; background: #fff; }
  main { max-width: 880px; margin: 0 auto; padding: 2.5rem 1.5rem 4rem; }
  h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
  .sub { color: #52525b; margin: 0 0 2rem; }
  .seal { border: 1px solid #a7f3d0; background: #ecfdf5; border-radius: .9rem; padding: 1.25rem 1.4rem; margin-bottom: 1.75rem; }
  .seal .ok { color: #047857; font-weight: 700; font-size: 1.05rem; }
  .seal dl { display: grid; grid-template-columns: max-content 1fr; gap: .35rem 1rem; margin: .9rem 0 0; }
  .seal dt { color: #047857; }
  .seal dd { margin: 0; }
  .hash { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; }
  .verify { margin-top: 1.1rem; padding-top: 1rem; border-top: 1px solid #a7f3d0; }
  .verify > p { color: #047857; margin: .6rem 0 0; font-size: .9rem; }
  .drop { display: block; border: 2px dashed #34d399; border-radius: .75rem; background: #fff; padding: 1.1rem 1rem; text-align: center; cursor: pointer; transition: border-color .15s, background .15s; }
  .drop:hover, .drop.over { border-color: #059669; background: #f0fdf4; }
  .drop strong { color: #047857; }
  .drop span { color: #52525b; font-size: .85rem; }
  .drop input { display: none; }
  .result { display: none; margin-top: .9rem; border-radius: .75rem; padding: 1rem 1.1rem; }
  .result.show { display: block; }
  .result.match   { background: #ecfdf5; border: 1px solid #34d399; }
  .result.nomatch { background: #fef2f2; border: 1px solid #fca5a5; }
  .result.info    { background: #f4f4f5; border: 1px solid #d4d4d8; }
  .result.warn    { background: #fffbeb; border: 1px solid #fcd34d; }
  .result .verdict { font-size: 1.02rem; font-weight: 700; margin: 0 0 .25rem; color: #18181b; }
  .result.match .verdict   { color: #047857; }
  .result.nomatch .verdict { color: #b91c1c; }
  .result.warn .verdict    { color: #b45309; }
  .result .detail { margin: 0; font-size: .88rem; color: #52525b; }
  .hashes { margin-top: .75rem; font-size: .78rem; }
  .hashes div { margin-top: .35rem; }
  .hashes .lbl { color: #71717a; display: block; }
  .hashes .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; }
  h2 { font-size: 1.05rem; margin: 2rem 0 .75rem; }
  /* Fixed layout + wrapping so long cells (the SHA and the QA summary) stay inside
     their columns instead of blowing the table past the page width. */
  table { width: 100%; border-collapse: collapse; font-size: .85rem; table-layout: fixed; }
  th, td { text-align: left; vertical-align: top; padding: .55rem .6rem; border-bottom: 1px solid #e4e4e7; overflow-wrap: anywhere; }
  th { color: #71717a; font-weight: 600; border-bottom: 2px solid #d4d4d8; }
  td.num { color: #52525b; }
  .chash { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .72rem; color: #71717a; word-break: break-all; }
  .muted { color: #71717a; font-size: .82rem; }
  footer { margin-top: 2.5rem; color: #a1a1aa; font-size: .8rem; }
  @media print { .drop, .result { display: none !important; } }
</style>
</head>
<body>
<main>
  <h1>Approved final receipt</h1>
  <p class="sub">{{ $project->title }}</p>

  <div class="seal">
    <div class="ok">✓ Approved as the final</div>
    <dl>
      <dt>Approved by</dt><dd>{{ $project->sealApprover() ?? '—' }}</dd>
      <dt>Approved on</dt><dd>{{ optional($project->sealed_at)->toDayDateTimeString() ?? '—' }}</dd>
      <dt>File</dt><dd>{{ $finalName }} @if($project->final_bytes)· {{ number_format($project->final_bytes) }} bytes @endif @if($project->mime_type)· {{ $project->mime_type }}@endif</dd>
      <dt>Voice</dt><dd>{{ $manifest['project']['voice'] ?? '—' }}</dd>
      <dt>SHA-256</dt><dd class="hash">{{ $project->final_sha256 }}</dd>
    </dl>

    <div class="verify">
      <label class="drop" id="drop">
        <strong>Drop {{ $finalName }} here to verify it</strong><br>
        <span>or click to choose the file</span>
        <input type="file" id="file" accept="audio/*,.mp3,.wav,.m4a,.ogg">
      </label>
      <div class="result" id="result">
        <p class="verdict" id="verdict"></p>
        <p class="detail" id="detail"></p>
        <div class="hashes" id="hashes"></div>
      </div>
      <p>Verifying re-computes the file's fingerprint <em>on your device</em> — nothing is uploaded —
         and compares it to the approved SHA-256 above. Works fully offline.</p>
    </div>
  </div>

  <h2>How it was made — {{ count($chunks) }} chunk{{ count($chunks) === 1 ? '' : 's' }}</h2>
  <table>
    <colgroup>
      <col style="width:4%">
      <col style="width:34%">
      <col style="width:14%">
      <col style="width:7%">
      <col style="width:25%">
      <col style="width:16%">
    </colgroup>
    <thead>
      <tr>
        <th>#</th>
        <th>Script</th>
        <th>Voice</th>
        <th>Takes</th>
        <th>QA</th>
        <th>Source SHA-256<br><span class="muted">(pre-trim / pre-stitch)</span></th>
      </tr>
    </thead>
    <tbody>
      @foreach($chunks as $row)
        <tr>
          <td class="num">{{ $row['position'] + 1 }}</td>
          <td>{{ $row['text'] }}</td>
          <td class="num">
            {{ $row['voice'] ?? '—' }}
            @if($row['voice_inherited'])<br><span class="muted">project default</span>@endif
          </td>
          <td class="num">{{ $row['attempts'] }}</td>
          <td class="num">
            @if($row['asr_score'] !== null){{ $row['asr_score'] }}@else—@endif
            @if($row['asr_summary'])<br><span class="muted">{{ $row['asr_summary'] }}</span>@endif
          </td>
          <td class="chash">{{ $row['source_audio_sha256'] ?? '—' }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <p class="muted" style="margin-top:1rem">
    The <strong>SHA-256</strong> above is the only fingerprint that verifies <strong>{{ $finalName }}</strong>
    byte-for-byte. The per-chunk hashes cover each chunk's source take <em>before</em> trimming, stitching,
    and re-encoding, so they document the inputs but won't reconstruct the final file.
  </p>

  <footer>
    Generated by Mimic TTS. The machine-readable version of this receipt is in <code>manifest.json</code>.
    Verification runs entirely in this page &mdash; save it, open it with no network, and it still works
    (it uses your browser's built-in <code>crypto.subtle</code>).
  </footer>
</main>

<script>
  (function () {
    var drop = document.getElementById('drop');
    var fileInput = document.getElementById('file');
    var result = document.getElementById('result');
    var verdict = document.getElementById('verdict');
    var detail = document.getElementById('detail');
    var hashes = document.getElementById('hashes');

    // The approved byte hash, baked in when this receipt was exported — the same
    // value printed in the SHA-256 row above and in manifest.json.
    var expect = '{{ $project->final_sha256 }}'.toLowerCase();

    function show(kind, head, msg) {
      result.className = 'result show ' + kind;
      verdict.textContent = head;
      detail.textContent = msg;
      hashes.innerHTML = '';
    }

    function row(label, value) {
      var d = document.createElement('div');
      var l = document.createElement('span'); l.className = 'lbl'; l.textContent = label;
      var v = document.createElement('span'); v.className = 'mono'; v.textContent = value;
      d.appendChild(l); d.appendChild(v); hashes.appendChild(d);
    }

    async function sha256Hex(file) {
      var buf = await file.arrayBuffer();
      var digest = await crypto.subtle.digest('SHA-256', buf);
      return Array.from(new Uint8Array(digest)).map(function (b) {
        return b.toString(16).padStart(2, '0');
      }).join('');
    }

    async function handleFile(file) {
      if (!file) return;
      // crypto.subtle exists only in a secure context: https, localhost, or
      // file://. On plain http to a LAN IP it's undefined — tell the user how to
      // get a real answer (open the local copy) instead of failing mute.
      if (!window.crypto || !crypto.subtle) {
        show('warn', 'Can’t verify here', 'This browser only exposes secure hashing over HTTPS or from a local file. Save this receipt, open it by double-clicking it, and drop the file there.');
        return;
      }
      show('info', 'Checking…', file.name);
      var hex;
      try {
        hex = await sha256Hex(file);
      } catch (e) {
        show('warn', 'Couldn’t read that file', String(e && e.message || e));
        return;
      }

      if (hex === expect) {
        show('match', '✅ Match — this is the approved final', 'These bytes are identical to the file that was approved. It has not been edited or re-exported.');
      } else {
        show('nomatch', '❌ No match', 'This file is NOT the approved final — it has been edited, re-exported, or is a different file.');
      }
      row('Expected (approved fingerprint)', expect);
      row('Computed (your file)', hex);
    }

    // Click-to-pick.
    fileInput.addEventListener('change', function () {
      handleFile(fileInput.files && fileInput.files[0]);
    });

    // Drag-and-drop (prevent the browser from just opening the file).
    ['dragenter', 'dragover'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
    });
    drop.addEventListener('drop', function (e) {
      var dt = e.dataTransfer;
      handleFile(dt && dt.files && dt.files[0]);
    });
  })();
</script>
</body>
</html>
