<?php

namespace App\Models;

use App\Services\Settings\SettingsManager;
use Illuminate\Database\Eloquent\Model;

/**
 * One UI-editable override, keyed by a config() path. The `value` is stored
 * JSON-encoded so a single column holds any scalar type (bool/int/float/string)
 * and reads back as the right PHP type. Written/read only via
 * {@see SettingsManager}.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'json',
    ];
}
