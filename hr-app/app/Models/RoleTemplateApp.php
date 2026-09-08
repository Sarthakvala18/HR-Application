<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Extends Pivot (not Model) so the casts below also apply when these rows are
 * reached through RoleTemplate::apps()->withPivot(...). Without this, `scopes`
 * comes back as a raw JSON string on the pivot.
 */
class RoleTemplateApp extends Pivot
{
    use HasFactory;

    protected $table = 'role_template_apps';

    /** The table has a real auto-incrementing key, unlike a plain pivot. */
    public $incrementing = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'required' => 'boolean',
        ];
    }

    public function roleTemplate(): BelongsTo
    {
        return $this->belongsTo(RoleTemplate::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }
}
