<?php

namespace LagerApp;

use PDO;

class ArticleRepository
{
    public function __construct(
        private PDO $db
    ) {
    }

    public function all(string $search = ''): array
    {
        if ($search === '') {
            $statement = $this->db->query(
                'SELECT * FROM articles
                 WHERE active = 1
                 ORDER BY name COLLATE NOCASE'
            );

            return $statement->fetchAll();
        }

        $statement = $this->db->prepare(
            'SELECT * FROM articles
             WHERE active = 1
             AND (
                 name LIKE :search
                 OR article_number LIKE :search
                 OR description LIKE :search
             )
             ORDER BY name COLLATE NOCASE'
        );

        $statement->execute([
            'search' => '%' . $search . '%'
        ]);

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT * FROM articles WHERE id = :id'
        );

        $statement->execute(['id' => $id]);

        $article = $statement->fetch();

        return $article ?: null;
    }

    public function create(
        ?string $articleNumber,
        string $name,
        string $description,
        string $unit,
        int $minimumStock
    ): int {
        $statement = $this->db->prepare(
            'INSERT INTO articles
                (article_number, name, description, unit, minimum_stock)
             VALUES
                (:article_number, :name, :description, :unit, :minimum_stock)'
        );

        $statement->execute([
            'article_number' => $articleNumber ?: null,
            'name' => $name,
            'description' => $description ?: null,
            'unit' => $unit,
            'minimum_stock' => $minimumStock
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(
        int $id,
        ?string $articleNumber,
        string $name,
        string $description,
        string $unit,
        int $minimumStock
    ): void {
        $statement = $this->db->prepare(
            'UPDATE articles
             SET article_number = :article_number,
                 name = :name,
                 description = :description,
                 unit = :unit,
                 minimum_stock = :minimum_stock
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id,
            'article_number' => $articleNumber ?: null,
            'name' => $name,
            'description' => $description ?: null,
            'unit' => $unit,
            'minimum_stock' => $minimumStock
        ]);
    }

    public function deactivate(int $id): void
    {
        $statement = $this->db->prepare(
            'UPDATE articles
             SET active = 0
             WHERE id = :id'
        );

        $statement->execute(['id' => $id]);
    }
}
