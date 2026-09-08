<?php

namespace Tests\Unit;

use App\Services\Import\NameMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameMatcherTest extends TestCase
{
    #[DataProvider('likelyTestRowProvider')]
    public function test_test_rows_are_recognised(string $name, bool $expected): void
    {
        $this->assertSame($expected, NameMatcher::isLikelyTestRow($name));
    }

    public static function likelyTestRowProvider(): array
    {
        return [
            ['Priya Test', true],
            ['Priya test', true],
            ['Vaibhav  Test', true],
            ['Shubham Test', true],
            ['Test Shubham', true],
            ['the previous owner Test', true],
            ['', true],
            // Must not fire on real names that merely contain the letters.
            ['Testa Moretti', false],
            ['Protester Jones', false],
            ['Nadia Rahman', false],
            ['Rahul Kumar Jha', false],
        ];
    }

    #[DataProvider('entityProvider')]
    public function test_company_payees_are_recognised(string $name, bool $expected): void
    {
        $this->assertSame($expected, NameMatcher::isLikelyEntity($name));
    }

    public static function entityProvider(): array
    {
        return [
            ['Make It Rain Ltd.', true],
            ['Task Virtual', true],
            ['Petar Mitrovic PR Business Support', true],
            // A two-line name field holds two legal names.
            ["Jorge Juan Moral Vizcarra for Peru\nJorge Moral for USA", true],
            ['Somava Majumdar', false],
            ['Aileen Manalili Musni', false],
        ];
    }

    public function test_normalisation_strips_case_and_punctuation(): void
    {
        $this->assertSame('patricia paula sibal', NameMatcher::normalize('PATRICIA PAULA  SIBAL'));
        $this->assertSame('joanavie e reintegrado', NameMatcher::normalize('Joanavie E. Reintegrado'));
    }

    /**
     * The real cross-file variants: these pairs are the same person written
     * differently in the paperwork and bank exports.
     */
    #[DataProvider('samePersonProvider')]
    public function test_same_person_variants_score_highly(string $a, string $b): void
    {
        $score = NameMatcher::similarity($a, $b);

        $this->assertGreaterThanOrEqual(
            70,
            $score,
            "Expected [$a] and [$b] to look like the same person, scored $score",
        );
    }

    public static function samePersonProvider(): array
    {
        return [
            ['Lauren Gouin', 'Lauren Marie Gouin'],
            ['Patricia Sibal', 'Patricia Paula Sibal'],
            ['Patricia Paula Sibal', 'PATRICIA PAULA SIOPONGCO SIBAL'],
            ['Dipti Padalkar', 'Dipti Sambhaji Padalkar'],
            ['Aileen Musni', 'Aileen Manalili Musni'],
            ['Rahul Jha', 'Rahul Kumar Jha'],
            ['Joanavie Reintegrado', 'Joanavie E. Reintegrado'],
            ['Donna Arcilla', 'ARCILLA DONNA BELLE'],
            ['Priya Chandran', 'Priya Chandran'],
        ];
    }

    /**
     * The dangerous direction: different people must not be merged, because the
     * consequence is paying the wrong person.
     */
    #[DataProvider('differentPeopleProvider')]
    public function test_different_people_do_not_score_as_a_match(string $a, string $b): void
    {
        $score = NameMatcher::similarity($a, $b);

        $this->assertLessThan(
            70,
            $score,
            "Expected [$a] and [$b] to stay separate, scored $score",
        );
    }

    public static function differentPeopleProvider(): array
    {
        return [
            // Shared first name only.
            ['Priya Chandran', 'Priya Sharma'],
            ['Nadia Rahman', 'Nadia Sharma'],
            ['Leena Jain', 'Leena Gupta'],
            // Shared surname only.
            ['Harsh Kumar Tiwari', 'Shiva Tiwari'],
            // Entirely unrelated.
            ['Somava Majumdar', 'Kartikeya Malviya'],
            ['Amrit Singh', 'Yuvraj Singh'],
        ];
    }

    public function test_a_single_shared_token_is_capped_as_weak_evidence(): void
    {
        // A shared first name alone must never be enough to link two records.
        $this->assertLessThanOrEqual(55, NameMatcher::similarity('Leena Jain', 'Leena Gupta'));
    }

    public function test_token_order_does_not_matter(): void
    {
        $this->assertSame(100, NameMatcher::similarity('Donna Belle Arcilla', 'Arcilla Donna Belle'));
    }
}
