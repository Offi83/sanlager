<?php

namespace LagerApp\Tests;

use LagerApp\LabelConfig;
use LagerApp\LabelSheetPdf;
use PHPUnit\Framework\TestCase;

/**
 * A4-Etikettenbögen als PDF: feste Seitengröße und Positionen, damit der
 * Druckdialog (auch der des Systems) nichts umbrechen oder verschieben kann.
 */
class LabelSheetPdfTest extends TestCase
{
    private const ARTICLES = [
        ['id' => 1, 'name' => 'Mullbinde 8 cm', 'article_number' => 'VB-008', 'category_name' => 'Verbandmaterial'],
        ['id' => 2, 'name' => 'Schere', 'article_number' => 'IN-001', 'category_name' => 'Instrumente'],
    ];

    /**
     * @return array<int, string> Objekt-Nummer => Inhalt, geprüft über die xref-Tabelle
     */
    private static function objects(string $pdf): array
    {
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringEndsWith("%%EOF\n", $pdf);

        preg_match('/startxref\s+(\d+)/', $pdf, $start);
        $xref = substr($pdf, (int) $start[1]);
        self::assertStringStartsWith('xref', $xref);

        preg_match('/^xref\s+0 (\d+)\s+((?:\d{10} \d{5} [nf] ?\s*)+)/', $xref, $table);
        preg_match_all('/(\d{10}) \d{5} n/', $table[2], $offsets);

        $objects = [];

        foreach ($offsets[1] as $index => $offset) {
            $number = $index + 1;
            $body = substr($pdf, (int) $offset);

            self::assertStringStartsWith($number . ' 0 obj', $body, 'xref zeigt auf Objekt ' . $number);
            $objects[$number] = substr($body, 0, strpos($body, 'endobj'));
        }

        self::assertSame((int) $table[1], count($objects) + 1);

        return $objects;
    }

    public function testSheetsAreA4WithEightLabelsEach(): void
    {
        // 9 Etiketten: 8 auf dem ersten Bogen, 1 auf dem zweiten.
        $labels = [...array_fill(0, 6, self::ARTICLES[0]), ...array_fill(0, 3, self::ARTICLES[1])];

        $pdf = (new LabelSheetPdf(LabelConfig::a4()))->render($labels);
        $objects = self::objects($pdf);

        $pages = array_filter($objects, static fn (string $object): bool => str_contains($object, '/Type /Page '));
        $this->assertCount(2, $pages);

        foreach ($pages as $page) {
            $this->assertStringContainsString('/MediaBox [0 0 595.28 841.89]', $page);
        }

        // Jedes Etikettenbild nur einmal eingebettet (je Artikel).
        $images = array_filter($objects, static fn (string $object): bool => str_contains($object, '/Subtype /Image'));
        $this->assertCount(2, $images);

        foreach ($images as $image) {
            $this->assertStringContainsString('/Width 1170 /Height 696', $image);
            $this->assertStringContainsString('/Filter /FlateDecode', $image);
            $this->assertStringContainsString('/Indexed /DeviceRGB', $image);
        }

        $contents = array_values(array_filter($objects, static fn (string $object): bool => str_contains($object, ' Do Q')));
        $this->assertSame(8, substr_count($contents[0], ' Do Q'));
        $this->assertSame(1, substr_count($contents[1], ' Do Q'));

        // Erstes Etikett: 95 × 56,5 mm, 5 mm vom linken Rand, 8,75 mm unter
        // der Oberkante (in Punkten, y von unten).
        $this->assertStringContainsString('q 269.29 0 0 160.16 14.17 656.93 cm /Im1 Do Q', $contents[0]);

        // Zweite Spalte, vierte Reihe (Platz 8).
        $this->assertStringContainsString('q 269.29 0 0 160.16 311.81 27.64 cm', $contents[0]);
    }

    public function testImageDataIsCompletePngData(): void
    {
        $pdf = (new LabelSheetPdf(LabelConfig::a4()))->render([self::ARTICLES[0]]);
        $image = array_values(array_filter(self::objects($pdf), static fn (string $object): bool => str_contains($object, '/Subtype /Image')))[0];

        preg_match('/\/Length (\d+).*?stream\n(.*)\nendstream/s', $image, $match);
        $this->assertSame((int) $match[1], strlen($match[2]));

        // Entpackt: je Zeile ein Filterbyte plus die Pixel (PNG-Prädiktor).
        preg_match('/\/BitsPerComponent (\d+)/', $image, $bits);
        $rowBytes = 1 + (int) ceil(1170 * (int) $bits[1] / 8);
        $this->assertSame(696 * $rowBytes, strlen(gzuncompress($match[2])));
    }

    public function testNothingToPrintStillGivesValidPdf(): void
    {
        $objects = self::objects((new LabelSheetPdf(LabelConfig::a4()))->render([]));

        $this->assertCount(1, array_filter($objects, static fn (string $object): bool => str_contains($object, '/Type /Page ')));
    }
}
