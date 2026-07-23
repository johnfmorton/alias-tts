<?php

namespace App\Console\Concerns;

/**
 * A maintenance command that rewrites or deletes stored objects in place is
 * safe on a developer's local disk and dangerous on a bucket: a dev machine
 * holding production's AWS_* credentials reaches production's objects at
 * exactly the same paths, while its own database says something else
 * entirely. Nothing in the command itself can tell the two apart.
 *
 * So: a non-local `tts.storage_disk` has to be confirmed with --force. The
 * commands using this need a `--force` option in their signature.
 */
trait GuardsSharedStorage
{
    /**
     * True when the run should stop. Prints the reason and what to do.
     *
     * @param  string  $action  What the command would do, e.g. "rewrite reference clips"
     */
    protected function sharedStorageBlocked(string $action): bool
    {
        $disk = (string) config('tts.storage_disk', 'local');

        if ((string) config("filesystems.disks.{$disk}.driver", 'local') === 'local' || $this->option('force')) {
            return false;
        }

        $bucket = (string) config("filesystems.disks.{$disk}.bucket", '');
        $root = trim((string) config("filesystems.disks.{$disk}.root", ''), '/');

        $this->error(sprintf(
            'Refusing to %s on the "%s" disk%s%s.',
            $action,
            $disk,
            $bucket === '' ? '' : " (bucket {$bucket})",
            $root === '' ? ' at the bucket root — the same paths every install without TTS_STORAGE_ROOT uses' : ", under {$root}/",
        ));
        $this->line('This command writes objects in place. Run it on the machine that owns that storage, or pass --force if this IS that machine.');

        return true;
    }
}
