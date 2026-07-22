<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Reap a batch of stored files off the request cycle. Object-storage deletes
 * are one round-trip each, so a housekeeping action that removes hundreds of
 * clips (e.g. project Clean up) would crawl toward the gateway timeout doing
 * them inline — the rows are deleted in the request and the files land here.
 *
 * Deleting is idempotent (a missing file is a non-error), so a retry after a
 * partial failure is safe. The orphan sweep (`speech:cleanup --orphans`)
 * remains the backstop if a batch is lost with the queue.
 */
class DeleteStoredFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Dispatchers chunk to this size so one job never outlives a worker slot. */
    public const BATCH_SIZE = 200;

    public function __construct(
        public string $disk,
        /** @var list<string> */
        public array $paths,
    ) {}

    public function handle(): void
    {
        Storage::disk($this->disk)->delete($this->paths);
    }
}
