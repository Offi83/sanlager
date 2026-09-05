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
        ?string $expiryDate
    ): ?int {
        $expiryDate = $expiryDate !== ''
            ? $expiryDate
            : null;

        if ($expiryDate === null) {
            return null;
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
