<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A named knob combo reusable in the voice tuning bench and as a project/chunk
 * "delivery" pick (e.g. "Calm narration"). A null knob means "leave that one to
 * inherit" when applied. Each preset belongs to the engine it was authored for
 * (`model`, null = classic chatterbox — exaggeration/cfg_weight; turbo presets
 * carry top_p/top_k/repetition_penalty instead); pickers filter by the active
 * engine. Presets are per-user: each user sees and manages only their own.
 */
class TuningPreset extends Model
{
    protected $fillable = ['user_id', 'name', 'exaggeration', 'cfg_weight', 'temperature', 'top_p', 'top_k', 'repetition_penalty', 'model'];

    protected $casts = [
        'exaggeration' => 'float',
        'cfg_weight' => 'float',
        'temperature' => 'float',
        'top_p' => 'float',
        'top_k' => 'integer',
        'repetition_penalty' => 'float',
    ];

    /** The engine this preset tunes (null rows predate the catalog). */
    public function engineModel(): string
    {
        return $this->model ?? 'chatterbox';
    }

    /** @param  Builder<self>  $query */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
