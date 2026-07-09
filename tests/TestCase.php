<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The runner URL is non-empty by default (config default http://127.0.0.1:8800,
        // and DDEV exports TTS_GENBLAZE_RUNNER_URL as a REAL container env var that
        // beats phpunit.xml's <env> — Laravel reads $_SERVER first, which PHPUnit
        // cannot rewrite). Left in place, the doctor's genblaze check would make
        // live 5s-timeout HTTP calls from tests. Neutralize it here; a test that
        // wants a runner sets this config itself and fakes the HTTP.
        config(['tts.genblaze.runner_url' => '']);

        // The production default is 'always' (every /v1 generation also mints a
        // Studio project). Pin the pristine 'never' baseline for tests so unrelated
        // generation tests don't incur a project side-effect; ApiProjectRecoveryTest
        // sets this config explicitly per case to exercise each mode.
        config(['tts.api_project_mode' => 'never']);
    }
}
