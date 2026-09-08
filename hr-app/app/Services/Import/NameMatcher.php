<?php

namespace App\Services\Import;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Links a form submission to a person.
 *
 * The bank form does not collect an email address, so historical rows can only
 * be joined on the name, and names differ between the two exports (full versus
 * short forms, all-caps versus title case, middle and maiden names present in
 * one file but not the other). Everything here therefore produces a *proposal*
 * with a confidence score; the importer never auto-applies a fuzzy bank match.
 */
class NameMatcher
{
    /** Obvious test submissions, quarantined rather than imported. */
    private const TEST_PATTERNS = [
        '/\btest\b/i',
        '/^probe\b/i',
        '/\bdummy\b/i',
        '/\bsample\b/i',
        '/\basdf/i',
    ];

    /** Tokens that mark a company rather than a person. */
    private const ENTITY_TOKENS = [
        'ltd', 'ltd.', 'llc', 'inc', 'inc.', 'limited', 'pvt', 'private',
        'corporation', 'corp', 'company', 'co.', 'gmbh', 'bv', 'pr',
        'services', 'solutions', 'virtual', 'enterprises', 'agency',
    ];

    public static function isLikelyTestRow(?string $name, ?string $email = null): bool
    {
        $haystack = trim(($name ?? '').' '.($email ?? ''));

        if ($haystack === '') {
            return true;
        }

        foreach (self::TEST_PATTERNS as $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function isLikelyEntity(?string $name): bool
    {
        if ($name === null || trim($name) === '') {
            return false;
        }

        // A name field spanning multiple lines usually holds two legal names.
        if (preg_match('/[\r\n]/', $name) === 1) {
            return true;
        }

        $tokens = preg_split('/\s+/', strtolower(trim($name))) ?: [];

        foreach ($tokens as $token) {
            if (in_array(trim($token, '.,'), self::ENTITY_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase, strip punctuation and accents, collapse whitespace.
     */
    public static function normalize(string $name): string
    {
        $value = trim($name);
        $value = preg_replace('/[\r\n]+/', ' ', $value) ?? $value;

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($transliterated !== false) {
            $value = $transliterated;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z\s]/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * @return array<int, string>
     */
    public static function tokens(string $name): array
    {
        $tokens = array_filter(explode(' ', self::normalize($name)), fn ($t) => strlen($t) > 1);

        return array_values(array_unique($tokens));
    }

    /**
     * Similarity 0-100 between two names, tolerant of missing middle names and
     * reordered tokens, which is exactly how these two exports differ.
     */
    public static function similarity(string $a, string $b): int
    {
        $tokensA = self::tokens($a);
        $tokensB = self::tokens($b);

        if ($tokensA === [] || $tokensB === []) {
            return 0;
        }

        sort($tokensA);
        sort($tokensB);

        if ($tokensA === $tokensB) {
            return 100;
        }

        $shared = array_intersect($tokensA, $tokensB);
        $smaller = min(count($tokensA), count($tokensB));

        // Containment: "Lauren Gouin" inside "Lauren Marie Gouin".
        $containment = count($shared) / $smaller;

        // Straight string similarity as a secondary signal.
        similar_text(implode(' ', $tokensA), implode(' ', $tokensB), $percent);

        $score = ($containment * 75) + ($percent / 100 * 25);

        // Sharing only one token of a multi-token name is weak evidence: many
        // people share a first name.
        if (count($shared) === 1 && $smaller > 1) {
            $score = min($score, 55);
        }

        return (int) round(min(100, $score));
    }

    /**
     * Best candidate for a name among existing employees.
     *
     * @return array{employee: ?Employee, confidence: int, method: string, alternatives: Collection}
     */
    public static function findBest(string $name, ?string $email = null): array
    {
        if ($email !== null && trim($email) !== '') {
            $byEmail = Employee::query()
                ->where('personal_email', trim($email))
                ->orWhere('work_email', trim($email))
                ->first();

            if ($byEmail !== null) {
                return [
                    'employee' => $byEmail,
                    'confidence' => 100,
                    'method' => 'email',
                    'alternatives' => collect(),
                ];
            }
        }

        $scored = Employee::query()
            ->get(['id', 'full_name', 'personal_email', 'work_email'])
            ->map(fn (Employee $employee) => [
                'employee' => $employee,
                'score' => self::similarity($name, $employee->full_name),
            ])
            ->filter(fn (array $row) => $row['score'] > 0)
            ->sortByDesc('score')
            ->values();

        if ($scored->isEmpty()) {
            return [
                'employee' => null,
                'confidence' => 0,
                'method' => 'none',
                'alternatives' => collect(),
            ];
        }

        $best = $scored->first();

        return [
            'employee' => $best['employee'],
            'confidence' => $best['score'],
            'method' => $best['score'] === 100 ? 'name_exact' : 'name_fuzzy',
            'alternatives' => $scored->slice(1, 3)->values(),
        ];
    }
}
