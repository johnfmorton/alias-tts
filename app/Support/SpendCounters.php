<?php

namespace App\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Per-model lifetime spend counters (tts_spend_counters) behind plain,
 * portable query-builder calls. Same rules as the `spent_characters` totals
 * they split: increment-only — deleting takes/chunks never lowers them, and a
 * duplicated project starts from zero (copies never inherit spend).
 */
final class SpendCounters
{
    private const TABLE = 'tts_spend_counters';

    /** Add characters to one owner's counter for one engine (atomic). */
    public static function add(string $ownerType, string $ownerId, string $model, int $characters): void
    {
        if ($characters <= 0) {
            return;
        }

        $keys = ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'model' => $model];

        if (DB::table(self::TABLE)->where($keys)->increment('characters', $characters) > 0) {
            return;
        }

        try {
            DB::table(self::TABLE)->insert($keys + [
                'characters' => $characters,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker inserted the row between our miss and insert.
            DB::table(self::TABLE)->where($keys)->increment('characters', $characters);
        }
    }

    /**
     * One owner's per-model characters. Falls back to attributing a legacy
     * total to classic chatterbox when no counter rows exist (safety for rows
     * created before the backfill migration ran).
     *
     * @return array<string, int> model key => lifetime characters
     */
    public static function forOwner(string $ownerType, string $ownerId, int $fallbackTotal = 0): array
    {
        $map = DB::table(self::TABLE)
            ->where(['owner_type' => $ownerType, 'owner_id' => $ownerId])
            ->pluck('characters', 'model')
            ->map(fn ($characters) => (int) $characters)
            ->all();

        if ($map === [] && $fallbackTotal > 0) {
            return ['chatterbox' => $fallbackTotal];
        }

        return $map;
    }

    /**
     * Per-model characters for many owners of one type in one query (the
     * project page loads every chunk's counters at once).
     *
     * @param  list<string>  $ownerIds
     * @return array<string, array<string, int>> owner id => (model => characters)
     */
    public static function forOwners(string $ownerType, array $ownerIds): array
    {
        return DB::table(self::TABLE)
            ->where('owner_type', $ownerType)
            ->whereIn('owner_id', $ownerIds)
            ->get()
            ->groupBy('owner_id')
            ->map(fn ($rows) => $rows->pluck('characters', 'model')->map(fn ($c) => (int) $c)->all())
            ->all();
    }
}
