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
}
