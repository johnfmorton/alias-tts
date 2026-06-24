<?php

namespace Tests\Feature;

use App\Rules\AudioOnlyUpload;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The ffprobe-backed upload screen that keeps a smuggled video stream out of the
 * reference-clip upload path (defense against MagicYUV / "PixelSmash",
 * CVE-2026-8461). Exercises the real ffmpeg/ffprobe in the test runner.
 */
class AudioOnlyUploadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tts_audioonly_'.uniqid();
        @mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_it_accepts_an_audio_only_file(): void
    {
        $path = $this->ffmpegMake('audio.wav', [
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1',
            '-ac', '1', '-ar', '44100', '-c:a', 'pcm_s16le',
        ]);

        $this->assertSame([], $this->failures($path));
    }

    public function test_it_rejects_a_file_with_a_video_stream(): void
    {
        $path = $this->ffmpegMake('video.mp4', [
            '-f', 'lavfi', '-i', 'testsrc=duration=1:size=64x64:rate=15',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1',
            '-shortest', '-c:v', 'mpeg4', '-c:a', 'aac',
        ]);

        $failures = $this->failures($path);
        $this->assertNotEmpty($failures, 'A smuggled video stream must be rejected.');
        $this->assertStringContainsString('video stream', $failures[0]);
    }

    public function test_it_accepts_audio_with_embedded_cover_art(): void
    {
        // Cover art is an attached_pic, not a real video stream; ffprobe's
        // `-select_streams V` excludes it, so this common case must pass.
        $cover = $this->ffmpegMake('cover.png', [
            '-f', 'lavfi', '-i', 'color=c=blue:s=32x32:d=1', '-frames:v', '1',
        ], skipOnFail: true);

        $path = $this->ffmpegMake('art.flac', [
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1',
            '-i', $cover,
            '-map', '0:a', '-map', '1:v',
            '-c:a', 'flac', '-c:v', 'mjpeg', '-disposition:v', 'attached_pic',
        ], skipOnFail: true);

        $this->assertSame([], $this->failures($path));
    }

    public function test_it_defers_on_an_unprobeable_file(): void
    {
        // A non-media file can't be probed; the rule defers (the file/mimes rules
        // and the -vn-guarded normalization handle it) rather than rejecting it.
        $path = $this->dir.'/notmedia.txt';
        file_put_contents($path, 'this is plainly not audio');

        $this->assertSame([], $this->failures($path));
    }

    /** @return array<int, string> the rule's failure messages, empty when it passes */
    private function failures(string $path): array
    {
        $file = new UploadedFile($path, basename($path), null, null, true);
        $messages = [];
        (new AudioOnlyUpload)->validate('audio', $file, function ($message) use (&$messages) {
            $messages[] = (string) $message;
        });

        return $messages;
    }

    /** @param array<int, string> $inArgs */
    private function ffmpegMake(string $name, array $inArgs, bool $skipOnFail = false): string
    {
        $out = $this->dir.'/'.$name;
        $process = new Process(array_merge(
            [(string) config('tts.ffmpeg_path', 'ffmpeg'), '-y', '-loglevel', 'error'],
            $inArgs,
            [$out],
        ));
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($out)) {
            $message = "ffmpeg could not build {$name}: ".trim($process->getErrorOutput());
            $skipOnFail ? $this->markTestSkipped($message) : $this->fail($message);
        }

        return $out;
    }
}
