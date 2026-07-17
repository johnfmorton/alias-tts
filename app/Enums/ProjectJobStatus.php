<?php

namespace App\Enums;

enum ProjectJobStatus: string
{
    /** Dispatched, waiting for a queue worker to pick it up. */
    case Queued = 'queued';
    /** A worker is generating chunks right now. */
    case Running = 'running';
    /** Ran to the end (individual chunks may still have failed — see chunks_failed). */
    case Completed = 'completed';
    /** Aborted before the end: out of credit, a fatal error, or a worker timeout. */
    case Failed = 'failed';
    /** Stopped on request; already-generated chunks keep their audio. */
    case Cancelled = 'cancelled';

    /** Still occupying the project (a new run can't start until this ends). */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
