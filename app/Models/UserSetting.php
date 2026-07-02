<?php

namespace App\Models;

use App\Services\Settings\SettingsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's UI-editable override, keyed by a config() path. The `value` is
 * stored JSON-encoded so a single column holds any scalar type
 * (bool/int/float/string) and reads back as the right PHP type. Written/read
 * only via {@see SettingsManager}, which overlays a user's rows onto runtime
 * config for exactly the duration of that user's request or job.
 */
class UserSetting extends Model
{
    protected $fillable = ['user_id', 'key', 'value'];

    protected $casts = [
        'value' => 'json',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
