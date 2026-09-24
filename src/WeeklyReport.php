<?php

namespace LagerApp;

use DateTimeImmutable;
use Symfony\Component\Mime\Email;

/**
 * Wochenbericht für den Lagerverantwortlichen:
 *
 * 1. Material, dessen MHD abgelaufen ist oder in den nächsten
 *    `$expiryDays` Tagen abläuft (je Lagerort),
 * 2. Artikel unter Mindestbestand (je überwachtem Lagerort, mit Fehlmenge),
 * 3. Entnahmen (Ausbuchungen) der vergangenen sieben Tage.
 *
 * Wird von bin/weekly-report.php per Cron erzeugt und verschickt. Die
 * Klasse selbst versendet nichts, sondern liefert Daten, Texte und die
 * fertige E-Mail – dadurch lässt sie sich testen und per --dry-run
 * ansehen.
 */
final class WeeklyReport
{
    public function __construct(
        private StockReports $reports,
        private int $expiryDays = 90
    ) {
    }

    /**
     * Sammelt alle Daten des Berichts. Der Entnahme-Zeitraum sind die
     * sieben vollen Tage vor $periodEnd (Standard: heute 0 Uhr, bei
     * einem Lauf am Montag also Montag bis Sonntag der Vorwoche).
     */
    public function build(?DateTimeImmutable $periodEnd = null): array
    {
        $periodEnd ??= new DateTimeImmutable('today');
        $periodStart = $periodEnd->modify('-7 days');

        $today = date('Y-m-d');
        $expired = [];
        $expiring = [];

        foreach ($this->reports->getExpiringBatches($this->expiryDays) as $row) {
            if ($row['expiry_date'] < $today) {
                $expired[] = $row;
            } else {
                $expiring[] = $row;
            }
        }

        $issues = $this->reports->getIssuesBetween($periodStart, $periodEnd);

        return [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'expiry_days' => $this->expiryDays,
            'expired' => $expired,
            'expiring' => $expiring,
            'low_stock' => $this->reports->getLowStockItems(),
            'issues' => $issues,
            // Je Einheit ("12 Stück · 12 Paar · 1 Rolle"), leer ohne Entnahmen.
            'issue_summary' => quantitiesByUnit($issues),
        ];
    }

    /**
     * "Entnahmen 18.09.2026 – 24.09.2026 (12 Stück · 12 Paar · 1 Rolle)".
     */
    private function issuesTitle(array $report): string
    {
        return sprintf(
            'Entnahmen %s – %s',
            $report['period_start']->format('d.m.Y'),
            $report['period_end']->modify('-1 day')->format('d.m.Y')
        ) . ($report['issue_summary'] !== '' ? ' (' . $report['issue_summary'] . ')' : '');
    }

    /**
     * Betreff mit den wichtigsten Zahlen, damit schon die Posteingangs-
     * Übersicht zeigt, ob Handlungsbedarf besteht.
     */
    public function subject(array $report): string
    {
        $lastDay = $report['period_end']->modify('-1 day');

        $parts = [];

        if ($report['expired'] !== []) {
            $parts[] = count($report['expired']) . ' abgelaufen';
        }

        if ($report['expiring'] !== []) {
            $parts[] = count($report['expiring']) . ' MHD bald erreicht';
        }

        if ($report['low_stock'] !== []) {
            $parts[] = count($report['low_stock']) . ' unter Mindestbestand';
        }

        return sprintf(
            'SanLager Wochenbericht KW %s – %s',
            $lastDay->format('W/o'),
            $parts === [] ? 'keine Auffälligkeiten' : implode(', ', $parts)
        );
    }

    public function createEmail(ReportConfig $config, array $report): Email
    {
        return (new Email())
            ->from($config->from)
            ->to(...$config->recipients)
            ->subject($this->subject($report))
            ->text($this->renderText($report, $config->appUrl))
            ->html($this->renderHtml($report, $config->appUrl));
    }

    public function renderText(array $report, ?string $appUrl = null): string
    {
        $lines = [
            $this->subject($report),
            str_repeat('=', 60),
            '',
        ];

        $section = function (string $title, array $rows, callable $line, string $empty) use (&$lines): void {
            $lines[] = $title;
            $lines[] = str_repeat('-', mb_strlen($title));

            if ($rows === []) {
                $lines[] = $empty;
            }

            foreach ($rows as $row) {
                $lines[] = '- ' . $line($row);
            }

            $lines[] = '';
        };

        $batchLine = static fn (array $row): string => sprintf(
            '%s: %s %s – %s (%s)',
            formatDate($row['expiry_date']),
            quantityText((int) $row['quantity'], $row),
            $row['article_name'],
            $row['location_name'],
            $row['article_number'] ?? ''
        );

        $section('Abgelaufenes Material', $report['expired'], $batchLine, 'Keins.');

        $section(
            'MHD läuft in den nächsten ' . $report['expiry_days'] . ' Tagen ab',
            $report['expiring'],
            $batchLine,
            'Keins.'
        );

        $section(
            'Unter Mindestbestand (abgelaufenes Material zählt nicht)',
            $report['low_stock'],
            static fn (array $row): string => sprintf(
                '%s: %s – %d von %s, fehlen %d',
                $row['location_name'],
                $row['article_name'],
                $row['usable_quantity'],
                quantityText((int) $row['minimum_stock'], $row),
                $row['missing_quantity']
            ),
            'Alle Mindestbestände sind erfüllt.'
        );

        /*
         * Direkt zur Auffüllliste (je Lagerort, mit Bestand im Hauptlager).
         */
        if ($appUrl !== null && $report['low_stock'] !== []) {
            array_splice($lines, -1, 0, ['Auffüllliste: ' . $appUrl . '/?page=restock']);
        }

        $section(
            $this->issuesTitle($report),
            $report['issues'],
            static fn (array $row): string => sprintf(
                '%s %s – %s',
                quantityText((int) $row['quantity'], $row),
                $row['article_name'],
                $row['location_name']
            ),
            'Keine Entnahmen.'
        );

        if ($appUrl !== null) {
            $lines[] = 'SanLager öffnen: ' . $appUrl . '/?page=expiry';
        }

        return implode("\n", $lines) . "\n";
    }

    public function renderHtml(array $report, ?string $appUrl = null): string
    {
        $articleName = static function (array $row) use ($appUrl): string {
            $name = h($row['article_name']);

            return $appUrl === null
                ? $name
                : '<a href="' . h($appUrl . '/?page=article&id=' . (int) $row['article_id'])
                    . '" style="color:#b91c1c;text-decoration:none">' . $name . '</a>';
        };

        $table = static function (array $headers, array $rows, string $empty): string {
            if ($rows === []) {
                return '<p style="color:#475569;margin:4px 0 16px">' . h($empty) . '</p>';
            }

            $html = '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;margin-bottom:16px;font-size:14px">'
                . '<tr style="background:#f1f5f9;text-align:left">';

            foreach ($headers as $header) {
                $html .= '<th style="border-bottom:1px solid #cbd5e1">' . h($header) . '</th>';
            }

            $html .= '</tr>';

            foreach ($rows as $cells) {
                $html .= '<tr>';

                foreach ($cells as $cell) {
                    $html .= '<td style="border-bottom:1px solid #e2e8f0;vertical-align:top">' . $cell . '</td>';
                }

                $html .= '</tr>';
            }

            return $html . '</table>';
        };

        $batchRows = static fn (array $rows): array => array_map(
            static fn (array $row): array => [
                h(formatDate($row['expiry_date'])),
                $articleName($row),
                h($row['location_name']),
                h(quantityText((int) $row['quantity'], $row)),
            ],
            $rows
        );

        $heading = static fn (string $text, string $color): string =>
            '<h2 style="font-size:17px;color:' . $color . ';margin:24px 0 6px">' . h($text) . '</h2>';

        $html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"></head>'
            . '<body style="font-family:Arial,Helvetica,sans-serif;color:#0f172a;max-width:760px;margin:0 auto;padding:16px">'
            . '<h1 style="font-size:20px;margin:0 0 4px">' . h($this->subject($report)) . '</h1>'
            . '<p style="color:#475569;margin:0 0 8px">Erstellt am ' . date('d.m.Y H:i') . ' Uhr</p>';

        $html .= $heading('Abgelaufenes Material', '#b91c1c')
            . $table(['MHD', 'Artikel', 'Lagerort', 'Menge'], $batchRows($report['expired']), 'Keins.');

        $html .= $heading('MHD läuft in den nächsten ' . $report['expiry_days'] . ' Tagen ab', '#b45309')
            . $table(['MHD', 'Artikel', 'Lagerort', 'Menge'], $batchRows($report['expiring']), 'Keins.');

        $html .= $heading('Unter Mindestbestand', '#b45309')
            . $table(
                ['Lagerort', 'Artikel', 'Bestand', 'Minimum', 'Fehlt'],
                array_map(
                    static fn (array $row): array => [
                        h($row['location_name']),
                        $articleName($row),
                        (int) $row['usable_quantity'],
                        (int) $row['minimum_stock'],
                        '<strong>' . h(quantityText((int) $row['missing_quantity'], $row)) . '</strong>',
                    ],
                    $report['low_stock']
                ),
                'Alle Mindestbestände sind erfüllt.'
            );

        if ($appUrl !== null && $report['low_stock'] !== []) {
            $html .= '<p style="margin:4px 0 16px"><a href="' . h($appUrl . '/?page=restock')
                . '" style="color:#b91c1c">Zur Auffüllliste</a></p>';
        }

        $html .= $heading(
            $this->issuesTitle($report),
            '#0f172a'
        )
            . $table(
                ['Artikel', 'Lagerort', 'Menge'],
                array_map(
                    static fn (array $row): array => [
                        $articleName($row),
                        h($row['location_name']),
                        h(quantityText((int) $row['quantity'], $row)),
                    ],
                    $report['issues']
                ),
                'Keine Entnahmen.'
            );

        $html .= '<p style="color:#64748b;font-size:12px;margin-top:24px">'
            . 'Abgelaufenes Material zählt nicht zum Mindestbestand. '
            . 'Diese E-Mail wurde automatisch von SanLager erzeugt.'
            . ($appUrl !== null ? ' <a href="' . h($appUrl . '/?page=expiry') . '">SanLager öffnen</a>' : '')
            . '</p></body></html>';

        return $html;
    }
}
