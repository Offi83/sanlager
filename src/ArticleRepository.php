<?php

namespace LagerApp;

use PDO;
use RuntimeException;

/**
 * Datenbankzugriff für Artikelstammdaten (Tabelle `articles`).
 *
 * Bestände selbst werden nicht hier, sondern über StockRepository anhand
 * der Lagerbewegungen ermittelt.
 */
class ArticleRepository
{
    public function __construct(
        private PDO $db
    ) {
    }

    /**
     * Liefert alle aktiven Artikel inkl. Kategorie-Infos, optional gefiltert
     * nach Suchbegriff (Name/Artikelnummer/Beschreibung) und Kategorie.
     */
    public function all(string $search = '', ?int $categoryId = null): array
    {
        $conditions = [
            'a.active = 1'
        ];

        $parameters = [];

        if ($search !== '') {
            $conditions[] = '(
                a.name LIKE :search
                OR a.article_number LIKE :search
                OR a.description LIKE :search
            )';

            $parameters['search'] = '%' . $search . '%';
        }

        if ($categoryId !== null) {
            $conditions[] = 'a.category_id = :category_id';
            $parameters['category_id'] = $categoryId;
        }

        $sql = 'SELECT
                    a.*,
                    c.name AS category_name,
                    c.color AS category_color,
                    c.sort_order AS category_sort_order
                FROM articles a
                LEFT JOIN article_categories c
                    ON c.id = a.category_id
                WHERE ' . implode(' AND ', $conditions) . '
                ORDER BY
                    COALESCE(c.sort_order, 9999),
                    c.name COLLATE NOCASE,
                    a.name COLLATE NOCASE';

        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * Liefert einen einzelnen Artikel inkl. Kategorie-Infos, unabhängig
     * vom `active`-Status (z. B. für die Bearbeiten-Seite eines gerade
     * deaktivierten Artikels).
     */
    public function find(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT
                a.*,
                c.name AS category_name,
                c.color AS category_color,
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

    /**
     * Legt einen neuen Artikel an.
     *
     * @throws RuntimeException wenn Artikelnummer oder Name bereits vergeben sind
     */
    public function create(
        ?string $articleNumber,
        string $name,
        string $description,
        string $unit,
        ?int $categoryId
    ): int {
        $this->assertArticleNumberAvailable($articleNumber);
        $this->assertNameAvailable($name);

        $statement = $this->db->prepare(
            'INSERT INTO articles
                (
                    article_number,
                    name,
                    description,
                    unit,
                    category_id
                )
             VALUES
                (
                    :article_number,
                    :name,
                    :description,
                    :unit,
                    :category_id
                )'
        );

        $statement->execute([
            'article_number' => $articleNumber ?: null,
            'name' => $name,
            'description' => $description ?: null,
            'unit' => $unit,
            'category_id' => $categoryId
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Aktualisiert einen bestehenden Artikel.
     *
     * @throws RuntimeException wenn Artikelnummer oder Name bereits von
     *                          einem anderen Artikel verwendet werden
     */
    public function update(
        int $id,
        ?string $articleNumber,
        string $name,
        string $description,
        string $unit,
        ?int $categoryId
    ): void {
        $this->assertArticleNumberAvailable($articleNumber, $id);
        $this->assertNameAvailable($name, $id);

        $statement = $this->db->prepare(
            'UPDATE articles
             SET article_number = :article_number,
                 name = :name,
                 description = :description,
                 unit = :unit,
                 category_id = :category_id
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id,
            'article_number' => $articleNumber ?: null,
            'name' => $name,
            'description' => $description,
            'unit' => $unit,
            'category_id' => $categoryId
        ]);
    }

    /**
     * Sucht einen aktiven Artikel anhand seiner Artikelnummer.
     *
     * Wird vom Scanner/der manuellen Eingabe auf der Buchen-Seite verwendet.
     */
    public function findByArticleNumber(
        string $articleNumber
    ): ?array {
        $statement = $this->db->prepare(
            'SELECT
                a.*,
                c.name AS category_name
             FROM articles a
             LEFT JOIN article_categories c
                ON c.id = a.category_id
             WHERE a.article_number = :article_number
             AND a.active = 1
             LIMIT 1'
        );

        $statement->execute([
            'article_number' => $articleNumber
        ]);

        $article = $statement->fetch();

        return $article ?: null;
    }

    /**
     * Deaktiviert einen Artikel (Soft-Delete). Vorhandene Lagerbewegungen
     * bleiben dabei unangetastet, der Artikel verschwindet lediglich aus
     * den aktiven Listen.
     */
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

    /**
     * @throws RuntimeException wenn die Artikelnummer bereits vergeben ist
     */
    private function assertArticleNumberAvailable(
        ?string $articleNumber,
        ?int $excludeId = null
    ): void {
        if ($articleNumber === null || $articleNumber === '') {
            return;
        }

        $statement = $this->db->prepare(
            'SELECT id
             FROM articles
             WHERE article_number = :article_number
             AND id != :exclude_id
             LIMIT 1'
        );

        $statement->execute([
            'article_number' => $articleNumber,
            'exclude_id' => $excludeId ?? 0
        ]);

        if ($statement->fetchColumn() !== false) {
            throw new RuntimeException(
                'Diese Artikelnummer wird bereits verwendet'
            );
        }
    }

    /**
     * @throws RuntimeException wenn der Name bereits vergeben ist
     */
    private function assertNameAvailable(
        string $name,
        ?int $excludeId = null
    ): void {
        $statement = $this->db->prepare(
            'SELECT id
             FROM articles
             WHERE name = :name
             AND id != :exclude_id
             LIMIT 1'
        );

        $statement->execute([
            'name' => $name,
            'exclude_id' => $excludeId ?? 0
        ]);

        if ($statement->fetchColumn() !== false) {
            throw new RuntimeException(
                'Ein Artikel mit diesem Namen existiert bereits.'
            );
        }
    }
}
