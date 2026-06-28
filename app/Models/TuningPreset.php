<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A named Chatterbox knob combo (exaggeration/cfg_weight) reusable in the Studio
 * tuning bench (e.g. "Calm narration"). A null knob means "leave that one to
 * inherit" when applied.
 */
class TuningPreset extends Model
{
    protected $fillable = ['name', 'exaggeration', 'cfg_weight'];

    protected $casts = [
        'exaggeration' => 'float',
        'cfg_weight' => 'float',
    ];
}
