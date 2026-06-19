<?php

namespace App\Enums;

enum ChunkStatus: string
{
    /** Not generated yet. */
    case Pending = 'pending';
    /** Generated; audio_path holds its raw audio. */
    case Completed = 'completed';
    /** Generation failed; see error_message. */
    case Failed = 'failed';
    /** Text edited after generation — audio is out of date. */
    case Stale = 'stale';
}
