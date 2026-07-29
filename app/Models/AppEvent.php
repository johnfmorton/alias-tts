<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product-analytics event, shown aggregated on /admin/insights. Written
 * only through record(), which is deliberately fire-and-forget: analytics
 * must never break a render or an API response. Volume/spend stays in
 * credit_transactions (never duplicated here); account audit stays in
 * user_events.
 */
#[Fillable(['user_id', 'name', 'source', 'meta', 'created_at'])]
class AppEvent extends Model
{
    public const PROJECT_CREATED = 'project.created';

    public const PROJECT_REVISED = 'project.revised';

    public const PROJECT_DUPLICATED = 'project.duplicated';

    public const PROJECT_REBUILT = 'project.rebuilt';

    public const PROJECT_SEALED = 'project.sealed';

    public const PROJECT_RUN_STARTED = 'project.run_started';

    public const PROJECT_ARCHIVED = 'project.archived';

    public const CHUNK_GENERATED = 'chunk.generated';

    public const TAKE_SELECTED = 'take.selected';

    public const TAKE_DELETED = 'take.deleted';

    public const RECEIPT_DOWNLOADED = 'receipt.downloaded';

    public const AUDIO_DOWNLOADED = 'audio.downloaded';

    public const VOICE_CREATED = 'voice.created';

    public const VOICE_CLIP_ADDED = 'voice.clip_added';

    public const API_SPEECH = 'api.speech';

    public const PAGE_VIEW = 'page.view';

    public const SOURCE_STUDIO = 'studio';

    public const SOURCE_API = 'api';

    public const SOURCE_INTERNAL = 'internal';

    public const SOURCE_WEB = 'web';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Fire-and-forget recorder. Any failure (missing table mid-migrate, full
     * disk, oversized meta) is swallowed — a lost analytics row must never
     * cost a user their render or an API caller their audio. Plain insert on
     * the request path: no queue, no retry.
     */
    public static function record(string $name, ?int $userId = null, string $source = self::SOURCE_STUDIO, array $meta = []): void
    {
        if (! config('tts.analytics.events_enabled')) {
            return;
        }

        try {
            self::create([
                'user_id' => $userId,
                'name' => $name,
                'source' => $source,
                'meta' => $meta ?: null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Intentionally swallowed — see docblock.
        }
    }
}
