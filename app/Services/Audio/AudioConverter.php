<?php

namespace App\Services\Audio;

use RuntimeException;
use Symfony\Component\Process\Process;

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
     * the threshold) is the fix. {@see \Tests\Feature\AudioConverterTest}.
     */
    private function trimChunk(string $bytes, int $rate, string $threshold, int $fadeMs, int $tailWindowMs): string
    {
        $fade = $this->seconds($fadeMs);
        $window = $this->seconds($tailWindowMs);
        $silenceRemove = "silenceremove=start_periods=1:start_threshold={$threshold}:start_silence=0.03:detection=peak";

        // Head trim, then split: keep everything before the last $window verbatim
        // [body]; strip trailing silence only within the last $window [tail]
        // (areverse + atrim addresses the end without needing the duration); then
        // rejoin and fade both edges.
        $tail = "[body][tail]concat=n=2:v=0:a=1";
        if ($fadeMs > 0) {
            $tail .= ",afade=t=in:d={$fade},areverse,afade=t=in:d={$fade},areverse";
        }
        $graph = "[0:a]{$silenceRemove},asplit=2[a][b];"
            ."[a]areverse,atrim=start={$window},areverse[body];"
            ."[b]areverse,atrim=end={$window},{$silenceRemove},areverse[tail];"
            .$tail.'[out]';

        $trimmed = $this->runGraphToWav($bytes, $rate, $graph);

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
