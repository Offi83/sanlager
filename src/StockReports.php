<?php

namespace LagerApp;

use PDO;

/**
 * Auswertungen über die Lagerbewegungen – nur lesend:
 *
 * - "Heute": Ausbuchungen, Entsorgungen, Umbuchungen, Einlagerungen des Tages
 * - MHD-Übersicht: abgelaufene und bald ablaufende Chargen
 * - Wochenbericht: Entnahmen eines Zeitraums, Unterschreitung der
 *   Mindestbestände
 * - Auffüll-Liste: Unterschreitung der Mindestbestände je Lagerort
 *
 * Buchen und Bestandsermittlung liegen in StockRepository.
 */
class StockReports
{
    use LocalDay;

    public function __construct(
        private PDO $db
    ) {
    }

    /**
     * Heute erfolgte Ausbuchungen (`issue`), gruppiert nach
     * Artikel/Charge/Lagerort. Umbuchungen (`transfer_out`/`transfer_in`)
     * und Entsorgungen (`disposal`) zählen bewusst nicht dazu, da dabei
     * kein Material verbraucht wird. Rückgängig gemachte Ausbuchungen
     * (`issue_reversal`, siehe reverseTodayIssue()) werden abgezogen;
     * vollständig zurückgenommene Zeilen entfallen.
     */
    public function getTodayIssues(): array
    {
        return $this->todayBookings(['issue', 'issue_reversal'], -1);
    }

    /**
     * Heutige Entsorgungen (`disposal`) je Artikel/Charge/Lagerort,
     * abzüglich Rücknahmen (`disposal_reversal`).
     */
    public function getTodayDisposals(): array
    {
        return $this->todayBookings(['disposal', 'disposal_reversal'], -1);
    }

    /**
     * Heutige Einlagerungen (`receipt`) je Artikel/Charge/Lagerort,
     * abzüglich Rücknahmen (`receipt_reversal`).
     */
    public function getTodayReceipts(): array
    {
        return $this->todayBookings(['receipt', 'receipt_reversal'], 1);
    }

    /**
     * Heutige Buchungen der angegebenen Typen, netto je Artikel/Charge/
     * Lagerort. $sign = -1 für Abgänge (Menge wird positiv geliefert),
     * 1 für Zugänge; Zeilen mit netto nichts entfallen.
     *
     * @param string[] $types Buchung und ihre Rücknahme
     */
    private function todayBookings(array $types, int $sign): array
    {
        $placeholders = implode(', ', array_fill(0, count($types), '?'));

        $statement = $this->db->prepare(
            'SELECT
                a.id AS article_id,
                sm.batch_id,
                sl.id AS location_id,
                a.name AS article_name,
                a.article_number,
                COALESCE(u.name, \'Stück\') AS unit,
                COALESCE(u.plural, u.name, \'Stück\') AS unit_plural,
                a.has_expiry,
                b.expiry_date,
                sl.name AS location_name,
                ? * SUM(sm.quantity) AS quantity
             FROM stock_movements sm
             INNER JOIN articles a
                ON a.id = sm.article_id
             LEFT JOIN units u
                ON u.id = a.unit_id
             LEFT JOIN batches b
                ON b.id = sm.batch_id
             INNER JOIN storage_locations sl
                ON sl.id = sm.location_id
             WHERE sm.movement_type IN (' . $placeholders . ')
             AND sm.created_at >= ?
             AND sm.created_at < ?
             GROUP BY
                sm.article_id,
                sm.batch_id,
                sm.location_id
             HAVING ? * SUM(sm.quantity) > 0
             ORDER BY
                a.name COLLATE NOCASE,
                CASE
                    WHEN b.expiry_date IS NULL THEN 1
                    ELSE 0
                END,
                b.expiry_date'
        );

        [$dayStart, $dayEnd] = array_values($this->todayUtcRange());

        $statement->execute([$sign, ...$types, $dayStart, $dayEnd, $sign]);

        return $statement->fetchAll();
    }

    /**
     * Heutige Umbuchungen je Artikel/Charge und Richtung (von → nach),
     * abzüglich Rücknahmen – nach Richtung sortiert (Lagerort-Reihenfolge),
     * damit "Heute" sie je Richtung gruppieren kann.
     *
     * Eine Umbuchung besteht aus zwei Bewegungen (Abgang und Zugang) mit
     * derselben transfer_id, siehe transferPair(). Eine
     * Rücknahme (`transfer_reversal_out` am ursprünglichen Ziel,
     * `transfer_reversal_in` an der ursprünglichen Quelle) wird der
     * ursprünglichen Richtung zugerechnet und abgezogen – eine echte
     * Rück-Umbuchung (z. B. Rucksack nach dem Dienst zurück ins Lager)
     * dagegen bleibt als eigene Zeile stehen.
     */
    public function getTodayTransfers(): array
    {
        $statement = $this->db->prepare(
            'SELECT
                t.article_id,
                t.batch_id,
                t.from_location_id,
                t.to_location_id,
                a.name AS article_name,
                a.article_number,
                COALESCE(u.name, \'Stück\') AS unit,
                COALESCE(u.plural, u.name, \'Stück\') AS unit_plural,
                a.has_expiry,
                b.expiry_date,
                src.name AS from_location_name,
                dst.name AS to_location_name,
                SUM(t.quantity) AS quantity
             FROM (
                SELECT
                    o.article_id,
                    o.batch_id,
                    CASE WHEN o.movement_type = \'transfer_out\'
                        THEN o.location_id ELSE i.location_id END AS from_location_id,
                    CASE WHEN o.movement_type = \'transfer_out\'
                        THEN i.location_id ELSE o.location_id END AS to_location_id,
                    CASE WHEN o.movement_type = \'transfer_out\'
                        THEN -o.quantity ELSE o.quantity END AS quantity
                FROM stock_movements o
                INNER JOIN stock_movements i
                    ON i.transfer_id = o.transfer_id
                    AND i.id <> o.id
                    AND (
                        (o.movement_type = \'transfer_out\' AND i.movement_type = \'transfer_in\')
                        OR (o.movement_type = \'transfer_reversal_out\' AND i.movement_type = \'transfer_reversal_in\')
                    )
                WHERE o.created_at >= :day_start
                AND o.created_at < :day_end
             ) t
             INNER JOIN articles a
                ON a.id = t.article_id
             LEFT JOIN units u
                ON u.id = a.unit_id
             LEFT JOIN batches b
                ON b.id = t.batch_id
             INNER JOIN storage_locations src
                ON src.id = t.from_location_id
             INNER JOIN storage_locations dst
                ON dst.id = t.to_location_id
             GROUP BY
                t.article_id,
                t.batch_id,
                t.from_location_id,
                t.to_location_id
             HAVING SUM(t.quantity) > 0
             ORDER BY
                src.sort_order,
                src.name COLLATE NOCASE,
                dst.sort_order,
                dst.name COLLATE NOCASE,
                a.name COLLATE NOCASE,
                b.expiry_date'
        );

        $statement->execute($this->todayUtcRange());

        return $statement->fetchAll();
    }

    /**
     * Ausbuchungen (`issue`) im Zeitraum [$from, $to), je Artikel und
     * Lagerort zusammengefasst, meistentnommene zuerst. Wie bei
     * getTodayIssues() zählen Umbuchungen und Entsorgungen nicht dazu,
     * Rückbuchungen werden abgezogen.
     */
    public function getIssuesBetween(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        [$start, $end] = $this->utcRange($from, $to);

        $statement = $this->db->prepare(
            'SELECT
                a.id AS article_id,
                a.name AS article_name,
                a.article_number,
                COALESCE(u.name, \'Stück\') AS unit,
                COALESCE(u.plural, u.name, \'Stück\') AS unit_plural,
                sl.name AS location_name,
                ABS(SUM(sm.quantity)) AS quantity
             FROM stock_movements sm
             INNER JOIN articles a
                ON a.id = sm.article_id
             LEFT JOIN units u
                ON u.id = a.unit_id
             INNER JOIN storage_locations sl
                ON sl.id = sm.location_id
             WHERE sm.movement_type IN (\'issue\', \'issue_reversal\')
             AND sm.created_at >= :period_start
             AND sm.created_at < :period_end
             GROUP BY
                a.id,
                sl.id
             HAVING SUM(sm.quantity) < 0
             ORDER BY
                ABS(SUM(sm.quantity)) DESC,
                a.name COLLATE NOCASE,
                sl.sort_order'
        );

        $statement->execute([
            'period_start' => $start,
            'period_end' => $end,
        ]);

        return $statement->fetchAll();
    }

    /**
     * Alle überwachten Artikel/Lagerort-Kombinationen (siehe
     * saveMinimums()), deren verwendbarer Bestand – ohne abgelaufene
     * Chargen – unter dem Mindestbestand liegt, inkl. Fehlmenge.
     * Nur aktive Artikel und Lagerorte, sortiert nach Lagerort und
     * Kategorie, damit sich daraus direkt eine Auffüll-Liste ergibt.
     */
    public function getLowStockItems(): array
    {
        $statement = $this->db->prepare(
            'SELECT
                a.id AS article_id,
                a.name AS article_name,
                a.article_number,
                COALESCE(u.name, \'Stück\') AS unit,
                COALESCE(u.plural, u.name, \'Stück\') AS unit_plural,
                sl.id AS location_id,
                sl.name AS location_name,
                c.name AS category_name,
                c.color AS category_color,
                alm.minimum_stock,
                COALESCE(SUM(
                    CASE
                        WHEN b.expiry_date IS NULL
                            OR b.expiry_date >= :today
                        THEN sm.quantity
                        ELSE 0
                    END
                ), 0) AS usable_quantity
             FROM article_location_minimums alm
             INNER JOIN articles a
                ON a.id = alm.article_id
             LEFT JOIN units u
                ON u.id = a.unit_id
             INNER JOIN storage_locations sl
                ON sl.id = alm.location_id
             LEFT JOIN article_categories c
                ON c.id = a.category_id
             LEFT JOIN stock_movements sm
                ON sm.article_id = alm.article_id
                AND sm.location_id = alm.location_id
             LEFT JOIN batches b
                ON b.id = sm.batch_id
             WHERE a.active = 1
             AND sl.active = 1
             GROUP BY alm.id
             HAVING usable_quantity < alm.minimum_stock
             ORDER BY
                sl.sort_order,
                sl.name COLLATE NOCASE,
                COALESCE(c.sort_order, 9999),
                a.name COLLATE NOCASE'
        );

        $statement->execute([
            'today' => $this->today()
        ]);

        return array_map(
            static fn (array $row): array => $row + [
                'missing_quantity' => (int) $row['minimum_stock']
                    - (int) $row['usable_quantity'],
            ],
            $statement->fetchAll()
        );
    }

    /**
     * Verwendbarer Bestand (ohne abgelaufene Chargen) je Artikel an einem
     * Lagerort, als [article_id => Menge]. Artikel ohne verwendbaren
     * Bestand fehlen. Für die Auffüll-Liste: Wie viel davon ist im
     * Standard-Lagerort vorhanden, um einen Rucksack aufzufüllen?
     */
    public function getUsableQuantitiesAtLocation(int $locationId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                sm.article_id,
                SUM(sm.quantity) AS quantity
             FROM stock_movements sm
             LEFT JOIN batches b
                ON b.id = sm.batch_id
             WHERE sm.location_id = :location_id
             AND (b.expiry_date IS NULL OR b.expiry_date >= :today)
             GROUP BY sm.article_id
             HAVING SUM(sm.quantity) > 0'
        );

        $statement->execute([
            'location_id' => $locationId,
            'today' => $this->today(),
        ]);

        return array_map(
            'intval',
            array_column($statement->fetchAll(), 'quantity', 'article_id')
        );
    }

    /**
     * Anzahl der abgelaufenen Chargen mit Bestand (je Artikel, Charge und
     * Lagerort gezählt, wie die Zeilen in getExpiringBatches()) – für die
     * Zahl am Menüpunkt "Kontrolle".
     */
    public function countExpiredBatches(): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM (
                SELECT 1
                FROM stock_movements sm
                INNER JOIN articles a
                    ON a.id = sm.article_id
                INNER JOIN batches b
                    ON b.id = sm.batch_id
                INNER JOIN storage_locations sl
                    ON sl.id = sm.location_id
                WHERE a.active = 1
                AND sl.active = 1
                AND b.expiry_date < :today
                GROUP BY
                    sm.article_id,
                    sm.location_id,
                    sm.batch_id
                HAVING SUM(sm.quantity) > 0
            )'
        );

        $statement->execute([
            'today' => $this->today(),
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Liefert alle Chargen (über alle Artikel und Lagerorte hinweg),
     * deren MHD bereits abgelaufen ist oder innerhalb der nächsten
     * `$withinDays` Tage abläuft – für die globale MHD-Übersicht.
     *
     * Älteste MHD zuerst, damit die dringendsten Fälle oben stehen
     * (Wochenbericht); die MHD-Übersicht sortiert selbst nach Lagerort und
     * Kategorie um.
     */
    public function getExpiringBatches(int $withinDays = 90): array
    {
        $statement = $this->db->prepare(
            'SELECT
                a.id AS article_id,
                a.name AS article_name,
                a.article_number,
                COALESCE(u.name, \'Stück\') AS unit,
                COALESCE(u.plural, u.name, \'Stück\') AS unit_plural,
                sl.id AS location_id,
                sl.name AS location_name,
                sl.sort_order AS location_sort_order,
                c.name AS category_name,
                c.color AS category_color,
                c.sort_order AS category_sort_order,
                b.id AS batch_id,
                b.expiry_date,
                SUM(sm.quantity) AS quantity
             FROM stock_movements sm
             INNER JOIN articles a
                ON a.id = sm.article_id
             LEFT JOIN units u
                ON u.id = a.unit_id
             INNER JOIN batches b
                ON b.id = sm.batch_id
             INNER JOIN storage_locations sl
                ON sl.id = sm.location_id
             LEFT JOIN article_categories c
                ON c.id = a.category_id
             WHERE a.active = 1
             AND sl.active = 1
             AND b.expiry_date <= :until
             GROUP BY
                a.id,
                sl.id,
                b.id
             HAVING SUM(sm.quantity) > 0
             ORDER BY
                b.expiry_date,
                a.name COLLATE NOCASE'
        );

        $statement->execute([
            'until' => date('Y-m-d', strtotime('+' . $withinDays . ' days'))
        ]);

        return $statement->fetchAll();
    }
}
