<?php

namespace LagerApp;

use RuntimeException;

/**
 * A4-Etikettenbögen als PDF: 2 × 4 Felder à 105 × 74 mm, in jedem das
 * Etikett vom Etikettendrucker (LabelImage, 95 × 56,5 mm, mittig).
 *
 * Warum PDF statt HTML-Druck: Beim Drucken über den Systemdialog (macOS,
 * auch aus Chrome) oder aus Safari gelten die Ränder des Druckertreibers
 * statt `@page { margin: 0 }`. Der 296 mm hohe Bogen passt dann nicht
 * mehr auf die Seite – er verrutscht gegen die Stanzung, und nach jedem
 * Bogen kommt eine Leerseite. Ein PDF hat feste Seiten und Koordinaten;
 * bei 100 % gedruckt schneidet der Treiberrand höchstens weißen Rand ab.
 *
 * Bewusst ohne PDF-Bibliothek: Die Etiketten sind schon PNG-Bilder
 * (Palette, ohne Zeilensprung), deren Bilddaten ein PDF unverändert
 * übernehmen kann (FlateDecode mit PNG-Prädiktor). Jedes Bild wird je
 * Artikel nur einmal eingebettet.
 */
final class LabelSheetPdf
{
    private const MM = 72 / 25.4;

    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;

    private const COLUMNS = 2;
    private const ROWS = 4;
    private const CELL_WIDTH_MM = 105;
    private const CELL_HEIGHT_MM = 74;

    /**
     * Etikett im Feld: 95 mm breit (Seitenverhältnis wie LabelImage),
     * mittig – der Rand hält Abstand zu Stanzkante und Blattrand.
     */
    private const IMAGE_WIDTH_MM = 95;
    private const IMAGE_HEIGHT_MM = 56.5;

    /**
     * Dünner grauer Rahmen je Feld, wie in der Bildschirmansicht
     * (Schnittlinie auf normalem Papier).
     */
    private const BORDER_MM = 0.3;

    public const LABELS_PER_SHEET = self::COLUMNS * self::ROWS;

    public function __construct(private LabelConfig $config)
    {
    }

    /**
     * @param array<int, array{id: int|string, name: string, article_number?: ?string, category_name?: ?string}> $articles
     *        je Eintrag ein Etikett, gleiche Artikel mehrfach für mehrere
     */
    public function render(array $articles): string
    {
        $objects = [];
        $imageNames = [];
        $imageObjects = [];

        // Objekte 1 und 2: Katalog und Seitenbaum (Seitenbaum kommt zuletzt).
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '';

        $label = new LabelImage($this->config);

        foreach ($articles as $article) {
            $key = (string) $article['id'];

            if (!isset($imageNames[$key])) {
                $number = count($objects) + 1;
                $objects[$number] = self::imageObject($label->png($article));
                $imageNames[$key] = '/Im' . (count($imageNames) + 1);
                $imageObjects[$imageNames[$key]] = $number;
            }
        }

        $xObjects = implode(' ', array_map(
            static fn (string $name, int $number): string => $name . ' ' . $number . ' 0 R',
            array_keys($imageObjects),
            $imageObjects
        ));

        $pageNumbers = [];

        foreach (array_chunk($articles, self::LABELS_PER_SHEET) ?: [[]] as $sheet) {
            $content = $this->sheetContent(array_map(
                static fn (array $article): string => $imageNames[(string) $article['id']],
                $sheet
            ));

            $contentNumber = count($objects) + 1;
            $objects[$contentNumber] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";

            $pageNumber = count($objects) + 1;
            $objects[$pageNumber] = '<< /Type /Page /Parent 2 0 R'
                . ' /MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . ']'
                . ' /Resources << /XObject << ' . $xObjects . ' >> >>'
                . ' /Contents ' . $contentNumber . ' 0 R >>';

            $pageNumbers[] = $pageNumber;
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(
            static fn (int $number): string => $number . ' 0 R',
            $pageNumbers
        )) . '] /Count ' . count($pageNumbers) . ' >>';

        return self::document($objects);
    }

    /**
     * Zeichenbefehle für einen Bogen: je Feld Rahmen und Bild.
     *
     * @param array<int, string> $images Bildnamen (/Im1 …) in Feld-Reihenfolge
     */
    private function sheetContent(array $images): string
    {
        $content = self::number(0.53) . ' G ' . self::number(self::BORDER_MM * self::MM) . " w\n";

        foreach ($images as $index => $image) {
            $column = $index % self::COLUMNS;
            $row = intdiv($index, self::COLUMNS);

            $cellLeft = $column * self::CELL_WIDTH_MM;
            $cellTop = $row * self::CELL_HEIGHT_MM;

            $content .= self::rectangle(
                $cellLeft + self::BORDER_MM / 2,
                $cellTop + self::BORDER_MM / 2,
                self::CELL_WIDTH_MM - self::BORDER_MM,
                self::CELL_HEIGHT_MM - self::BORDER_MM
            ) . " re S\n";

            $left = $cellLeft + (self::CELL_WIDTH_MM - self::IMAGE_WIDTH_MM) / 2;
            $top = $cellTop + (self::CELL_HEIGHT_MM - self::IMAGE_HEIGHT_MM) / 2;

            $content .= 'q ' . self::number(self::IMAGE_WIDTH_MM * self::MM) . ' 0 0 '
                . self::number(self::IMAGE_HEIGHT_MM * self::MM) . ' '
                . self::number($left * self::MM) . ' '
                . self::number(self::PAGE_HEIGHT - ($top + self::IMAGE_HEIGHT_MM) * self::MM)
                . ' cm ' . $image . " Do Q\n";
        }

        return $content;
    }

    /**
     * Rechteck in mm (von oben links) als PDF-Koordinaten „x y b h“.
     */
    private static function rectangle(float $left, float $top, float $width, float $height): string
    {
        return self::number($left * self::MM) . ' '
            . self::number(self::PAGE_HEIGHT - ($top + $height) * self::MM) . ' '
            . self::number($width * self::MM) . ' '
            . self::number($height * self::MM);
    }

    /**
     * Bild-Objekt aus einem PNG mit Farbpalette (wie LabelImage es
     * schreibt): Palette aus PLTE, Bilddaten aus IDAT unverändert.
     */
    private static function imageObject(string $png): string
    {
        if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new RuntimeException('Etikettenbild ist kein PNG.');
        }

        $position = 8;
        $palette = '';
        $data = '';
        $header = null;

        while ($position < strlen($png)) {
            $length = unpack('N', substr($png, $position, 4))[1];
            $type = substr($png, $position + 4, 4);
            $chunk = substr($png, $position + 8, $length);
            $position += 12 + $length;

            match ($type) {
                'IHDR' => $header = unpack('Nwidth/Nheight/Cbits/Ccolor/Ccompression/Cfilter/Cinterlace', $chunk),
                'PLTE' => $palette = $chunk,
                'IDAT' => $data .= $chunk,
                default => null,
            };
        }

        if ($header === null || $header['color'] !== 3 || $header['interlace'] !== 0 || $palette === '') {
            throw new RuntimeException('Etikettenbild: nur PNG mit Farbpalette ohne Zeilensprung möglich.');
        }

        return '<< /Type /XObject /Subtype /Image'
            . ' /Width ' . $header['width'] . ' /Height ' . $header['height']
            . ' /ColorSpace [/Indexed /DeviceRGB ' . (strlen($palette) / 3 - 1) . ' <' . bin2hex($palette) . '>]'
            . ' /BitsPerComponent ' . $header['bits']
            . ' /Filter /FlateDecode'
            . ' /DecodeParms << /Predictor 15 /Colors 1 /BitsPerComponent ' . $header['bits']
            . ' /Columns ' . $header['width'] . ' >>'
            . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    }

    /**
     * PDF-Datei aus den Objekten (Nummer => Inhalt) mit xref-Tabelle.
     *
     * @param array<int, string> $objects
     */
    private static function document(array $objects): string
    {
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\n"
            . "startxref\n" . $xref . "\n%%EOF\n";
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
