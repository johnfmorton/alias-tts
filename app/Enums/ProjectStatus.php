<?php

namespace App\Enums;

enum ProjectStatus: string
{
    /** No final audio built yet. */
    case Draft = 'draft';
    /** Final audio built and current with every chunk. */
    case Ready = 'ready';
    /** Final audio exists but a chunk has changed since it was built. */
    case Stale = 'stale';
}
