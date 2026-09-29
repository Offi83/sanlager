<?php

namespace LagerApp\Tests;

use GdImage;
use LagerApp\LabelConfig;
use LagerApp\LabelImage;
use LagerApp\LabelPrinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Etikettendrucker: Einstellungen aus der .env, das erzeugte
 * Etikettenbild und der Aufruf von brother_ql (ohne echten Drucker –
 * der Aufruf wird durch eine Testfunktion ersetzt).
 */
class LabelTest extends TestCase
{
    private const PRINTER_ENV = [
        'LABEL_OUTPUT' => 'printer',
        'LABEL_PRINTER' => 'tcp://192.168.1.50:9100',
    ];

    private const ARTICLE = [
        'name' => 'Mullbinde 8 cm',
        'article_number' => 'VB-008',
        'category_name' => 'Verbandmaterial',
    ];

    private static function config(array $env = []): LabelConfig
    {
        return LabelConfig::fromEnv($env + self::PRINTER_ENV);
    }

    private static function configError(array $env): string
    {
        try {
            LabelConfig::fromEnv($env);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        self::fail('Ungültige Einstellung wurde nicht abgelehnt: ' . json_encode($env));
    }

    // ------------------------------------------------------------------
    // LabelConfig
    // ------------------------------------------------------------------

    public function testA4IsDefaultAndIgnoresPrinterSettings(): void
    {
        $this->assertFalse(LabelConfig::fromEnv([])->usesPrinter());
        $this->assertFalse(LabelConfig::fromEnv(['LABEL_OUTPUT' => ' A4 '])->usesPrinter());

        // Im A4-Modus stören fehlende oder falsche Druckereinstellungen nicht.
        $this->assertFalse(LabelConfig::fromEnv([
            'LABEL_OUTPUT' => 'a4',
            'LABEL_PRINTER' => 'quatsch',
            'LABEL_WIDTH_MM' => '7',
        ])->usesPrinter());
    }

    public function testPrinterDefaultsFitQl810wWith62mmRoll(): void
    {
        $config = self::config();

        $this->assertTrue($config->usesPrinter());
        $this->assertSame('QL-810W', $config->model);
        $this->assertSame(62, $config->widthMm);
        $this->assertSame(105, $config->lengthMm);
        $this->assertFalse($config->red);
        $this->assertSame('/opt/brother-ql/bin/brother_ql', $config->brotherQl);
        $this->assertSame('network', $config->backend());
        $this->assertSame('62', $config->labelIdentifier());
        $this->assertSame(696, $config->printableDots());
    }

    public function testA4LabelsLookLikePrinterLabelsWithRedBar(): void
    {
        $config = LabelConfig::a4();

        // A4-Bögen zeigen dasselbe Bild wie der Etikettendrucker, immer mit
        // rotem Balken (Farbdrucker); nur die Druckereinstellungen fehlen.
        $this->assertFalse($config->usesPrinter());
        $this->assertTrue($config->red);
        $this->assertSame(62, $config->widthMm);
        $this->assertSame(105, $config->lengthMm);
        $this->assertTrue(LabelConfig::fromEnv(['LABEL_OUTPUT' => 'a4', 'LABEL_RED' => 'false'])->red, 'LABEL_RED gilt nur für den Drucker');
    }

    public function testRedUsesRedLabelAndUsbUsesKernelBackend(): void
    {
        $config = self::config([
            'LABEL_PRINTER' => 'file:///dev/usb/lp0',
            'LABEL_RED' => 'true',
            'LABEL_LENGTH_MM' => '100',
            'BROTHER_QL' => '/usr/local/bin/brother_ql',
        ]);

        $this->assertTrue($config->red);
        $this->assertSame('62red', $config->labelIdentifier());
        $this->assertSame('linux_kernel', $config->backend());
        $this->assertSame(100, $config->lengthMm);
        $this->assertSame('/usr/local/bin/brother_ql', $config->brotherQl);
    }

    public static function rollWidths(): array
    {
        return [
            '29 mm' => [29, 306],
            '38 mm' => [38, 413],
            '50 mm' => [50, 554],
            '54 mm' => [54, 590],
            '62 mm' => [62, 696],
        ];
    }

    #[DataProvider('rollWidths')]
    public function testNarrowerRollsAreSupported(int $width, int $dots): void
    {
        $config = self::config(['LABEL_WIDTH_MM' => (string) $width]);

        $this->assertSame((string) $width, $config->labelIdentifier());
        $this->assertSame($dots, $config->printableDots());
    }

    public function testWideRollOnlyOnWidePrinters(): void
    {
        $this->assertSame(1164, self::config(['LABEL_WIDTH_MM' => '102', 'LABEL_PRINTER_MODEL' => 'QL-1100'])->printableDots());

        $this->assertStringContainsString('102 mm', self::configError(self::PRINTER_ENV + ['LABEL_WIDTH_MM' => '102']));
    }

    public function testInvalidPrinterSettingsAreListedTogether(): void
    {
        $message = self::configError([
            'LABEL_OUTPUT' => 'printer',
            'LABEL_PRINTER' => '',
            'LABEL_PRINTER_MODEL' => 'QL-999',
            'LABEL_WIDTH_MM' => '12',
            'LABEL_LENGTH_MM' => '20',
            'LABEL_RED' => 'vielleicht',
        ]);

        $this->assertStringContainsString('LABEL_PRINTER fehlt', $message);
        $this->assertStringContainsString('LABEL_PRINTER_MODEL', $message);
        $this->assertStringContainsString('LABEL_WIDTH_MM', $message);
        $this->assertStringContainsString('LABEL_LENGTH_MM', $message);
        $this->assertStringContainsString('LABEL_RED', $message);

        $this->assertStringContainsString('LABEL_OUTPUT', self::configError(['LABEL_OUTPUT' => 'drucker']));
        $this->assertStringContainsString('tcp://', self::configError(['LABEL_PRINTER' => '192.168.1.50'] + self::PRINTER_ENV));
        $this->assertStringContainsString('tcp://', self::configError(['LABEL_PRINTER' => 'http://drucker'] + self::PRINTER_ENV));
    }

    public function testRedNeeds62mmRollAndQl800Series(): void
    {
        $this->assertStringContainsString(
            'DK-22251',
            self::configError(self::PRINTER_ENV + ['LABEL_RED' => 'true', 'LABEL_WIDTH_MM' => '29'])
        );

        $this->assertStringContainsString(
            'QL-800',
            self::configError(self::PRINTER_ENV + ['LABEL_RED' => 'true', 'LABEL_PRINTER_MODEL' => 'QL-700'])
        );

        $this->assertTrue(self::config(['LABEL_RED' => 'true', 'LABEL_PRINTER_MODEL' => 'QL-820NWB'])->red);
    }

    // ------------------------------------------------------------------
    // LabelImage
    // ------------------------------------------------------------------

    /**
     * @return array<int, int> Farbe (0xRRGGBB) => Anzahl Pixel
     */
    private static function colors(GdImage $image): array
    {
        $colors = [];

        for ($x = 0; $x < imagesx($image); $x++) {
            for ($y = 0; $y < imagesy($image); $y++) {
                $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $key = ($rgb['red'] << 16) | ($rgb['green'] << 8) | $rgb['blue'];
                $colors[$key] = ($colors[$key] ?? 0) + 1;
            }
        }

        return $colors;
    }

    private static function colorAt(GdImage $image, int $x, int $y): int
    {
        $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return ($rgb['red'] << 16) | ($rgb['green'] << 8) | $rgb['blue'];
    }

    public function testImageHasRollWidthAndLabelLength(): void
    {
        $image = (new LabelImage(self::config()))->create(self::ARTICLE);

        // Querformat wie auf der Box: Länge × Rollenbreite. 105 mm bei
        // 300 dpi = 1240 Punkte, abzüglich der Ränder, die der Drucker am
        // Anfang und Ende selbst vorschiebt (je 35 Punkte).
        $this->assertSame(1240 - 70, imagesx($image));
        $this->assertSame(696, imagesy($image));
    }

    public function testRedLabelUsesOnlyPureBlackRedAndWhite(): void
    {
        $image = (new LabelImage(self::config(['LABEL_RED' => 'true'])))->create(self::ARTICLE);
        $colors = self::colors($image);

        $this->assertSame([], array_diff(array_keys($colors), [0xFFFFFF, 0x000000, 0xFF0000]), 'keine Graustufen oder Mischfarben');
        $this->assertArrayHasKey(0xFF0000, $colors);
        $this->assertArrayHasKey(0x000000, $colors);

        // Roter Balken oben über die ganze Länge, darin weiße Schrift.
        $this->assertSame(0xFF0000, self::colorAt($image, 2, 2));
        $this->assertSame(0xFF0000, self::colorAt($image, imagesx($image) - 3, 2));
        $this->assertContains(0xFFFFFF, array_map(
            static fn (int $x): int => self::colorAt($image, $x, (int) (imagesy($image) * 0.1)),
            range(0, (int) (imagesx($image) / 2))
        ));

        // Unten rechts der QR-Code (schwarz), unten links keine rote Fläche.
        $this->assertSame(0xFFFFFF, self::colorAt($image, 2, imagesy($image) - 3));
    }

    public function testBlackLabelHasNoRed(): void
    {
        $image = (new LabelImage(self::config()))->create(self::ARTICLE);
        $colors = self::colors($image);

        $this->assertSame([], array_diff(array_keys($colors), [0xFFFFFF, 0x000000]));
        $this->assertSame(0x000000, self::colorAt($image, 2, 2), 'Balken schwarz statt rot');
    }

    public function testQrCodeSitsRightAndIsSquare(): void
    {
        $label = new LabelImage(self::config());
        $image = $label->create(self::ARTICLE);
        $qr = $label->qrBox();

        $this->assertGreaterThan(imagesx($image) / 2, $qr['x'], 'rechte Hälfte');
        $this->assertLessThanOrEqual(imagesx($image), $qr['x'] + $qr['size']);
        $this->assertLessThanOrEqual(imagesy($image), $qr['y'] + $qr['size']);

        // 62 × 105 mm: QR-Code etwa 36 mm (Hand-Scanner).
        $this->assertGreaterThanOrEqual(420, $qr['size']);
    }

    #[DataProvider('rollWidths')]
    public function testLongNamesStayInsideTextArea(int $width, int $dots): void
    {
        $label = new LabelImage(self::config(['LABEL_WIDTH_MM' => (string) $width, 'LABEL_RED' => 'false']));
        $image = $label->create([
            'name' => 'Blutzuckermessstreifen Wundantiseptikum Sterile Kompressen extra groß 10 × 10 cm',
            'article_number' => 'diag-bz-streifen-verylong',
            'category_name' => 'Diagnostik und Überwachung',
        ]);

        $text = $label->textBox();
        $qr = $label->qrBox();

        $this->assertLessThan($qr['x'], $text['x'] + $text['width'], 'Text nicht über dem QR-Code');

        // Rechts neben dem Textbereich bis zum QR-Code bleibt es weiß.
        for ($x = $text['x'] + $text['width'] + 1; $x < $qr['x']; $x++) {
            for ($y = $text['y']; $y < imagesy($image); $y++) {
                $this->assertSame(0xFFFFFF, self::colorAt($image, $x, $y), "Pixel $x/$y");
            }
        }
    }

    public function testLongWordsBreakAtHyphenAndNameStaysLargerThanNumber(): void
    {
        $label = new LabelImage(self::config());

        $label->create(['name' => 'Thermometer-Schutzhüllen'] + self::ARTICLE);
        $this->assertSame(['Thermometer-', 'Schutzhüllen'], $label->nameLines());
        $this->assertGreaterThan($label->numberSize(), $label->nameSize());

        // Ohne Bindestrich: lieber mit Trennstrich umbrechen als winzig.
        $label->create(['name' => 'Blutzuckermessstreifen'] + self::ARTICLE);
        $this->assertGreaterThan($label->numberSize(), $label->nameSize());
        $this->assertCount(2, $label->nameLines());
        $this->assertStringEndsWith('-', $label->nameLines()[0]);
        $this->assertSame('Blutzuckermessstreifen', str_replace('-', '', implode('', $label->nameLines())));

        // Getrennt wird nach Silben (Blut-zu-cker-mess-strei-fen).
        $this->assertContains(
            $label->nameLines()[0],
            ['Blut-', 'Blutzu-', 'Blutzucker-', 'Blutzuckermess-', 'Blutzuckermessstrei-']
        );

        // Passt ein Wort in der kleinsten Größe, wird es nicht getrennt.
        $label->create(['name' => 'Händedesinfektion 100 ml'] + self::ARTICLE);
        $this->assertSame(['Händedesinfektion', '100 ml'], $label->nameLines());

        // Kurze Namen bleiben groß und ungetrennt.
        $label->create(['name' => 'Mullbinde 8 cm'] + self::ARTICLE);
        $this->assertNotContains('-', array_map(static fn (string $line): string => substr($line, -1), $label->nameLines()));
    }

    public function testMeasurementsStayTogether(): void
    {
        $label = new LabelImage(self::config());
        $lines = static fn (): array => array_map(
            static fn (string $line): string => str_replace("\u{00A0}", ' ', $line),
            $label->nameLines()
        );

        $label->create(['name' => 'Kompresse 10 × 10 cm'] + self::ARTICLE);
        $this->assertSame(['Kompresse', '10 × 10 cm'], $lines());

        // Ganze Maßketten mit Einheiten bleiben zusammen (nicht „6 cm“ / „x 4 m“).
        $label->create(['name' => 'Fixierbinde 6 cm x 4 m'] + self::ARTICLE);
        $this->assertSame(['Fixierbinde', '6 cm x 4 m'], $lines());

        $label->create(['name' => 'Heftpflaster 2,5 cm × 5 m'] + self::ARTICLE);
        $this->assertSame(['Heftpflaster', '2,5 cm × 5 m'], $lines());

        $label->create(['name' => 'Fixierbinde 10cm x 4m'] + self::ARTICLE);
        $this->assertSame(['Fixierbinde', '10cm x 4m'], $lines());

        $label->create(['name' => 'Fixierbinde elastisch 6 cm x 4 m'] + self::ARTICLE);
        $elasticLines = $lines();
        $this->assertSame('6 cm x 4 m', end($elasticLines));

        $label->create(['name' => 'Beatmungsmaske Gr. 4'] + self::ARTICLE);
        $maskLines = $lines();
        $this->assertStringEndsWith('Gr. 4', end($maskLines));
        $this->assertNotSame('4', end($maskLines));
    }

        public function testShortNamesAreNotHuge(): void
    {
        $label = new LabelImage(self::config());

        $label->create(['name' => 'Schere'] + self::ARTICLE);
        $short = $label->nameSize();

        $label->create(['name' => 'Ohrthermometer'] + self::ARTICLE);
        $reference = $label->nameSize();
        $this->assertSame(['Ohrthermometer'], $label->nameLines());

        // Höchstens so groß wie „Ohrthermometer“ auf einer Zeile – kurze
        // Namen werden nicht größer.
        $this->assertEqualsWithDelta($reference, $short, 0.01);

        $label->create(['name' => 'Mullbinde 8 cm'] + self::ARTICLE);
        $this->assertLessThanOrEqual($reference + 0.01, $label->nameSize());
    }

    public function testArticleWithoutNumberHasNoQrCode(): void
    {
        $label = new LabelImage(self::config());
        $label->create(['article_number' => null] + self::ARTICLE);

        $this->assertNull($label->qrBox());
    }

    public function testPngIsWritten(): void
    {
        $png = (new LabelImage(self::config(['LABEL_RED' => 'true'])))->png(self::ARTICLE);

        $this->assertStringStartsWith("\x89PNG", $png);
        $this->assertSame([1170, 696], array_slice(getimagesizefromstring($png), 0, 2));
    }

    // ------------------------------------------------------------------
    // LabelPrinter
    // ------------------------------------------------------------------

    /**
     * Drucker mit Testfunktion statt brother_ql: merkt sich den Aufruf
     * und liefert den vorgegebenen Exit-Code und die Ausgabe.
     *
     * @param array{command?: array<int, string>} $calls
     */
    private static function printer(LabelConfig $config, int $exitCode, string $output, ?array &$calls = []): LabelPrinter
    {
        return new LabelPrinter(
            $config,
            static function (array $command, int $timeout) use ($exitCode, $output, &$calls): array {
                $calls[] = ['command' => $command, 'timeout' => $timeout];

                return ['exitCode' => $exitCode, 'output' => $output];
            },
            static fn (): bool => true
        );
    }

    private static function printError(LabelPrinter $printer): string
    {
        try {
            $printer->print([self::ARTICLE]);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        self::fail('Druckfehler wurde nicht gemeldet.');
    }

    public function testNetworkPrintCallsBrotherQlAndCannotConfirm(): void
    {
        $calls = [];
        $printer = self::printer(self::config(['LABEL_RED' => 'true']), 0, "INFO:brother_ql.backends.helpers:Sending instructions to the printer. Total: 1234 bytes.\n", $calls);

        $confirmed = $printer->print([self::ARTICLE, self::ARTICLE]);

        $this->assertFalse($confirmed, 'WLAN meldet keinen Status zurück');
        $this->assertCount(1, $calls);

        $command = $calls[0]['command'];
        $files = array_slice($command, 13);

        $this->assertSame([
            '/opt/brother-ql/bin/brother_ql',
            '-b', 'network',
            '-m', 'QL-810W',
            '-p', 'tcp://192.168.1.50:9100',
            'print',
            '-l', '62red',
            '-r', '90',
            '--red',
        ], array_slice($command, 0, 13));

        $this->assertCount(2, $files);

        // Die Bilddateien sind nach dem Druck wieder weg.
        foreach ($files as $file) {
            $this->assertFileDoesNotExist($file);
        }
    }

    public function testBlackPrintHasNoRedOption(): void
    {
        $calls = [];
        self::printer(self::config(), 0, '', $calls)->print([self::ARTICLE]);

        $this->assertNotContains('--red', $calls[0]['command']);
        $this->assertContains('62', $calls[0]['command']);
    }

    public function testUsbPrintIsConfirmedByPrinter(): void
    {
        $printer = self::printer(
            self::config(['LABEL_PRINTER' => 'file:///dev/usb/lp0']),
            0,
            "INFO:brother_ql.backends.helpers:Printing was successful. Waiting for the next job.\n"
        );

        $this->assertTrue($printer->print([self::ARTICLE]));
    }

    public function testManyLabelsAreSentInSeveralJobs(): void
    {
        $calls = [];
        self::printer(self::config(), 0, '', $calls)->print(array_fill(0, 23, self::ARTICLE));

        $this->assertSame([10, 10, 3], array_map(
            static fn (array $call): int => count($call['command']) - 12,
            $calls
        ));
    }

    public static function printerErrors(): array
    {
        return [
            'Rolle leer' => [
                0,
                "ERROR:brother_ql.backends.helpers:Errors occured: ['No media when printing']\n",
                'Keine Rolle eingelegt',
            ],
            'falsche Rolle' => [
                0,
                "ERROR:brother_ql.backends.helpers:Errors occured: ['Replace media error']\n",
                'DK-22251',
            ],
            'Deckel offen' => [
                0,
                "ERROR:brother_ql.backends.helpers:Errors occured: ['Cover opened while printing (Except QL-500)']\n",
                'Deckel',
            ],
            'kein Druck bestätigt' => [
                0,
                "WARNING:brother_ql.backends.helpers:'printing completed' status not received.\nWARNING:brother_ql.backends.helpers:Printing potentially not successful?\n",
                'nicht bestätigt',
            ],
            'Gerät fehlt' => [
                1,
                "FileNotFoundError: [Errno 2] No such file or directory: '/dev/usb/lp0'\n",
                'nicht angeschlossen oder ausgeschaltet',
            ],
            'keine Berechtigung' => [
                1,
                "PermissionError: [Errno 13] Permission denied: '/dev/usb/lp0'\n",
                'Gruppe lp',
            ],
        ];
    }

    #[DataProvider('printerErrors')]
    public function testUsbPrinterErrorsAreExplained(int $exitCode, string $output, string $expected): void
    {
        $printer = self::printer(self::config(['LABEL_PRINTER' => 'file:///dev/usb/lp0']), $exitCode, $output);

        $this->assertStringContainsString($expected, self::printError($printer));
    }

    public function testUnreachableNetworkPrinter(): void
    {
        $printer = self::printer(self::config(), 1, "ConnectionRefusedError: [Errno 111] Connection refused\n");

        $message = self::printError($printer);

        $this->assertStringContainsString('nicht erreichbar', $message);
        $this->assertStringContainsString('192.168.1.50', $message);
    }

    public function testTimeoutAndMissingProgram(): void
    {
        $printer = self::printer(self::config(), LabelPrinter::TIMEOUT_EXIT_CODE, '');
        $this->assertStringContainsString('antwortet nicht', self::printError($printer));

        $printer = new LabelPrinter(
            self::config(['BROTHER_QL' => '/gibt/es/nicht/brother_ql']),
            static fn (): array => self::fail('darf nicht aufgerufen werden'),
        );

        $message = self::printError($printer);
        $this->assertStringContainsString('brother_ql', $message);
        $this->assertStringContainsString('/gibt/es/nicht/brother_ql', $message);
    }

    public function testNothingToPrint(): void
    {
        $calls = [];
        $this->assertTrue(self::printer(self::config(), 0, '', $calls)->print([]));
        $this->assertSame([], $calls);
    }

    public function testRealRunnerReportsExitCodeAndOutput(): void
    {
        $result = LabelPrinter::run([PHP_BINARY, '-r', 'fwrite(STDERR, "fehler\n"); echo "aus"; exit(3);'], 10);

        $this->assertSame(3, $result['exitCode']);
        $this->assertStringContainsString('fehler', $result['output']);
        $this->assertStringContainsString('aus', $result['output']);

        $result = LabelPrinter::run([PHP_BINARY, '-r', 'sleep(5);'], 1);
        $this->assertSame(LabelPrinter::TIMEOUT_EXIT_CODE, $result['exitCode']);
    }
}
