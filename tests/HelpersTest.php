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

    public function testExpiryWarningUsesReportExpiryDays(): void
    {
        $previous = $_ENV['REPORT_EXPIRY_DAYS'] ?? null;
        $in45Days = date('Y-m-d', strtotime('+45 days'));

        try {
            unset($_ENV['REPORT_EXPIRY_DAYS']);
            $this->assertSame(90, expiryWarningDays());
            $this->assertSame('expiry-warning', expiryInfo($in45Days)['class']);

            // Wie im Wochenbericht eingestellt: 30 Tage.
            $_ENV['REPORT_EXPIRY_DAYS'] = '30';
            $this->assertSame(30, expiryWarningDays());
            $this->assertSame('', expiryInfo($in45Days)['class']);

            // Ungültige Werte: Standard.
            foreach (['0', '366', 'bald', ''] as $invalid) {
                $_ENV['REPORT_EXPIRY_DAYS'] = $invalid;
                $this->assertSame(90, expiryWarningDays(), $invalid);
            }
        } finally {
            if ($previous === null) {
                unset($_ENV['REPORT_EXPIRY_DAYS']);
            } else {
                $_ENV['REPORT_EXPIRY_DAYS'] = $previous;
            }
        }
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

    public static function categoryColors(): array
    {
        return [
            'Gelb' => ['#fffb00', '#202124'],
            'Grün' => ['#00f900', '#202124'],
            'Orange' => ['#f97316', '#202124'],
            'Blau' => ['#0433ff', '#fff'],
            'Lila' => ['#7c3aed', '#fff'],
            'Grau (Standard)' => ['#64748b', '#fff'],
            'Großbuchstaben' => ['#FFFB00', '#202124'],
        ];
    }

    #[DataProvider('categoryColors')]
    public function testCategoryStylePicksReadableTextColor(string $background, string $text): void
    {
        $this->assertSame(
            'background-color: ' . strtolower($background) . '; color: ' . $text . ';',
            categoryStyle($background)
        );
    }

    public function testCategoryStyleFallsBackForMissingOrInvalidColor(): void
    {
        $this->assertSame('background-color: #64748b; color: #fff;', categoryStyle(null));
        $this->assertSame('background-color: #64748b; color: #fff;', categoryStyle('red; position: fixed'));
    }
}
