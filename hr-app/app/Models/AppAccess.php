<?php

namespace App\Models;

use App\Enums\AccessStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppAccess extends Model
{
    use HasFactory;

    protected $table = 'app_accesses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => AccessStatus::class,
            'scopes' => 'array',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** Access that currently lets someone in. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AccessStatus::Active->value,
            AccessStatus::RevokePending->value,
        ]);
    }

    public function scopeForApp(Builder $query, string $appKey): Builder
    {
        return $query->whereHas('app', fn (Builder $q) => $q->where('key', $appKey));
    }

    /** A paid seat still held: what the license guard looks for. */
    public function isBillableSeat(): bool
    {
        return $this->status->isLive()
            && $this->license_tier !== null
            && $this->app?->costs_money === true;
    }
}
