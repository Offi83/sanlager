<?php

namespace LagerApp;

use RuntimeException;

/**
 * Einstellungen für den Etikettendruck, ausschließlich aus der .env-Datei
 * (siehe .env.example und docs/05-installation.md#etikettendrucker).
 *
 * LABEL_OUTPUT=a4 (Standard) druckt wie bisher A4-Bögen über den
 * Druckdialog des Browsers. LABEL_OUTPUT=printer schickt die Etiketten
 * über brother_ql (Paket brother-ql-next) direkt an einen Brother-
 * Etikettendrucker der QL-Serie – per WLAN/Netzwerk (tcp://…) oder USB
 * (file:///dev/usb/lp0). Nur so ist Rot/Schwarz-Druck möglich; die
 * CUPS-Treiber unter Linux drucken nur schwarz.
 */
final class LabelConfig
{
    /**
     * Modelle, die brother_ql kennt.
     */
    public const MODELS = [
        'QL-500', 'QL-550', 'QL-560', 'QL-570', 'QL-580N', 'QL-600', 'QL-650TD',
        'QL-700', 'QL-710W', 'QL-720NW', 'QL-800', 'QL-810W', 'QL-820NWB',
        'QL-1050', 'QL-1060N', 'QL-1100', 'QL-1100NWB', 'QL-1110NWB', 'QL-1115NWB',
    ];

    /**
     * Endlosrollen: Breite in mm => bedruckbare Punkte quer zur Rolle
     * (300 dpi, Werte aus brother_ql/labels.py). 12 mm fehlt bewusst:
     * darauf passt kein lesbarer QR-Code.
     */
    public const ROLL_DOTS = [
        29 => 306,
        38 => 413,
        50 => 554,
        54 => 590,
        62 => 696,
        102 => 1164,
    ];

    /**
     * 102-mm-Rollen passen nur in die breiten Modelle.
     */
    private const WIDE_MODELS = ['QL-1050', 'QL-1060N', 'QL-1100', 'QL-1100NWB', 'QL-1110NWB', 'QL-1115NWB'];

    /**
     * Rot/Schwarz (Rolle DK-22251, 62 mm) können nur diese Modelle.
     */
    private const RED_MODELS = ['QL-800', 'QL-810W', 'QL-820NWB'];

    private const MIN_LENGTH_MM = 40;
    private const MAX_LENGTH_MM = 300;

    private function __construct(
        public readonly bool $printer,
        public readonly string $printerAddress = '',
        public readonly string $model = 'QL-810W',
        public readonly int $widthMm = 62,
        public readonly int $lengthMm = 120,
        public readonly bool $red = false,
        public readonly string $brotherQl = '/opt/brother-ql/bin/brother_ql'
    ) {
    }

    /**
     * A4-Bögen über den Browser (auch Rückfall bei fehlerhafter Einstellung).
     */
    public static function a4(): self
    {
        return new self(false);
    }

    /**
     * @param array<string, mixed> $env in der Anwendung $_ENV
     * @throws RuntimeException mit allen fehlenden/ungültigen Einstellungen
     */
    public static function fromEnv(array $env): self
    {
        $value = static fn (string $key): string =>
            is_string($env[$key] ?? null) ? trim($env[$key]) : '';

        $output = strtolower($value('LABEL_OUTPUT'));

        if ($output === '' || $output === 'a4') {
            return self::a4();
        }

        if ($output !== 'printer') {
            throw new RuntimeException(
                'Etikettendruck ist nicht korrekt konfiguriert (.env): '
                . 'LABEL_OUTPUT muss a4 oder printer sein.'
            );
        }

        $errors = [];

        $address = $value('LABEL_PRINTER');

        if ($address === '') {
            $errors[] = 'LABEL_PRINTER fehlt (z. B. tcp://192.168.1.50:9100 für WLAN oder file:///dev/usb/lp0 für USB).';
        } elseif (!preg_match('#^(tcp://[^/\s]+|file:///\S+)$#', $address)) {
            $errors[] = 'LABEL_PRINTER muss mit tcp:// (WLAN/Netzwerk) oder file:/// (USB) beginnen, z. B. tcp://192.168.1.50:9100.';
        }

        $model = $value('LABEL_PRINTER_MODEL') !== '' ? $value('LABEL_PRINTER_MODEL') : 'QL-810W';

        if (!in_array($model, self::MODELS, true)) {
            $errors[] = 'LABEL_PRINTER_MODEL „' . $model . '“ ist unbekannt (z. B. QL-810W, QL-820NWB, QL-700).';
        }

        $width = self::number($value('LABEL_WIDTH_MM'), 62);

        if (!isset(self::ROLL_DOTS[$width])) {
            $errors[] = 'LABEL_WIDTH_MM muss eine Rollenbreite in mm sein: '
                . implode(', ', array_keys(self::ROLL_DOTS)) . '.';
        } elseif ($width === 102 && !in_array($model, self::WIDE_MODELS, true)) {
            $errors[] = 'LABEL_WIDTH_MM: 102 mm breite Rollen passen nur in die QL-1000er-Modelle.';
        }

        $length = self::number($value('LABEL_LENGTH_MM'), 120);

        if ($length < self::MIN_LENGTH_MM || $length > self::MAX_LENGTH_MM) {
            $errors[] = 'LABEL_LENGTH_MM muss eine Zahl zwischen ' . self::MIN_LENGTH_MM
                . ' und ' . self::MAX_LENGTH_MM . ' sein.';
        }

        $red = $value('LABEL_RED') === ''
            ? false
            : filter_var($value('LABEL_RED'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($red === null) {
            $errors[] = 'LABEL_RED muss true oder false sein.';
        } elseif ($red && $width !== 62) {
            $errors[] = 'LABEL_RED=true geht nur mit LABEL_WIDTH_MM=62 (Rolle DK-22251).';
        } elseif ($red && !in_array($model, self::RED_MODELS, true)) {
            $errors[] = 'LABEL_RED=true geht nur mit der QL-800-Serie (QL-800, QL-810W, QL-820NWB).';
        }

        $brotherQl = $value('BROTHER_QL') !== '' ? $value('BROTHER_QL') : '/opt/brother-ql/bin/brother_ql';

        if ($errors !== []) {
            throw new RuntimeException(
                "Etikettendrucker ist nicht korrekt konfiguriert (.env):\n- "
                . implode("\n- ", $errors)
            );
        }

        return new self(true, $address, $model, $width, $length, (bool) $red, $brotherQl);
    }

    /**
     * Ganze Zahl aus der .env, leer = Standard, sonst -1 (ungültig).
     */
    private static function number(string $value, int $default): int
    {
        if ($value === '') {
            return $default;
        }

        return ctype_digit($value) ? (int) $value : -1;
    }

    public function usesPrinter(): bool
    {
        return $this->printer;
    }

    /**
     * Anschluss für brother_ql: network (WLAN/LAN) oder linux_kernel (USB).
     */
    public function backend(): string
    {
        return str_starts_with($this->printerAddress, 'tcp://') ? 'network' : 'linux_kernel';
    }

    /**
     * Rollenbezeichnung für brother_ql (-l), z. B. 62 oder 62red.
     */
    public function labelIdentifier(): string
    {
        return $this->widthMm . ($this->red ? 'red' : '');
    }

    /**
     * Bedruckbare Punkte quer zur Rolle (= Höhe des Etiketts auf der Box).
     */
    public function printableDots(): int
    {
        return self::ROLL_DOTS[$this->widthMm];
    }

    /**
     * Kurzbeschreibung für die Etikettenseiten, z. B.
     * „62 × 120 mm, QL-810W, rot/schwarz“.
     */
    public function description(): string
    {
        return $this->widthMm . ' × ' . $this->lengthMm . ' mm, ' . $this->model
            . ($this->red ? ', rot/schwarz' : '');
    }
}
