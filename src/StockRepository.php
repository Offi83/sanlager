<?php

namespace LagerApp;

use PDO;
use RuntimeException;

class StockRepository
{
    public function __construct(
        private PDO $db
    ) {
    }

    public function locations(): array
    {
        return $this->db
            ->query(
                'SELECT *
                 FROM storage_locations
                 WHERE active = 1
                 ORDER BY name COLLATE NOCASE'
            )
            ->fetchAll();
    }

    public function getStockForArticle(int $articleId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                sl.id AS location_id,
                sl.name AS location_name,
                COALESCE(SUM(sm.quantity), 0) AS quantity
             FROM storage_locations sl
             LEFT JOIN stock_movements sm
                ON sm.location_id = sl.id
                AND sm.article_id = :article_id
             WHERE sl.active = 1
             GROUP BY sl.id, sl.name
             ORDER BY sl.name COLLATE NOCASE'
        );

        $statement->execute([
            'article_id' => $articleId
        ]);

        return $statement->fetchAll();
    }

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

    public function getTotalStock(int $articleId): int
    {
        $statement = $this->db->prepare(
            'SELECT COALESCE(SUM(quantity), 0)
             FROM stock_movements
             WHERE article_id = :article_id'
        );

        $statement->execute([
            'article_id' => $articleId
        ]);

        return (int) $statement->fetchColumn();
    }

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
            ['receipt', 'issue', 'correction'],
            true
        )) {
            throw new RuntimeException(
                'Ungültiger Bewegungstyp.'
            );
        }

        if ($type === 'issue') {
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
