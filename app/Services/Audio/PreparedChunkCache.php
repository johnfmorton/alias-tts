<?php

namespace App\Services\Audio;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Local-disk cache of prepared chunk audio — the output of
 * {@see AudioConverter::prepareChunk()} (edge-trimmed, artifact-cut, faded
 * mono PCM WAV). Preparing is ~80% of a project build and depends only on the
 * chunk itself, so a rebuild after fixing one chunk reuses every other chunk's
 * prepared audio and only joins + encodes.
 *
 * Correctness rule: a hit must be byte-for-byte what preparing from scratch
 * would produce now. The key therefore covers every input of the prepare:
 * the take's raw-audio hash, the output sample rate, preserveTail, every
 * trim/detector config value, the ffmpeg build, a hash of AudioConverter's
 * own source (so a code change to the trim or its detectors invalidates by
 * itself), and {@see VERSION} for anything those miss — e.g. prepare logic
 * that moves into another file.
 *
 * It is a cache, not storage: local (a hit costs no network read even when
 * audio lives on S3/B2), unreferenced by the database, and safe to delete at
 * any time — a miss simply prepares again.
 */
class PreparedChunkCache
{
    public const VERSION = 1;

    /**
     * Config prefixes (under tts.) that shape prepareChunk()'s output. Guarded
     * by PreparedChunkCacheTest: every tts.chunk_* key AudioConverter reads
     * must fall under one of these.
     */
    public const CONFIG_PREFIXES = ['chunk_trim_', 'chunk_fade_', 'chunk_head_', 'chunk_tail_'];

    public function __construct(private AudioConverter $converter) {}

    public function enabled(): bool
    {
        return (bool) config('tts.prepared_cache.enabled', true);
    }

    /** Cache key for one take's prepared audio under the CURRENT config. */
    public function key(string $sourceSha256, string $outputFormat, bool $preserveTail): string
    {
        return hash('sha256', json_encode([
            'v' => self::VERSION,
            'source' => strtolower($sourceSha256),
            'rate' => $this->converter->sampleRate($outputFormat),
            'preserve_tail' => $preserveTail,
            'config' => $this->configFingerprint(),
            'ffmpeg' => $this->converter->ffmpegVersion(),
            'code' => self::codeFingerprint(),
        ]));
    }

    /**
     * Hash of the AudioConverter source (memoized per process). VERSION is a
     * rule someone has to remember; this makes ANY edit to the trim code or
     * its detectors invalidate the cache on its own, at the cost of one cold
     * build per project after such a deploy.
     */
    private static function codeFingerprint(): string
    {
        static $hash = null;

        return $hash ??= (string) hash_file('sha256', (new \ReflectionClass(AudioConverter::class))->getFileName());
    }

    /**
     * Path of the cached prepared WAV for a key, or null on a miss. A hit is
     * touched so pruning removes only audio no build has used recently.
     */
    public function path(string $key): ?string
    {
        $file = $this->fileFor($key);

        if (! is_file($file) || filesize($file) < 1000) {
            return null;
        }

        @touch($file);

        return $file;
    }

    /** Store prepared WAV bytes; returns the cached file's path, or null if the write failed. */
    public function put(string $key, string $wav): ?string
    {
        $file = $this->fileFor($key);

        try {
            File::ensureDirectoryExists(dirname($file));

            // Write-then-rename so a concurrent build never reads a half-written
            // file (two workers preparing the same take write identical bytes).
            $tmp = $file.'.'.Str::random(8).'.tmp';
            if (file_put_contents($tmp, $wav) !== strlen($wav) || ! rename($tmp, $file)) {
                @unlink($tmp);

                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return $file;
    }

    /**
     * Delete cached files not used for $days days (and stray temp files).
     *
     * @return array{files: int, bytes: int}
     */
    public function prune(int $days, bool $dryRun = false): array
    {
        $root = $this->root();
        $cutoff = time() - max(0, $days) * 86400;
        $files = 0;
        $bytes = 0;

        if (! is_dir($root)) {
            return ['files' => 0, 'bytes' => 0];
        }

        foreach (File::allFiles($root) as $file) {
            if ($file->getMTime() > $cutoff) {
                continue;
            }
            $files++;
            $bytes += $file->getSize();
            if (! $dryRun) {
                @unlink($file->getPathname());
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    public function root(): string
    {
        return rtrim((string) config('tts.prepared_cache.path', storage_path('app/tts-prepared')), '/');
    }

    private function fileFor(string $key): string
    {
        return $this->root().'/'.substr($key, 0, 2).'/'.$key.'.wav';
    }

    /**
     * Every config value that shapes a prepared chunk, in a stable order.
     *
     * @return array<string, mixed>
     */
    private function configFingerprint(): array
    {
        $values = array_filter(
            (array) config('tts'),
            fn (string $key): bool => Str::startsWith($key, self::CONFIG_PREFIXES),
            ARRAY_FILTER_USE_KEY,
        );
        ksort($values);

        return $values;
    }
}
