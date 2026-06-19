<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A named stability/style combo reusable in the Studio tuning bench (e.g. "Calm
 * narration"). A null knob means "leave that one to inherit" when applied.
 */
class TuningPreset extends Model
{
    protected $fillable = ['name', 'stability', 'style'];

    protected $casts = [
        'stability' => 'float',
        'style' => 'float',
    ];
}
