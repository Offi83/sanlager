<?php

namespace LagerApp;

use Closure;
use RuntimeException;

/**
 * Schickt Etiketten über brother_ql (Paket brother-ql-next) an einen
 * Brother-Etikettendrucker der QL-Serie – ohne Druckertreiber und CUPS.
 *
 * Je Etikett wird ein Bild erzeugt (LabelImage) und brother_ql als
 * eigener Prozess aufgerufen, in Aufträgen zu höchstens zehn Etiketten
 * (so bleibt jeder Aufruf kurz, auch auf dem Raspberry Pi).
 *
 * Rückmeldung: Per USB meldet der Drucker, ob gedruckt wurde, und Fehler
 * wie „keine Rolle“ oder „Deckel offen“. Über WLAN/Netzwerk kann
 * brother_ql den Status nicht lesen – dann ist nur sicher, dass die Daten
 * angekommen sind.
 */
final class LabelPrinter
{
    /**
     * Exit-Code von run(), wenn brother_ql nicht rechtzeitig fertig wird
     * (wie beim Kommando timeout).
     */
    public const TIMEOUT_EXIT_CODE = 124;

    private const JOB_SIZE = 10;
    private const TIMEOUT_SECONDS = 60;

    /**
     * Fehler, die der Drucker per USB meldet (brother_ql/reader.py),
     * nach Textanfang.
     */
    private const PRINTER_ERRORS = [
        'No media when printing' => 'Keine Rolle eingelegt',
        'End of media' => 'Rolle zu Ende',
        'Tape cutter jam' => 'Abschneider klemmt',
        'Printer turned off' => 'Drucker ausgeschaltet',
        'Replace media error' => 'Falsche Rolle eingelegt – sie muss zu LABEL_WIDTH_MM und LABEL_RED passen (Rot/Schwarz nur mit DK-22251)',
        'Expansion buffer full error' => 'Druckerspeicher voll',
        'Transmission / Communication error' => 'Übertragungsfehler',
        'Cover opened while printing' => 'Deckel offen',
        'Media cannot be fed' => 'Rolle wird nicht eingezogen (leer oder verklemmt)',
        'System error' => 'Systemfehler am Drucker',
    ];

    private Closure $runner;
    private Closure $isExecutable;

    /**
     * @param Closure|null $runner       nur für Tests: ersetzt run()
     * @param Closure|null $isExecutable nur für Tests: ersetzt is_executable()
     */
    public function __construct(
        private LabelConfig $config,
        ?Closure $runner = null,
        ?Closure $isExecutable = null
    ) {
        $this->runner = $runner ?? self::run(...);
        $this->isExecutable = $isExecutable ?? is_executable(...);
    }

    /**
     * Druckt je Eintrag ein Etikett (gleiche Artikel mehrfach für mehrere
     * Etiketten).
     *
     * @param array<int, array{name: string, article_number?: ?string, category_name?: ?string}> $articles
     * @return bool true, wenn der Drucker den Druck bestätigt hat (nur USB)
     * @throws RuntimeException mit verständlicher Meldung
     */
    public function print(array $articles): bool
    {
        if ($articles === []) {
            return true;
        }

        if (!($this->isExecutable)($this->config->brotherQl)) {
            throw new RuntimeException(
                'Das Programm brother_ql wurde nicht gefunden: ' . $this->config->brotherQl
                . '. Bitte BROTHER_QL in der .env prüfen (siehe Installationsanleitung, Abschnitt Etikettendrucker).'
            );
        }

        $files = [];
        $confirmed = true;

        try {
            $image = new LabelImage($this->config);
            $labels = [];

            // Gleiche Artikel nur einmal zeichnen.
            foreach ($articles as $article) {
                $key = serialize([$article['name'], $article['article_number'] ?? null, $article['category_name'] ?? null]);

                if (!isset($files[$key])) {
                    $files[$key] = self::tempFile($image->png($article));
                }

                $labels[] = $files[$key];
            }

            foreach (array_chunk($labels, self::JOB_SIZE) as $job) {
                // Jeder Auftrag bekommt wieder Zeit (Apache bricht sonst nach 30 s ab).
                @set_time_limit(self::TIMEOUT_SECONDS + 30);

                $result = ($this->runner)([...$this->command(), ...$job], self::TIMEOUT_SECONDS);

                $confirmed = $this->check($result['exitCode'], $result['output']) && $confirmed;
            }
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        return $confirmed;
    }

    /**
     * Aufruf ohne Bilddateien, z. B.
     * brother_ql -b network -m QL-810W -p tcp://… print -l 62red -r 90 --red
     *
     * -r 90: Das Bild liegt quer wie auf der Box, der Drucker braucht es
     * längs zur Rolle.
     *
     * @return array<int, string>
     */
    private function command(): array
    {
        return [
            $this->config->brotherQl,
            '-b', $this->config->backend(),
            '-m', $this->config->model,
            '-p', $this->config->printerAddress,
            'print',
            '-l', $this->config->labelIdentifier(),
            '-r', '90',
            ...($this->config->red ? ['--red'] : []),
        ];
    }

    /**
     * Wertet Exit-Code und Ausgabe von brother_ql aus.
     *
     * @return bool true, wenn der Drucker den Druck bestätigt hat
     * @throws RuntimeException bei Fehlern
     */
    private function check(int $exitCode, string $output): bool
    {
        if ($exitCode !== 0 || str_contains($output, 'Errors occured') || str_contains($output, 'potentially not successful')) {
            error_log('SanLager: brother_ql (Exit-Code ' . $exitCode . "):\n" . $output);
        }

        $device = substr($this->config->printerAddress, strlen('file://'));

        if ($exitCode === self::TIMEOUT_EXIT_CODE) {
            throw new RuntimeException(
                'Der Etikettendrucker antwortet nicht (Zeitüberschreitung). Ist er eingeschaltet und erreichbar?'
            );
        }

        if ($exitCode !== 0) {
            if (str_contains($output, 'Permission denied')) {
                throw new RuntimeException(
                    'Keine Berechtigung für den Etikettendrucker (' . $device . '). Den Webserver-Benutzer '
                    . 'in die Gruppe lp aufnehmen (sudo usermod -aG lp www-data) und Apache neu starten.'
                );
            }

            if ($this->config->backend() === 'linux_kernel' && str_contains($output, 'No such file or directory')) {
                throw new RuntimeException(
                    'Etikettendrucker nicht gefunden (' . $device . '): nicht angeschlossen oder ausgeschaltet?'
                );
            }

            if ($this->config->backend() === 'network') {
                throw new RuntimeException(
                    'Etikettendrucker nicht erreichbar (' . substr($this->config->printerAddress, strlen('tcp://'))
                    . '). Ist er eingeschaltet und im WLAN?'
                );
            }

            throw new RuntimeException('Der Etikettendrucker meldet einen Fehler: ' . self::lastLine($output));
        }

        if (preg_match('/Errors occured: \[(.*)\]/', $output, $match)) {
            preg_match_all("/'([^']*)'|\"([^\"]*)\"/", $match[1], $errors);

            $messages = array_map(
                static function (string $error): string {
                    foreach (self::PRINTER_ERRORS as $prefix => $message) {
                        if (str_starts_with($error, $prefix)) {
                            return $message;
                        }
                    }

                    return $error;
                },
                array_filter(array_merge($errors[1], $errors[2]))
            );

            throw new RuntimeException('Der Etikettendrucker meldet: ' . implode(', ', $messages) . '.');
        }

        if (str_contains($output, 'potentially not successful')) {
            throw new RuntimeException(
                'Der Etikettendrucker hat den Druck nicht bestätigt. Bitte am Gerät prüfen, ob die Etiketten gedruckt wurden.'
            );
        }

        return str_contains($output, 'Printing was successful');
    }

    private static function lastLine(string $output): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $output)));

        return $lines === [] ? 'unbekannt' : end($lines);
    }

    private static function tempFile(string $png): string
    {
        $base = tempnam(sys_get_temp_dir(), 'sanlager-label-');
        $file = $base . '.png';

        @unlink($base);
        file_put_contents($file, $png);

        return $file;
    }

    /**
     * Startet $command (ohne Shell) und liefert Exit-Code und die
     * gemeinsame Ausgabe (stdout und stderr). Nach $timeout Sekunden wird
     * der Prozess beendet (Exit-Code TIMEOUT_EXIT_CODE).
     *
     * @param array<int, string> $command
     * @return array{exitCode: int, output: string}
     */
    public static function run(array $command, int $timeout): array
    {
        $process = @proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes
        );

        if (!is_resource($process)) {
            return ['exitCode' => 127, 'output' => 'Programm kann nicht gestartet werden: ' . $command[0]];
        }

        stream_set_blocking($pipes[1], false);

        $output = '';
        $deadline = microtime(true) + $timeout;

        while (!feof($pipes[1])) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                proc_close($process);

                return ['exitCode' => self::TIMEOUT_EXIT_CODE, 'output' => $output];
            }

            $read = [$pipes[1]];
            $write = $except = null;

            if (stream_select($read, $write, $except, 0, 200_000) > 0) {
                $output .= (string) fread($pipes[1], 8192);
            }
        }

        fclose($pipes[1]);

        return ['exitCode' => proc_close($process), 'output' => $output];
    }
}
