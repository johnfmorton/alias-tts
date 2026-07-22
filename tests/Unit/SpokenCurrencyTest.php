<?php

namespace Tests\Unit;

use App\Services\SpokenCurrency;
use PHPUnit\Framework\TestCase;

class SpokenCurrencyTest extends TestCase
{
    private function apply(string $text): string
    {
        return (new SpokenCurrency)->apply($text);
    }

    public function test_cents_only_amount(): void
    {
        $this->assertSame('It costs eighteen cents per run.', $this->apply('It costs $0.18 per run.'));
    }

    public function test_bare_decimal_amount(): void
    {
        $this->assertSame('eighteen cents', $this->apply('$.18'));
    }

    public function test_singular_dollar_and_cent(): void
    {
        $this->assertSame('one dollar', $this->apply('$1'));
        $this->assertSame('one cent', $this->apply('$0.01'));
        $this->assertSame('one dollar and one cent', $this->apply('$1.01'));
    }

    public function test_dollars_and_cents(): void
    {
        $this->assertSame('five dollars and fifty cents', $this->apply('$5.50'));
    }

    public function test_zero_cents_are_dropped(): void
    {
        $this->assertSame('five dollars', $this->apply('$5.00'));
    }

    public function test_single_decimal_digit_reads_as_tens_of_cents(): void
    {
        $this->assertSame('five dollars and fifty cents', $this->apply('$5.5'));
    }

    public function test_zero_dollars(): void
    {
        $this->assertSame('zero dollars', $this->apply('$0'));
        $this->assertSame('zero dollars', $this->apply('$0.00'));
    }

    public function test_thousands_separators(): void
    {
        $this->assertSame(
            'one thousand two hundred ninety nine dollars and ninety nine cents',
            $this->apply('$1,299.99'),
        );
    }

    public function test_word_scale_suffixes(): void
    {
        $this->assertSame('three point five million dollars', $this->apply('$3.5 million'));
        $this->assertSame('two billion dollars', $this->apply('$2 billion'));
        $this->assertSame('three point zero five million dollars', $this->apply('$3.05 million'));
        $this->assertSame('three point five million dollars', $this->apply('$3.50 million'));
    }

    public function test_letter_scale_suffixes(): void
    {
        $this->assertSame('ten thousand dollars', $this->apply('$10k'));
        $this->assertSame('five million dollars', $this->apply('$5M'));
        $this->assertSame('two billion dollars', $this->apply('$2bn'));
    }

    public function test_scale_suffix_requires_attachment_or_scale_word(): void
    {
        $this->assertSame('five dollars more', $this->apply('$5 more'));
    }

    public function test_euro_and_pound(): void
    {
        $this->assertSame('five euros and fifty cents', $this->apply('€5.50'));
        $this->assertSame('five pounds and fifty pence', $this->apply('£5.50'));
        $this->assertSame('one pound and one penny', $this->apply('£1.01'));
    }

    public function test_cent_sign(): void
    {
        $this->assertSame('eighteen cents', $this->apply('18¢'));
        $this->assertSame('one cent', $this->apply('1¢'));
    }

    public function test_non_monetary_dollar_signs_untouched(): void
    {
        $this->assertSame('run echo $PATH now', $this->apply('run echo $PATH now'));
    }

    public function test_ambiguous_long_decimals_untouched(): void
    {
        $this->assertSame('build $1.2345 is out', $this->apply('build $1.2345 is out'));
    }

    public function test_idempotent(): void
    {
        $once = $this->apply('Prices: $0.18, $1,299.99 and $3.5 million.');

        $this->assertSame($once, $this->apply($once));
    }
}
