{{--
  Per-chunk provenance for a sealed final — shared by the receipt (.zip, offline)
  and the hosted /verify page so both show the identical table, including the
  SELECTED take's snapshotted text for each chunk. The host page styles the
  `table / th / td / .num / .chash / .muted` classes for its own theme.

  Expects: $chunks (list of rows from ProjectExportService::chunkRows), $finalName.
--}}
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
