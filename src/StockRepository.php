<?php

namespace LagerApp;

use PDO;
use RuntimeException;

/**
 * Bestandsverwaltung anhand der Lagerbewegungen (Tabelle `stock_movements`).
 *
 * Es gibt keine eigene Bestandstabelle: Der Bestand eines Artikels an
 * einem Lagerort/einer Charge ergibt sich stets aus der Summe seiner
 * Bewegungen. Zugänge (`receipt`, `transfer_in`) werden als positive,
 * Abgänge (`issue`, `transfer_out`) als negative Menge gespeichert.
 */
class StockRepository
{
    public function __construct(
        private PDO $db
    ) {
    }

    /**
     * Liefert alle aktiven Lagerorte in ihrer festgelegten Reihenfolge,
     * z. B. für das Lagerort-Dropdown beim Bestand buchen.
     */
    public function locations(): array
    {
        return $this->db
            ->query(
                'SELECT *
                 FROM storage_locations
                 WHERE active = 1
                 ORDER BY sort_order, name COLLATE NOCASE'
            )
            ->fetchAll();
    }

    /**
     * Prüft, ob an einem Lagerort noch (irgendein) positiver Bestand
     * vorhanden ist. Dient als Schutz davor, einen noch benutzten
     * Lagerort zu deaktivieren.
     */
    public function locationHasStock(int $locationId): bool
    {
        $statement = $this->db->prepare(
            'SELECT article_id
             FROM stock_movements
             WHERE location_id = :location_id
             GROUP BY article_id
             HAVING SUM(quantity) > 0
             LIMIT 1'
        );

        $statement->execute([
            'location_id' => $locationId
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Liefert den kompletten Bestand AN einem Lagerort, über alle
     * Artikel hinweg, je Charge/MHD aufgeschlüsselt. Beantwortet die
     * Frage "was liegt hier eigentlich?" – für die Lagerort-Detailseite.
     *
     * Sortiert wie die Artikelliste (Kategorie-Reihenfolge, dann Name),
     * damit sich beide Ansichten vertraut anfühlen.
     */
    public function getStockAtLocationDetailed(int $locationId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                a.id AS article_id,
                a.name AS article_name,
                a.article_number,
                a.unit,
                c.name AS category_name,
                c.color AS category_color,
                c.sort_order AS category_sort_order,
                sm.batch_id,
                b.expiry_date,
                SUM(sm.quantity) AS quantity
             FROM stock_movements sm
             INNER JOIN articles a
                ON a.id = sm.article_id
             LEFT JOIN article_categories c
                ON c.id = a.category_id
             LEFT JOIN batches b
                ON b.id = sm.batch_id
             WHERE sm.location_id = :location_id
             AND a.active = 1
             GROUP BY
                a.id,
                sm.batch_id,
                b.expiry_date
             HAVING SUM(sm.quantity) > 0
             ORDER BY
                COALESCE(c.sort_order, 9999),
                c.name COLLATE NOCASE,
                a.name COLLATE NOCASE,
                CASE
                    WHEN b.expiry_date IS NULL THEN 0
                    ELSE 1
                END,
                b.expiry_date'
        );

        $statement->execute([
            'location_id' => $locationId
        ]);

        return $statement->fetchAll();
    }

    /**
     * Bestand eines Artikels je Lagerort (inkl. Lagerorte ohne Bestand,
     * dort dann 0). `quantity` enthält auch bereits abgelaufene Chargen –
     * die Kennzeichnung "abgelaufen" erfolgt separat, siehe
     * getExpiredStock(). `usable_quantity` zählt abgelaufene Chargen
     * bewusst nicht mit (siehe getTotalStock()) und ist die Grundlage
     * für den Mindestbestand-Vergleich (`minimum_stock`, NULL wenn für
     * diesen Lagerort nicht überwacht, siehe saveMinimums()).
     */
    public function getStockForArticle(int $articleId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                sl.id AS location_id,
                sl.name AS location_name,
                COALESCE(SUM(sm.quantity), 0) AS quantity,
                COALESCE(SUM(
                    CASE
                        WHEN sm.batch_id IS NULL
                            OR b.expiry_date IS NULL
                            OR b.expiry_date >= date("now")
                        THEN sm.quantity
                        ELSE 0
                    END
                ), 0) AS usable_quantity,
                alm.minimum_stock AS minimum_stock
             FROM storage_locations sl
             LEFT JOIN stock_movements sm
                ON sm.location_id = sl.id
                AND sm.article_id = :article_id
             LEFT JOIN batches b
                ON b.id = sm.batch_id
             LEFT JOIN article_location_minimums alm
                ON alm.location_id = sl.id
                AND alm.article_id = :article_id
             WHERE sl.active = 1
             GROUP BY sl.id, sl.name, alm.minimum_stock
             ORDER BY sl.name COLLATE NOCASE'
        );

        $statement->execute([
            'article_id' => $articleId
        ]);

        return $statement->fetchAll();
    }

    /**
     * Speichert die Mindestbestände eines Artikels je Lagerort.
     *
     * @param array<int, int|null> $minimumsByLocationId Lagerort-ID =>
     *        Mindestbestand, oder null um die Überwachung für diesen
     *        Lagerort zu entfernen (nicht jeder Lagerort muss einen
     *        Mindestbestand haben).
     */
    public function saveMinimums(int $articleId, array $minimumsByLocationId): void
    {
        $this->db->beginTransaction();

        try {
            $delete = $this->db->prepare(
                'DELETE FROM article_location_minimums
                 WHERE article_id = :article_id
                 AND location_id = :location_id'
            );

            $upsert = $this->db->prepare(
                'INSERT INTO article_location_minimums
                    (article_id, location_id, minimum_stock)
                 VALUES
                    (:article_id, :location_id, :minimum_stock)
                 ON CONFLICT (article_id, location_id)
                 DO UPDATE SET minimum_stock = excluded.minimum_stock'
            );

            foreach ($minimumsByLocationId as $locationId => $minimumStock) {
                if ($minimumStock === null) {
                    $delete->execute([
                        'article_id' => $articleId,
                        'location_id' => $locationId,
                    ]);

                    continue;
                }

                $upsert->execute([
                    'article_id' => $articleId,
                    'location_id' => $locationId,
                    'minimum_stock' => $minimumStock,
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();

            throw $exception;
        }
    }

    /**
     * Prüft, ob ein Artikel an mindestens einem überwachten Lagerort
     * (siehe saveMinimums()) unter seinem dortigen Mindestbestand liegt.
     * Abgelaufene Chargen zählen dabei nicht als verfügbarer Bestand
     * (siehe getTotalStock()).
     */
    public function hasLowStockAtAnyLocation(int $articleId): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1
             FROM article_location_minimums alm
             LEFT JOIN stock_movements sm
                ON sm.article_id = alm.article_id
                AND sm.location_id = alm.location_id
             LEFT JOIN batches b
                ON b.id = sm.batch_id
             WHERE alm.article_id = :article_id
             GROUP BY alm.id, alm.minimum_stock
             HAVING COALESCE(SUM(
                CASE
                    WHEN sm.batch_id IS NULL
                        OR b.expiry_date IS NULL
                        OR b.expiry_date >= date("now")
                    THEN sm.quantity
                    ELSE 0
                END
             ), 0) < alm.minimum_stock
             LIMIT 1'
        );

        $statement->execute([
            'article_id' => $articleId
        ]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Bestand eines Artikels je Charge/MHD UND Lagerort (eine Zeile pro
     * Kombination mit positivem Bestand, "ohne MHD" eingeschlossen).
     *
     * Anders als getStockForArticle()/getStockByBatch(), die jeweils nur
     * eine Dimension zusammenfassen, beantwortet das die Frage "wo genau
     * liegt die Charge, die bald abläuft" – ohne das lässt sich eine
     * MHD-Warnung nicht in Handeln übersetzen.
     */
    public function getStockByBatchAndLocation(int $articleId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                sm.batch_id,
                b.expiry_date,
                sl.id AS location_id,
                sl.name AS location_name,
                SUM(sm.quantity) AS quantity
             FROM stock_movements sm
             LEFT JOIN batches b
                ON b.id = sm.batch_id
             INNER JOIN storage_locations sl
                ON sl.id = sm.location_id
             WHERE sm.article_id = :article_id
             AND sl.active = 1
             GROUP BY
                sm.batch_id,
                b.expiry_date,
                sl.id,
                sl.name
             HAVING SUM(sm.quantity) > 0
             ORDER BY
                CASE
                    WHEN b.expiry_date IS NULL THEN 0
                    ELSE 1
                END,
                b.expiry_date,
                sl.name COLLATE NOCASE'
        );

        $statement->execute([
            'article_id' => $articleId
        ]);

        return $statement->fetchAll();
    }

    /**
     * Bestand eines Artikels je Charge/MHD (über alle Lagerorte hinweg),
     * älteste MHD zuerst. Enthält auch Chargen mit 0 oder negativem
     * Bestand sowie bereits abgelaufene Chargen.
     *
     * Wird für die MHD-Auswahl im "Bestand buchen"-Formular verwendet;
     * für die ortsaufgelöste Anzeige siehe getStockByBatchAndLocation().
     */
    public function getStockByBatch(int $articleId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                b.id AS batch_id,
                b.expiry_date,
                COALESCE(SUM(sm.quantity), 0) AS quantity
             FROM batches b
             LEFT JOIN stock_movements sm
                ON sm.batch_id = b.id
             WHERE b.article_id = :article_id
             GROUP BY
                b.id,
                b.expiry_date
             ORDER BY
                CASE
                    WHEN b.expiry_date IS NULL THEN 1
                    ELSE 0
                END,
                b.expiry_date'
        );

        $statement->execute([
            'article_id' => $articleId
        ]);

        return $statement->fetchAll();
    }

    /**
     * Gesamtbestand eines Artikels über alle Lagerorte hinweg – ohne
     * bereits abgelaufene Chargen. Wird u. a. für den
     * Mindestbestand-Vergleich verwendet, damit abgelaufenes (nicht mehr
     * einsatzbereites) Material nicht als verfügbarer Bestand zählt.
     *
     * ACHTUNG: Dadurch kann diese Zahl kleiner sein als die Summe der
     * Werte aus getStockForArticle() bzw. getStockByBatch(), die
     * abgelaufene Chargen mitzählen (dort aber separat als "abgelaufen"
     * gekennzeichnet werden, siehe getExpiredStock()). Das ist so
     * beabsichtigt, kann auf der Artikelseite aber wie ein
     * Rechenfehler wirken, wenn beides nebeneinander angezeigt wird.
     */
    public function getTotalStock(int $articleId): int
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(sm.quantity), 0)
             FROM stock_movements sm
             LEFT JOIN batches b
                 ON b.id = sm.batch_id
             WHERE sm.article_id = :article_id
             AND (
                 sm.batch_id IS NULL
                 OR b.expiry_date IS NULL
                 OR b.expiry_date >= date("now")
             )'
        );

        $statement->execute([
            'article_id' => $articleId
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Heute erfolgte Ausbuchungen (`issue`), gruppiert nach
     * Artikel/Charge/Lagerort. Umbuchungen (`transfer_out`/`transfer_in`)
     * zählen bewusst nicht dazu, da dabei kein Material verbraucht wird.
     */
    public function getTodayIssues(): array
    {
        $statement = $this->db->query(
            'SELECT
                a.name AS article_name,
                a.article_number,
                a.unit,
                b.expiry_date,
                sl.name AS location_name,
                ABS(SUM(sm.quantity)) AS quantity
             FROM stock_movements sm
             INNER JOIN articles a
                ON a.id = sm.article_id
             LEFT JOIN batches b
                ON b.id = sm.batch_id
             INNER JOIN storage_locations sl
                ON sl.id = sm.location_id
             WHERE sm.movement_type = "issue"
             AND date(sm.created_at, "localtime")
                 = date("now", "localtime")
             GROUP BY
                sm.article_id,
                sm.batch_id,
                sm.location_id
             ORDER BY
                a.name COLLATE NOCASE,
                CASE
                    WHEN b.expiry_date IS NULL THEN 1
                    ELSE 0
                END,
                b.expiry_date'
        );

        return $statement->fetchAll();
    }

    /**
     * Anzahl heutiger Ausbuchungen, siehe getTodayIssues().
     */
    public function getTodayIssueCount(): int
    {
        $statement = $this->db->query(
            'SELECT COUNT(*)
             FROM stock_movements
             WHERE movement_type = "issue"
             AND date(created_at, "localtime")
                 = date("now", "localtime")'
        );

        return (int) $statement->fetchColumn();
    }

    /**
     * Bestand eines Artikels, dessen MHD bereits überschritten ist
     * (über alle Lagerorte hinweg). Dient der "X abgelaufen"-Anzeige
     * in der Artikelliste.
     */
    public function getExpiredStock(int $articleId): int
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(sm.quantity), 0)
             FROM stock_movements sm
             INNER JOIN batches b
                 ON b.id = sm.batch_id
             WHERE sm.article_id = :article_id
             AND b.expiry_date < date("now")'
        );

        $statement->execute([
            'article_id' => $articleId
        ]);

        return max(0, (int) $statement->fetchColumn());
    }

    /**
     * Liefert alle Chargen (über alle Artikel und Lagerorte hinweg),
     * deren MHD bereits abgelaufen ist oder innerhalb der nächsten
     * `$withinDays` Tage abläuft – für die globale MHD-Übersicht.
     *
     * Älteste MHD zuerst, damit die dringendsten Fälle oben stehen.
     */
    public function getExpiringBatches(int $withinDays = 90): array
    {
        $statement = $this->db->prepare(
            'SELECT
                a.id AS article_id,
                a.name AS article_name,
                a.article_number,
                a.unit,
                sl.id AS location_id,
                sl.name AS location_name,
                b.expiry_date,
                SUM(sm.quantity) AS quantity
             FROM stock_movements sm
             INNER JOIN articles a
                ON a.id = sm.article_id
             INNER JOIN batches b
                ON b.id = sm.batch_id
             INNER JOIN storage_locations sl
                ON sl.id = sm.location_id
             WHERE a.active = 1
             AND sl.active = 1
             AND b.expiry_date <= date("now", "+" || :within_days || " days")
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
            'within_days' => $withinDays
        ]);

        return $statement->fetchAll();
    }

    /**
     * Bestand eines Artikels an einem konkreten Lagerort, optional auf
     * eine bestimmte Charge eingegrenzt (`$batchId = null` bedeutet
     * "ohne MHD", nicht "egal welche Charge"). Wird u. a. von move()
     * genutzt, um vor einer Entnahme/Umbuchung die Verfügbarkeit zu
     * prüfen.
     */
    public function getStockAtLocation(
        int $articleId,
        int $locationId,
        ?int $batchId = null
    ): int {
        if ($batchId === null) {
            $statement = $this->db->prepare(
                'SELECT COALESCE(SUM(quantity), 0)
                 FROM stock_movements
                 WHERE article_id = :article_id
                 AND location_id = :location_id
                 AND batch_id IS NULL'
            );

            $statement->execute([
                'article_id' => $articleId,
                'location_id' => $locationId
            ]);
        } else {
            $statement = $this->db->prepare(
                'SELECT COALESCE(SUM(quantity), 0)
                 FROM stock_movements
                 WHERE article_id = :article_id
                 AND location_id = :location_id
                 AND batch_id = :batch_id'
            );

            $statement->execute([
                'article_id' => $articleId,
                'location_id' => $locationId,
                'batch_id' => $batchId
            ]);
        }

        return (int) $statement->fetchColumn();
    }

    /**
     * Bucht ein Stück der Charge mit dem ältesten MHD an einem Lagerort
     * aus (FIFO-Prinzip). Wird von der Buchen-Seite (Scanner und manuelle
     * Eingabe) für den Standardfall "Ausbuchen" verwendet.
     *
     * Bereits abgelaufene Chargen werden hier NICHT ausgeschlossen –
     * ist die älteste verfügbare Charge abgelaufen, wird genau diese
     * gebucht. Der Aufrufer muss das zurückgelieferte `expiry_date`
     * selbst gegen das heutige Datum prüfen, um eine Warnung anzuzeigen
     * (siehe expiryInfo() in public/index.php).
     *
     * @throws RuntimeException wenn kein Bestand an diesem Lagerort vorhanden ist
     */
    public function issueOldest(
        int $articleId,
        int $locationId,
        ?string $note = null
    ): array {
        $batch = $this->findOldestBatchWithStock($articleId, $locationId);

        $this->move(
            $articleId,
            $locationId,
            1,
            'issue',
            $note,
            $batch['batch_id']
        );

        return $batch;
    }

    /**
     * Bucht ein Stück der ältesten Charge von einem Lagerort auf einen
     * anderen um, statt es auszubuchen. Beide Bewegungen teilen sich
     * dieselbe Charge, damit das MHD beim Zielort erhalten bleibt.
     *
     * Wie issueOldest() werden auch hier bereits abgelaufene Chargen
     * nicht ausgeschlossen; der Aufrufer muss das ggf. selbst prüfen.
     *
     * @throws RuntimeException wenn Quell- und Ziellagerort identisch sind
     *                          oder kein Bestand am Quell-Lagerort vorhanden ist
     */
    public function transferOldest(
        int $articleId,
        int $fromLocationId,
        int $toLocationId,
        ?string $note = null
    ): array {
        if ($fromLocationId === $toLocationId) {
            throw new RuntimeException(
                'Quell- und Ziellagerort dürfen nicht identisch sein.'
            );
        }

        $batch = $this->findOldestBatchWithStock($articleId, $fromLocationId);

        $this->db->beginTransaction();

        try {
            $this->move(
                $articleId,
                $fromLocationId,
                1,
                'transfer_out',
                $note,
                $batch['batch_id']
            );

            $this->move(
                $articleId,
                $toLocationId,
                1,
                'transfer_in',
                $note,
                $batch['batch_id']
            );

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        return $batch;
    }

    /**
     * Bucht den kompletten aktuellen Bestand eines Lagerorts (alle
     * Artikel, alle Chargen) auf einen anderen Lagerort um – z. B. um
     * eine Kiste nach einem Dienst vollständig zurück ins Lager zu
     * räumen, ohne jeden Artikel einzeln umbuchen zu müssen.
     *
     * Anders als transferOldest() wird hier pro Artikel/Charge jeweils
     * die volle vorhandene Menge in einer einzigen Bewegung verschoben
     * (nicht Stück für Stück), das MHD bleibt dabei je Charge erhalten.
     * Bei leerem Quell-Lagerort passiert nichts.
     *
     * @return int Anzahl der insgesamt verschobenen Einheiten
     * @throws RuntimeException wenn Quell- und Ziellagerort identisch sind
     */
    public function transferAllStock(
        int $fromLocationId,
        int $toLocationId,
        ?string $note = null
    ): int {
        if ($fromLocationId === $toLocationId) {
            throw new RuntimeException(
                'Quell- und Ziellagerort dürfen nicht identisch sein.'
            );
        }

        $statement = $this->db->prepare(
            'SELECT article_id, batch_id, SUM(quantity) AS quantity
             FROM stock_movements
             WHERE location_id = :location_id
             GROUP BY article_id, batch_id
             HAVING SUM(quantity) > 0'
        );

        $statement->execute([
            'location_id' => $fromLocationId
        ]);

        $rows = $statement->fetchAll();

        if (!$rows) {
            return 0;
        }

        $totalMoved = 0;

        $this->db->beginTransaction();

        try {
            foreach ($rows as $row) {
                $articleId = (int) $row['article_id'];
                $batchId = $row['batch_id'] !== null
                    ? (int) $row['batch_id']
                    : null;
                $quantity = (int) $row['quantity'];

                $this->move($articleId, $fromLocationId, $quantity, 'transfer_out', $note, $batchId);
                $this->move($articleId, $toLocationId, $quantity, 'transfer_in', $note, $batchId);

                $totalMoved += $quantity;
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }

        return $totalMoved;
    }

    /**
     * Ermittelt die Charge mit dem ältesten MHD, die an einem Lagerort
     * noch positiven Bestand hat. Wird sowohl beim Ausbuchen als auch
     * beim Umbuchen verwendet, damit stets zuerst das älteste MHD
     * bewegt wird.
     */
    private function findOldestBatchWithStock(
        int $articleId,
        int $locationId
    ): array {
        $statement = $this->db->prepare(
            'SELECT
                sm.batch_id,
                b.expiry_date,
                SUM(sm.quantity) AS quantity
             FROM stock_movements sm
             LEFT JOIN batches b
                ON b.id = sm.batch_id
             WHERE sm.article_id = :article_id
             AND sm.location_id = :location_id
             GROUP BY
                sm.batch_id,
                b.expiry_date
             HAVING SUM(sm.quantity) > 0
             ORDER BY
                CASE
                    WHEN b.expiry_date IS NULL THEN 1
                    ELSE 0
                END,
                b.expiry_date ASC,
                sm.batch_id ASC'
        );

        $statement->execute([
            'article_id' => $articleId,
            'location_id' => $locationId
        ]);

        $batch = $statement->fetch();

        if (!$batch) {
            throw new RuntimeException(
                'Kein Bestand an diesem Lagerort vorhanden.'
            );
        }

        return [
            'batch_id' => $batch['batch_id'] !== null
                ? (int) $batch['batch_id']
                : null,
            'expiry_date' => $batch['expiry_date'],
        ];
    }

    /**
     * Erzeugt eine einzelne Lagerbewegung (einen Zugang oder Abgang).
     *
     * `$quantity` wird immer positiv übergeben; bei den Abgangstypen
     * `issue`/`transfer_out` wird sie hier intern negiert, nachdem
     * geprüft wurde, dass genug Bestand der betroffenen Charge an diesem
     * Lagerort vorhanden ist. Für eine vollständige Umbuchung (Abgang an
     * einem Lagerort + Zugang an einem anderen) siehe transferOldest(),
     * das move() zweimal in einer Transaktion aufruft.
     *
     * @param string $type receipt|issue|correction|transfer_out|transfer_in
     * @throws RuntimeException bei Menge 0, ungültigem Typ oder nicht
     *                          ausreichendem Bestand bei einem Abgang
     */
    public function move(
        int $articleId,
        int $locationId,
        int $quantity,
        string $type,
        ?string $note = null,
        ?int $batchId = null
    ): void {
        if ($quantity === 0) {
            throw new RuntimeException(
                'Die Menge darf nicht 0 sein.'
            );
        }

        if (!in_array(
            $type,
            ['receipt', 'issue', 'correction', 'transfer_out', 'transfer_in'],
            true
        )) {
            throw new RuntimeException(
                'Ungültiger Bewegungstyp.'
            );
        }

        if (in_array($type, ['issue', 'transfer_out'], true)) {
            $current = $this->getStockAtLocation(
                $articleId,
                $locationId,
                $batchId
            );

            if ($quantity > $current) {
                throw new RuntimeException(
                    'Nicht genügend Bestand dieser Charge an diesem Lagerort.'
                );
            }

            $quantity = -$quantity;
        }

        $statement = $this->db->prepare(
            'INSERT INTO stock_movements
                (
                    article_id,
                    batch_id,
                    location_id,
                    quantity,
                    movement_type,
                    note
                )
             VALUES
                (
                    :article_id,
                    :batch_id,
                    :location_id,
                    :quantity,
                    :movement_type,
                    :note
                )'
        );

        $statement->execute([
            'article_id' => $articleId,
            'batch_id' => $batchId,
            'location_id' => $locationId,
            'quantity' => $quantity,
            'movement_type' => $type,
            'note' => $note
        ]);
    }
}
