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
     * @param  array<int, string>  $inputChunks
     * @return array{0: string, 1: string, 2: string} [bytes, mimeType, extension]
     */
    public function concatenate(array $inputChunks, string $outputFormat, string $inputContainer = 'wav'): array
    {
        $inputChunks = array_values($inputChunks);

        if (count($inputChunks) === 1) {
            return $this->convert($inputChunks[0], $outputFormat, $inputContainer);
        }

        $spec = $this->parseFormat($outputFormat);
        $crossfadeMs = (int) config('tts.chunk_crossfade_ms', 25);

        $files = [];
        foreach ($inputChunks as $bytes) {
            $file = tempnam(sys_get_temp_dir(), 'tts_cat_');
            file_put_contents($file, $bytes);
            $files[] = $file;
        }

        $list = null;
        $outFile = tempnam(sys_get_temp_dir(), 'tts_catout_');

        try {
            if ($crossfadeMs > 0) {
                // Crossfade successive chunks so seams have no clicks/gaps.
                $d = rtrim(rtrim(number_format($crossfadeMs / 1000, 3, '.', ''), '0'), '.');
                $last = count($files) - 1;
                $filterParts = [];
                $prev = '[0:a]';
                for ($i = 1; $i <= $last; $i++) {
                    $label = $i === $last ? '[out]' : "[a{$i}]";
                    $filterParts[] = "{$prev}[{$i}:a]acrossfade=d={$d}:c1=tri:c2=tri{$label}";
                    $prev = $label;
                }

                $args = [$this->ffmpegPath, '-y', '-hide_banner', '-loglevel', 'error'];
                foreach ($files as $file) {
                    $args[] = '-i';
                    $args[] = $file;
                }
                $args = array_merge($args, [
                    '-filter_complex', implode(';', $filterParts),
                    '-map', '[out]',
                    '-ac', '1', '-ar', (string) $spec['rate'],
                ], $spec['codec_args'], [$outFile]);
            } else {
                // Hard join via the concat demuxer.
                $list = tempnam(sys_get_temp_dir(), 'tts_list_');
                file_put_contents($list, implode("\n", array_map(
                    static fn ($file) => "file '".$file."'",
                    $files
                ))."\n");

                $args = array_merge(
                    [$this->ffmpegPath, '-y', '-hide_banner', '-loglevel', 'error',
                        '-f', 'concat', '-safe', '0', '-i', $list,
                        '-ac', '1', '-ar', (string) $spec['rate']],
                    $spec['codec_args'],
                    [$outFile]
                );
            }

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
            if ($list !== null) {
                @unlink($list);
            }
            @unlink($outFile);
        }
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
