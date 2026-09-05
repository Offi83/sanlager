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
                'SELECT
                    a.*,
                    c.name AS category_name,
                    c.sort_order AS category_sort_order
                 FROM articles a
                 LEFT JOIN article_categories c
                    ON c.id = a.category_id
                 WHERE a.active = 1
                 ORDER BY
                    COALESCE(c.sort_order, 9999),
                    c.name COLLATE NOCASE,
                    a.name COLLATE NOCASE'
            );

            return $statement->fetchAll();
        }

        $statement = $this->db->prepare(
            'SELECT
                a.*,
                c.name AS category_name,
                c.sort_order AS category_sort_order
             FROM articles a
             LEFT JOIN article_categories c
                ON c.id = a.category_id
             WHERE a.active = 1
             AND (
                 a.name LIKE :search
                 OR a.article_number LIKE :search
                 OR a.description LIKE :search
             )
             ORDER BY
                COALESCE(c.sort_order, 9999),
                c.name COLLATE NOCASE,
                a.name COLLATE NOCASE'
        );

        $statement->execute([
            'search' => '%' . $search . '%'
        ]);

        return $statement->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT
                a.*,
                c.name AS category_name,
                c.sort_order AS category_sort_order
             FROM articles a
             LEFT JOIN article_categories c
                ON c.id = a.category_id
             WHERE a.id = :id'
        );

        $statement->execute([
            'id' => $id
        ]);

        $article = $statement->fetch();

        return $article ?: null;
    }

    public function create(
        ?string $articleNumber,
        string $name,
        string $description,
        string $unit,
        int $minimumStock,
        ?int $categoryId
    ): int {
        $statement = $this->db->prepare(
            'INSERT INTO articles
                (
                    article_number,
                    name,
                    description,
                    unit,
                    minimum_stock,
                    category_id
                )
             VALUES
                (
                    :article_number,
                    :name,
                    :description,
                    :unit,
                    :minimum_stock,
                    :category_id
                )'
        );

        $statement->execute([
            'article_number' => $articleNumber ?: null,
            'name' => $name,
            'description' => $description ?: null,
            'unit' => $unit,
            'minimum_stock' => $minimumStock,
            'category_id' => $categoryId
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(
        int $id,
        ?string $articleNumber,
        string $name,
        string $description,
        string $unit,
        int $minimumStock,
        ?int $categoryId
    ): void {
        $statement = $this->db->prepare(
            'UPDATE articles
             SET article_number = :article_number,
                 name = :name,
                 description = :description,
                 unit = :unit,
                 minimum_stock = :minimum_stock,
                 category_id = :category_id
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id,
            'article_number' => $articleNumber ?: null,
            'name' => $name,
            'description' => $description,
            'unit' => $unit,
            'minimum_stock' => $minimumStock,
            'category_id' => $categoryId
        ]);
    }

    public function deactivate(int $id): void
    {
        $statement = $this->db->prepare(
            'UPDATE articles
             SET active = 0
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id
        ]);
    }
}
