<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // DDEV exports TTS_GENBLAZE_RUNNER_URL as a REAL container env var (it
        // wires the runner service), and real env beats phpunit.xml's <env> —
        // Laravel reads $_SERVER first, which PHPUnit cannot rewrite. Left in
        // place, the doctor's genblaze check would make live 5s-timeout HTTP
        // calls from tests. Neutralize it here; a test that wants a runner sets
        // this config itself and fakes the HTTP.
        config(['tts.genblaze.runner_url' => '']);
    }
}
