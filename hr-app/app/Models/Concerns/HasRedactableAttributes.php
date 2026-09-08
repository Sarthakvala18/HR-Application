<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Filters sensitive attributes at the model layer rather than in the view, so a
 * field cannot leak through an API response, an export, or a forgotten Blade
 * partial. Implementers declare which attributes hang off which policy ability.
 */
trait HasRedactableAttributes
{
    /**
     * @return array<string, string> attribute name => policy ability
     */
    abstract public function redactableAttributes(): array;

    /**
     * The attributes this user is not allowed to see.
     *
     * @return array<int, string>
     */
    public function redactedAttributesFor(?User $user): array
    {
        $redacted = [];

        foreach ($this->redactableAttributes() as $attribute => $ability) {
            if (! $this->userCan($user, $ability)) {
                $redacted[] = $attribute;
            }
        }

        return $redacted;
    }

    /**
     * Model data as this user is permitted to see it. Restricted values are
     * removed, and the caller is told what was withheld so the UI can say
     * "hidden" rather than silently showing nothing.
     */
    public function toVisibleArray(?User $user): array
    {
        $data = $this->attributesToArray();
        $redacted = $this->redactedAttributesFor($user);

        foreach ($redacted as $attribute) {
            unset($data[$attribute]);
        }

        $data['_redacted'] = $redacted;

        return $data;
    }

    public function canBeSeenBy(?User $user, string $attribute): bool
    {
        $ability = $this->redactableAttributes()[$attribute] ?? null;

        return $ability === null || $this->userCan($user, $ability);
    }

    private function userCan(?User $user, string $ability): bool
    {
        if ($user === null) {
            return false;
        }

        return Gate::forUser($user)->allows($ability, $this);
    }
}
