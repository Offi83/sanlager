<?php

namespace LagerApp;

use PDO;
use RuntimeException;

/**
 * Datenbankzugriff für Chargen/Mindesthaltbarkeitsdaten (Tabelle `batches`).
 *
 * Eine Charge gehört immer zu genau einem Artikel und repräsentiert ein
 * MHD (oder dessen Fehlen). Der Bestand einer Charge selbst wird nicht
 * hier, sondern über StockRepository anhand der Lagerbewegungen ermittelt.
 */
class BatchRepository
{
    public function __construct(
        private PDO $db
    ) {
    }

    /**
     * Alle Chargen eines Artikels mit ihrem aktuellen Bestand
     * (über alle Lagerorte hinweg), älteste MHD zuerst.
     */
    public function allForArticle(int $articleId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                b.id,
                b.article_id,
                b.expiry_date,
                COALESCE(SUM(sm.quantity), 0) AS quantity
             FROM batches b
             LEFT JOIN stock_movements sm
                ON sm.batch_id = b.id
             WHERE b.article_id = :article_id
             GROUP BY
                b.id,
                b.article_id,
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
     * Liefert eine einzelne Charge unabhängig vom Artikel.
     */
    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM batches
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id
        ]);

        $batch = $statement->fetch();

        return $batch ?: null;
    }

    /**
     * Findet die Charge eines Artikels zu einem MHD oder legt sie neu an.
     * Ein leeres MHD steht für "ohne MHD" und liefert `null` zurück,
     * ohne eine Charge anzulegen (Bestand ohne MHD referenziert stets
     * `batch_id = null`, nie eine eigene Charge).
     */
    public function findOrCreate(
        int $articleId,
        ?string $expiryDate
    ): ?int {
        $expiryDate = $expiryDate !== ''
            ? $expiryDate
            : null;

        if ($expiryDate === null) {
            return null;
        }

        /*
         * Letzte Absicherung: MHD-Vergleiche in SQL sind Textvergleiche
         * und funktionieren nur mit Y-m-d. Die Umwandlung von
         * Benutzereingaben erfolgt vorher per normalizeDate().
         */
        if (normalizeDate($expiryDate) !== $expiryDate) {
            throw new RuntimeException(
                'MHD muss im Format JJJJ-MM-TT gespeichert werden: '
                . $expiryDate
            );
        }

        $statement = $this->db->prepare(
            'SELECT id
             FROM batches
             WHERE article_id = :article_id
             AND expiry_date = :expiry_date
             LIMIT 1'
        );

        $statement->execute([
            'article_id' => $articleId,
            'expiry_date' => $expiryDate
        ]);

        $id = $statement->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        $statement = $this->db->prepare(
            'INSERT INTO batches
                (
                    article_id,
                    expiry_date
                )
             VALUES
                (
                    :article_id,
                    :expiry_date
                )'
        );

        $statement->execute([
            'article_id' => $articleId,
            'expiry_date' => $expiryDate
        ]);

        return (int) $this->db->lastInsertId();
    }
}
