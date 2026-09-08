<?php

namespace Tests\Unit;

use App\Services\Import\ValueNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cases are drawn from the shapes that actually occur in the two Typeform
 * exports, not from invented examples.
 */
class ValueNormalizerTest extends TestCase
{
    // ---------------------------------------------------------------- salary

    #[DataProvider('salaryProvider')]
    public function test_salary_parsing(?string $raw, ?float $amount, ?string $currency, ?string $issue): void
    {
        $result = ValueNormalizer::salary($raw);

        $this->assertSame($amount, $result['value']);
        $this->assertSame($currency, $result['currency']);
        $this->assertSame($issue, $result['issue']);
    }

    public static function salaryProvider(): array
    {
        return [
            'dollar and code' => ['$400 USD', 400.0, 'USD', null],
            'spaced' => ['$ 350 USD', 350.0, 'USD', null],
            'symbol only' => ['$500', 500.0, 'USD', null],
            'four figures' => ['$1200', 1200.0, 'USD', null],
            'bare number flags assumption' => ['800', 800.0, 'USD', 'salary_currency_assumed'],
            'thousands separator' => ['$1,250 USD', 1250.0, 'USD', null],
            'blank' => ['', null, null, null],
            'nullish' => ['N/A', null, null, null],
            'text only' => ['to be decided', null, null, 'salary_unparseable'],
        ];
    }

    // ------------------------------------------------------------------ date

    #[DataProvider('dateProvider')]
    public function test_date_parsing(?string $raw, ?string $expected, ?string $issue): void
    {
        $result = ValueNormalizer::date($raw);

        $this->assertSame($expected, $result['value']);
        $this->assertSame($issue, $result['issue']);
    }

    public static function dateProvider(): array
    {
        return [
            'ordinal long' => ['9th February 2023', '2023-02-09', null],
            'ordinal no comma' => ['31st January 2023', '2023-01-31', null],
            'dash short' => ['5-Dec-2022', '2022-12-05', null],
            'dash short 2' => ['23-Dec-2022', '2022-12-23', null],
            'long form' => ['22nd November 2022', '2022-11-22', null],
            // A job title pasted into the date field: must never become a date.
            'job title in date field' => ['Sales Operations Manager and Head of Sales', null, 'date_not_a_date'],
            'blank' => ['', null, null],
        ];
    }

    public function test_date_without_a_year_is_flagged_rather_than_guessed(): void
    {
        // "Jan 9" would otherwise silently become the current year.
        $result = ValueNormalizer::date('Jan 9');

        $this->assertSame('date_year_missing', $result['issue']);
    }

    // -------------------------------------------------------------- currency

    #[DataProvider('currencyProvider')]
    public function test_currency_normalisation(?string $raw, ?string $code, ?string $issue): void
    {
        $result = ValueNormalizer::currency($raw);

        $this->assertSame($code, $result['value']);
        $this->assertSame($issue, $result['issue']);
    }

    public static function currencyProvider(): array
    {
        return [
            'iso' => ['USD', 'USD', null],
            'inr' => ['INR', 'INR', null],
            'php' => ['PHP', 'PHP', null],
            'euro spelled out' => ['EURO', 'EUR', null],
            'pound sterling' => ['Pound Sterling', 'GBP', null],
            'amount in currency field' => ['300 USD', 'USD', 'currency_contained_amount'],
            'unrecognised' => ['galleons', null, 'currency_unrecognised'],
        ];
    }

    // ------------------------------------------------------------------ IBAN

    public function test_valid_iban_is_accepted(): void
    {
        // Published example IBAN with a valid mod-97 checksum. Never use a real
        // account here: these fixtures live in version control.
        $result = ValueNormalizer::ibanColumn('GB82WEST12345698765432', '98765432', 'WESTGB2L');

        $this->assertSame('GB82WEST12345698765432', $result['iban']);
        $this->assertNull($result['issue']);
    }

    public function test_iban_column_holding_a_repeated_swift_code_is_rerouted(): void
    {
        $result = ValueNormalizer::ibanColumn('TDOMCATTTOR', '1234567', 'TDOMCATTTOR');

        $this->assertNull($result['iban']);
        $this->assertSame('iban_duplicated_swift', $result['issue']);
    }

    public function test_iban_column_holding_the_account_number_again_is_detected(): void
    {
        $result = ValueNormalizer::ibanColumn('11122233344', '11122233344', null);

        $this->assertNull($result['iban']);
        $this->assertSame('iban_duplicated_account_number', $result['issue']);
    }

    public function test_iban_column_holding_a_routing_number_is_rerouted(): void
    {
        $result = ValueNormalizer::ibanColumn('061000052', '00001234567890123', 'BOFAUS3N');

        $this->assertSame('061000052', $result['routing_number']);
        $this->assertNull($result['iban']);
        $this->assertSame('iban_contained_routing_number', $result['issue']);
    }

    public function test_nullish_iban_values_are_ignored(): void
    {
        foreach (['N/A', 'n/a', 'NA', 'na', '', 'N/a'] as $value) {
            $result = ValueNormalizer::ibanColumn($value, '123', 'ABCDEF12');

            $this->assertNull($result['iban'], "[$value] should be ignored");
            $this->assertNull($result['issue'], "[$value] should not raise an issue");
        }
    }

    public function test_iban_checksum_rejects_a_corrupted_value(): void
    {
        $this->assertTrue(ValueNormalizer::looksLikeIban('GB82WEST12345698765432'));
        // Same shape, wrong check digits.
        $this->assertFalse(ValueNormalizer::looksLikeIban('GB83WEST12345698765432'));
    }

    public function test_swift_shape_detection(): void
    {
        $this->assertTrue(ValueNormalizer::looksLikeSwift('HDFCINBB'));
        $this->assertTrue(ValueNormalizer::looksLikeSwift('SBININBB336'));
        $this->assertTrue(ValueNormalizer::looksLikeSwift('CHASUS33XXX'));
        $this->assertFalse(ValueNormalizer::looksLikeSwift('12345678'));
    }

    // ------------------------------------------------------------ bank country

    public function test_bank_country_column_holding_a_bank_name_is_flagged(): void
    {
        foreach (['Kotak', 'Tonik Bank', 'State Bank of India', 'ICICI BANK LTD'] as $bankName) {
            $result = ValueNormalizer::bankCountry($bankName);

            $this->assertSame($bankName, $result['bank_name']);
            $this->assertNull($result['country']);
            $this->assertSame('bank_country_contained_bank_name', $result['issue']);
        }
    }

    public function test_bank_country_accepts_a_real_country(): void
    {
        $result = ValueNormalizer::bankCountry('Philippines');

        $this->assertSame('Philippines', $result['country']);
        $this->assertNull($result['bank_name']);
        $this->assertNull($result['issue']);
    }

    // ----------------------------------------------------------------- country

    #[DataProvider('countryProvider')]
    public function test_country_normalisation(string $raw, string $expected): void
    {
        $this->assertSame($expected, ValueNormalizer::country($raw)['value']);
    }

    public static function countryProvider(): array
    {
        return [
            ['India', 'India'],
            ['IN', 'India'],
            ['INDIA', 'India'],
            ['Indian', 'India'],
            ['United States', 'United States'],
            ['US', 'United States'],
            ['Philippines', 'Philippines'],
            ['Philippines ', 'Philippines'],
            ['GB', 'United Kingdom'],
            ['United Kingdom', 'United Kingdom'],
        ];
    }
}
