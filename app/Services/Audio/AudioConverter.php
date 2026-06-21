<?php

namespace App\Services\Audio;

use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Feature\AudioConverterTest;

/**
 * Transcodes raw audio bytes to an ElevenLabs-style output_format using ffmpeg.
 *
 * The default profile is a fixed mono MP3 (44.1 kHz / 128 kbps) so that audio
 * generated across multiple requests concatenates cleanly — the Bespoken Craft
 * plugin chunks long text and stitches the resulting .mp3 files together.
 */
class AudioConverter
{
    public function __construct(
        private string $ffmpegPath = 'ffmpeg',
    ) {}

    /**
     * @return array{0: string, 1: string, 2: string} [bytes, mimeType, extension]
     */
    public function convert(string $inputBytes, string $outputFormat, string $inputContainer = 'wav'): array
    {
        $spec = $this->parseFormat($outputFormat);

        // ffmpeg probes the input format from content, and the output format is
        // pinned with `-f` below, so neither temp file needs an extension.
        $in = tempnam(sys_get_temp_dir(), 'tts_in_');
        $out = tempnam(sys_get_temp_dir(), 'tts_out_');

        try {
            file_put_contents($in, $inputBytes);

            $args = array_merge(
                [$this->ffmpegPath, '-y', '-hide_banner', '-loglevel', 'error', '-i', $in, '-ac', '1', '-ar', (string) $spec['rate']],
                $spec['codec_args'],
                [$out]
            );

            $process = new Process($args);
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('ffmpeg conversion failed: '.trim($process->getErrorOutput()));
            }

            $bytes = file_get_contents($out);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('ffmpeg produced no output.');
            }

            return [$bytes, $spec['mime'], $spec['ext']];
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * Concatenate multiple audio byte-chunks (same container) into a single file
     * in the requested output format. Used when long text is split into chunks.
     *
     * Each chunk is first edge-trimmed and faded: Chatterbox appends a low-level
     * "swoosh"/hiss tail to most generations, and left in place it lands exactly
     * at every seam (which falls at a sentence/paragraph boundary), so trimming
     * it is the core fix for the noisy pauses. The trimmed chunks are then joined
     * with a controlled amount of true digital silence — $seamGapsMs[i] ms after
     * chunk i — giving clean, click-free seams and natural pacing (callers pass a
     * larger gap at paragraph seams than at sentence seams).
     *
     * @param  array<int, string>  $inputChunks
     * @param  array<int, int>  $seamGapsMs  silence (ms) to insert after each chunk; the entry after the last chunk is ignored
     * @return array{0: string, 1: string, 2: string} [bytes, mimeType, extension]
     */
    public function concatenate(array $inputChunks, string $outputFormat, string $inputContainer = 'wav', array $seamGapsMs = []): array
    {
        $inputChunks = array_values($inputChunks);
        $spec = $this->parseFormat($outputFormat);

        $threshold = (string) config('tts.chunk_trim_threshold', '-40dB');
        $fadeMs = max(0, (int) config('tts.chunk_fade_ms', 8));
        $tailWindowMs = max(0, (int) config('tts.chunk_trim_tail_window_ms', 300));

        if (count($inputChunks) === 1) {
            // Trim the single chunk's edges too (drops the trailing artifact),
            // then encode to the requested format.
            $trimmed = $this->trimChunk($inputChunks[0], $spec['rate'], $threshold, $fadeMs, $tailWindowMs);

            return $this->convert($trimmed, $outputFormat, 'wav');
        }

        $files = [];          // every temp file to clean up
        $silenceCache = [];    // gap ms => silence temp file (reused across seams)
        $entries = [];         // concat demuxer list lines

        $outFile = tempnam(sys_get_temp_dir(), 'tts_catout_');
        $list = tempnam(sys_get_temp_dir(), 'tts_list_');
        $files[] = $list;

        try {
            $last = count($inputChunks) - 1;

            foreach ($inputChunks as $i => $bytes) {
                $chunkFile = tempnam(sys_get_temp_dir(), 'tts_cat_');
                file_put_contents($chunkFile, $this->trimChunk($bytes, $spec['rate'], $threshold, $fadeMs, $tailWindowMs));
                $files[] = $chunkFile;
                $entries[] = "file '".$chunkFile."'";

                if ($i >= $last) {
                    continue;
                }

                $gapMs = max(0, (int) ($seamGapsMs[$i] ?? 0));
                if ($gapMs === 0) {
                    continue;
                }

                if (! isset($silenceCache[$gapMs])) {
                    $silenceFile = tempnam(sys_get_temp_dir(), 'tts_sil_');
                    file_put_contents($silenceFile, $this->silenceWav($gapMs, $spec['rate']));
                    $files[] = $silenceFile;
                    $silenceCache[$gapMs] = $silenceFile;
                }
                $entries[] = "file '".$silenceCache[$gapMs]."'";
            }

            file_put_contents($list, implode("\n", $entries)."\n");

            // Every piece is mono pcm_s16le at $spec['rate'], so the concat
            // demuxer joins them cleanly; encode straight to the output format.
            $args = array_merge(
                [$this->ffmpegPath, '-y', '-hide_banner', '-loglevel', 'error',
                    '-f', 'concat', '-safe', '0', '-i', $list,
                    '-ac', '1', '-ar', (string) $spec['rate']],
                $spec['codec_args'],
                [$outFile]
            );

            $process = new Process($args);
            $process->setTimeout(300);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('ffmpeg concatenation failed: '.trim($process->getErrorOutput()));
            }

            $bytes = file_get_contents($outFile);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('ffmpeg produced no concatenated output.');
            }

            return [$bytes, $spec['mime'], $spec['ext']];
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
            @unlink($outFile);
        }
    }

    /**
     * Hard-cut a WAV to its first $ms milliseconds, preserving the source sample
     * rate and channel layout (re-encoded lossless pcm_s16le). Used to drop a
     * trailing artifact at a known speech-end point — e.g. the ASR-derived cut
     * for a speech-like / "ghostly singing" tail that the zero-crossing
     * {@see detectLongTailArtifact} can't see — on the raw chunk before it is
     * stored; the normal concat-time trim still runs on top. Returns null on
     * failure (or a non-positive $ms) so callers can keep the untrimmed bytes.
     */
    public function truncateToMs(string $bytes, int $ms): ?string
    {
        if ($ms <= 0) {
            return null;
        }

        $in = tempnam(sys_get_temp_dir(), 'tts_cut_in_');
        $out = tempnam(sys_get_temp_dir(), 'tts_cut_out_');

        try {
            file_put_contents($in, $bytes);

            // No -ar/-ac: keep the source rate + channels (this is a raw chunk;
            // concatenate() resamples to the output spec later). -t bounds output.
            $process = new Process([
                $this->ffmpegPath, '-y', '-hide_banner', '-loglevel', 'error',
                '-i', $in, '-t', $this->seconds($ms),
                '-c:a', 'pcm_s16le', '-f', 'wav', $out,
            ]);
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $result = file_get_contents($out);

            return ($result === false || $result === '') ? null : $result;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * Trim leading/trailing silence and Chatterbox's trailing noise tail from a
     * chunk, fade its edges, and return mono pcm_s16le WAV at $rate. Falls back
     * to a straight transcode (never empty) when trimming would remove
     * everything — e.g. a fully-silent chunk — so the seam keeps its content and
     * the concat demuxer still sees uniform inputs.
     *
     * The head (leading-silence) trim is unbounded — that's dead air before
     * speech, always safe to drop. The TAIL trim is bounded to the last
     * $tailWindowMs: ffmpeg's silenceremove is far more aggressive than its
     * nominal threshold and will treat a soft trailing word (a quiet "Why?") as
     * part of the trailing silence and remove the whole word + the pause before
     * it. Because Chatterbox's swoosh sits *after* the word, restricting the trim
     * to a short end-window strips the swoosh while the word — which ends before
     * the window — is provably untouched. No single threshold can separate a
     * quiet word from a similar-level swoosh, so bounding the window (not tuning
     * the threshold) is the fix. {@see AudioConverterTest}.
     *
     * Separately, Chatterbox sometimes appends a MULTI-SECOND low-frequency drone
     * after speech that is both too loud for silenceremove and too long for the
     * tail window. {@see detectLongTailArtifact}: when such a tail is found the
     * chunk is hard-cut at the detected speech end (then head-trimmed and faded);
     * otherwise the bounded body/tail graph above runs unchanged.
     */
    private function trimChunk(string $bytes, int $rate, string $threshold, int $fadeMs, int $tailWindowMs): string
    {
        $fade = $this->seconds($fadeMs);
        $window = $this->seconds($tailWindowMs);
        $silenceRemove = "silenceremove=start_periods=1:start_threshold={$threshold}:start_silence=0.03:detection=peak";

        // Canonicalize to PCM first so the detector and the trim graph share one
        // timeline (the detected cut time is measured on exactly these samples).
        $pcm = $this->runFilterToWav($bytes, $rate, null) ?? $bytes;

        $cut = (bool) config('tts.chunk_tail_artifact_enabled', true)
            ? $this->detectLongTailArtifact($pcm, $rate)
            : null;

        if ($cut !== null) {
            // Long tonal tail: hard-cut at the speech end, then the usual head
            // (leading-silence) trim and click-free edge fades. atrim runs first,
            // on the same buffer the cut was measured against, so it is exact.
            $chain = 'atrim=end='.number_format($cut, 3, '.', '').','.$silenceRemove;
            if ($fadeMs > 0) {
                $chain .= ",afade=t=in:d={$fade},areverse,afade=t=in:d={$fade},areverse";
            }
            $trimmed = $this->runFilterToWav($pcm, $rate, $chain);
        } else {
            // Head trim, then split: keep everything before the last $window
            // verbatim [body]; strip trailing silence only within the last
            // $window [tail] (areverse + atrim addresses the end without needing
            // the duration); then rejoin and fade both edges.
            $tail = '[body][tail]concat=n=2:v=0:a=1';
            if ($fadeMs > 0) {
                $tail .= ",afade=t=in:d={$fade},areverse,afade=t=in:d={$fade},areverse";
            }
            $graph = "[0:a]{$silenceRemove},asplit=2[a][b];"
                ."[a]areverse,atrim=start={$window},areverse[body];"
                ."[b]areverse,atrim=end={$window},{$silenceRemove},areverse[tail];"
                .$tail.'[out]';

            $trimmed = $this->runGraphToWav($pcm, $rate, $graph);
        }

        // A header-only / vanishingly short result means trimming ate the whole
        // chunk (e.g. a fully-silent chunk); canonicalize the original instead so
        // it isn't dropped.
        if ($trimmed === null || strlen($trimmed) < 1000) {
            $canon = $this->runFilterToWav($bytes, $rate, null);

            return ($canon === null || $canon === '') ? $bytes : $canon;
        }

        return $trimmed;
    }

    /**
     * Find where speech ends in a mono 16-bit PCM WAV when a long, loud tail
     * artifact follows it, returning the seconds-offset to cut at (last speech +
     * guard), or null when there is no such tail.
     *
     * Each $windowMs window is classed as speech only when it is BOTH loud enough
     * (RMS above the floor — rejects the silent gap / quiet decay before the
     * artifact) AND high-ZCR enough (rejects a tonal ~100 Hz drone, whose
     * zero-crossing rate is far below speech). ZCR is compared in crossings/second
     * so the threshold is independent of $rate.
     *
     * The speech end is the end of the last speech window — but FIRST any isolated
     * trailing artifact run at the very end is peeled off when it sits behind a
     * long (>= min_artifact) QUIET (sub-floor) gap that itself follows earlier
     * audio. Chatterbox follows the quiet decay tail with a re-swell that clears
     * both gates; left in, it resets the speech end to ~EOF, the trailing run
     * collapses to ~0, and the whole tail survives. A run isolated by such a gap
     * is peeled when it is EITHER short (a brief re-swell "blip", <= blip_max_ms)
     * OR tonal (a longer drone/swell whose ZCR coefficient of variation is
     * <= tonal_cv_max — real speech alternates voiced/unvoiced so its ZCR swings
     * widely; a near-constant ZCR is never speech). The gap is measured as truly
     * quiet windows, NOT merely non-speech ones: a quiet final word ("will be")
     * ends in low-ZCR voiced windows that fail the speech gate while still being
     * loud, and counting those as a gap would wrongly peel the word. The
     * leading-silence-before-first-word case is excluded (something must precede
     * the gap), and blip_max_ms = 0 disables the peel entirely. {@see AudioConverterTest}.
     *
     * We only return a cut when the (post-peel) trailing non-speech run is at
     * least min_artifact_ms, so ordinary clips and quiet final words — which have
     * no long tail — return null and keep the bounded trim in {@see trimChunk}.
     */
    private function detectLongTailArtifact(string $pcmWav, int $rate): ?float
    {
        $offset = $this->wavDataOffset($pcmWav);
        if ($offset === null || $rate <= 0) {
            return null;
        }

        $floorDb = (float) config('tts.chunk_tail_rms_floor_db', -40);
        $zcrMinHz = (float) config('tts.chunk_tail_zcr_min_hz', 700);
        $windowSec = max(1, (int) config('tts.chunk_tail_window_ms', 50)) / 1000;
        $minArtifactSec = max(0, (int) config('tts.chunk_tail_min_artifact_ms', 400)) / 1000;
        $guardSec = max(0, (int) config('tts.chunk_tail_guard_ms', 60)) / 1000;
        $blipMaxSec = max(0, (int) config('tts.chunk_tail_blip_max_ms', 400)) / 1000;
        $tonalCvMax = max(0.0, (float) config('tts.chunk_tail_tonal_cv_max', 0.35));

        $win = max(1, (int) round($rate * $windowSec));
        $bytesPerWin = $win * 2; // 16-bit mono
        $dataLen = strlen($pcmWav) - $offset;
        $totalSamples = intdiv($dataLen, 2);
        if ($totalSamples < $win) {
            return null;
        }
        $totalSec = $totalSamples / $rate;

        // Classify every window: speech (loud enough AND high-ZCR enough); quiet
        // (RMS at/below the floor, i.e. genuine silence/decay, NOT loud voiced
        // speech, which is low-ZCR so it fails the speech gate too); and its raw
        // ZCR (kept so a trailing run's ZCR variability can be measured below).
        $speech = [];
        $quiet = [];
        $zcr = [];
        for ($i = 0; $i + $win <= $totalSamples; $i += $win) {
            $samples = unpack('s*', substr($pcmWav, $offset + $i * 2, $bytesPerWin));
            if ($samples === false) {
                break;
            }

            $n = count($samples);
            $sum = 0.0;
            $sumSq = 0.0;
            foreach ($samples as $s) {
                $sum += $s;
                $sumSq += $s * $s;
            }
            $mean = $sum / $n;
            $rms = sqrt($sumSq / $n);
            $db = $rms > 0 ? 20 * log10($rms / 32768) : -INF;

            // Zero crossings of the DC-removed window.
            $crossings = 0;
            $prev = $samples[1] - $mean;
            for ($k = 2; $k <= $n; $k++) {
                $cur = $samples[$k] - $mean;
                if (($cur < 0) !== ($prev < 0)) {
                    $crossings++;
                }
                $prev = $cur;
            }
            $crossingsPerSec = $crossings / $windowSec;

            $speech[] = ($db > $floorDb && $crossingsPerSec > $zcrMinHz);
            $quiet[] = ($db <= $floorDb);
            $zcr[] = $crossingsPerSec;
        }

        // Walk back from EOF, peeling isolated trailing "re-swell" blips so the
        // speech end lands on the real body, not on a blip after the decay tail.
        $blipMaxWin = (int) round($blipMaxSec / $windowSec);
        $gapMinWin = (int) ceil($minArtifactSec / $windowSec);
        $end = count($speech); // windows [0, $end) are still in play
        $lastSpeechWin = null;

        while (true) {
            $li = null; // last speech window strictly before $end
            for ($j = $end - 1; $j >= 0; $j--) {
                if ($speech[$j]) {
                    $li = $j;
                    break;
                }
            }
            if ($li === null) {
                break; // no speech left in range
            }

            // Contiguous speech run ending at $li, then the QUIET (sub-floor) gap
            // immediately before it. The gap must be genuine silence/decay — NOT
            // merely "non-speech" — because a quiet final word (e.g. "will be")
            // ends in low-ZCR voiced windows that fail the speech gate while still
            // being LOUD; counting those as a gap would peel real words. The decay
            // before a re-swell artifact, by contrast, is truly below the floor.
            $rs = $li;
            while ($rs - 1 >= 0 && $speech[$rs - 1]) {
                $rs--;
            }
            $gapLen = 0;
            for ($j = $rs - 1; $j >= 0 && $quiet[$j]; $j--) {
                $gapLen++;
            }

            // A run that a long QUIET gap isolates from EARLIER audio (a leading
            // gap before the first word, with nothing below it, does not count) is
            // an artifact when it is EITHER short (a brief re-swell "blip") OR
            // tonal — a longer run whose ZCR barely varies, i.e. a sustained
            // drone/swell. Real speech alternates voiced/unvoiced, so its ZCR
            // swings widely; a near-constant ZCR is never speech. Either way the
            // run is not resumed speech: drop it and keep peeling toward the body.
            $runLen = $li - $rs + 1;
            $quietGapIsolated = $blipMaxWin > 0
                && $gapLen >= $gapMinWin
                && ($rs - $gapLen - 1) >= 0;
            $isShortBlip = $runLen <= $blipMaxWin;
            $isTonalRun = $tonalCvMax > 0.0
                && $runLen > $blipMaxWin
                && $this->zcrCoeffOfVariation($zcr, $rs, $li) <= $tonalCvMax;

            if ($quietGapIsolated && ($isShortBlip || $isTonalRun)) {
                $end = $rs; // drop the artifact run and keep peeling toward the body

                continue;
            }

            $lastSpeechWin = $li;
            break;
        }

        if ($lastSpeechWin === null) {
            return null; // no speech found at all — leave it to trimChunk's fallback
        }

        $lastSpeechEnd = ($lastSpeechWin + 1) * $win / $rate;

        if (($totalSec - $lastSpeechEnd) < $minArtifactSec) {
            return null; // no long trailing artifact — keep the bounded trim
        }

        return min($totalSec, $lastSpeechEnd + $guardSec);
    }

    /**
     * Coefficient of variation (stddev / mean) of the per-window ZCR over windows
     * [$start, $end]. Low for a sustained tone (steady ZCR), high for speech (its
     * ZCR swings between voiced and unvoiced). Returns INF when it can't be
     * measured (too few windows / zero mean) so the caller never treats it as tonal.
     */
    private function zcrCoeffOfVariation(array $zcr, int $start, int $end): float
    {
        $n = $end - $start + 1;
        if ($n < 2) {
            return INF;
        }

        $sum = 0.0;
        for ($j = $start; $j <= $end; $j++) {
            $sum += $zcr[$j];
        }
        $mean = $sum / $n;
        if ($mean <= 0) {
            return INF;
        }

        $var = 0.0;
        for ($j = $start; $j <= $end; $j++) {
            $d = $zcr[$j] - $mean;
            $var += $d * $d;
        }

        return sqrt($var / $n) / $mean;
    }

    /**
     * Byte offset of the first sample in a WAV's `data` chunk, or null if absent.
     * Walks the chunk list so extra (LIST/INFO) chunks are tolerated.
     */
    private function wavDataOffset(string $wav): ?int
    {
        $pos = 12; // skip 'RIFF' <size> 'WAVE'
        $len = strlen($wav);
        while ($pos + 8 <= $len) {
            $id = substr($wav, $pos, 4);
            $size = unpack('V', substr($wav, $pos + 4, 4))[1];
            if ($id === 'data') {
                return $pos + 8;
            }
            $pos += 8 + $size + ($size & 1); // chunks are word-aligned
        }

        return null;
    }

    /**
     * Run an ffmpeg audio filter (or none) over input bytes, returning mono
     * pcm_s16le WAV at $rate, or null on failure.
     */
    private function runFilterToWav(string $bytes, int $rate, ?string $filter): ?string
    {
        $in = tempnam(sys_get_temp_dir(), 'tts_trim_in_');
        $out = tempnam(sys_get_temp_dir(), 'tts_trim_out_');

        try {
            file_put_contents($in, $bytes);

            $args = [$this->ffmpegPath, '-y', '-hide_banner', '-loglevel', 'error', '-i', $in];
            if ($filter !== null && $filter !== '') {
                $args[] = '-af';
                $args[] = $filter;
            }
            $args = array_merge($args, ['-ac', '1', '-ar', (string) $rate, '-c:a', 'pcm_s16le', '-f', 'wav', $out]);

            $process = new Process($args);
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $result = file_get_contents($out);

            return $result === false ? null : $result;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * Run a complex ffmpeg filtergraph (one that splits/joins, so it needs named
     * pads and -filter_complex rather than -af) over input bytes, returning mono
     * pcm_s16le WAV at $rate, or null on failure. The graph must read [0:a] and
     * expose its result on [out].
     */
    private function runGraphToWav(string $bytes, int $rate, string $graph): ?string
    {
        $in = tempnam(sys_get_temp_dir(), 'tts_trim_in_');
        $out = tempnam(sys_get_temp_dir(), 'tts_trim_out_');

        try {
            file_put_contents($in, $bytes);

            $process = new Process([
                $this->ffmpegPath, '-y', '-hide_banner', '-loglevel', 'error', '-i', $in,
                '-filter_complex', $graph, '-map', '[out]',
                '-ac', '1', '-ar', (string) $rate, '-c:a', 'pcm_s16le', '-f', 'wav', $out,
            ]);
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $result = file_get_contents($out);

            return $result === false ? null : $result;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * Generate $ms of true digital silence as mono pcm_s16le WAV at $rate.
     */
    private function silenceWav(int $ms, int $rate): string
    {
        $out = tempnam(sys_get_temp_dir(), 'tts_silsrc_');

        try {
            $process = new Process([
                $this->ffmpegPath, '-y', '-hide_banner', '-loglevel', 'error',
                '-f', 'lavfi', '-t', $this->seconds($ms), '-i', "anullsrc=r={$rate}:cl=mono",
                '-c:a', 'pcm_s16le', '-f', 'wav', $out,
            ]);
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('ffmpeg silence generation failed: '.trim($process->getErrorOutput()));
            }

            $bytes = file_get_contents($out);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('ffmpeg produced no silence.');
            }

            return $bytes;
        } finally {
            @unlink($out);
        }
    }

    /**
     * Format milliseconds as a trimmed seconds string for ffmpeg (e.g. 120 -> "0.12").
     */
    private function seconds(int $ms): string
    {
        return rtrim(rtrim(number_format(max(0, $ms) / 1000, 3, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * Normalize a reference voice clip for zero-shot cloning: downmix to mono,
     * trim leading/trailing silence, loudness-normalize, and cap the true peak
     * so the result can never clip. Returns 16-bit PCM WAV bytes.
     */
    public function normalizeReference(string $inputBytes): string
    {
        $loudness = (string) config('tts.reference_loudness', '-20');
        $truePeak = (string) config('tts.reference_true_peak', '-1.5');
        $rate = (int) config('tts.reference_sample_rate', 44100);

        $filter = implode(',', [
            'silenceremove=start_periods=1:start_silence=0.1:start_threshold=-50dB',
            'areverse',
            'silenceremove=start_periods=1:start_silence=0.1:start_threshold=-50dB',
            'areverse',
            "loudnorm=I={$loudness}:TP={$truePeak}:LRA=11",
        ]);

        $in = tempnam(sys_get_temp_dir(), 'tts_ref_in_');
        $out = tempnam(sys_get_temp_dir(), 'tts_ref_out_');

        try {
            file_put_contents($in, $inputBytes);

            $process = new Process([
                $this->ffmpegPath, '-y', '-hide_banner', '-loglevel', 'error',
                '-i', $in,
                '-af', $filter,
                '-ac', '1', '-ar', (string) $rate,
                '-c:a', 'pcm_s16le', '-f', 'wav',
                $out,
            ]);
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('ffmpeg reference normalization failed: '.trim($process->getErrorOutput()));
            }

            $bytes = file_get_contents($out);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('ffmpeg produced no normalized reference.');
            }

            return $bytes;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * Parse ElevenLabs output_format tokens (e.g. mp3_44100_128, pcm_16000,
     * ulaw_8000, wav). Falls back to a fixed MP3 profile for unknown values.
     *
     * @return array{ext:string, mime:string, rate:int, codec_args:array<int,string>}
     */
    private function parseFormat(string $format): array
    {
        $format = strtolower(trim($format)) ?: 'mp3_44100_128';
        $parts = explode('_', $format);
        $codec = $parts[0];
        $rate = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 44100;

        return match ($codec) {
            'wav' => [
                'ext' => 'wav', 'mime' => 'audio/wav', 'rate' => $rate,
                'codec_args' => ['-c:a', 'pcm_s16le', '-f', 'wav'],
            ],
            'pcm' => [
                'ext' => 'wav', 'mime' => 'audio/wav', 'rate' => $rate,
                'codec_args' => ['-c:a', 'pcm_s16le', '-f', 'wav'],
            ],
            'ulaw' => [
                'ext' => 'ulaw', 'mime' => 'audio/basic', 'rate' => $rate ?: 8000,
                'codec_args' => ['-c:a', 'pcm_mulaw', '-f', 'mulaw'],
            ],
            default => [ // mp3 and anything unrecognized
                'ext' => 'mp3', 'mime' => 'audio/mpeg', 'rate' => $rate,
                'codec_args' => ['-c:a', 'libmp3lame', '-b:a', (isset($parts[2]) && is_numeric($parts[2]) ? (int) $parts[2] : 128).'k', '-f', 'mp3'],
            ],
        };
    }
}
