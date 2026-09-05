<?php

namespace LagerApp;

use PDO;

class BatchRepository
{
    public function __construct(
        private PDO $db
    ) {
    }

    public function allForArticle(int $articleId): array
    {
        $statement = $this->db->prepare(
            'SELECT
                b.id,
                b.article_id,
                b.batch_number,
                b.expiry_date,
                COALESCE(SUM(sm.quantity), 0) AS quantity
             FROM batches b
             LEFT JOIN stock_movements sm
                ON sm.batch_id = b.id
             WHERE b.article_id = :article_id
             GROUP BY
                b.id,
                b.article_id,
                b.batch_number,
                b.expiry_date
             ORDER BY
                CASE
                    WHEN b.expiry_date IS NULL THEN 1
                    ELSE 0
                END,
                b.expiry_date,
                b.batch_number'
        );

        $statement->execute([
            'article_id' => $articleId
        ]);

        return $statement->fetchAll();
    }

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

    public function findOrCreate(
        int $articleId,
        ?string $batchNumber,
        ?string $expiryDate
    ): ?int {
        $batchNumber = $batchNumber !== ''
            ? $batchNumber
            : null;

        $expiryDate = $expiryDate !== ''
            ? $expiryDate
            : null;

        // Kein Charge/MHD angegeben:
        // Bestand darf weiterhin ohne Charge geführt werden.
        if ($batchNumber === null && $expiryDate === null) {
            return null;
        }

        $statement = $this->db->prepare(
            'SELECT id
             FROM batches
             WHERE article_id = :article_id
             AND (
                 batch_number = :batch_number
                 OR (
                     batch_number IS NULL
                     AND :batch_number IS NULL
                 )
             )
             AND (
                 expiry_date = :expiry_date
                 OR (
                     expiry_date IS NULL
                     AND :expiry_date IS NULL
                 )
             )
             LIMIT 1'
        );

        $statement->execute([
            'article_id' => $articleId,
            'batch_number' => $batchNumber,
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
                    batch_number,
                    expiry_date
                )
             VALUES
                (
                    :article_id,
                    :batch_number,
                    :expiry_date
                )'
        );

        $statement->execute([
            'article_id' => $articleId,
            'batch_number' => $batchNumber,
            'expiry_date' => $expiryDate
        ]);

        return (int) $this->db->lastInsertId();
    }
}
