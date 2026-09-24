<?php

namespace LagerApp;

/**
 * "Heute" und Zeiträume für Datenbankabfragen, gemeinsam genutzt von
 * StockRepository und StockReports.
 *
 * Maßgeblich ist die Zeitzone der Anwendung (APP_TIMEZONE), nicht UTC:
 * created_at wird in UTC gespeichert, "heute" meint aber den lokalen Tag.
 */
trait LocalDay
{
    /**
     * Heutiges Datum (Y-m-d) in der Zeitzone der Anwendung.
     *
     * Wird bewusst in PHP statt per `date('now')` in SQLite ermittelt:
     * SQLite rechnet in UTC, die MHD-Anzeige (expiryInfo()) aber in der
     * PHP-Zeitzone. So gelten beide Seiten rund um Mitternacht
     * denselben Tag als "heute".
     */
    private function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * Beginn und Ende des heutigen (lokalen) Tages als UTC-Zeitstempel,
     * siehe utcRange().
     *
     * @return array{day_start: string, day_end: string}
     */
    private function todayUtcRange(): array
    {
        $start = new \DateTimeImmutable('today');
        [$dayStart, $dayEnd] = $this->utcRange($start, $start->modify('+1 day'));

        return [
            'day_start' => $dayStart,
            'day_end' => $dayEnd,
        ];
    }

    /**
     * Übersetzt einen Zeitraum in lokaler Zeit in UTC-Zeitstempel im
     * Format von `created_at`.
     *
     * `created_at` wird per CURRENT_TIMESTAMP in UTC gespeichert. Statt
     * jede Zeile per date(..., 'localtime') umzurechnen, wird der
     * Zeitraum einmal übersetzt – das nutzt zudem den Index auf
     * `created_at`.
     *
     * @return array{0: string, 1: string}
     */
    private function utcRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $utc = new \DateTimeZone('UTC');

        return [
            $from->setTimezone($utc)->format('Y-m-d H:i:s'),
            $to->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }
}
