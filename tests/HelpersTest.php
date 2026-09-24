<?php

namespace LagerApp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    public static function validDates(): array
    {
        return [
            'ISO (Datumsfeld)' => ['2027-12-31', '2027-12-31'],
            'deutsches Format' => ['31.12.2027', '2027-12-31'],
            'mit Leerzeichen' => [' 2027-01-05 ', '2027-01-05'],
            'Schaltjahr' => ['29.02.2028', '2028-02-29'],
        ];
    }

    #[DataProvider('validDates')]
    public function testNormalizeDateAcceptsValidDates(string $input, string $expected): void
    {
        $this->assertSame($expected, normalizeDate($input));
    }

    public static function invalidDates(): array
    {
        return [
            'leer' => [''],
            'Text' => ['bald'],
            'kein Schaltjahr' => ['29.02.2027'],
            'Monat 13' => ['2027-13-01'],
            'ohne führende Null' => ['1.1.2027'],
            'amerikanisch' => ['12/31/2027'],
            'mit Uhrzeit' => ['2027-12-31 10:00'],
        ];
    }

    #[DataProvider('invalidDates')]
    public function testNormalizeDateRejectsInvalidDates(string $input): void
    {
        $this->assertNull(normalizeDate($input));
    }

    public function testNameKeyIgnoresCaseUmlautsAndSpaces(): void
    {
        $this->assertSame(nameKey('Mullbinde 8 cm'), nameKey('  MULLBINDE   8 cm '));
        $this->assertSame(nameKey('Ärmelschoner'), nameKey('ärmelschoner'));
        $this->assertNotSame(nameKey('Mullbinde 8 cm'), nameKey('Mullbinde 10 cm'));
    }

    public function testExpiryInfo(): void
    {
        $this->assertSame('', expiryInfo(null)['class']);
        $this->assertSame('expiry-expired', expiryInfo(date('Y-m-d', strtotime('-1 day')))['class']);
        $this->assertSame('expiry-warning', expiryInfo(date('Y-m-d'))['class']);
        $this->assertSame('expiry-warning', expiryInfo(date('Y-m-d', strtotime('+90 days')))['class']);
        $this->assertSame('', expiryInfo(date('Y-m-d', strtotime('+91 days')))['class']);
    }
}
