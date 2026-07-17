<?php

namespace App\Models;

use App\Services\Credit\CreditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One append-only credit ledger row: a grant, a charge, or a manual
 * adjustment. Written only via {@see CreditService} —
 * never mutated or deleted afterwards, so the ledger always reconciles with
 * the cached users.credit_balance_micro it feeds. Amounts are integer
 * micro-dollars (1,000,000 = $1): `amount_micro` is the user-facing
 * (marked-up) figure, signed; `actual_cost_micro` is the owner's provider
 * cost for charges, so the SuperAdmin sees both sides of the markup.
 */
class CreditTransaction extends Model
{
    public const TYPE_GRANT = 'grant';

    public const TYPE_CHARGE = 'charge';

    public const TYPE_ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'user_id',
        'type',
        'source',
        'amount_micro',
        'actual_cost_micro',
        'characters',
        'model',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
    ];

    protected $casts = [
        'amount_micro' => 'integer',
        'actual_cost_micro' => 'integer',
        'characters' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The SuperAdmin who granted/adjusted (null for system-written charges). */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
