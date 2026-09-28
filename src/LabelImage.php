<?php

namespace LagerApp;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use GdImage;
use RuntimeException;

/**
 * Zeichnet ein Etikett für den Etikettendrucker als Bild (PHP-Erweiterung
 * gd): oben die Kategorie als Balken (rot, sonst schwarz) mit weißer
 * Schrift, darunter links Name und Artikelnummer, rechts der QR-Code.
 *
 *   ┌──────────────────────────────────────┐
 *   │ VERBANDMATERIAL                      │  ← Balken
 *   │                           ┌───────┐  │
 *   │ Mullbinde 8 cm            │  QR   │  │
 *   │ VB-008                    └───────┘  │
 *   └──────────────────────────────────────┘
 *
 * Das Bild liegt quer wie auf der Box: Breite = Etikettenlänge, Höhe =
 * bedruckbare Breite der Rolle, bei 300 dpi. Alle Maße ergeben sich aus
 * diesen beiden Werten, damit auch schmalere Rollen passen.
 *
 * Es gibt nur reines Weiß, Schwarz und Rot (#FF0000) – ohne Kanten-
 * glättung. brother_ql entscheidet je Pixel, ob es rot oder schwarz
 * gedruckt wird; Zwischentöne würden dabei ausfransen.
 */
final class LabelImage
{
    /**
     * Auflösung des Druckers.
     */
    private const DPI = 300;

    /**
     * Vorschub, den der Drucker am Anfang und Ende jedes Etiketts selbst
     * hinzufügt (brother_ql feed_margin, je 35 Punkte ≈ 3 mm). Wird von
     * der Länge abgezogen, damit das Etikett insgesamt LABEL_LENGTH_MM lang
     * wird.
     */
    private const FEED_MARGIN_DOTS = 35;

    /**
     * Die Schrift liefert endroid/qr-code mit (Open Sans, siehe
     * THIRD-PARTY-NOTICES.md). Fett wird durch versetztes Mehrfach-
     * zeichnen nachgebildet.
     */
    private const FONT = __DIR__ . '/../vendor/endroid/qr-code/assets/open_sans.ttf';

    private const MAX_NAME_LINES = 3;

    /**
     * Längster Name, der noch in der größten Schrift erscheint.
     */
    private const MAX_SIZE_REFERENCE = 'Ohrthermometer';

    /** @var array{x: int, y: int, size: int}|null */
    private ?array $qrBox = null;

    /** @var array{x: int, y: int, width: int, height: int} */
    private array $textBox = ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0];

    /** @var array<int, string> */
    private array $nameLines = [];

    private float $nameSize = 0;
    private float $numberSize = 0;

    public function __construct(private LabelConfig $config)
    {
    }

    /**
     * Etikett als PNG.
     *
     * @param array{name: string, article_number?: ?string, category_name?: ?string} $article
     */
    public function png(array $article): string
    {
        $image = $this->create($article);

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * @param array{name: string, article_number?: ?string, category_name?: ?string} $article
     */
    public function create(array $article): GdImage
    {
        if (!function_exists('imagecreate') || !function_exists('imagettftext')) {
            throw new RuntimeException(
                'Für den Etikettendrucker fehlt die PHP-Erweiterung gd (Paket php8.5-gd, siehe Installationsanleitung).'
            );
        }

        [$width, $height] = self::size($this->config);

        // Palettenbild: kann nur die hier angelegten Farben enthalten.
        $image = imagecreate($width, $height);
        imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $barColor = $this->config->red ? imagecolorallocate($image, 255, 0, 0) : $black;

        // Weiß noch einmal für Schrift: Die erste Farbe hat den Index 0, und
        // -0 schaltet die Kantenglättung nicht ab (siehe drawText()).
        $textWhite = imagecolorallocate($image, 255, 255, 255);

        $margin = (int) round($height * 0.06);
        $barHeight = (int) round($height * 0.2);

        // Kategorie-Balken über die ganze Länge.
        imagefilledrectangle($image, 0, 0, $width - 1, $barHeight - 1, $barColor);

        $category = mb_strtoupper(trim((string) ($article['category_name'] ?? '')) ?: 'Sonstiges');
        $this->drawSingleLine($image, $category, $textWhite, $margin, 0, $width - 2 * $margin, $barHeight, 0.5);

        // QR-Code rechts, so groß wie der Platz unter dem Balken erlaubt.
        $contentTop = $barHeight + $margin;
        $contentHeight = $height - $barHeight - 2 * $margin;
        $articleNumber = trim((string) ($article['article_number'] ?? ''));

        $this->qrBox = null;
        $textRight = $width - $margin;

        if ($articleNumber !== '') {
            $qrSize = min($contentHeight, (int) round($width * 0.36));

            $this->qrBox = [
                'x' => $width - $margin - $qrSize,
                'y' => $contentTop + intdiv($contentHeight - $qrSize, 2),
                'size' => $qrSize,
            ];

            $this->drawQrCode($image, $articleNumber, $black, $this->qrBox);

            $textRight = $this->qrBox['x'] - $margin;
        }

        // Links Name (bis drei Zeilen) und darunter die Artikelnummer.
        $this->textBox = [
            'x' => $margin,
            'y' => $contentTop,
            'width' => $textRight - $margin,
            'height' => $contentHeight,
        ];

        $this->drawNameAndNumber($image, trim((string) $article['name']), $articleNumber, $black);

        return $image;
    }

    /**
     * Bildgröße in Punkten: [Länge, Rollenbreite] (quer wie auf der Box).
     *
     * @return array{0: int, 1: int}
     */
    public static function size(LabelConfig $config): array
    {
        return [
            (int) round($config->lengthMm / 25.4 * self::DPI) - 2 * self::FEED_MARGIN_DOTS,
            $config->printableDots(),
        ];
    }

    /**
     * Lage des QR-Codes im zuletzt erzeugten Etikett (null ohne
     * Artikelnummer). Für Tests.
     *
     * @return array{x: int, y: int, size: int}|null
     */
    public function qrBox(): ?array
    {
        return $this->qrBox;
    }

    /**
     * Bereich für Name und Artikelnummer im zuletzt erzeugten Etikett.
     * Für Tests.
     *
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function textBox(): array
    {
        return $this->textBox;
    }

    /**
     * Zeilen und Schriftgröße des Namens bzw. der Artikelnummer im
     * zuletzt erzeugten Etikett. Für Tests.
     *
     * @return array<int, string>
     */
    public function nameLines(): array
    {
        return $this->nameLines;
    }

    public function nameSize(): float
    {
        return $this->nameSize;
    }

    public function numberSize(): float
    {
        return $this->numberSize;
    }

    /**
     * QR-Code als Blöcke zeichnen (statt ein fertiges Bild einzufügen,
     * das beim Skalieren Grautöne bekäme). Enthält wie auf dem A4-
     * Etikett nur die Artikelnummer.
     *
     * @param array{x: int, y: int, size: int} $box
     */
    private function drawQrCode(GdImage $image, string $value, int $color, array $box): void
    {
        $matrix = (new Builder(
            writer: new SvgWriter(),
            data: $value,
            size: $box['size'],
            margin: 0,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        ))->build()->getMatrix();

        $blockSize = (int) $matrix->getBlockSize();
        $offset = intdiv($box['size'] - $blockSize * $matrix->getBlockCount(), 2);

        for ($row = 0; $row < $matrix->getBlockCount(); $row++) {
            for ($column = 0; $column < $matrix->getBlockCount(); $column++) {
                if ($matrix->getBlockValue($row, $column) !== 1) {
                    continue;
                }

                $x = $box['x'] + $offset + $column * $blockSize;
                $y = $box['y'] + $offset + $row * $blockSize;

                imagefilledrectangle($image, $x, $y, $x + $blockSize - 1, $y + $blockSize - 1, $color);
            }
        }
    }

    private function drawNameAndNumber(GdImage $image, string $name, string $articleNumber, int $color): void
    {
        $box = $this->textBox;
        $numberHeight = 0;
        $this->numberSize = 0;

        if ($articleNumber !== '') {
            $this->numberSize = $this->fitSize($articleNumber, $box['width'], (int) round($box['height'] * 0.13));
            $numberHeight = $this->lineHeight($this->numberSize);

            $this->drawText(
                $image,
                $this->numberSize,
                $box['x'],
                $box['y'] + $box['height'] - $numberHeight,
                $color,
                $articleNumber,
                false
            );

            // Abstand zwischen Name und Artikelnummer.
            $numberHeight += (int) round($numberHeight * 0.4);
        }

        // Der Name ist das Wichtigste: nie kleiner als die Artikelnummer.
        // Höchstens so groß, dass „Ohrthermometer“ gerade auf eine Zeile
        // passt – kurze Namen wie „Schere“ werden sonst riesig.
        $nameHeight = $box['height'] - $numberHeight;
        $minSize = max($this->numberSize * 1.15, $nameHeight / 12, 6.0);
        $maxSize = max($minSize, $this->largestSize(self::MAX_SIZE_REFERENCE, $box['width'], $nameHeight / 3.3));

        [$this->nameSize, $this->nameLines] = $this->wrapName($name, $box['width'], $nameHeight, $minSize, $maxSize);
        $lineHeight = $this->lineHeight($this->nameSize);

        foreach ($this->nameLines as $index => $line) {
            $this->drawText($image, $this->nameSize, $box['x'], $box['y'] + $index * $lineHeight, $color, $line, true);
        }
    }

    /**
     * Größte Schrift (bis $maxSize), bei der der Name in höchstens drei Zeilen passt,
     * umbrochen an Leerzeichen und nach Bindestrichen. Passt ein Wort
     * dann nicht, wird es mit Trennstrich umbrochen (kaum kleiner als
     * nötig); was dann noch zu viel ist, wird mit … abgeschnitten.
     *
     * @return array{0: float, 1: array<int, string>}
     */
    private function wrapName(string $name, int $width, int $height, float $minSize, float $maxSize): array
    {
        $fits = fn (?array $lines, float $size): bool => $lines !== null
            && count($lines) <= self::MAX_NAME_LINES
            && count($lines) * $this->lineHeight($size) <= $height;

        foreach ($this->sizes(min($height / 3.3, $maxSize), $minSize) as $size) {
            $lines = $this->wrapWords($name, $size, $width, false);

            if ($fits($lines, $size)) {
                return [$size, $lines];
            }
        }

        foreach ($this->sizes(min($minSize * 1.5, $maxSize), $minSize) as $size) {
            $lines = $this->wrapWords($name, $size, $width, true);

            if ($fits($lines, $size)) {
                return [$size, $lines];
            }
        }

        $size = $minSize;
        $maxLines = max(1, min(self::MAX_NAME_LINES, intdiv($height, $this->lineHeight($size))));
        $lines = $this->wrapWords($name, $size, $width, true);

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $last = $maxLines - 1;
            $lines[$last] = rtrim($lines[$last], '-');

            while ($lines[$last] !== '' && $this->textWidth($lines[$last] . '…', $size, true) > $width) {
                $lines[$last] = mb_substr($lines[$last], 0, -1);
            }

            $lines[$last] = rtrim($lines[$last]) . '…';
        }

        return [$size, $lines];
    }

    /**
     * Größte Schrift (höchstens $limit), in der $text fett auf eine Zeile
     * der Breite $width passt.
     */
    private function largestSize(string $text, int $width, float $limit): float
    {
        $size = $limit * min(1, $width / $this->textWidth($text, $limit, true));

        // Die Breite wächst nicht ganz gleichmäßig mit (Rundung, Fettdruck).
        while ($size > 1 && $this->textWidth($text, $size, true) > $width) {
            $size *= 0.99;
        }

        return $size;
    }

    /**
     * Schriftgrößen von $from in Schritten von 6 % abwärts, zuletzt genau
     * $to (damit die kleinste erlaubte Größe immer mitgeprüft wird).
     *
     * @return array<int, float>
     */
    private function sizes(float $from, float $to): array
    {
        $sizes = [];

        for ($size = $from; $size > $to; $size *= 0.94) {
            $sizes[] = $size;
        }

        $sizes[] = $to;

        return $sizes;
    }

    /**
     * Zeilenumbruch an Leerzeichen und nach Bindestrichen
     * („Thermometer-“ / „Schutzhüllen“). Ist ein Teil breiter als eine
     * Zeile: null (kleinere Schrift versuchen) oder, mit $breakWords, mit
     * Trennstrich umbrechen.
     *
     * @return array<int, string>|null
     */
    private function wrapWords(string $text, float $size, int $width, bool $breakWords): ?array
    {
        $lines = [];
        $line = '';

        foreach (preg_split('/[ \t\r\n]+/', self::keepTogether($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            foreach (preg_split('/(?<=-)/u', $word, -1, PREG_SPLIT_NO_EMPTY) as $index => $piece) {
                $glue = $index === 0 ? ' ' : '';

                if ($this->textWidth($piece, $size, true) > $width) {
                    if (!$breakWords) {
                        return null;
                    }

                    $parts = $this->breakWord($piece, $size, $width);
                    $piece = array_pop($parts);

                    if ($line !== '') {
                        $lines[] = $line;
                    }

                    array_push($lines, ...$parts);
                    $line = $piece;
                    continue;
                }

                $candidate = $line === '' ? $piece : $line . $glue . $piece;

                if ($this->textWidth($candidate, $size, true) <= $width) {
                    $line = $candidate;
                    continue;
                }

                $lines[] = $line;
                $line = $piece;
            }
        }

        $lines[] = $line;

        // Geschützte Leerzeichen (keepTogether()) wieder als normale zeichnen.
        return str_replace("\u{00A0}", ' ', $lines);
    }

    /**
     * Maße und Einheiten nicht auseinanderreißen: geschütztes Leerzeichen
     * in „10 × 10 cm“, „100 ml“, „2,5 cm“, „Gr. 4“ (wrapWords() trennt nur
     * an normalen Leerzeichen).
     */
    private static function keepTogether(string $text): string
    {
        $nbsp = "\u{00A0}";

        $text = preg_replace('/(\d)\s*([×x])\s*(?=\d)/u', '$1' . $nbsp . '$2' . $nbsp, $text);
        $text = preg_replace('/(\d) (?=(?:mm|cm|m|ml|l|g|kg|mg|µl|Stk\.?|St\.|%)(?![\p{L}]))/u', '$1' . $nbsp, $text);

        return preg_replace('/\b(Gr\.|Größe|Nr\.) (?=\S)/u', '$1' . $nbsp, $text);
    }

    /**
     * Ein zu langes Wort mit Trennstrich auf mehrere Zeilen verteilen.
     *
     * @return array<int, string>
     */
    private function breakWord(string $word, float $size, int $width): array
    {
        $parts = [];
        $part = '';

        foreach (mb_str_split($word) as $character) {
            if ($part !== '' && $this->textWidth($part . $character . '-', $size, true) > $width) {
                $parts[] = $part . '-';
                $part = '';
            }

            $part .= $character;
        }

        $parts[] = $part;

        return $parts;
    }

    /**
     * Eine Zeile, senkrecht mittig in einem Bereich (Kategorie im
     * Balken). Zu lange Texte werden kleiner, notfalls mit … gekürzt.
     */
    private function drawSingleLine(
        GdImage $image,
        string $text,
        int $color,
        int $x,
        int $y,
        int $width,
        int $height,
        float $sizeFactor
    ): void {
        $size = $this->fitSize($text, $width, (int) round($height * $sizeFactor));

        while ($text !== '' && $this->textWidth($text, $size, true) > $width) {
            $text = rtrim(mb_substr($text, 0, -2)) . '…';
        }

        $top = $y + intdiv($height - $this->capHeight($size), 2) - $this->capOffset($size);

        $this->drawText($image, $size, $x, $top, $color, $text, true);
    }

    /**
     * Schriftgröße, bei der $text in $width passt – höchstens so groß,
     * dass eine Zeile $maxHeight Punkte hoch ist, mindestens halb so groß.
     */
    private function fitSize(string $text, int $width, int $maxHeight): float
    {
        $size = $maxHeight / 1.4;
        $minSize = $size / 2;

        while ($size > $minSize && $this->textWidth($text, $size, true) > $width) {
            $size *= 0.95;
        }

        return max($size, $minSize);
    }

    /**
     * Text mit Oberkante bei $top zeichnen (imagettftext erwartet die
     * Grundlinie). Negative Farbe = ohne Kantenglättung.
     */
    private function drawText(GdImage $image, float $size, int $x, int $top, int $color, string $text, bool $bold): void
    {
        $box = imagettfbbox($size, 0, self::FONT, 'ÄÅgjp');
        $baseline = $top - $box[7];
        $left = $x - imagettfbbox($size, 0, self::FONT, $text)[0];

        foreach (range(0, $bold ? $this->boldOffset($size) : 0) as $shift) {
            imagettftext($image, $size, 0, $left + $shift, $baseline, -$color, self::FONT, $text);
        }
    }

    private function textWidth(string $text, float $size, bool $bold): int
    {
        $box = imagettfbbox($size, 0, self::FONT, $text);

        return $box[2] - $box[0] + ($bold ? $this->boldOffset($size) : 0) + 1;
    }

    /**
     * Zeilenhöhe inkl. Ober- und Unterlängen.
     */
    private function lineHeight(float $size): int
    {
        $box = imagettfbbox($size, 0, self::FONT, 'ÄÅgjp');

        return (int) ceil(($box[1] - $box[7]) * 1.05);
    }

    /**
     * Höhe der Großbuchstaben und deren Abstand zur Oberkante der Zeile
     * (für die senkrechte Mitte im Balken, dort nur Großbuchstaben).
     */
    private function capHeight(float $size): int
    {
        $box = imagettfbbox($size, 0, self::FONT, 'H');

        return $box[1] - $box[7];
    }

    private function capOffset(float $size): int
    {
        return imagettfbbox($size, 0, self::FONT, 'H')[7] - imagettfbbox($size, 0, self::FONT, 'ÄÅgjp')[7];
    }

    private function boldOffset(float $size): int
    {
        return max(1, (int) round($size / 14));
    }
}
