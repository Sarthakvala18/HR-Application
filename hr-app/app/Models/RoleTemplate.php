<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The per-role default access set. Editing one of these changes what future
 * hires receive without touching any code.
 */
class RoleTemplate extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function templateApps(): HasMany
    {
        return $this->hasMany(RoleTemplateApp::class);
    }

    public function apps(): BelongsToMany
    {
        return $this->belongsToMany(App::class, 'role_template_apps')
            ->using(RoleTemplateApp::class)
            ->withPivot(['id', 'license_tier', 'scopes', 'required'])
            ->withTimestamps();
    }
}
