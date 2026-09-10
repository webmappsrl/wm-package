<?php

namespace Wm\WmPackage\TrailRegistry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;

/**
 * Un passaggio di stato di un codice. Tabella in sola aggiunta: non si
 * aggiorna e non si cancella, e le righe le scrive solo TrailRegistryService.
 *
 * @property int $id
 * @property int $trail_registry_code_id
 * @property TrailCodeStatus|null $from_status
 * @property TrailCodeStatus $to_status
 * @property string $reason
 * @property int|null $user_id
 * @property Carbon $created_at
 * @property-read TrailRegistryCode $code
 * @property-read User|null $user
 */
class TrailRegistryCodeEvent extends Model
{
    protected $table = 'trail_registry_code_events';

    public $timestamps = false;

    protected $fillable = [
        'trail_registry_code_id',
        'from_status',
        'to_status',
        'reason',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'from_status' => TrailCodeStatus::class,
        'to_status' => TrailCodeStatus::class,
        'created_at' => 'datetime',
    ];

    public function code(): BelongsTo
    {
        return $this->belongsTo(TrailRegistryCode::class, 'trail_registry_code_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
