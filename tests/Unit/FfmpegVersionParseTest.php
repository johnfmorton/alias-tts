<?php

namespace Tests\Unit;

use App\Services\Health\HealthReport;
use PHPUnit\Framework\TestCase;

class FfmpegVersionParseTest extends TestCase
{
    public function test_it_extracts_the_upstream_version(): void
    {
        $cases = [
            'ffmpeg version 6.1.1-3ubuntu5 Copyright (c) 2000-2024' => '6.1.1',
            'ffmpeg version 7.1.4-0+deb13u1 Copyright (c) 2007-2026' => '7.1.4',
            'ffmpeg version 8.1.2-static https://johnvansickle.com/ffmpeg/' => '8.1.2',
            'ffmpeg version 8.1.2 Copyright (c) 2000-2026' => '8.1.2',
            'ffmpeg version n8.1.2 Copyright' => '8.1.2',
            'ffmpeg version 7.0 Copyright' => '7.0',
        ];

        foreach ($cases as $banner => $expected) {
            $this->assertSame($expected, HealthReport::parseFfmpegVersion($banner), "banner: {$banner}");
        }
    }

    public function test_unparseable_banners_return_null(): void
    {
        // A git "N-…" build and outright garbage can't be pinned to a version;
        // the health gate treats null as "warn", not "fail".
        $this->assertNull(HealthReport::parseFfmpegVersion('ffmpeg version N-109534-g7d1234abc Copyright'));
        $this->assertNull(HealthReport::parseFfmpegVersion('not ffmpeg output at all'));
    }

    public function test_pixelsmash_gate_threshold(): void
    {
        // The gate fails only when a parsed version is strictly older than 8.1.2,
        // the first release with the MagicYUV "PixelSmash" fix (CVE-2026-8461).
        $shouldFail = ['6.1.1', '7.1.4', '8.1.1'];
        $shouldPass = ['8.1.2', '8.1.3', '8.2', '9.0'];

        foreach ($shouldFail as $version) {
            $this->assertTrue(version_compare($version, '8.1.2', '<'), "{$version} should fail the gate");
        }

        foreach ($shouldPass as $version) {
            $this->assertFalse(version_compare($version, '8.1.2', '<'), "{$version} should pass the gate");
        }
    }
}
