<?php

namespace App\Services\Import;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Parsers for the free-text columns in the Typeform exports.
 *
 * Every method returns ['value' => mixed, 'raw' => string|null, 'issue' => ?string].
 * A null value with an issue means "a human must look at this" -- the importer
 * never guesses when the source is ambiguous.
 */
class ValueNormalizer
{
    /** Placeholders that mean "nothing", used across both exports. */
    private const NULLISH = ['n/a', 'na', 'n.a', 'n.a.', 'none', 'nil', '-', '--', 'null', ''];

    public static function isNullish(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), self::NULLISH, true);
    }

    /**
     * Salary arrives as "$400 USD", "$500", "$ 350 USD", "$1200" or blank.
     */
    public static function salary(?string $raw): array
    {
        $result = ['value' => null, 'currency' => null, 'raw' => $raw, 'issue' => null];

        if (self::isNullish($raw)) {
            return $result;
        }

        $text = trim($raw);

        if (preg_match('/(\d[\d,]*(?:\.\d+)?)/', $text, $amountMatch) !== 1) {
            $result['issue'] = 'salary_unparseable';

            return $result;
        }

        $result['value'] = (float) str_replace(',', '', $amountMatch[1]);
        $result['currency'] = self::detectCurrency($text);

        if ($result['currency'] === null) {
            // A bare number is almost certainly USD here, but say so rather
            // than silently deciding.
            $result['issue'] = 'salary_currency_assumed';
            $result['currency'] = 'USD';
        }

        return $result;
    }

    /**
     * Joining dates appear as "9th February 2023", "Jan 9", "5-Dec-2022",
     * "23-Dec-2022", "5 Jan" and, in at least one row, a job title.
     */
    public static function date(?string $raw): array
    {
        $result = ['value' => null, 'raw' => $raw, 'issue' => null];

        if (self::isNullish($raw)) {
            return $result;
        }

        $text = trim($raw);

        // A value with no digits at all is not a date (e.g. a pasted job title).
        if (preg_match('/\d/', $text) !== 1) {
            $result['issue'] = 'date_not_a_date';

            return $result;
        }

        // Strip English ordinal suffixes: "9th February" -> "9 February".
        $cleaned = preg_replace('/(\d+)(st|nd|rd|th)\b/i', '$1', $text);
        $cleaned = trim(preg_replace('/\s+/', ' ', (string) $cleaned));

        try {
            $parsed = CarbonImmutable::parse($cleaned);
        } catch (Throwable) {
            $result['issue'] = 'date_unparseable';

            return $result;
        }

        // "Jan 9" with no year parses to the current year, which is wrong for a
        // 2022/2023 export. Flag rather than invent a year.
        if (preg_match('/\b(19|20)\d{2}\b/', $cleaned) !== 1) {
            $result['value'] = $parsed->toDateString();
            $result['issue'] = 'date_year_missing';

            return $result;
        }

        $result['value'] = $parsed->toDateString();

        return $result;
    }

    /**
     * The currency column contains ISO codes, but also "EURO",
     * "Pound Sterling" and "300 USD".
     */
    public static function currency(?string $raw): array
    {
        $result = ['value' => null, 'raw' => $raw, 'issue' => null];

        if (self::isNullish($raw)) {
            return $result;
        }

        $detected = self::detectCurrency($raw);

        if ($detected === null) {
            $result['issue'] = 'currency_unrecognised';

            return $result;
        }

        $result['value'] = $detected;

        // "300 USD" in a currency field means the respondent misread the question.
        if (preg_match('/\d/', $raw) === 1) {
            $result['issue'] = 'currency_contained_amount';
        }

        return $result;
    }

    private static function detectCurrency(string $text): ?string
    {
        $upper = strtoupper($text);

        $named = [
            'EURO' => 'EUR', 'EUROS' => 'EUR', 'EUR' => 'EUR', '€' => 'EUR',
            'POUND STERLING' => 'GBP', 'STERLING' => 'GBP', 'POUND' => 'GBP', 'GBP' => 'GBP',
            'RUPEE' => 'INR', 'RUPEES' => 'INR', 'INR' => 'INR',
            'PESO' => 'PHP', 'PHP' => 'PHP',
            'DOLLAR' => 'USD', 'USD' => 'USD',
            'PKR' => 'PKR', 'CAD' => 'CAD', 'AUD' => 'AUD', 'AED' => 'AED',
        ];

        // Longest keys first so "POUND STERLING" wins over "POUND".
        uksort($named, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($named as $needle => $code) {
            if (str_contains($upper, $needle)) {
                return $code;
            }
        }

        return str_contains($text, '$') ? 'USD' : null;
    }

    /**
     * The IBAN column is polluted: it holds "N/A", a repeated SWIFT code, a
     * repeated account number, and in some rows a routing number. Route each
     * value to the field it actually belongs in.
     *
     * @return array{iban: ?string, swift_code: ?string, routing_number: ?string, issue: ?string}
     */
    public static function ibanColumn(?string $raw, ?string $accountNumber, ?string $swiftColumn): array
    {
        $result = ['iban' => null, 'swift_code' => null, 'routing_number' => null, 'issue' => null];

        if (self::isNullish($raw)) {
            return $result;
        }

        $value = strtoupper(preg_replace('/\s+/', '', trim($raw)));

        if (self::looksLikeIban($value)) {
            $result['iban'] = $value;

            return $result;
        }

        // The same value repeated from another column carries no information.
        if ($accountNumber !== null && $value === strtoupper(preg_replace('/\s+/', '', $accountNumber))) {
            $result['issue'] = 'iban_duplicated_account_number';

            return $result;
        }

        if ($swiftColumn !== null && $value === strtoupper(preg_replace('/\s+/', '', $swiftColumn))) {
            $result['issue'] = 'iban_duplicated_swift';

            return $result;
        }

        if (self::looksLikeSwift($value)) {
            $result['swift_code'] = $value;
            $result['issue'] = 'iban_contained_swift';

            return $result;
        }

        // Nine digits is a US ABA routing number; PH banks use a similar code.
        if (preg_match('/^\d{9}$/', $value) === 1) {
            $result['routing_number'] = $value;
            $result['issue'] = 'iban_contained_routing_number';

            return $result;
        }

        $result['issue'] = 'iban_unrecognised';

        return $result;
    }

    /** Structural check plus the ISO 7064 mod-97 checksum. */
    public static function looksLikeIban(string $value): bool
    {
        $value = strtoupper(preg_replace('/\s+/', '', $value));

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $value) !== 1) {
            return false;
        }

        $rearranged = substr($value, 4).substr($value, 0, 4);

        $numeric = '';
        foreach (str_split($rearranged) as $character) {
            $numeric .= ctype_alpha($character)
                ? (string) (ord($character) - 55)
                : $character;
        }

        return self::mod97($numeric) === 1;
    }

    public static function looksLikeSwift(string $value): bool
    {
        return preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper(trim($value))) === 1;
    }

    /** bcmod without requiring the bcmath extension. */
    private static function mod97(string $numeric): int
    {
        $remainder = 0;

        foreach (str_split($numeric) as $digit) {
            if (! ctype_digit($digit)) {
                return -1;
            }

            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder;
    }

    /**
     * The bank-country column frequently holds a bank name instead
     * ("Kotak", "Tonik Bank", "State Bank of India").
     */
    public static function bankCountry(?string $raw): array
    {
        $result = ['country' => null, 'bank_name' => null, 'raw' => $raw, 'issue' => null];

        if (self::isNullish($raw)) {
            return $result;
        }

        $text = trim($raw);
        $country = self::country($text);

        // country() returns a best-effort value even when it does not recognise
        // the input, so trust the issue flag rather than the value: otherwise a
        // bank name like "Kotak" is accepted as a country.
        if ($country['issue'] === null) {
            $result['country'] = $country['value'];

            return $result;
        }

        $result['bank_name'] = $text;
        $result['issue'] = 'bank_country_contained_bank_name';

        return $result;
    }

    /** Normalises "India", "IN", "INDIA", "Indian" to one label. */
    public static function country(?string $raw): array
    {
        $result = ['value' => null, 'raw' => $raw, 'issue' => null];

        if (self::isNullish($raw)) {
            return $result;
        }

        $key = strtolower(trim($raw));
        $key = rtrim($key, '.');

        $map = [
            'india' => 'India', 'in' => 'India', 'ind' => 'India', 'indian' => 'India',
            'united states' => 'United States', 'usa' => 'United States', 'us' => 'United States',
            'u.s.a' => 'United States', 'america' => 'United States',
            'united kingdom' => 'United Kingdom', 'uk' => 'United Kingdom', 'gb' => 'United Kingdom',
            'great britain' => 'United Kingdom',
            'philippines' => 'Philippines', 'ph' => 'Philippines', 'philippine' => 'Philippines',
            'pakistan' => 'Pakistan', 'pk' => 'Pakistan',
            'canada' => 'Canada', 'ca' => 'Canada',
            'spain' => 'Spain', 'es' => 'Spain',
            'serbia' => 'Serbia', 'macedonia' => 'Macedonia', 'peru' => 'Peru',
            'australia' => 'Australia', 'uae' => 'United Arab Emirates',
        ];

        if (isset($map[$key])) {
            $result['value'] = $map[$key];

            return $result;
        }

        $result['issue'] = 'country_unrecognised';
        $result['value'] = ucwords($key);

        return $result;
    }
}
