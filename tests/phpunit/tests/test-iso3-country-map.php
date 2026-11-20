<?php

require_once __DIR__ . '/../../../includes/iso3-country-map.php';

/**
 * @covers ::rzpGetIso3CountryCode
 */

class Test_Iso3_Country_Map extends \PHPUnit_Framework_TestCase
{
    public function testMapsIso2ToIso3()
    {
        $this->assertSame('IND', rzpGetIso3CountryCode('IN'));
        $this->assertSame('USA', rzpGetIso3CountryCode('US'));
        $this->assertSame('MYS', rzpGetIso3CountryCode('MY'));
        $this->assertSame('ARE', rzpGetIso3CountryCode('AE'));
    }

    public function testIsCaseInsensitive()
    {
        $this->assertSame('GBR', rzpGetIso3CountryCode('gb'));
        $this->assertSame('SGP', rzpGetIso3CountryCode('Sg'));
    }

    public function testMapsWooCommerceTerritories()
    {
        $this->assertSame('HKG', rzpGetIso3CountryCode('HK'));
        $this->assertSame('CYM', rzpGetIso3CountryCode('KY'));
        $this->assertSame('GGY', rzpGetIso3CountryCode('GG'));
    }

    public function testPassesThroughUnmappedAndEmptyValues()
    {
        $this->assertSame('IND', rzpGetIso3CountryCode('IND'));
        $this->assertSame('', rzpGetIso3CountryCode(''));
        $this->assertSame('', rzpGetIso3CountryCode(null));
    }
}
