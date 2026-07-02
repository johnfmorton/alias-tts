<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A named Chatterbox knob combo (exaggeration/cfg_weight) reusable in the voice
 * tuning bench and as a project/chunk "delivery" pick (e.g. "Calm narration").
 * A null knob means "leave that one to inherit" when applied. Presets are
 * per-user: each user sees and manages only their own.
 */
class TuningPreset extends Model
{
    protected $fillable = ['user_id', 'name', 'exaggeration', 'cfg_weight'];

    protected $casts = [
        'exaggeration' => 'float',
        'cfg_weight' => 'float',
    ];

    /** @param  Builder<self>  $query */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
