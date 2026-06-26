<?php

namespace App\Services\Genblaze;

use App\Jobs\RunGenblazeJob;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed state for an async "Generate via Genblaze" run. The Studio button
 * creates a run, {@see RunGenblazeJob} advances it, and the panel polls
 * it — so the long generate → QA → re-roll → stitch never holds an HTTP request
 * open (which was hitting the fastcgi/proxy read timeout as an HTTP 502). The
 * state is transient (the durable record is the provenance manifest in B2), so
 * the cache — shared between web + worker via the database/redis store — is
 * enough; no table required.
 */
class GenblazeRunStore
{
    private const TTL_MINUTES = 60;

    public function create(string $id): void
    {
        $this->write($id, ['status' => 'queued']);
    }

    public function markRunning(string $id): void
    {
        $this->merge($id, ['status' => 'running']);
    }

    /** @param  array<string, mixed>  $result */
    public function complete(string $id, array $result): void
    {
        $this->merge($id, ['status' => 'completed', 'result' => $result, 'error' => null]);
    }

    public function fail(string $id, string $error): void
    {
        $this->merge($id, ['status' => 'failed', 'error' => $error]);
    }

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array
    {
        $state = Cache::get($this->key($id));

        return is_array($state) ? $state : null;
    }

    /** @param  array<string, mixed>  $patch */
    private function merge(string $id, array $patch): void
    {
        $current = $this->get($id);
        if ($current === null) {
            return; // expired or unknown — nothing to update
        }
        $this->write($id, array_merge($current, $patch));
    }

    /** @param  array<string, mixed>  $state */
    private function write(string $id, array $state): void
    {
        Cache::put($this->key($id), $state, now()->addMinutes(self::TTL_MINUTES));
    }

    private function key(string $id): string
    {
        return "genblaze:run:{$id}";
    }
}
