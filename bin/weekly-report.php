<?php

/*
|--------------------------------------------------------------------------
| SanLager – Wochenbericht per E-Mail
|--------------------------------------------------------------------------
|
| Verschickt an die in der .env eingetragenen Empfänger:
|   - abgelaufenes Material und MHDs, die bald ablaufen
|   - Artikel unter Mindestbestand (je Lagerort, mit Fehlmenge)
|   - Entnahmen der vergangenen sieben Tage
|
| Gedacht für einen wöchentlichen Cron-Job, z. B. montags um 7 Uhr:
|
|   0 7 * * 1  cd /pfad/zu/sanlager && php bin/weekly-report.php
|
| Aufruf:
|   php bin/weekly-report.php              Bericht erzeugen und versenden
|   php bin/weekly-report.php --dry-run    nur anzeigen, nichts versenden
|   php bin/weekly-report.php --to=a@b.de  an diese Adresse statt an
|                                          REPORT_RECIPIENTS senden (Test)
|
| Konfiguration ausschließlich über .env (siehe .env.example und
| docs/12-wochenbericht.md). Liegt außerhalb von public/ und ist daher
| nicht über den Webserver aufrufbar.
|--------------------------------------------------------------------------
*/

use LagerApp\ReportConfig;
use LagerApp\StockRepository;
use LagerApp\WeeklyReport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur über die Kommandozeile aufrufbar.');
}

$options = getopt('', ['dry-run', 'to:', 'help']);

if (isset($options['help'])) {
    echo "Aufruf: php bin/weekly-report.php [--dry-run] [--to=adresse]\n";
    exit(0);
}

$dryRun = isset($options['dry-run']);

try {
    $db = require __DIR__ . '/../bootstrap.php';

    $env = $_ENV;

    /*
     * Für --dry-run werden keine Maildaten benötigt; fehlende Werte
     * sollen die Vorschau nicht verhindern.
     */
    if ($dryRun) {
        $env += [
            'MAILER_DSN' => 'null://null',
            'REPORT_FROM' => 'vorschau@example.org',
            'REPORT_RECIPIENTS' => 'vorschau@example.org',
        ];
    }

    $config = ReportConfig::fromEnv($env);

    $report = new WeeklyReport(new StockRepository($db), $config->expiryDays);
    $data = $report->build();

    if ($dryRun) {
        echo $report->renderText($data, $config->appUrl);
        exit(0);
    }

    $email = $report->createEmail($config, $data);

    if (isset($options['to'])) {
        $email->to(Address::create((string) $options['to']));
    }

    (new Mailer(Transport::fromDsn($config->mailerDsn)))->send($email);

    printf(
        "[%s] Wochenbericht an %s versendet: %s\n",
        date('Y-m-d H:i'),
        implode(', ', array_map(static fn (Address $a): string => $a->getAddress(), $email->getTo())),
        $email->getSubject()
    );

    exit(0);
} catch (Throwable $exception) {
    /*
     * Ausgabe auf STDERR mit Exit-Code 1: Cron meldet das (je nach
     * Einrichtung per Mail an den Server-Admin bzw. im Log).
     */
    fwrite(STDERR, '[' . date('Y-m-d H:i') . '] Wochenbericht fehlgeschlagen: ' . $exception->getMessage() . "\n");
    exit(1);
}
