<?php

namespace Tests\Unit;

use App\Support\NameNormalizer;
use PHPUnit\Framework\TestCase;

class NameNormalizerTest extends TestCase
{
    public function test_whitespace_is_always_trimmed_and_collapsed(): void
    {
        $this->assertSame('Ada Lovelace', NameNormalizer::person("  Ada \u{00A0}  Lovelace\t"));
        $this->assertSame('Northwind Digital', NameNormalizer::organization("  Northwind   Digital "));
        $this->assertSame('', NameNormalizer::person('   '));
    }

    public function test_entirely_upper_or_lower_case_names_are_title_cased(): void
    {
        $this->assertSame('John Doe', NameNormalizer::person('JOHN DOE'));
        $this->assertSame('John Doe', NameNormalizer::person('john doe'));
        $this->assertSame('Élodie Durand', NameNormalizer::person('ÉLODIE DURAND'));
        $this->assertSame('Anne-Marie O\'Neil', NameNormalizer::person('anne-marie o\'neil'));
        $this->assertSame('Ahmed bin Ali', NameNormalizer::person('AHMED BIN ALI'));
        $this->assertSame('Ludwig van Beethoven', NameNormalizer::person('ludwig van beethoven'));
        $this->assertSame('Flora McDonald', NameNormalizer::person('flora mcdonald'));
    }

    public function test_broken_mid_word_capitals_are_repaired(): void
    {
        $this->assertSame('Mahnoor Sarfraz', NameNormalizer::person('mAHNOOR sarfraz'));
        $this->assertSame('John', NameNormalizer::person('jOHN'));
        $this->assertSame('Sara Khan', NameNormalizer::person('SARa kHAN'));
    }

    public function test_names_written_deliberately_are_left_alone(): void
    {
        foreach (['McDonald', 'DeShawn Carter', 'Mary-Jane Watson', 'ahmed BIN ali', 'Ada Lovelace', 'Jo'] as $name) {
            $this->assertSame($name, NameNormalizer::person($name), $name);
        }
        $this->assertSame('iConnect Labs', NameNormalizer::organization('iConnect Labs'));
        $this->assertSame('eBay Partners', NameNormalizer::organization('eBay Partners'));
        $this->assertSame('1234', NameNormalizer::person('1234'));
    }

    public function test_organisation_names_keep_short_acronyms_and_possessives(): void
    {
        $this->assertSame('GHL Agency', NameNormalizer::organization('GHL AGENCY'));
        $this->assertSame('Mike\'s Diner', NameNormalizer::organization('MIKE\'S DINER'));
        $this->assertSame('Acme Corp', NameNormalizer::organization('ACME CORP'));
        $this->assertSame('Bread and Butter', NameNormalizer::organization('BREAD AND BUTTER'));
        $this->assertSame('Northwind', NameNormalizer::organization('northwind'));
    }
}
