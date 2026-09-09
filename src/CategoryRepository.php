<?php

namespace LagerApp;

use PDO;

class CategoryRepository
{
    public function __construct(
        private PDO $db
    ) {
    }

    public function all(): array
    {
        return $this->db
            ->query(
                'SELECT *
                 FROM article_categories
                 WHERE active = 1
                 ORDER BY sort_order, name COLLATE NOCASE'
            )
            ->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT *
             FROM article_categories
             WHERE id = :id
             AND active = 1'
        );

        $statement->execute([
            'id' => $id
        ]);

        $category = $statement->fetch();

        return $category ?: null;
    }

    public function create(
        string $name,
        string $shortName,
        string $color
    ): int {
        $statement = $this->db->prepare(
            'INSERT INTO article_categories
                (name, short_name, color, sort_order, active)
             VALUES
                (:name, :short_name, :color, :sort_order, 1)'
        );

        $statement->execute([
            'name' => $name,
            'short_name' => $shortName,
            'color' => $color,
            'sort_order' => $this->nextSortOrder()
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(
        int $id,
        string $name,
        string $shortName,
        string $color
    ): void {
        $statement = $this->db->prepare(
            'UPDATE article_categories
             SET name = :name,
                 short_name = :short_name,
                 color = :color
             WHERE id = :id
             AND active = 1'
        );

        $statement->execute([
            'id' => $id,
            'name' => $name,
            'short_name' => $shortName,
            'color' => $color
        ]);
    }

    public function delete(int $id): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM article_categories
             WHERE id = :id
             AND active = 1'
        );

        $statement->execute([
            'id' => $id
        ]);
    }

    public function articleCount(int $id): int
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*)
             FROM articles
             WHERE category_id = :category_id
             AND active = 1'
        );

        $statement->execute([
            'category_id' => $id
        ]);

        return (int) $statement->fetchColumn();
    }

    public function reorder(array $ids): void
    {
        $statement = $this->db->prepare(
            'UPDATE article_categories
             SET sort_order = :sort_order
             WHERE id = :id
             AND active = 1'
        );

        $this->db->beginTransaction();

        try {

            foreach ($ids as $position => $id) {

                $statement->execute([
                    'sort_order' => ($position + 1) * 10,
                    'id' => (int) $id
                ]);
            }

            $this->db->commit();

        } catch (\Throwable $exception) {

            $this->db->rollBack();

            throw $exception;
        }
    }

    private function nextSortOrder(): int
    {
        $sortOrder = $this->db->query(
            'SELECT COALESCE(MAX(sort_order), 0)
             FROM article_categories
             WHERE active = 1'
        )->fetchColumn();

        return ((int) $sortOrder) + 10;
    }
}
